<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Repairs\Models\ParserFailure;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ParserFailureController extends Controller
{
    /**
     * GET /api/sources/{source}/failures
     *
     * List parser failures for the source. Defaults to open/investigating failures
     * only; pass ?status=all to include resolved and ignored.
     *
     * Query parameters:
     *   status  — 'open' | 'investigating' | 'repaired' | 'ignored' | 'all'
     *             Default: returns open + investigating failures only.
     *   limit   — integer, 1–100, default 25
     */
    public function index(Request $request, AirportSource $source): JsonResponse
    {
        $this->authorize('failures.view');

        $statusFilter = $request->input('status', 'active');
        $limit        = min((int) $request->integer('limit', 25), 100);

        $query = ParserFailure::where('airport_source_id', $source->id)
            ->orderByDesc('created_at');

        if ($statusFilter === 'all') {
            // no filter
        } elseif (in_array($statusFilter, ['open', 'investigating', 'repaired', 'ignored'], true)) {
            $query->where('status', $statusFilter);
        } else {
            // default: active = open + investigating
            $query->whereIn('status', ['open', 'investigating']);
        }

        $failures = $query->limit($limit)->get()->map(fn ($f) => $this->formatFailure($f));

        return response()->json(['data' => $failures]);
    }

    /**
     * PATCH /api/failures/{failure}
     *
     * Update the status of a parser failure. Valid transitions:
     *
     *   open          → investigating, repaired, ignored
     *   investigating → repaired, ignored
     *   repaired      → (terminal — no transitions allowed)
     *   ignored       → (terminal — no transitions allowed)
     *
     * OPERATIONAL NOTE
     * ----------------
     * Status transitions are enforced here to prevent accidental state corruption.
     * 'repaired' and 'ignored' are terminal: once a failure is resolved, reopening
     * it via this endpoint is not supported. If a parser regresses after a repair,
     * a new ParserFailure row will be opened by the next scrape cycle.
     *
     * Setting status to 'repaired' also sets resolved_at to now() if not already set.
     * Setting status to 'ignored' also sets resolved_at to now() if not already set.
     */
    public function update(Request $request, ParserFailure $failure): JsonResponse
    {
        $this->authorize('failures.repair');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['investigating', 'repaired', 'ignored'])],
        ]);

        $currentStatus = $failure->status;
        $newStatus     = $validated['status'];

        // Enforce terminal states
        if (in_array($currentStatus, ['repaired', 'ignored'], true)) {
            return response()->json([
                'message' => "Failure is already '{$currentStatus}' (terminal). No further transitions allowed.",
            ], 422);
        }

        // Enforce valid transitions from 'open'
        if ($currentStatus === 'open' && $newStatus === 'repaired') {
            // Allow direct open → repaired (e.g. operator self-fixes and closes immediately)
        }

        $updates = ['status' => $newStatus];

        if (in_array($newStatus, ['repaired', 'ignored'], true) && ! $failure->resolved_at) {
            $updates['resolved_at'] = now();
        }

        $failure->update($updates);

        Log::info('ParserFailureController: status updated', [
            'parser_failure_id' => $failure->id,
            'from'              => $currentStatus,
            'to'                => $newStatus,
            'operator_id'       => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->formatFailure($failure->fresh())]);
    }

    private function formatFailure(ParserFailure $f): array
    {
        return [
            'id'               => $f->id,
            'airport_source_id'=> $f->airport_source_id,
            'scrape_job_id'    => $f->scrape_job_id,
            'failure_type'     => $f->failure_type,
            'severity'         => $f->severity,
            'error_code'       => $f->error_code,
            'error_message'    => $f->error_message,
            'failure_details'  => $f->failure_details,
            'status'           => $f->status,
            'resolved_at'      => $f->resolved_at,
            'created_at'       => $f->created_at,
        ];
    }
}
