# Changelog

All notable changes to `sidewalkdevelopers/platform-agent` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the package follows [SemVer](https://semver.org). The published version IS the
`agent_version` reported on the wire (ADR-0007 §2.9).

**Minimum Hub Agent Contract targeted: v1.2.0** (the enrollment-exchange
`register` runtime-token block — ADR-0007 Addendum D; supersedes the v1.1.0
split-backup `kind` baseline — Addendum F).

## [Unreleased]

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
- The **Laravel Echo push subscriber is intentionally DEFERRED** to a latency
  follow-up — polling is the Rule-6 fallback and is never removed.

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

[Unreleased]: https://github.com/sidewalkdevelopers/platform-agent
