# Changelog

All notable changes to `sidewalkdevelopers/platform-agent` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the package follows [SemVer](https://semver.org). The published version IS the
`agent_version` reported on the wire (ADR-0007 §2.9).

**Minimum Hub Agent Contract targeted: v1.2.0** (the enrollment-exchange
`register` runtime-token block — ADR-0007 Addendum D; supersedes the v1.1.0
split-backup `kind` baseline — Addendum F).

## [Unreleased]

### Added

- **Local retention is live** (`backup.kinds.*.retention_days` was dead
  config). `PlatformAgent::schedule()` now wires a daily
  `platform-agent:clean --kind=database|files` entry per kind (at
  `backup.clean_at`, default `03:00`; gate with `backup.clean_enabled`,
  default true). The new command runs spatie
  `backup:clean --disable-notifications` scoped exactly like a backup run
  (per-kind name + local temp disk) with `keep_all_backups_for_days` pinned to
  the kind's `retention_days` and the graduated tiers zeroed — everything
  older than the horizon is deleted; the customer's global MB disk cap is left
  untouched. Local-only orphan hygiene; node-side retention stays Hub-governed.
  `retention_days <= 0` disables the clean for that kind.
- **Real heartbeat/report telemetry.** Heartbeat and report now send measured
  facts instead of a hardcoded `healthy` shell: `last_backup_at` (latest
  successful run, recorded in a new non-secret `platform_agent_state` table),
  `storage_usage_bytes` (recursive size of `telemetry.storage_paths`, default
  the app `storage/` dir, cached `telemetry.cache_ttl_seconds` — default 30 min),
  and `disk_free_bytes`/`disk_total_bytes` for the app base path (in `metadata`
  pending first-class Hub columns). Status is **computed**: `degraded` when the
  most recent run of any backup kind failed or free disk drops below
  `telemetry.min_free_bytes` (0 = disabled, the default); `healthy` otherwise,
  with `metadata.status_reasons` explaining why. `platform-agent:report`'s
  default `--status` is now `auto` (computed); an explicit
  `healthy|degraded|unreachable` still wins. Rule 1 holds: bytes only, never a
  percentage; unmeasurable values are omitted, never fabricated. **Upgrade
  note:** run `php artisan migrate` once to create `platform_agent_state`;
  until then telemetry degrades null-safely (nothing breaks).

## [1.0.6] - 2026-07-02

### Changed

- **Lowered the tus threshold 256 MiB → 64 MiB** (`PLATFORM_BACKUP_TUS_THRESHOLD_BYTES`).
  Archives at/above 64 MiB now upload via the resumable tus protocol (small PATCH
  chunks) instead of a single large POST; smaller archives keep the single-POST
  path. This reconciles with the Hub's single-POST ceiling
  (`agentmanagement.upload.single_post_max_bytes`, also 64 MiB) so the boundary is
  crisp and a single-POST archive is always within the Hub's PHP upload limits.
  Paired with Hub-side changes raising `upload_max_filesize`/`post_max_size` and
  making the single-POST validation cap config-driven (was a fixed value larger
  than the tus threshold).

## [1.0.5] - 2026-07-02

### Fixed

- **Successful backups were reported as failed (`platform-agent:backup`).** The
  runner captured the produced archive path from spatie's `BackupZipWasCreated` /
  `BackupWasSuccessful` events, but it also runs spatie with
  `--disable-notifications` (the agent must never fire the customer's configured
  backup mail/Slack) — and spatie dispatches BOTH events through
  `sendNotification()`, gated on that same flag. So the events never fired, the
  path was never captured, and every successful backup was reported failed. The
  runner now locates the archive by diffing the destination disk for the `.zip`
  produced during the run — independent of spatie's events (whose shape also
  differs between v9 and v10) and of the resolved backup-name directory.
- Adds direct test coverage for the concrete `SpatieBackupRunner` (previously only
  the `BackupRunner` interface was faked, so this class was untested).

## [1.0.4] - 2026-07-01

### Fixed

- **Reported `agent_version` no longer freezes in a published config.** The
  version lived as a string literal in `config/platform-agent.php`. Because
  `install` publishes that file into the customer app and `composer update`
  never overwrites a published config, upgraded agents kept reporting the
  version captured at first install (e.g. a customer on the package's v1.0.3
  code still reporting `1.0.0`). The config default now references
  `PlatformAgent::VERSION`; `vendor:publish` copies that reference verbatim, so a
  published config keeps tracking the installed package across upgrades.

### Changed

- **Single source of truth for the wire version:** new `PlatformAgent::VERSION`
  constant. The config default resolves to it, and the release CI guard now
  asserts `tag == PlatformAgent::VERSION`.
- **Existing installs:** a config published before v1.0.4 still holds a frozen
  literal — re-publish with `php artisan vendor:publish --tag=platform-agent-config --force`
  (or set `'agent_version' => \SidewalkDevelopers\PlatformAgent\PlatformAgent::VERSION`)
  once after upgrading to pick up the self-tracking default.

## [1.0.3] - 2026-07-01

### Fixed

- **`install` no longer burns the enrollment token when the DB isn't migrated.**
  `platform-agent:install` performed the enrollment→runtime exchange (which
  single-use-**consumes** the operator-minted enrollment token at the Hub)
  *before* it tried to persist the runtime token. If the
  `platform_agent_credentials` table was missing (the customer never ran
  `php artisan migrate`), the persist crashed *after* the token was already
  spent, leaving onboarding unrecoverable without a fresh token. Install now
  pre-flights storage readiness (`CredentialStore::isReady()`) BEFORE the
  exchange: if the table is missing it runs the package's **own** migration
  (targeted `--path`, never a blanket `migrate` that would touch the customer's
  unrelated pending migrations) and re-checks; if it still can't persist, it
  fails loudly with the token intact for a clean retry.

### Added

- `CredentialStore::isReady()` — whether the durable store can persist a runtime
  token (backing table exists). Implemented by `DatabaseCredentialStore` via a
  schema check that never throws into onboarding.

## [1.0.2] - 2026-07-01

### Fixed

- **Reported `agent_version` now matches the tag.** v1.0.1 shipped the PHP 8.2
  dependency fix but the published artifact (`f43ef65`) still reported
  `agent_version = 1.0.0` — the corrective bump was tagged onto a re-tag that
  Packagist rejected under stable-version immutability. v1.0.2 is a clean,
  forward-only release carrying the same spatie/laravel-backup `^9.0 || ^10.0`
  fix with `agent_version = 1.0.2`. No functional change beyond v1.0.1.

## [1.0.1] - 2026-07-01

### Fixed

- **PHP 8.2 onboarding.** The manifest declared `php: ^8.2 || ^8.3 || ^8.4` but
  pinned `spatie/laravel-backup: ^10.0`, whose v10 line requires PHP 8.3+ —
  making the package uninstallable on PHP 8.2 customers (Composer failed to
  resolve). Widened to `spatie/laravel-backup: ^9.0 || ^10.0`; Composer now
  resolves v9.3.x on PHP 8.2 + Laravel 11/12 and v10.x on PHP 8.3+. Only the
  long-standing `Spatie\Backup\Events\BackupZipWasCreated` event is used, which
  is present across v9 and v10 — no behavior change.

## [1.0.0] - 2026-07-01

First **public Packagist** release (ADR-0007 Addendum B.4). Ships the full PA0–PA5
track: HTTP client + contract pin, enrollment-exchange onboarding, register +
heartbeat/report, split backup → checksum → resumable upload + run-log, agent-PULL
restore, and the restore-discovery push listener. SemVer starts at `1.0.0`; this
version IS the `agent_version` reported on the wire (ADR-0007 §2.9).

### Added (PA5 — hardening + restore-discovery push + Packagist release)

- **`platform-agent:listen`** — long-lived **Reverb/Pusher restore-discovery push**
  (ADR-0007 Addendum B.5). Holds a subscription to the Hub's per-Application
  PRIVATE restore channel and **drains approved restore jobs the instant the Hub
  broadcasts**, instead of waiting for the next poll. Polling stays the Rule-6
  fallback and is **never removed**: a poll sweep runs on startup, on every idle
  tick (`poll_fallback_seconds`), and on any disconnect; `--once` runs a single
  poll sweep and exits (and is the scheduled fallback entry). With
  `restore.push.enabled=false` the command is poll-only.
- **Pusher-protocol stack** — `PusherRestoreSubscriber` over a `WebSocketConnector`
  seam with a dependency-free native-stream `StreamWebSocketConnector`
  (RFC 6455 via the pure, unit-tested `PusherFrame`). Channel subscription is
  authorized by `PlatformClient::broadcastingAuth()` →
  `POST /api/v1/agent/broadcasting/auth` (runtime PAT; the Hub binds the channel
  to the token's Application — the channel id is never trusted from the client).
- **`RestoreCoordinator`** — shared drain path: every downloadable restore job
  routes through the SAME `ArchiveRestorer` pull → SHA256 verify (Rule 4) →
  non-destructive deposit → report pipeline as `platform-agent:restore`, so a
  push-driven restore is byte-identical to a polled one and **no failure is
  silent**. A 426 aborts the whole sweep (upgrade-required hard block).
- **Schedule macro** now also wires the Rule-6 restore poll (`:listen --once`,
  every 5 min) whenever `restore.default_location` is set — independent of the
  push daemon.
- Hardening: security + observability review (`SECURITY.md`) — **PAT-only auth**
  (no FTP/shared/anon), **secrets never logged**, **no silent failures**.
- CI: a **tag-triggered release workflow** (`release.yml`) validates the package,
  runs the full matrix, publishes a GitHub Release and pings the Packagist
  update webhook on `v*` SemVer tags.

### Added (PA4 — restore: agent PULL → verify → deposit)

- **`platform-agent:restore {location}`** — agent-PULL restore (ADR-0011). Discovers
  an approved RestoreJob via the `GET /api/v1/agent/restore-jobs` **poll fallback**
  (Rule 6), fetches the **non-mutating** manifest, pulls `backup.zip` bytes off the
  **signed `/archive` byte-egress** (runtime PAT carried alongside the signature —
  defence in depth), **verifies the SHA256 before depositing** (Rule 4), and
  **deposits the verified `backup.zip` + a `.sha256` sidecar at {location}**. It is
  **NON-DESTRUCTIVE** — the customer applies the deposited archive; the agent never
  extracts or imports it. A **checksum mismatch aborts**: the partial download is
  deleted and a failure (with reason) is reported — no silent failure. `--job=<id>`
  selects among multiple approved jobs; a single approved job is auto-selected;
  `PLATFORM_RESTORE_LOCATION` supplies the target for argument-less (scheduled) runs.
- **`ArchiveRestorer`** — manifest → byte download → SHA256 verify → deposit, returning
  a typed **`RestoreResult`** (success / Rule-4 mismatch / failure). A 426 hard-block
  surfaces as an upgrade error (never swallowed into a reportable failure).
- **`PlatformClient`** gains `restoreJobs()`, `restoreManifest()`, `reportRestore()`
  and a memory-safe `downloadArchive()` (streams to a sink for GB-scale archives).
- The Laravel Echo / Reverb push subscriber was deferred from PA4 to the PA5
  latency follow-up — now shipped as `platform-agent:listen` (see [1.0.0] above);
  polling remains the Rule-6 fallback and is never removed.

### Added (PA2 — register + heartbeat/report + schedule macro)

- **`platform-agent:register`** — explicit re-pair over the enrollment exchange;
  rotates the runtime PAT using a fresh operator-minted enrollment token. Soft
  `version_warning` continues; HTTP 426 hard-blocks. Shares the exchange with
  `:install` via the `RunsEnrollmentExchange` concern.
- **`platform-agent:heartbeat`** — frequent (every-5-min, Rule 2) liveness ping
  to `POST /api/v1/agent/heartbeat`. **Bytes-only (Rule 1)** — the payload never
  carries a usage percentage. WARN-and-continue on soft version lag; hard-block
  on 426; fails fast if not yet enrolled.
- **`platform-agent:report`** — richer, less-frequent health/version/environment
  telemetry to `POST /api/v1/agent/report` with a `--status`
  (healthy|degraded|unreachable). Same bytes-only + version rules.
- **`EnvironmentReporter`** — single source of the reported environment facts
  (agent/php/framework version, host, stable sha256 fingerprint, OS), shared by
  register and heartbeat/report so all surfaces report identical derived values.
- **`PlatformAgent::schedule($schedule)`** — one-line schedule wiring: heartbeat
  (every 5 min), hourly report, and both split backups (`--kind=database` /
  `--kind=files`) on their configured cadences.

### Added (PA1 — install + onboarding)

- **Encrypted DB-backed `CredentialStore`** (`DatabaseCredentialStore`) — persists
  the durable runtime PAT encrypted at rest (Laravel `Crypt` / customer APP_KEY)
  in the customer DB via a published/loaded package migration
  (`platform_agent_credentials`); never written to `.env`. Replaces the PA0
  config stub; the frozen `CredentialStore` interface is unchanged.
- **`platform-agent:install`** — publishes config, validates the 3 env vars,
  runs the enrollment → runtime PAT exchange (`POST /api/v1/agent/register`),
  persists the runtime PAT encrypted, prints a schedule-wiring hint, and fails
  loudly on missing-env / auth (401) / connectivity / 426 upgrade-required.
  Re-install rotates the runtime token (requires a fresh enrollment token).
- **`platform-agent:diagnose`** — prints resolved config with tokens redacted,
  runtime-token presence + non-secret meta, and a Hub connectivity probe.
- Pinned Hub contract bumped to **v1.2.0** (`register` now returns
  `data.runtime_token` and single-use-consumes the enrollment token).

### Added (PA0 — repo + skeleton + contract pin)

- Package skeleton: `composer.json` (PSR-4 `SidewalkDevelopers\PlatformAgent\`,
  Laravel auto-discovery), `PlatformAgentServiceProvider`, env-driven
  `config/platform-agent.php`.
- `PlatformClient` — thin typed HTTP client over `/api/v1/agent/*`: bearer auth
  (runtime PAT, enrollment-token fallback for register), configurable
  timeout/retries, canonical `ApiResponse` envelope parsing into `AgentResponse`,
  soft `version_warning` (log + continue), and `AgentUpgradeRequiredException`
  on HTTP 426.
- `CredentialStore` interface + `ConfigCredentialStore` stub (PA0 seam; the
  encrypted-DB store and enrollment→runtime exchange land at PA1).
- Command surface stubs: `platform-agent:install`, `:diagnose`, `:register`,
  `:heartbeat`, `:report`, `:backup --kind=`, `:restore` (real impls PA1–PA4).
- Pinned Hub Agent Contract fixtures + Pest suite (envelope parsing, version
  warning, 426 handling) + GitHub Actions CI on PHP 8.2/8.3/8.4.

[Unreleased]: https://github.com/Sidewalk-Developer-Group/platform-agent/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Sidewalk-Developer-Group/platform-agent/releases/tag/v1.0.0
