<script>
  export let airports = [];

  function statusClass(rate) {
    if (rate === null) return 'text-gray-400';
    if (rate >= 90) return 'text-green-600';
    if (rate >= 70) return 'text-yellow-600';
    return 'text-red-600';
  }

  function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
  }
</script>

<svelte:head>
  <title>Airports — Admin</title>
</svelte:head>

<div class="max-w-7xl mx-auto px-4 py-8">
  <h1 class="text-2xl font-bold mb-6">Airports</h1>

  {#if airports.length === 0}
    <p class="text-gray-500">No airports configured.</p>
  {:else}
    <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Airport</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Source Type</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Board</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Last Success</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Last Failure</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-600">Success Rate 24h</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-600">Active Flights</th>
            <th class="px-4 py-3 text-right font-semibold text-gray-600">Last Run Rows</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Parser Ver.</th>
            <th class="px-4 py-3 text-left font-semibold text-gray-600">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
          {#each airports as airport}
            {#if airport.sources.length === 0}
              <tr>
                <td class="px-4 py-3 font-medium">
                  <span class="font-mono font-bold">{airport.iata}</span>
                  <span class="text-gray-500 ml-1">{airport.name}</span>
                </td>
                <td colspan="9" class="px-4 py-3 text-gray-400 italic">No sources</td>
              </tr>
            {:else}
              {#each airport.sources as source, i}
                <tr class="hover:bg-gray-50">
                  {#if i === 0}
                    <td class="px-4 py-3 font-medium align-top" rowspan={airport.sources.length}>
                      <a href="/admin/airports/{airport.id}" class="hover:underline">
                        <span class="font-mono font-bold">{airport.iata}</span>
                        <span class="text-gray-500 ml-1">{airport.city}</span>
                      </a>
                      <div class="text-xs text-gray-400 mt-1">{airport.active_flights_count} active</div>
                    </td>
                  {/if}
                  <td class="px-4 py-3">
                    <span class="text-xs bg-gray-100 rounded px-1.5 py-0.5">{source.source_type}</span>
                  </td>
                  <td class="px-4 py-3 capitalize">{source.board_type}</td>
                  <td class="px-4 py-3 text-gray-600 text-xs">{formatDate(source.last_success_at)}</td>
                  <td class="px-4 py-3 text-xs">
                    {#if source.last_failure_at}
                      <span class="text-red-600">{formatDate(source.last_failure_at)}</span>
                    {:else}
                      <span class="text-gray-400">—</span>
                    {/if}
                  </td>
                  <td class="px-4 py-3 text-right font-mono {statusClass(source.success_rate_24h)}">
                    {source.success_rate_24h !== null ? source.success_rate_24h + '%' : '—'}
                  </td>
                  <td class="px-4 py-3 text-right font-mono">{airport.active_flights_count}</td>
                  <td class="px-4 py-3 text-right font-mono text-gray-600">
                    {source.latest_row_count ?? '—'}
                  </td>
                  <td class="px-4 py-3 font-mono text-xs">
                    {source.parser_version !== null ? 'v' + source.parser_version : '—'}
                  </td>
                  <td class="px-4 py-3">
                    <a
                      href="/admin/airports/{airport.id}"
                      class="text-blue-600 hover:underline text-xs mr-2"
                    >Details</a>
                  </td>
                </tr>
              {/each}
            {/if}
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>
