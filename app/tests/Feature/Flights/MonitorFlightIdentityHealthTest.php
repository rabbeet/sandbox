<?php

namespace Tests\Feature\Flights;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorFlightIdentityHealthTest extends TestCase
{
    use RefreshDatabase;

    private Airport $airport;
    private ScrapeJob $scrapeJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::create([
            'iata'      => 'LHR',
            'name'      => 'Heathrow',
            'city'      => 'London',
            'country'   => 'GB',
            'timezone'  => 'Europe/London',
            'is_active' => true,
        ]);

        $source = AirportSource::create([
            'airport_id'              => $this->airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'html_table',
            'url'                     => 'https://example.com/lhr/dep',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        $this->scrapeJob = ScrapeJob::create([
            'airport_source_id' => $source->id,
            'status'            => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // Healthy baseline
    // -------------------------------------------------------------------------

    public function test_returns_success_and_reports_healthy_when_no_issues(): void
    {
        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Identity layer healthy');
    }

    // -------------------------------------------------------------------------
    // Orphaned snapshots (condition 1)
    // -------------------------------------------------------------------------

    public function test_detects_orphaned_snapshot_older_than_lag_window(): void
    {
        // created_at is not in FlightSnapshot::$fillable, so it cannot be backdated
        // via ::create(). Use a direct DB update after creation to simulate a snapshot
        // that has been sitting unprocessed for 10 minutes.
        $snapshot = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'raw_payload'        => ['flight_number' => 'BA436'],
            'normalized_payload' => ['flight_number' => 'BA436'],
        ]);

        \Illuminate\Support\Facades\DB::table('flight_snapshots')
            ->where('id', $snapshot->id)
            ->update([
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertFailed()
            ->expectsOutputToContain('ALERT');
    }

    public function test_does_not_flag_snapshot_within_lag_window_as_orphaned(): void
    {
        // A snapshot created just now is still in-flight through the queue — not an alert.
        FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'raw_payload'        => ['flight_number' => 'BA436'],
            'normalized_payload' => ['flight_number' => 'BA436'],
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Unadopted currents (condition 2 — informational, does not cause FAILURE)
    // -------------------------------------------------------------------------

    public function test_reports_unadopted_currents_without_failing(): void
    {
        // Legacy row: flight_instance_id is null (pre-migration)
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ]);

        // unadopted_currents > 0 is informational, not a hard-failure condition
        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Identity layer healthy');
    }

    // -------------------------------------------------------------------------
    // Duplicate instances (condition 3) and duplicate currents (condition 4)
    // -------------------------------------------------------------------------

    /**
     * With fi_identity_unique and fc_instance_unique constraints in place, it is
     * not possible to insert duplicate rows through any application path — the DB
     * rejects the insert before the monitoring command could ever see them.
     *
     * The duplicate-detection SQL in the command is therefore a belt-and-suspenders
     * check for scenarios such as: the constraint being accidentally dropped via a
     * schema migration, a direct DB-level restore loading unclean data, or a future
     * code path that bypasses the ORM.
     *
     * What we verify here is that the detection queries execute correctly and return
     * zero in a normal DB. The constraint-enforcement behavior itself is proven by
     * test_flight_instance_unique_constraint_rejects_duplicate_4tuple and
     * test_flights_current_unique_constraint_rejects_duplicate_instance in
     * UpdateFlightCurrentStateJobTest.
     */
    public function test_duplicate_instance_detection_query_returns_zero_when_clean(): void
    {
        FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        // A second instance with a different flight number is not a duplicate
        FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'VS4',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful();
    }

    public function test_duplicate_current_detection_query_returns_zero_when_clean(): void
    {
        $instance1 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        $instance2 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'VS4',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance1->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ]);

        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance2->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:VS4',
            'board_type'         => 'departures',
            'flight_number'      => 'VS4',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Clean state after proper job execution
    // -------------------------------------------------------------------------

    public function test_all_counters_are_zero_after_job_processes_snapshot(): void
    {
        $payload      = [
            'board_type'                 => 'departures',
            'flight_number'              => 'BA436',
            'operating_flight_number'    => null,
            'airline_iata'               => 'BA',
            'airline_name'               => 'British Airways',
            'origin_iata'                => 'LHR',
            'destination_iata'           => 'JFK',
            'service_date_local'         => '2026-03-08',
            'scheduled_departure_at_utc' => '2026-03-08 10:00:00',
            'estimated_departure_at_utc' => null,
            'actual_departure_at_utc'    => null,
            'scheduled_arrival_at_utc'   => '2026-03-08 13:00:00',
            'estimated_arrival_at_utc'   => null,
            'actual_arrival_at_utc'      => null,
            'departure_terminal'         => '5',
            'arrival_terminal'           => 'B',
            'departure_gate'             => 'B36',
            'arrival_gate'               => null,
            'baggage_belt'               => null,
            'status_raw'                 => 'On Time',
            'status_normalized'          => 'on_time',
        ];
        $canonicalKey = 'LHR:departures:2026-03-08:BA436';

        $snapshot = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => $canonicalKey,
            'raw_payload'        => $payload,
            'normalized_payload' => $payload,
        ]);

        (new \App\Domain\Flights\Jobs\UpdateFlightCurrentStateJob(
            $this->scrapeJob->id,
            $this->airport->id,
            $snapshot->id,
            $canonicalKey,
            $payload,
        ))->handle();

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Identity layer healthy');
    }

    // -------------------------------------------------------------------------
    // Split instances (condition 6 — direct identity-drift signal)
    // -------------------------------------------------------------------------

    /**
     * BA436 acquires two FlightInstance rows on adjacent dates (the retime-driven
     * identity split scenario). Both are active on the board right now.
     * The command must detect this and return FAILURE.
     */
    public function test_detects_split_instances_when_same_flight_number_has_two_active_instances(): void
    {
        // Instance for the original service date (flight scheduled at 23:50, service date = 2026-03-07)
        $instance1 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-07',
            'first_seen_at'      => now()->subHours(2),
        ]);

        // Instance for the next day (service_date_local derived incorrectly as 2026-03-08)
        $instance2 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now()->subHour(),
        ]);

        // Both are currently active on the board
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance1->id,
            'canonical_key'      => 'LHR:departures:2026-03-07:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-07',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(15),
            'last_changed_at'    => now()->subMinutes(15),
        ]);

        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance2->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(5),
            'last_changed_at'    => now()->subMinutes(5),
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertFailed()
            ->expectsOutputToContain('ALERT');
    }

    /**
     * A recurring weekly service (BA436 on 2026-03-01 and BA436 on 2026-03-08)
     * must NOT trigger a split-instances alert. The older instance's last_seen_at
     * has aged out of the 24-hour window — it is no longer "on the board."
     */
    public function test_does_not_flag_recurring_weekly_service_as_split(): void
    {
        // Last week's instance — last seen more than 24 hours ago
        $instanceOld = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-01',
            'first_seen_at'      => now()->subDays(7),
        ]);

        // This week's instance — currently active
        $instanceNew = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now()->subHours(2),
        ]);

        // Old instance: last seen 25 hours ago (outside the 24-hour window)
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instanceOld->id,
            'canonical_key'      => 'LHR:departures:2026-03-01:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-01',
            'is_active'          => false,
            'is_completed'       => true,
            'last_seen_at'       => now()->subHours(25),
            'last_changed_at'    => now()->subHours(25),
        ]);

        // New instance: currently on the board
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instanceNew->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(10),
            'last_changed_at'    => now()->subMinutes(10),
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Identity layer healthy');
    }

    /**
     * Same flight_number ('XY100'), same airport, same board, both active within 24h —
     * but genuinely different routes on adjacent service dates.
     *
     * The constraint (fi_identity_unique) prevents two instances with the same
     * (airport, board, flight_number, service_date_local), so the false-positive case
     * only arises at a day boundary: yesterday's XY100 LHR→JFK and today's XY100 LHR→CDG
     * can both be within the 24-hour active window simultaneously.
     *
     * Without origin+destination in the group key, these would land in the same group
     * (count = 2) and fire a false ALERT. With location dimensions, they are in separate
     * groups (count = 1 each) and no alert fires.
     *
     * Contrast with the true split test above: BA436 LHR→JFK on 2026-03-07 and
     * BA436 LHR→JFK on 2026-03-08 (retime drift). Same destination → same group →
     * correctly detected as a split.
     */
    public function test_does_not_flag_same_flight_number_on_different_routes_across_day_boundary_as_split(): void
    {
        // Yesterday's XY100: LHR → JFK (completed, but still within the 24-hour window)
        $instanceJfk = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'XY100',
            'service_date_local' => '2026-03-07',
            'origin_iata'        => 'LHR',
            'destination_iata'   => 'JFK',
            'first_seen_at'      => now()->subHours(20),
        ]);

        // Today's XY100: LHR → CDG (same number reused for a different service)
        $instanceCdg = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'XY100',
            'service_date_local' => '2026-03-08',
            'origin_iata'        => 'LHR',
            'destination_iata'   => 'CDG',
            'first_seen_at'      => now()->subHours(2),
        ]);

        // Yesterday's still visible on the board (last seen 18 hours ago)
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instanceJfk->id,
            'canonical_key'      => 'LHR:departures:2026-03-07:XY100',
            'board_type'         => 'departures',
            'flight_number'      => 'XY100',
            'origin_iata'        => 'LHR',
            'destination_iata'   => 'JFK',
            'service_date_local' => '2026-03-07',
            'is_active'          => false,
            'is_completed'       => true,
            'last_seen_at'       => now()->subHours(18),
            'last_changed_at'    => now()->subHours(18),
        ]);

        // Today's currently active
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instanceCdg->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:XY100',
            'board_type'         => 'departures',
            'flight_number'      => 'XY100',
            'origin_iata'        => 'LHR',
            'destination_iata'   => 'CDG',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(5),
            'last_changed_at'    => now()->subMinutes(5),
        ]);

        // Different destinations → different route groups → count = 1 each → no alert
        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Identity layer healthy');
    }

    /**
     * Two different flight numbers on the same route must not be flagged as a split,
     * even if both are currently active. BA436 and VS4 are distinct identities.
     */
    public function test_does_not_flag_two_different_flight_numbers_as_split(): void
    {
        $instance1 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        $instance2 = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'VS4',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance1->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ]);

        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance2->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:VS4',
            'board_type'         => 'departures',
            'flight_number'      => 'VS4',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ]);

        $this->artisan('flights:monitor-identity-health')
            ->assertSuccessful();
    }
}
