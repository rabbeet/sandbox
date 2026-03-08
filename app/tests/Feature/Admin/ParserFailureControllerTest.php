<?php

namespace Tests\Feature\Admin;

use App\Domain\Airports\Models\Airport;
use App\Domain\Airports\Models\AirportSource;
use App\Domain\Repairs\Models\ParserFailure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests for parser failure endpoints (Step 9).
 */
class ParserFailureControllerTest extends TestCase
{
    use RefreshDatabase;

    private AirportSource $source;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['failures.view', 'failures.repair'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->operator = User::factory()->create();
        $this->operator->givePermissionTo(['failures.view', 'failures.repair']);

        $airport = Airport::create([
            'iata'      => 'DXB',
            'name'      => 'Dubai International',
            'city'      => 'Dubai',
            'country'   => 'AE',
            'timezone'  => 'Asia/Dubai',
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
    }

    public function test_index_returns_open_and_investigating_failures_by_default(): void
    {
        $this->actingAs($this->operator);

        $open   = $this->makeFailure('open');
        $invest = $this->makeFailure('investigating');
        $fixed  = $this->makeFailure('repaired');
        $ignore = $this->makeFailure('ignored');

        $response = $this->getJson("/api/sources/{$this->source->id}/failures");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertTrue($ids->contains($invest->id));
        $this->assertFalse($ids->contains($fixed->id));
        $this->assertFalse($ids->contains($ignore->id));
    }

    public function test_index_with_status_all_returns_all_failures(): void
    {
        $this->actingAs($this->operator);

        $this->makeFailure('open');
        $this->makeFailure('repaired');
        $this->makeFailure('ignored');

        $response = $this->getJson("/api/sources/{$this->source->id}/failures?status=all");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_with_specific_status_filters_correctly(): void
    {
        $this->actingAs($this->operator);

        $this->makeFailure('open');
        $this->makeFailure('repaired');

        $response = $this->getJson("/api/sources/{$this->source->id}/failures?status=repaired");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('repaired', $response->json('data.0.status'));
    }

    public function test_update_transitions_open_to_investigating(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('open');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'investigating']);

        $response->assertOk();
        $this->assertEquals('investigating', $response->json('data.status'));
        $failure->refresh();
        $this->assertEquals('investigating', $failure->status);
    }

    public function test_update_transitions_open_to_repaired_and_sets_resolved_at(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('open');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'repaired']);

        $response->assertOk();
        $this->assertEquals('repaired', $response->json('data.status'));
        $this->assertNotNull($response->json('data.resolved_at'));

        $failure->refresh();
        $this->assertNotNull($failure->resolved_at);
    }

    public function test_update_transitions_open_to_ignored_and_sets_resolved_at(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('open');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'ignored']);

        $response->assertOk();
        $this->assertEquals('ignored', $response->json('data.status'));
        $this->assertNotNull($response->json('data.resolved_at'));
    }

    public function test_update_rejects_transition_from_terminal_repaired_status(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('repaired');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'investigating']);

        $response->assertStatus(422);
        $failure->refresh();
        $this->assertEquals('repaired', $failure->status); // unchanged
    }

    public function test_update_rejects_transition_from_terminal_ignored_status(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('ignored');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'repaired']);

        $response->assertStatus(422);
    }

    public function test_update_rejects_invalid_status_value(): void
    {
        $this->actingAs($this->operator);

        $failure = $this->makeFailure('open');

        $response = $this->patchJson("/api/failures/{$failure->id}", ['status' => 'deleted']);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFailure(string $status): ParserFailure
    {
        return ParserFailure::create([
            'airport_source_id' => $this->source->id,
            'failure_type'      => 'soft',
            'severity'          => 'low',
            'error_code'        => 'zero_rows',
            'error_message'     => 'Test failure.',
            'status'            => $status,
            'resolved_at'       => in_array($status, ['repaired', 'ignored']) ? now() : null,
        ]);
    }
}
