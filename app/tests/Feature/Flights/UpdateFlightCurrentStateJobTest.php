<?php

namespace Tests\Feature\Flights;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Flights\Jobs\UpdateFlightCurrentStateJob;
use App\Domain\Flights\Models\FlightChange;
use App\Domain\Flights\Models\FlightCurrent;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Concurrency and correctness tests for UpdateFlightCurrentStateJob.
 *
 * Covers five explicit scenarios requested before the identity layer was
 * considered production-safe:
 *
 *   1. Two dispatches for the same new instance — produces exactly one
 *      FlightInstance and one FlightCurrent; DB constraint catches any race
 *      that slips past the application-level lock.
 *
 *   2. Repeated dispatch for the same snapshot — idempotent; no duplicate
 *      rows, no spurious FlightChange records on the second run.
 *
 *   3. Delayed flight crossing midnight — service_date_local is derived from
 *      the scheduled time (set upstream by NormalizeScrapePayloadJob) and
 *      never from actual/estimated times, so the instance keeps its original
 *      service date even when actual departure is on the following calendar day.
 *
 *   4. Codeshare row appearing across scrapes — both scrapes resolve to the
 *      same FlightInstance keyed on the marketing flight number, regardless
 *      of whether the operating number is present or absent in a given row.
 *
 *   5. Adoption of a legacy flights_current row without flight_instance_id —
 *      the job wires the pre-existing row to the newly resolved instance
 *      rather than creating a duplicate.
 *
 * Also covers snapshot immutability — the append-only guard on FlightSnapshot
 * prevents payload fields from being mutated after initial creation.
 */
class UpdateFlightCurrentStateJobTest extends TestCase
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
    // Helpers
    // -------------------------------------------------------------------------

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /**
     * Create a FlightSnapshot in the DB and return [snapshot, payload, canonicalKey].
     * The canonical key mirrors what CanonicalKey::build() would produce.
     */
    private function createSnapshot(array $payloadOverrides = []): array
    {
        $payload      = $this->basePayload($payloadOverrides);
        $flightNumber = strtoupper($payload['flight_number']);
        $canonicalKey = 'LHR:departures:' . $payload['service_date_local'] . ':' . $flightNumber;

        $snapshot = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => $canonicalKey,
            'raw_payload'        => $payload,
            'normalized_payload' => $payload,
        ]);

        return [$snapshot, $payload, $canonicalKey];
    }

    private function runJob(FlightSnapshot $snapshot, array $payload, string $canonicalKey): void
    {
        (new UpdateFlightCurrentStateJob(
            $this->scrapeJob->id,
            $this->airport->id,
            $snapshot->id,
            $canonicalKey,
            $payload,
        ))->handle();
    }

    // -------------------------------------------------------------------------
    // Scenario 1a: Two sequential dispatches for the same new flight
    // -------------------------------------------------------------------------

    /**
     * Two separate snapshots (e.g. two queue workers) for the same logical flight
     * run sequentially. Only one FlightInstance and one FlightCurrent must exist
     * after both complete, and both snapshots must point to that single instance.
     *
     * This is the application-level deduplication path: the second dispatch finds
     * the existing FlightInstance via lockForUpdate() and the existing FlightCurrent
     * via flight_instance_id, so it updates instead of inserting.
     */
    public function test_two_dispatches_for_same_flight_produce_one_instance_and_one_current(): void
    {
        [$snapshot1, $payload, $canonicalKey] = $this->createSnapshot();
        [$snapshot2]                          = $this->createSnapshot();

        $this->runJob($snapshot1, $payload, $canonicalKey);
        $this->runJob($snapshot2, $payload, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);

        $instanceId = FlightInstance::first()->id;
        $this->assertSame($instanceId, $snapshot1->fresh()->flight_instance_id);
        $this->assertSame($instanceId, $snapshot2->fresh()->flight_instance_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 1b: DB constraint is the last line of defence against concurrent inserts
    // -------------------------------------------------------------------------

    /**
     * Even if application-level locking were bypassed (e.g. two workers both
     * passed the SELECT before either INSERT committed), the UNIQUE constraint
     * on (airport_id, board_type, flight_number, service_date_local) rejects
     * the second insert at the DB level.
     */
    public function test_flight_instance_unique_constraint_rejects_duplicate_4tuple(): void
    {
        $attrs = [
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ];

        FlightInstance::create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        FlightInstance::create($attrs);
    }

    /**
     * The UNIQUE constraint on flights_current.flight_instance_id enforces the
     * 1:1 relationship between FlightInstance and FlightCurrent at the DB level.
     */
    public function test_flights_current_unique_constraint_rejects_duplicate_instance(): void
    {
        $instance = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        $attrs = [
            'airport_id'         => $this->airport->id,
            'flight_instance_id' => $instance->id,
            'canonical_key'      => 'LHR:departures:2026-03-08:BA436',
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now(),
            'last_changed_at'    => now(),
        ];

        FlightCurrent::create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        FlightCurrent::create(array_merge($attrs, ['canonical_key' => 'LHR:departures:2026-03-08:BA436-dup']));
    }

    // -------------------------------------------------------------------------
    // Scenario 2: Repeated dispatch for the same snapshot
    // -------------------------------------------------------------------------

    /**
     * Dispatching the job twice with the exact same snapshot id is idempotent:
     * - Still exactly one FlightInstance and one FlightCurrent after the second run.
     * - The snapshot's flight_instance_id is set once and not changed on retry.
     * - No FlightChange records are created on the second run (nothing changed).
     */
    public function test_repeated_dispatch_for_same_snapshot_is_idempotent(): void
    {
        [$snapshot, $payload, $canonicalKey] = $this->createSnapshot();

        $this->runJob($snapshot, $payload, $canonicalKey);
        $this->runJob($snapshot, $payload, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);

        // snapshot is wired and stays wired
        $this->assertNotNull($snapshot->fresh()->flight_instance_id);

        // second run detected no changes — no FlightChange records
        $this->assertDatabaseCount('flight_changes', 0);
    }

    /**
     * When the second dispatch does carry a real state change (e.g. gate update),
     * a FlightChange record is created for that field and the current row is updated.
     * This confirms the idempotency guard does not suppress legitimate changes.
     */
    public function test_second_dispatch_with_changed_gate_creates_one_change_record(): void
    {
        [$snapshot1, $payload1, $canonicalKey] = $this->createSnapshot(['departure_gate' => 'B36']);

        $this->runJob($snapshot1, $payload1, $canonicalKey);

        [$snapshot2, $payload2] = $this->createSnapshot(['departure_gate' => 'C10']);

        $this->runJob($snapshot2, $payload2, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);
        $this->assertDatabaseCount('flight_changes', 1);
        $this->assertDatabaseHas('flight_changes', [
            'field_name' => 'departure_gate',
            'old_value'  => 'B36',
            'new_value'  => 'C10',
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: Delayed flight crossing midnight
    // -------------------------------------------------------------------------

    /**
     * A flight scheduled at 23:50 local on 2026-03-07 is delayed and actually
     * departs at 00:20 on 2026-03-08.
     *
     * NormalizeScrapePayloadJob derives service_date_local from the scheduled
     * departure time (23:50 → 2026-03-07) before this job runs. This job must
     * use that pre-resolved date, not re-derive it from actual/estimated times.
     *
     * Assert: FlightInstance.service_date_local = 2026-03-07, not 2026-03-08.
     * No second instance must be created for 2026-03-08.
     */
    public function test_delayed_flight_crossing_midnight_keeps_original_service_date(): void
    {
        $payload = $this->basePayload([
            'service_date_local'         => '2026-03-07', // resolved from scheduled 23:50
            'scheduled_departure_at_utc' => '2026-03-07 23:50:00',
            'actual_departure_at_utc'    => '2026-03-08 00:20:00', // crossed midnight
            'status_normalized'          => 'departed',
        ]);
        $canonicalKey = 'LHR:departures:2026-03-07:BA436';

        $snapshot = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => $canonicalKey,
            'raw_payload'        => $payload,
            'normalized_payload' => $payload,
        ]);

        $this->runJob($snapshot, $payload, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);

        $instance = FlightInstance::first();
        $this->assertSame('2026-03-07', $instance->service_date_local->toDateString());

        // No instance was accidentally created for the following day
        $this->assertDatabaseMissing('flight_instances', [
            'service_date_local' => '2026-03-08',
        ]);
    }

    /**
     * A subsequent scrape of the same delayed flight (now showing actual_departure)
     * still resolves to the same FlightInstance keyed on 2026-03-07.
     * is_completed transitions to true once actual_departure_at_utc is present.
     */
    public function test_subsequent_scrape_of_midnight_delayed_flight_updates_same_instance(): void
    {
        // First scrape: flight still showing as delayed
        $payload1 = $this->basePayload([
            'service_date_local'         => '2026-03-07',
            'scheduled_departure_at_utc' => '2026-03-07 23:50:00',
            'status_normalized'          => 'delayed',
        ]);
        $canonicalKey = 'LHR:departures:2026-03-07:BA436';
        $snapshot1    = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => $canonicalKey,
            'raw_payload'        => $payload1,
            'normalized_payload' => $payload1,
        ]);
        $this->runJob($snapshot1, $payload1, $canonicalKey);

        // Second scrape: now departed, actual time is after midnight
        $payload2 = $this->basePayload([
            'service_date_local'         => '2026-03-07',
            'scheduled_departure_at_utc' => '2026-03-07 23:50:00',
            'actual_departure_at_utc'    => '2026-03-08 00:20:00',
            'status_normalized'          => 'departed',
        ]);
        $snapshot2 = FlightSnapshot::create([
            'scrape_job_id'      => $this->scrapeJob->id,
            'canonical_key'      => $canonicalKey,
            'raw_payload'        => $payload2,
            'normalized_payload' => $payload2,
        ]);
        $this->runJob($snapshot2, $payload2, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);

        $current = FlightCurrent::first();
        $this->assertTrue($current->is_completed);
        $this->assertSame('2026-03-08 00:20:00', $current->actual_departure_at_utc->format('Y-m-d H:i:s'));
    }

    // -------------------------------------------------------------------------
    // Scenario 4: Codeshare normalization across scrapes
    // -------------------------------------------------------------------------

    /**
     * First scrape emits only the marketing number (BA436).
     * Second scrape emits the same marketing number with an operating number added (IB3167).
     *
     * Both should resolve to the same FlightInstance keyed on BA436 — one current
     * state row, no duplicate identity records.
     *
     * The operating number must be stored in flights_current as metadata but must
     * NOT affect the identity lookup.
     */
    public function test_codeshare_with_and_without_operating_number_resolves_to_same_instance(): void
    {
        // First scrape: marketing number only
        [$snapshot1, $payload1, $canonicalKey] = $this->createSnapshot([
            'flight_number'           => 'BA436',
            'operating_flight_number' => null,
        ]);
        $this->runJob($snapshot1, $payload1, $canonicalKey);

        // Second scrape: same marketing number, operating number now present
        [$snapshot2, $payload2] = $this->createSnapshot([
            'flight_number'           => 'BA436',
            'operating_flight_number' => 'IB3167',
        ]);
        $this->runJob($snapshot2, $payload2, $canonicalKey);

        $this->assertDatabaseCount('flight_instances', 1);
        $this->assertDatabaseCount('flights_current', 1);

        $instance = FlightInstance::first();
        $this->assertSame('BA436', $instance->flight_number);

        // Operating number is stored in the current projection, not in identity
        $this->assertSame('IB3167', FlightCurrent::first()->operating_flight_number);
    }

    /**
     * Two different marketing flight numbers on the same route and date must
     * produce two separate FlightInstance records — they are different flights.
     */
    public function test_two_distinct_flight_numbers_produce_two_instances(): void
    {
        [$snapshot1, $payload1, $key1] = $this->createSnapshot(['flight_number' => 'BA436']);
        [$snapshot2, $payload2, $key2] = $this->createSnapshot(['flight_number' => 'VS4']);

        $this->runJob($snapshot1, $payload1, $key1);
        $this->runJob($snapshot2, $payload2, $key2);

        $this->assertDatabaseCount('flight_instances', 2);
        $this->assertDatabaseCount('flights_current', 2);
    }

    // -------------------------------------------------------------------------
    // Scenario 5: Legacy flights_current row adoption
    // -------------------------------------------------------------------------

    /**
     * A FlightCurrent row created before flight_instance_id was introduced has
     * flight_instance_id = null. When the job processes a new snapshot for the
     * same flight, it must:
     *   - Create a FlightInstance for the 4-tuple
     *   - Find the legacy row via the canonical_key safety net
     *   - Wire flight_instance_id onto the existing row (adoption)
     *   - Not create a duplicate FlightCurrent row
     */
    public function test_legacy_flights_current_row_without_instance_id_is_adopted(): void
    {
        $canonicalKey = 'LHR:departures:2026-03-08:BA436';

        // Pre-existing row: correct canonical_key, no flight_instance_id
        $legacyCurrent = FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'canonical_key'      => $canonicalKey,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(15),
            'last_changed_at'    => now()->subMinutes(15),
        ]);
        $this->assertNull($legacyCurrent->flight_instance_id);

        [$snapshot, $payload] = $this->createSnapshot();
        $this->runJob($snapshot, $payload, $canonicalKey);

        // No duplicate was created
        $this->assertDatabaseCount('flights_current', 1);
        $this->assertDatabaseCount('flight_instances', 1);

        // The original row was adopted in place (same DB id)
        $this->assertSame($legacyCurrent->id, FlightCurrent::first()->id);

        // flight_instance_id is now wired
        $instance = FlightInstance::first();
        $this->assertSame($instance->id, FlightCurrent::first()->flight_instance_id);
    }

    /**
     * Snapshot is also wired to the instance in the same transaction as the
     * legacy row adoption.
     */
    public function test_snapshot_is_wired_to_instance_during_legacy_adoption(): void
    {
        $canonicalKey  = 'LHR:departures:2026-03-08:BA436';
        FlightCurrent::create([
            'airport_id'         => $this->airport->id,
            'canonical_key'      => $canonicalKey,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'is_active'          => true,
            'is_completed'       => false,
            'last_seen_at'       => now()->subMinutes(15),
            'last_changed_at'    => now()->subMinutes(15),
        ]);

        [$snapshot, $payload] = $this->createSnapshot();
        $this->assertNull($snapshot->flight_instance_id);

        $this->runJob($snapshot, $payload, $canonicalKey);

        $instance = FlightInstance::first();
        $this->assertSame($instance->id, $snapshot->fresh()->flight_instance_id);
    }

    // -------------------------------------------------------------------------
    // Snapshot immutability
    // -------------------------------------------------------------------------

    /**
     * The FlightSnapshot model fires a LogicException if any of the immutable
     * payload fields are modified via a model-level save() after creation.
     *
     * The flight_instance_id back-fill in UpdateFlightCurrentStateJob uses a
     * bulk ::where()->update() query which bypasses model events, so it is not
     * affected by this guard.
     */
    public function test_snapshot_immutability_guard_blocks_payload_modification(): void
    {
        [$snapshot] = $this->createSnapshot();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $snapshot->normalized_payload = ['tampered' => true];
        $snapshot->save();
    }

    public function test_snapshot_immutability_guard_blocks_canonical_key_change(): void
    {
        [$snapshot] = $this->createSnapshot();

        $this->expectException(\LogicException::class);

        $snapshot->canonical_key = 'TAMPERED:departures:2026-03-08:XX999';
        $snapshot->save();
    }

    /**
     * Confirm the guard does NOT block setting flight_instance_id via model save,
     * since flight_instance_id is not in IMMUTABLE_FIELDS. (The back-fill uses a
     * bulk query, but model-level wiring should also be allowed for tooling/repairs.)
     */
    public function test_snapshot_allows_setting_flight_instance_id_via_model_save(): void
    {
        [$snapshot] = $this->createSnapshot();

        $instance = FlightInstance::create([
            'airport_id'         => $this->airport->id,
            'board_type'         => 'departures',
            'flight_number'      => 'BA436',
            'service_date_local' => '2026-03-08',
            'first_seen_at'      => now(),
        ]);

        // Must not throw — flight_instance_id is mutable by design
        $snapshot->flight_instance_id = $instance->id;
        $snapshot->save();

        $this->assertSame($instance->id, $snapshot->fresh()->flight_instance_id);
    }
}
