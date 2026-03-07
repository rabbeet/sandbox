# Memories

## Patterns

### mem-1772915949-cb8e
> ScrapeAirportSourceJob pattern: constructor takes scrapeJobId + airportSourceId (not model objects) for clean serialization. Marks job running first, calls ScrapeRuntimeClient via HTTP, dispatches NormalizeScrapePayloadJob on success. failed() hook catches queue-level failures.
<!-- tags: airport-platform, scraping, jobs | created: 2026-03-07 -->

## Decisions

## Fixes

## Context
