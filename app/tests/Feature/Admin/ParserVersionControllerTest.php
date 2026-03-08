<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Flights\Jobs\UpdateFlightCurrentStateJob;
use App\Domain\Flights\Models\FlightInstance;
use App\Domain\Scraping\Models\FlightSnapshot;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests for parser version endpoints (Step 9).
 */
class ParserVersionControllerTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['parser-versions.view', 'parser-versions.create', 'parser-versions.activate', 'parser-versions.replay'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['parser-versions.view', 'parser-versions.create', 'parser-versions.activate', 'parser-versions.replay']);

        $airport = Airport::create([
            'iata'      => 'CDG',
            'name'      => 'Charles de Gaulle',
            'city'      => 'Paris',
            'country'   => 'FR',
            'timezone'  => 'Europe/Paris',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'arrivals',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);
    }

    public function test_index_lists_parser_versions_newest_first(): void
    {
        $this->actingAs($this->admin);

        $this->makeVersion(1);
        $this->makeVersion(2);

        $response = $this->getJson("/api/sources/{$this->source->id}/parser-versions");

        $response->assertOk();
        $versions = $response->json('data');
        $this->assertCount(2, $versions);
        $this->assertEquals(2, $versions[0]['version']); // newest first
    }

    public function test_store_creates_version_with_auto_incremented_number(): void
    {
        $this->actingAs($this->admin);

        $this->makeVersion(3); // existing max

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions", [
            'definition' => ['mode' => 'json_endpoint', 'fields' => []],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(4, $response->json('data.version')); // next after 3
        $this->assertFalse($response->json('data.is_active'));    // not active by default
    }

    public function test_store_creates_version_1_when_no_prior_versions_exist(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions", [
            'definition' => ['mode' => 'html_table', 'fields' => []],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.version'));
    }

    public function test_activate_marks_version_active_and_deactivates_others(): void
    {
        $this->actingAs($this->admin);

        $v1 = $this->makeVersion(1, active: true);
        $v2 = $this->makeVersion(2, active: false);
        $this->source->update(['active_parser_version_id' => $v1->id]);

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions/{$v2->id}/activate");

        $response->assertOk();
        $this->assertTrue($response->json('data.is_active'));

        $v1->refresh();
        $v2->refresh();
        $this->assertFalse($v1->is_active);
        $this->assertTrue($v2->is_active);

        $this->source->refresh();
        $this->assertEquals($v2->id, $this->source->active_parser_version_id);
    }

    public function test_activate_returns_404_for_version_belonging_to_different_source(): void
    {
        $this->actingAs($this->admin);

        $otherAirport = Airport::create([
            'iata' => 'JFK', 'name' => 'JFK', 'city' => 'NYC',
            'country' => 'US', 'timezone' => 'America/New_York', 'is_active' => true,
        ]);
        $otherSource = AirportSource::create([
            'airport_id' => $otherAirport->id, 'board_type' => 'departures',
            'source_type' => 'json_endpoint', 'url' => 'https://other.com',
            'scrape_interval_minutes' => 15, 'is_active' => true,
        ]);
        $foreignVersion = $this->makeVersionForSource($otherSource, 1);

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions/{$foreignVersion->id}/activate");

        $response->assertStatus(404);
    }

    public function test_replay_dispatches_state_update_jobs_for_existing_snapshots(): void
    {
        // Replay operates on EXISTING snapshots with flight_instance_id set.
        // It dispatches UpdateFlightCurrentStateJob (not NormalizeScrapePayloadJob),
        // so no new snapshots are created.
        $this->actingAs($this->admin);

        $pv = $this->makeVersion(1, active: true);
        $this->source->update(['active_parser_version_id' => $pv->id]);

        $scrapeJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $pv->id,
            'status'            => 'success',
            'completed_at'      => now()->subHours(1),
            'row_count'         => 2,
        ]);

        // 2 snapshots fully processed (flight_instance_id set — requires real FK rows)
        for ($i = 0; $i < 2; $i++) {
            $instance = FlightInstance::create([
                'airport_id'         => $this->source->airport_id,
                'board_type'         => 'arrivals',
                'flight_number'      => "AF{$i}",
                'service_date_local' => '2026-03-08',
                'first_seen_at'      => now(),
            ]);
            FlightSnapshot::create([
                'scrape_job_id'      => $scrapeJob->id,
                'flight_instance_id' => $instance->id,
                'canonical_key'      => "CDG:arrivals:2026-03-08:AF{$i}",
                'raw_payload'        => ['flight_number' => "AF{$i}"],
                'normalized_payload' => ['flight_number' => "AF{$i}", 'board_type' => 'arrivals'],
            ]);
        }

        // 1 orphaned snapshot (no flight_instance_id) — should NOT be replayed
        FlightSnapshot::create([
            'scrape_job_id'      => $scrapeJob->id,
            'flight_instance_id' => null,
            'canonical_key'      => 'CDG:arrivals:2026-03-08:AF99',
            'raw_payload'        => ['flight_number' => 'AF99'],
            'normalized_payload' => ['flight_number' => 'AF99', 'board_type' => 'arrivals'],
        ]);

        Queue::fake();

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions/{$pv->id}/replay");

        $response->assertOk();
        $this->assertEquals(2, $response->json('dispatched')); // only 2 (orphan excluded)
        Queue::assertPushed(UpdateFlightCurrentStateJob::class, 2);
    }

    public function test_replay_returns_zero_when_no_successful_jobs_exist(): void
    {
        $this->actingAs($this->admin);

        $pv = $this->makeVersion(1, active: true);
        $this->source->update(['active_parser_version_id' => $pv->id]);

        // Only a failed job
        ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $pv->id,
            'status'            => 'failed',
            'completed_at'      => now()->subHours(1),
        ]);

        Queue::fake();

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions/{$pv->id}/replay");

        $response->assertOk();
        $this->assertEquals(0, $response->json('dispatched'));
        Queue::assertNothingPushed();
    }

    public function test_replay_respects_limit_query_parameter(): void
    {
        $this->actingAs($this->admin);

        $pv = $this->makeVersion(1, active: true);
        $this->source->update(['active_parser_version_id' => $pv->id]);

        $scrapeJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'parser_version_id' => $pv->id,
            'status'            => 'success',
            'completed_at'      => now()->subHours(1),
            'row_count'         => 10,
        ]);

        // 10 fully-processed snapshots (need real FK rows)
        for ($i = 0; $i < 10; $i++) {
            $instance = FlightInstance::create([
                'airport_id'         => $this->source->airport_id,
                'board_type'         => 'arrivals',
                'flight_number'      => "LM{$i}",
                'service_date_local' => '2026-03-08',
                'first_seen_at'      => now(),
            ]);
            FlightSnapshot::create([
                'scrape_job_id'      => $scrapeJob->id,
                'flight_instance_id' => $instance->id,
                'canonical_key'      => "CDG:arrivals:2026-03-08:LM{$i}",
                'raw_payload'        => ['flight_number' => "LM{$i}"],
                'normalized_payload' => ['flight_number' => "LM{$i}", 'board_type' => 'arrivals'],
            ]);
        }

        Queue::fake();

        $response = $this->postJson("/api/sources/{$this->source->id}/parser-versions/{$pv->id}/replay?limit=3");

        $this->assertEquals(3, $response->json('dispatched'));
        Queue::assertPushed(UpdateFlightCurrentStateJob::class, 3);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeVersion(int $version, bool $active = false): ParserVersion
    {
        return $this->makeVersionForSource($this->source, $version, $active);
    }

    private function makeVersionForSource(AirportSource $source, int $version, bool $active = false): ParserVersion
    {
        return ParserVersion::create([
            'airport_source_id' => $source->id,
            'version'           => $version,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => $active,
            'activated_at'      => $active ? now() : null,
        ]);
    }
}
