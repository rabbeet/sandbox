<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Airports\Models\ParserVersion;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests for POST /api/sources/{source}/scrape (Step 9: manual scrape trigger).
 */
class ManualScrapeTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private ParserVersion $parserVersion;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'scrape-jobs.trigger', 'guard_name' => 'web']);

        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo('scrape-jobs.trigger');

        $airport = Airport::create([
            'iata'      => 'LHR',
            'name'      => 'Heathrow',
            'city'      => 'London',
            'country'   => 'GB',
            'timezone'  => 'Europe/London',
            'is_active' => true,
        ]);

        $this->source = AirportSource::create([
            'airport_id'              => $airport->id,
            'board_type'              => 'departures',
            'source_type'             => 'json_endpoint',
            'url'                     => 'https://example.com',
            'scrape_interval_minutes' => 15,
            'is_active'               => true,
        ]);

        $this->parserVersion = ParserVersion::create([
            'airport_source_id' => $this->source->id,
            'version'           => 1,
            'definition'        => ['mode' => 'json_endpoint'],
            'is_active'         => true,
            'activated_at'      => now(),
        ]);

        $this->source->update(['active_parser_version_id' => $this->parserVersion->id]);
    }

    public function test_trigger_creates_pending_scrape_job_and_returns_202(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(202);
        $response->assertJsonStructure(['scrape_job_id', 'status', 'message']);
        $response->assertJson(['status' => 'pending']);

        $jobId = $response->json('scrape_job_id');
        $scrapeJob = ScrapeJob::find($jobId);

        $this->assertNotNull($scrapeJob);
        $this->assertEquals('pending', $scrapeJob->status);
        $this->assertEquals('manual', $scrapeJob->triggered_by);
        $this->assertEquals($this->source->id, $scrapeJob->airport_source_id);
    }

    public function test_trigger_dispatches_scrape_airport_source_job_to_queue(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $this->postJson("/api/sources/{$this->source->id}/scrape");

        Queue::assertPushed(ScrapeAirportSourceJob::class, function ($job) {
            return $job->airportSourceId === $this->source->id;
        });
    }

    public function test_trigger_returns_422_when_source_is_inactive(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $this->source->update(['is_active' => false]);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_trigger_returns_422_when_no_active_parser_version(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $this->source->update(['active_parser_version_id' => null]);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        Queue::fake();

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Queue::fake();

        $user = User::factory()->create(); // no permissions
        $this->actingAs($user);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_trigger_returns_409_when_pending_job_already_exists(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        // Create an in-progress job
        $existingJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'pending',
            'triggered_by'      => 'scheduler',
        ]);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(409);
        $response->assertJson(['scrape_job_id' => $existingJob->id]);

        // No new job should be dispatched
        Queue::assertNothingPushed();
    }

    public function test_trigger_returns_409_when_running_job_exists(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $existingJob = ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'running',
            'started_at'        => now(),
        ]);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(409);
        $response->assertJson(['scrape_job_id' => $existingJob->id, 'status' => 'running']);
        Queue::assertNothingPushed();
    }

    public function test_trigger_succeeds_when_only_completed_jobs_exist(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        // A completed job should not block a new manual trigger
        ScrapeJob::create([
            'airport_source_id' => $this->source->id,
            'status'            => 'success',
            'completed_at'      => now()->subMinutes(30),
        ]);

        $response = $this->postJson("/api/sources/{$this->source->id}/scrape");

        $response->assertStatus(202);
        Queue::assertPushed(ScrapeAirportSourceJob::class);
    }
}
