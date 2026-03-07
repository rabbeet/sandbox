const axios = require('axios');

/**
 * Fetches a JSON endpoint and maps fields per parser_definition.field_map.
 */
async function jsonEndpointParser({ url, parser_definition }) {
    const { field_map = {}, headers = {} } = parser_definition;

    const response = await axios.get(url, { headers, timeout: 30000 });
    const data = response.data;

    // Expect data to be an array or wrapped in a key
    const items = Array.isArray(data) ? data : (data[parser_definition.data_key] || []);

    const rows = items.map(item => {
        const row = {};
        for (const [target, source] of Object.entries(field_map)) {
            row[target] = getNestedValue(item, source);
        }
        return row;
    });

    return {
        rows,
        artifact: { html: null, screenshot_path: null, har: null },
    };
}

function getNestedValue(obj, path) {
    return path.split('.').reduce((acc, key) => (acc != null ? acc[key] : null), obj);
}

module.exports = jsonEndpointParser;
