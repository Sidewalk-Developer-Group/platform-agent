# Platform Agent

[![CI](https://github.com/Sidewalk-Developer-Group/platform-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/Sidewalk-Developer-Group/platform-agent/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/sidewalkdevelopers/platform-agent.svg)](https://packagist.org/packages/sidewalkdevelopers/platform-agent)
[![License](https://img.shields.io/packagist/l/sidewalkdevelopers/platform-agent.svg)](LICENSE)

The official **Sidewalk Developers Group Platform Agent** — the near-zero-code
integration package that connects a customer Laravel application to the
**Distributed Backup Orchestration Platform** (the Cloud Hub).

It is installed into a *customer's* Laravel app and ships **all** integration
logic so the customer writes near-zero code: backup creation (database + files,
split), SHA256 checksum generation, resumable archive upload, heartbeats, health
and version reporting, and agent-pull restore.

> Status: **1.1.0 — full PA0–PA5 track shipped**, plus real heartbeat
> telemetry (measured storage/disk facts + computed status), live local
> retention (`platform-agent:clean`), a doctor-grade `diagnose`, install-time
> schedule auto-wiring, and memory-safe streaming uploads. Onboarding
> (`install`/`diagnose`), `register`/`heartbeat`/`report`, the
> `PlatformAgent::schedule()` wiring, split backup → checksum → resumable upload +
> run-log, agent-PULL `restore`, and the optional Reverb/Pusher restore-discovery
> push `listen` are all live. Minimum Hub Agent Contract: **v1.2.0**. See
> `CHANGELOG.md` and ADR-0007 §11a / BUILD_PLAN §11a.

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
**encrypted in your application's database**, and — since v1.1.0 — **wires the
agent schedule into `routes/console.php` for you** (interactive confirm,
default yes; idempotent — an existing registration is detected and never
duplicated). For non-interactive provisioning pass `--schedule` (wire without
asking) or `--no-schedule` (skip — a LOUD warning reminds you that **no
backups run until the schedule is wired**). Verify any install with
`php artisan platform-agent:diagnose`.

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
| `platform-agent:diagnose` | Doctor-grade pre-flight: config, connectivity, LIVE version verdict, schedule wiring + cron freshness, temp disk, spatie config (exit ≠ 0 on FAIL) | PA1 |
| `platform-agent:register` | Register / re-pair (version + host/fingerprint) | PA2 |
| `platform-agent:heartbeat` | Frequent liveness ping (bytes only — Rule 1) | PA2 |
| `platform-agent:report` | Richer health/version/environment report | PA2 |
| `platform-agent:backup --kind=database\|files` | Split backup → checksum → upload | PA3 |
| `platform-agent:clean --kind=database\|files` | Apply local retention (`retention_days`) to a kind's local archives | v1.1.0 |
| `platform-agent:restore {location}` | Agent-PULL restore: pull → verify SHA256 (Rule 4) → deposit `backup.zip` + sidecar at {location} (non-destructive; `--job=<id>` to pick among many) | PA4 |
| `platform-agent:listen {location?}` | Subscribe to Hub restore broadcasts and drain approved jobs instantly; poll fallback always on (`--once` = single poll sweep) | PA5 |

## Scheduling

Wire the agent's recurring work with a single line in `routes/console.php`:

```php
use Illuminate\Console\Scheduling\Schedule;
use SidewalkDevelopers\PlatformAgent\PlatformAgent;

PlatformAgent::schedule(app(Schedule::class));
```

It registers the heartbeat (every 5 min — Rule 2), an hourly report, both
split backups (`--kind=database` / `--kind=files`) on their configured cadences
(`config/platform-agent.php` → `backup.kinds.*.cadence`), and — since v1.1.0 —
a **daily local retention clean per kind** (see below).

### Local retention (v1.1.0)

`backup.kinds.*.retention_days` (default 30 for database, 14 for files) is
applied by a daily `platform-agent:clean --kind=…` schedule entry (at
`PLATFORM_BACKUP_CLEAN_AT`, default `03:00`; disable scheduling with
`PLATFORM_BACKUP_CLEAN_ENABLED=false`). It runs spatie `backup:clean
--disable-notifications` scoped exactly like a backup run — this kind's backup
name on the local temp disk only — so it never touches your own separate
spatie backups. Retention semantics: keep everything `retention_days` days,
then delete (the graduated spatie tiers are zeroed for the scoped run; your
global `delete_oldest_when_using_more_megabytes_than` disk cap stays in
effect). Uploaded archives are already deleted per run — this clean is the
safety net for orphans left by crashed/interrupted runs. It is **local-only**
hygiene: platform-side retention on storage nodes is governed by the Hub.

## Telemetry (real heartbeat facts — v1.1.0)

Every heartbeat and report carries **measured** telemetry (Rule 1: bytes only —
a usage percentage is never sent; the Hub derives it):

- `last_backup_at` — the latest successful backup run, recorded locally in the
  package's non-secret state table (`platform_agent_state`).
- `storage_usage_bytes` — recursive size of `telemetry.storage_paths`
  (default: your app's `storage/` directory; comma-separated
  `PLATFORM_TELEMETRY_STORAGE_PATHS` to override). The measurement is cached
  for `PLATFORM_TELEMETRY_CACHE_TTL` seconds (default 30 min) so the 5-minute
  heartbeat never repeatedly walks a large tree.
- `disk_free_bytes` / `disk_total_bytes` for the app base path (sent inside
  `metadata` until the Hub contract promotes them).
- a **computed status**: `degraded` when the most recent run of any backup
  kind failed, or when base-path free space drops below
  `PLATFORM_TELEMETRY_MIN_FREE_BYTES` (default 0 = disabled); `healthy`
  otherwise. `metadata.status_reasons` says why. An explicit
  `platform-agent:report --status=healthy|degraded|unreachable` always wins
  over the computed default (`--status=auto`).

Unmeasurable values are **omitted, never fabricated** — a missing state table
(upgrade installed but `php artisan migrate` not run yet) degrades to "unknown"
without breaking backups or heartbeats.

**Upgrading from ≤ 1.0.x:** run `php artisan migrate` once after updating — it
creates the small `platform_agent_state` table the telemetry reads.

## Doctor: `platform-agent:diagnose` (doctor-grade since v1.1.0)

Answers "did onboarding actually work?" with clear PASS / WARN / FAIL lines and
a non-zero exit on any FAIL (CI/provisioning friendly):

- resolved config (tokens redacted) + Hub connectivity;
- **live version verdict** — fires a real heartbeat and surfaces the Hub's soft
  `version_warning` (WARN) or hard 426 upgrade block (FAIL);
- **schedule wiring** — FAILS loudly when the `PlatformAgent::schedule()`
  one-liner was never added (the silent "zero backups ever run" mode);
- **scheduler freshness** — the scheduled heartbeat stamps a local marker;
  stale (> 2× the 5-minute beat) means your `schedule:run` cron is dead;
- temp-disk writability (`backup.temp_disk`) with a write/read/delete probe;
- spatie config presence + per-kind sources (empty DB/file sources WARN);
- local state table readiness (v1.1.0 migration).

## Restore-discovery push (optional, latency follow-up)

By default the agent **polls** for approved restore jobs (Rule 6). For lower
latency, `platform-agent:listen` keeps a long-lived **Reverb/Pusher** subscription
to the Hub's per-Application **private** restore channel and drains a restore the
instant the Hub broadcasts. **Polling is never removed** — `:listen` runs a poll
sweep on startup, on every idle tick, and on any disconnect, and the scheduled
`:listen --once` fallback stays wired regardless.

```env
PLATFORM_RESTORE_LOCATION=/var/backups/restore   # deposit dir (also enables the scheduled poll)
PLATFORM_RESTORE_PUSH_ENABLED=true               # opt in to the live push daemon
PLATFORM_RESTORE_PUSH_KEY=                        # Hub broadcaster (Reverb/Pusher) app key
PLATFORM_RESTORE_PUSH_HOST=                       # defaults to the Hub host
PLATFORM_RESTORE_PUSH_PORT=443
```

Run the daemon under a process supervisor (systemd / supervisor):

```bash
php artisan platform-agent:listen
```

The push only changes **when** a restore runs; the authoritative action is always
the same manifest → pull → SHA256 verify (Rule 4) → non-destructive deposit →
report path as the poll command. Channel subscription is authorized by the runtime
PAT via `POST /api/v1/agent/broadcasting/auth`; the Hub binds the channel to the
token's Application (the channel id is never trusted from the client). The
transport is dependency-free (native PHP streams, RFC 6455) — no WebSocket library
is added to your app.

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
