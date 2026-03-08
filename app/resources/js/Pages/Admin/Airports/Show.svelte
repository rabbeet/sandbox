<script>
  import { router, usePage } from '@inertiajs/svelte';

  export let airport = {};
  export let sources = [];
  export let recent_jobs = [];
  export let active_flights = [];
  export let recent_changes = [];
  export let artifacts = [];
  export let failures = [];

  const page = usePage();

  $: flash = $page.props.flash ?? {};

  function fmt(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
  }

  function fmtDate(d) {
    if (!d) return '—';
    return d;
  }

  function statusColor(status) {
    if (status === 'success') return 'text-green-600';
    if (status === 'failed' || status === 'timeout') return 'text-red-600';
    if (status === 'running') return 'text-blue-600';
    return 'text-gray-500';
  }

  function severityColor(severity) {
    if (severity === 'critical') return 'bg-red-100 text-red-700';
    if (severity === 'high') return 'bg-orange-100 text-orange-700';
    if (severity === 'medium') return 'bg-yellow-100 text-yellow-700';
    return 'bg-gray-100 text-gray-600';
  }

  function delayColor(mins) {
    if (!mins) return 'text-gray-500';
    if (mins > 60) return 'text-red-600 font-semibold';
    if (mins > 15) return 'text-yellow-600';
    return 'text-gray-600';
  }

  function triggerScrape(sourceId) {
    router.post(`/admin/sources/${sourceId}/scrape`, {}, { preserveScroll: true });
  }

  function activateParser(sourceId, pvId) {
    router.post(`/admin/sources/${sourceId}/parser-versions/${pvId}/activate`, {}, { preserveScroll: true });
  }

  function updateFailure(failureId, status) {
    router.patch(`/admin/failures/${failureId}`, { status }, { preserveScroll: true });
  }
</script>

<svelte:head>
  <title>{airport.iata} — {airport.name} — Admin</title>
</svelte:head>

<div class="max-w-7xl mx-auto px-4 py-8 space-y-10">

  <!-- Header -->
  <div class="flex items-center gap-4">
    <a href="/admin/airports" class="text-blue-600 hover:underline text-sm">&larr; Airports</a>
    <div>
      <h1 class="text-2xl font-bold">
        <span class="font-mono">{airport.iata}</span>
        {#if airport.icao}<span class="text-gray-400 font-mono text-lg ml-2">{airport.icao}</span>{/if}
        — {airport.name}
      </h1>
      <p class="text-gray-500 text-sm mt-1">{airport.city}, {airport.country} · {airport.timezone}</p>
    </div>
    <span class="ml-auto text-xs px-2 py-1 rounded-full {airport.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}">
      {airport.is_active ? 'Active' : 'Inactive'}
    </span>
  </div>

  <!-- Flash messages -->
  {#if flash.success}
    <div class="rounded-lg px-4 py-3 bg-green-50 border border-green-200 text-green-800 text-sm">
      {flash.success}
    </div>
  {/if}
  {#if flash.error}
    <div class="rounded-lg px-4 py-3 bg-red-50 border border-red-200 text-red-800 text-sm">
      {flash.error}
    </div>
  {/if}

  <!-- Source Configuration -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Source Configuration</h2>
    {#if sources.length === 0}
      <p class="text-gray-400 italic">No sources configured.</p>
    {:else}
      <div class="space-y-4">
        {#each sources as src}
          <div class="border border-gray-200 rounded-lg p-4 bg-white shadow-sm">
            <div class="flex items-center gap-3 mb-3">
              <span class="font-mono text-sm font-bold capitalize">{src.board_type}</span>
              <span class="text-xs bg-gray-100 rounded px-2 py-0.5">{src.source_type}</span>
              <span class="text-xs px-2 py-0.5 rounded-full {src.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}">{src.is_active ? 'Active' : 'Inactive'}</span>
              <span class="text-xs text-gray-400">every {src.scrape_interval_minutes}m</span>
              <button
                class="ml-auto text-xs px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50"
                on:click={() => triggerScrape(src.id)}
              >
                Trigger Scrape
              </button>
            </div>
            <p class="text-xs text-gray-500 font-mono break-all mb-3">{src.url}</p>

            <!-- Parser versions -->
            {#if src.parser_versions.length > 0}
              <div>
                <p class="text-xs text-gray-500 mb-1">Parser versions (active: {src.active_parser_version ?? '—'})</p>
                <div class="flex flex-wrap gap-2">
                  {#each src.parser_versions as pv}
                    <div class="flex items-center gap-1">
                      <span class="text-xs px-2 py-0.5 rounded border {pv.is_active ? 'border-green-400 bg-green-50 text-green-700 font-semibold' : 'border-gray-200 text-gray-500'}">
                        v{pv.version}
                        {#if pv.is_active} ✓{/if}
                      </span>
                      {#if !pv.is_active}
                        <button
                          class="text-xs px-2 py-0.5 rounded border border-blue-300 text-blue-600 hover:bg-blue-50"
                          on:click={() => activateParser(src.id, pv.id)}
                        >
                          Activate
                        </button>
                      {/if}
                    </div>
                  {/each}
                </div>
              </div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  </section>

  <!-- Recent Scrape Jobs -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Recent Jobs</h2>
    {#if recent_jobs.length === 0}
      <p class="text-gray-400 italic">No jobs yet.</p>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Board</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Status</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Started</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Duration</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Rows</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Quality</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Error</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            {#each recent_jobs as job}
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 capitalize">{job.board_type ?? '—'}</td>
                <td class="px-4 py-2 font-mono font-semibold {statusColor(job.status)}">{job.status}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(job.started_at)}</td>
                <td class="px-4 py-2 text-right font-mono text-xs">{job.duration_ms ? job.duration_ms + 'ms' : '—'}</td>
                <td class="px-4 py-2 text-right font-mono">{job.row_count ?? '—'}</td>
                <td class="px-4 py-2 text-right font-mono">{job.quality_score !== null ? (job.quality_score * 100).toFixed(0) + '%' : '—'}</td>
                <td class="px-4 py-2 text-xs text-red-600">{job.error_code ?? ''}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

  <!-- Current Active Flights -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Active Flights <span class="text-gray-400 font-normal text-base">({active_flights.length})</span></h2>
    {#if active_flights.length === 0}
      <p class="text-gray-400 italic">No active flights.</p>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Flight</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Board</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Route</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Scheduled</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Estimated</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Gate</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Status</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Delay</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            {#each active_flights as f}
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-mono font-semibold">{f.flight_number}</td>
                <td class="px-4 py-2 capitalize text-xs">{f.board_type}</td>
                <td class="px-4 py-2 font-mono text-xs">{f.origin_iata ?? '—'} → {f.destination_iata ?? '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(f.scheduled_departure_at_utc)}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(f.estimated_departure_at_utc)}</td>
                <td class="px-4 py-2 font-mono text-xs">{f.departure_gate ?? f.arrival_gate ?? '—'}</td>
                <td class="px-4 py-2 text-xs">{f.status_normalized ?? '—'}</td>
                <td class="px-4 py-2 text-right font-mono {delayColor(f.delay_minutes)}">{f.delay_minutes !== null ? '+' + f.delay_minutes + 'm' : '—'}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

  <!-- Recent Flight Changes -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Recent Flight Changes</h2>
    {#if recent_changes.length === 0}
      <p class="text-gray-400 italic">No changes recorded.</p>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Flight</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Field</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Old</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">New</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Changed At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            {#each recent_changes as c}
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 font-mono font-semibold">{c.flight_number ?? '—'}</td>
                <td class="px-4 py-2 font-mono text-xs text-gray-600">{c.field_name}</td>
                <td class="px-4 py-2 text-xs text-red-600">{c.old_value ?? '—'}</td>
                <td class="px-4 py-2 text-xs text-green-600">{c.new_value ?? '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(c.changed_at)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

  <!-- Latest Artifacts -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Latest Artifacts</h2>
    {#if artifacts.length === 0}
      <p class="text-gray-400 italic">No artifacts stored.</p>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Type</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Path</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Size</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Expires</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Created</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            {#each artifacts as a}
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-xs font-mono">{a.artifact_type}</td>
                <td class="px-4 py-2 text-xs text-gray-500 font-mono break-all max-w-xs truncate">{a.storage_path}</td>
                <td class="px-4 py-2 text-right font-mono text-xs">{a.size_bytes ? (a.size_bytes / 1024).toFixed(1) + ' KB' : '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-400">{fmtDate(a.expires_at?.split('T')[0])}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(a.created_at)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

  <!-- Failures -->
  <section>
    <h2 class="text-lg font-semibold mb-3">Failures</h2>
    {#if failures.length === 0}
      <p class="text-gray-400 italic">No failures recorded.</p>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Type</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Severity</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Error</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Status</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Resolved</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Created</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 bg-white">
            {#each failures as f}
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-xs font-mono">{f.failure_type ?? '—'}</td>
                <td class="px-4 py-2">
                  <span class="text-xs px-2 py-0.5 rounded {severityColor(f.severity)}">{f.severity ?? '—'}</span>
                </td>
                <td class="px-4 py-2 text-xs text-red-600 max-w-xs truncate">{f.error_code ? f.error_code + ': ' : ''}{f.error_message ?? '—'}</td>
                <td class="px-4 py-2 text-xs font-mono">{f.status ?? '—'}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(f.resolved_at)}</td>
                <td class="px-4 py-2 text-xs text-gray-500">{fmt(f.created_at)}</td>
                <td class="px-4 py-2">
                  {#if !['repaired', 'ignored'].includes(f.status)}
                    <div class="flex gap-1">
                      {#if f.status === 'open'}
                        <button
                          class="text-xs px-2 py-0.5 rounded border border-yellow-400 text-yellow-700 hover:bg-yellow-50"
                          on:click={() => updateFailure(f.id, 'investigating')}
                        >Investigating</button>
                      {/if}
                      <button
                        class="text-xs px-2 py-0.5 rounded border border-green-400 text-green-700 hover:bg-green-50"
                        on:click={() => updateFailure(f.id, 'repaired')}
                      >Repaired</button>
                      <button
                        class="text-xs px-2 py-0.5 rounded border border-gray-300 text-gray-500 hover:bg-gray-50"
                        on:click={() => updateFailure(f.id, 'ignored')}
                      >Ignore</button>
                    </div>
                  {:else}
                    <span class="text-xs text-gray-400 italic">terminal</span>
                  {/if}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

</div>
