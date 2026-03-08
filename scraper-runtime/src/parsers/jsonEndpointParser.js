const axios = require('axios');

/**
 * Fetches a JSON endpoint and maps fields per parser_definition.
 *
 * parser_definition options:
 *
 *   data_key        {string}   Key in response object that contains the rows array.
 *                              If omitted, response is expected to be an array directly.
 *
 *   field_map       {object}   { targetField: "dot.notation.path" }
 *                              Maps raw item fields to output row fields.
 *
 *   computed_fields {object}   { targetField: "{{path1}}{{path2}}" }
 *                              Template-based field computation. {{path}} is resolved
 *                              via dot notation on the raw item. Used for concatenation
 *                              (e.g. flight_number = "{{co.code}}{{flt}}").
 *
 *   row_filter      {object}   { rawField: expectedValue }
 *                              Client-side equality filter applied before mapping.
 *                              Rows where any filter field does not match are dropped.
 *                              Useful when the API returns mixed types (departures +
 *                              arrivals) but the source is for one board type only.
 *
 *   url_params      {object}   Query parameters merged into the URL before fetching.
 *                              Supports one placeholder: {{today}} → UTC date YYYY-MM-DD.
 *                              Useful for APIs that require a date range parameter.
 *
 *   date_filter     {object}   { field, utc_offset_hours }
 *                              Keeps only rows where the date portion of `field` (an ISO
 *                              datetime string) matches today's date in the given UTC offset.
 *                              Example: { "field": "dat", "utc_offset_hours": 3 } keeps
 *                              rows where dat's date = today in UTC+3 (Moscow time).
 *                              Applied after row_filter.
 *
 *   headers         {object}   Extra HTTP request headers.
 */
async function jsonEndpointParser({ url, parser_definition }) {
    const {
        field_map       = {},
        computed_fields = {},
        row_filter      = {},
        date_filter     = null,
        url_params      = {},
        data_key,
        headers         = {},
    } = parser_definition;

    const finalUrl = buildUrl(url, url_params);

    const response = await axios.get(finalUrl, { headers, timeout: 30000 });
    const data = response.data;

    const items = Array.isArray(data) ? data : (data[data_key] || []);

    // Client-side row filter (simple equality on raw item fields).
    const filterEntries = Object.entries(row_filter);
    let filtered = filterEntries.length > 0
        ? items.filter(item => filterEntries.every(([field, value]) => item[field] === value))
        : items;

    // Date filter: keep only rows matching today in the specified UTC offset.
    if (date_filter && date_filter.field) {
        const offsetMs = (date_filter.utc_offset_hours || 0) * 60 * 60 * 1000;
        const todayLocal = new Date(Date.now() + offsetMs).toISOString().slice(0, 10);
        filtered = filtered.filter(item => {
            const val = item[date_filter.field];
            return val && String(val).slice(0, 10) === todayLocal;
        });
    }

    const rows = filtered.map(item => {
        const row = {};

        for (const [target, source] of Object.entries(field_map)) {
            row[target] = getNestedValue(item, source);
        }

        for (const [target, template] of Object.entries(computed_fields)) {
            row[target] = template.replace(/\{\{([^}]+)\}\}/g, (_, path) => {
                const val = getNestedValue(item, path);
                return val != null ? String(val) : '';
            });
        }

        return row;
    });

    return {
        rows,
        artifact: { html: null, screenshot_path: null, har: null },
    };
}

/**
 * Merge url_params into the base URL. Supports {{today}} → UTC date YYYY-MM-DD.
 */
function buildUrl(baseUrl, params) {
    if (!params || Object.keys(params).length === 0) return baseUrl;

    const today = new Date().toISOString().split('T')[0];

    const url = new URL(baseUrl);
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.set(key, String(value).replace('{{today}}', today));
    }
    return url.toString();
}

function getNestedValue(obj, path) {
    return path.split('.').reduce((acc, key) => (acc != null ? acc[key] : null), obj);
}

module.exports = jsonEndpointParser;
