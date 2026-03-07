# Airport Flight Board Platform — Scratchpad

## Objective
Build an Airport Flight Board Platform per the tech spec in PROMPT.md.

## Stack
- Backend: Laravel (PHP)
- DB: PostgreSQL
- Queue/Cache/Locks: Redis
- Frontend admin: Svelte + Inertia + TailwindCSS
- Scraping runtime: Node.js/Playwright worker
- Object storage: S3/MinIO

## Implementation Plan

### Phase 1 (DONE)
- [x] Project scaffold (Laravel monorepo structure)
- [x] Auth (Laravel Breeze + spatie/laravel-permission)
- [x] Roles (admin, operator, viewer) + 19 permissions
- [x] Airports CRUD (AirportController, Form Requests, API Resources)
- [x] Airport sources CRUD (AirportSourceController)
- [x] Migrations (all 10 tables migrated to postgres)

### Phase 2 (IN PROGRESS)
- Scrape job lifecycle, scheduler, queue workers, scraper runtime

### Phase 3
- Normalization pipeline, flights_current, flight_changes, search API

### Phase 4
- Admin dashboard, job logs, source health, artifact links

### Phase 5
- Anomaly detection, parser failure workflow, parser versions UI

### Phase 6
- AI repair candidate generation, replay, approve/canary/activate

## Key Design Decisions
- Domain-driven structure: app/Domain/{Airports,Scraping,Flights,Repairs}
- Canonical flight key: {airport_iata}:{board_type}:{service_date_local}:{flight_number}:{scheduled_time_rounded}
- Scraper runtime is separate Node.js service (no headless browser in PHP)
- AI repair NEVER writes directly to active production parser
- Queues: scrape-high (large airports), scrape-default, normalize, state-update, alerts, repair, maintenance
- Scraper runtime called via HTTP POST from ScrapeAirportSourceJob

## Current Status
- Phase 1 DONE: scaffold, auth, roles, CRUD, 10 migrations applied
- DB port: 5433 (system postgres on 5432); .env uses pgsql/127.0.0.1:5433/airport/secret
- Domain models: Airport, AirportSource, ParserVersion; ScrapeJob, ScrapeArtifact, FlightSnapshot; FlightCurrent, FlightChange; ParserFailure, AiRepairAttempt
- API routes: /api/airports (CRUD) + /api/airports/{airport}/sources (nested CRUD)

## Iteration Notes

### Iteration 3 (Phase 2, task 1): scrapes:schedule + ScrapeAirportSourceJob
- Implementing: `scrapes:schedule` artisan command + `ScrapeAirportSourceJob` + scheduler registration
- Strategy: command queries active sources, checks interval, creates ScrapeJob, dispatches job with Redis distributed lock
- ScrapeAirportSourceJob calls scraper runtime via HTTP POST, persists artifacts, dispatches NormalizeScrapePayloadJob
- Next: Node.js scraper runtime HTTP server, cleanup/recheck commands

### Iteration 4 (Phase 2, task 2): Node.js scraper runtime contract fix
- Fixed contract mismatch between PHP ScrapeRuntimeClient and Node.js index.js
- PHP sends: scrape_job_id, airport_iata, board_type, source_type, url, parser
- Node.js now accepts that format and maps to internal: job_id, parser_definition
- PHP expects: rows, row_count, quality_score, artifacts[] — now returned correctly
- Added qualityScorer.js: required field coverage (0.5) + null rate (0.3) + duplicate rate (0.2)
- Port changed 3000→3100 to match PHP config (scraper.runtime_url: http://localhost:3100)
- Next: scrapes:cleanup + repairs:recheck-open-failures maintenance commands
