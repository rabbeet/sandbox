<?php

namespace App\Console\Commands;

use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Scraping\Models\FlightSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks the operational health of the flight identity/state separation layer.
 *
 * Run every 5 minutes via the scheduler. Emits structured log entries that
 * alerting tools (Datadog, CloudWatch, Grafana Loki, etc.) can use to fire
 * alerts or increment counters.
 *
 * Monitored conditions:
 *
 *   1. orphaned_snapshots        — FlightSnapshot rows with flight_instance_id IS NULL
 *                                  that are older than 5 minutes. These indicate that
 *                                  UpdateFlightCurrentStateJob failed all its retries
 *                                  or was never dispatched for the snapshot.
 *
 *   2. unadopted_currents        — FlightCurrent rows with flight_instance_id IS NULL.
 *                                  Expected to decrease post-rollout as active flights
 *                                  are re-scraped. A rising count indicates flight
 *                                  activity that the new code path has not yet seen.
 *
 *   3. duplicate_instances       — flight_instances rows that share the same 4-tuple.
 *                                  Must always be zero; a non-zero count means the
 *                                  UNIQUE constraint is somehow not being enforced.
 *
 *   4. duplicate_currents        — flights_current rows with the same flight_instance_id.
 *                                  Must always be zero; same rationale as above.
 *
 *   5. stale_unadopted_currents  — FlightCurrent rows with flight_instance_id IS NULL
 *                                  that have not been updated in over 24 hours. These
 *                                  will never be adopted organically (the source flight
 *                                  is no longer being scraped); they represent completed
 *                                  or inactive flights from before the migration.
 *
 *   6. split_instances           — (airport_id, board_type, flight_number) tuples that
 *                                  map to more than one FlightInstance, where both
 *                                  instances have been seen on the board within the last
 *                                  24 hours (via flights_current.last_seen_at).
 *
 *                                  This is the direct identity-drift signal: the same
 *                                  flight number simultaneously resolving to two logical
 *                                  identities. It catches retime-driven splits earlier
 *                                  than a count-spike heuristic because it detects the
 *                                  structural condition (two active instances per flight)
 *                                  rather than inferring it from aggregate behaviour.
 *
 *                                  A weekly recurring flight (e.g. BA436 on both
 *                                  2026-03-07 and 2026-03-14) does not trigger this
 *                                  because only the current day's flight is active on
 *                                  the board — last_seen_at on last week's instance will
 *                                  have aged out of the 24-hour window.
 *
 * The command exits with FAILURE if any of conditions 1, 3, 4, or 6 are non-zero,
 * so a non-zero exit code can be used as a cron alert trigger.
 * Conditions 2 and 5 are informational (expected post-rollout drain).
 */
class MonitorFlightIdentityHealth extends Command
{
    protected $signature   = 'flights:monitor-identity-health';
    protected $description = 'Emit structured health metrics for the flight identity layer';

    /** Snapshots older than this are considered stuck (not still in-flight in the queue). */
    private const ORPHAN_LAG_MINUTES = 5;

    /** Currents older than this without adoption are considered permanently unadopted. */
    private const STALE_ADOPTION_HOURS = 24;

    public function handle(): int
    {
        $metrics = $this->collectMetrics();

        $this->emitLog($metrics);
        $this->renderTable($metrics);

        // Exit non-zero if any hard-failure condition is detected.
        $hardFailure = $metrics['orphaned_snapshots'] > 0
            || $metrics['duplicate_instances'] > 0
            || $metrics['duplicate_currents'] > 0
            || $metrics['split_instances'] > 0;

        return $hardFailure ? self::FAILURE : self::SUCCESS;
    }

    private function collectMetrics(): array
    {
        $orphanedSnapshots = FlightSnapshot::whereNull('flight_instance_id')
            ->where('created_at', '<', now()->subMinutes(self::ORPHAN_LAG_MINUTES))
            ->count();

        $unadoptedCurrents = FlightCurrent::whereNull('flight_instance_id')
            ->count();

        $staleUnadoptedCurrents = FlightCurrent::whereNull('flight_instance_id')
            ->where('updated_at', '<', now()->subHours(self::STALE_ADOPTION_HOURS))
            ->count();

        // A non-zero count here means the DB UNIQUE constraint is not being enforced —
        // this should be structurally impossible but is worth asserting explicitly.
        $duplicateInstances = $this->countDuplicateInstances();
        $duplicateCurrents  = $this->countDuplicateCurrents();

        // Direct identity-drift signal: same flight_number active on multiple instances
        // within the same airport+board combination right now.
        $splitInstances = $this->countSplitInstances();

        return [
            'orphaned_snapshots'       => $orphanedSnapshots,
            'unadopted_currents'       => $unadoptedCurrents,
            'stale_unadopted_currents' => $staleUnadoptedCurrents,
            'duplicate_instances'      => $duplicateInstances,
            'duplicate_currents'       => $duplicateCurrents,
            'split_instances'          => $splitInstances,
            'checked_at'               => now()->toIso8601String(),
        ];
    }

    /**
     * Count (airport_id, board_type, flight_number, service_date_local) tuples
     * that appear more than once in flight_instances.
     * Zero is the only acceptable value — enforced by fi_identity_unique.
     */
    private function countDuplicateInstances(): int
    {
        // selectRaw('1') avoids the Postgres requirement that all projected columns
        // appear in GROUP BY. havingRaw avoids the alias-in-HAVING restriction.
        return DB::table('flight_instances')
            ->selectRaw('1')
            ->groupBy('airport_id', 'board_type', 'flight_number', 'service_date_local')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    /**
     * Count flight_instance_id values that appear more than once in flights_current.
     * Zero is the only acceptable value — enforced by fc_instance_unique.
     */
    private function countDuplicateCurrents(): int
    {
        return DB::table('flights_current')
            ->selectRaw('1')
            ->whereNotNull('flight_instance_id')
            ->groupBy('flight_instance_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    /**
     * Count identity-drift splits: groups where the same logical flight maps to more
     * than one FlightInstance that has been seen on the board within the last 24 hours.
     *
     * SPLIT DEFINITION
     * A split occurs when the same (airport_id, board_type, flight_number,
     * origin_iata, destination_iata) combination resolves to more than one
     * FlightInstance — meaning the same physical flight acquired different
     * service_date_local values across scrapes, creating two separate identity records.
     *
     * Origin and destination are included in the group to avoid false positives for
     * carriers that legitimately reuse a flight number on different routes
     * (e.g. 'XY100 LHR→JFK' and 'XY100 LHR→CDG' active simultaneously). Without the
     * location dimensions, both would fall into the same group and trigger a spurious
     * alert. In the actual retime-driven split scenario, both instances are the same
     * physical flight and therefore share the same origin/destination — they still land
     * in the same group and the split is correctly detected.
     *
     * NULLS: COALESCE to '' treats unknown origin/destination as a single sentinel
     * group. If metadata is missing on both split instances, the grouping still works
     * correctly. If metadata is missing on only one instance (parser regression), the
     * two instances land in different groups and the split goes undetected — a known
     * limitation noted in the operational runbook.
     *
     * 24-HOUR WINDOW — HEURISTIC, NOT A GUARANTEE
     * The recency filter (last_seen_at >= now() - 24h) scopes the check to flights
     * currently visible on the board. This is an operational heuristic based on the
     * assumption that boards do not retain a completed flight as active for more than
     * 24 hours after it was last scraped.
     *
     * Limitations:
     *   - If a board has extended retention (e.g. a heavily delayed flight stays
     *     visible for 30+ hours), a genuine split could age out of detection.
     *   - If the scraper is down for > 24 hours, last_seen_at on all flights ages out
     *     and the check reports 0 splits regardless of actual drift. The
     *     orphaned_snapshots counter will fire in that scenario instead.
     *   - The window duration (24h) should be re-evaluated if operational board
     *     retention patterns differ materially from this assumption.
     *
     * A recurring service (e.g. BA436 running weekly) does not trigger this because
     * last week's instance ages out of the window before this week's instance appears.
     */
    private function countSplitInstances(): int
    {
        return DB::table('flight_instances as fi')
            ->join('flights_current as fc', 'fc.flight_instance_id', '=', 'fi.id')
            ->selectRaw('1')
            ->where('fc.last_seen_at', '>=', now()->subHours(24))
            ->groupByRaw("
                fi.airport_id,
                fi.board_type,
                fi.flight_number,
                COALESCE(fi.origin_iata, ''),
                COALESCE(fi.destination_iata, '')
            ")
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    /**
     * Emit a single structured log entry so alerting tools can match on event name
     * and threshold-trigger on individual counter fields.
     *
     * Log::warning is used when any hard-failure condition is non-zero so that a
     * plain log-level filter is sufficient for a basic alert rule.
     */
    private function emitLog(array $metrics): void
    {
        $hardFailure = $metrics['orphaned_snapshots'] > 0
            || $metrics['duplicate_instances'] > 0
            || $metrics['duplicate_currents'] > 0
            || $metrics['split_instances'] > 0;

        $payload = array_merge(['event' => 'flight_identity_health'], $metrics);

        if ($hardFailure) {
            Log::warning('flight_identity_health', $payload);
        } else {
            Log::info('flight_identity_health', $payload);
        }
    }

    private function renderTable(array $metrics): void
    {
        $rows = [
            [
                'orphaned_snapshots',
                $metrics['orphaned_snapshots'],
                $metrics['orphaned_snapshots'] > 0 ? 'ALERT  — job failed all retries or was never dispatched' : 'ok',
            ],
            [
                'unadopted_currents',
                $metrics['unadopted_currents'],
                $metrics['unadopted_currents'] > 0 ? 'draining — expected to fall to 0 post-rollout'          : 'ok',
            ],
            [
                'stale_unadopted_currents',
                $metrics['stale_unadopted_currents'],
                $metrics['stale_unadopted_currents'] > 0 ? 'info   — completed/inactive flights pre-migration'  : 'ok',
            ],
            [
                'duplicate_instances',
                $metrics['duplicate_instances'],
                $metrics['duplicate_instances'] > 0 ? 'ALERT  — fi_identity_unique not enforced'              : 'ok',
            ],
            [
                'duplicate_currents',
                $metrics['duplicate_currents'],
                $metrics['duplicate_currents'] > 0 ? 'ALERT  — fc_instance_unique not enforced'               : 'ok',
            ],
            [
                'split_instances',
                $metrics['split_instances'],
                $metrics['split_instances'] > 0 ? 'ALERT  — same flight_number active on multiple instances'  : 'ok',
            ],
        ];

        $this->table(['metric', 'count', 'status'], $rows);
        $this->line('  checked_at: ' . $metrics['checked_at']);

        $this->newLine();

        if ($metrics['orphaned_snapshots'] > 0 || $metrics['duplicate_instances'] > 0 || $metrics['duplicate_currents'] > 0 || $metrics['split_instances'] > 0) {
            $this->error('One or more ALERT conditions detected. Check logs for event=flight_identity_health.');
        } else {
            $this->info('Identity layer healthy.');
        }
    }
}
