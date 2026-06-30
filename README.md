# Platform Agent

The official **Sidewalk Developers Group Platform Agent** — the near-zero-code
integration package that connects a customer Laravel application to the
**Distributed Backup Orchestration Platform** (the Cloud Hub).

It is installed into a *customer's* Laravel app and ships **all** integration
logic so the customer writes near-zero code: backup creation (database + files,
split), SHA256 checksum generation, resumable archive upload, heartbeats, health
and version reporting, and agent-pull restore.

> Status: **PA1 (install + onboarding)**. The HTTP client, config, contract
> tests, the encrypted credential store, and the `install` + `diagnose` commands
> are live. `register` / `heartbeat` / `report` (PA2), `backup` (PA3) and
> `restore` (PA4) land next (see `CHANGELOG.md` and ADR-0007 §11a / BUILD_PLAN
> §11a).

## Requirements

- PHP `^8.2 | ^8.3 | ^8.4`
- Laravel `^11 | ^12` (`illuminate/*`)
- `spatie/laravel-backup ^10` (backup engine, used from PA3)

## Installation

```bash
composer require sidewalkdevelopers/platform-agent
php artisan platform-agent:install
```

`platform-agent:install` (PA1) publishes the config, performs the one-time
**enrollment → runtime token exchange**, persists the durable runtime token
**encrypted in your application's database**, wires the schedule, and runs a
connectivity/auth pre-flight.

## Configuration — the only three env vars

```env
PLATFORM_URL=                 # Cloud Hub base URL (host only; /api/v1 is appended)
PLATFORM_TOKEN=               # one-time ENROLLMENT token (ability agent:register only)
PLATFORM_APPLICATION_UUID=    # the bound Application UUID (sanity/match check)
```

**Token model (ADR-0007 Addendum D — enrollment-exchange):** `PLATFORM_TOKEN` is a
short-lived, single-use **enrollment** token an operator mints for you. On
`install` it is exchanged for a durable **runtime** token (abilities
`app:backup` + `app:heartbeat` + `app:restore`) that is stored **encrypted in
your database — never written back to `.env`**. After enrollment the
`.env` enrollment token is spent and can be discarded. The agent never sends
`application_id` in a request body — identity is the token's bound Application.

Everything else (HTTP timeouts/retries, per-kind backup cadence/retention, the
single-POST-vs-tus `threshold_bytes`) is config-driven in
`config/platform-agent.php` — nothing about endpoints, thresholds or schedules is
hardcoded.

## Commands

| Command | Purpose | Phase |
|---|---|---|
| `platform-agent:install` | Onboard (publish config, enroll, persist runtime token, wire schedule) | PA1 |
| `platform-agent:diagnose` | Print resolved config (token redacted) + connectivity/version status | PA1 |
| `platform-agent:register` | Register / re-pair (version + host/fingerprint) | PA2 |
| `platform-agent:heartbeat` | Frequent liveness ping (bytes only — Rule 1) | PA2 |
| `platform-agent:report` | Richer health/version/environment report | PA2 |
| `platform-agent:backup --kind=database\|files` | Split backup → checksum → upload | PA3 |
| `platform-agent:restore {location}` | Agent-PULL restore (verify SHA256 before restoring) | PA4 |

## Dev-environment upload limits (setup note)

Large archives upload via the **tus.io 1.0 resumable protocol** to
`POST /api/v1/agent/uploads` (small archives use single-POST
`/api/v1/agent/archives`). The Laravel-layer cap is ~10 GiB; the real ceilings are
**infrastructure**. For large uploads ensure: PHP `upload_max_filesize`,
`post_max_size`, `memory_limit`, `max_execution_time`; nginx
`client_max_body_size` and `proxy_request_buffering off` (and/or
`fastcgi_request_buffering off`) for streaming `PATCH`; php-fpm timeouts. The tus
protocol headers and `Authorization` must survive CORS/proxy middleware.

## Testing

```bash
composer install
composer test
```

The suite pins against the **frozen Hub Agent Contract** fixtures in
`tests/Fixtures/hub-contract/` (no live Hub required).

## License

MIT. See `LICENSE`.
