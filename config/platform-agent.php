<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cloud Hub base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the Cloud Hub control plane (the host only, e.g.
    | "https://hub.sidewalkdevelopers.com"). The version-prefixed API path is
    | appended via `api_prefix` below. The agent NEVER hardcodes an unversioned
    | path (ADR-0007 §1) — the version prefix is authoritative.
    |
    */

    'url' => env('PLATFORM_URL'),

    /*
    |--------------------------------------------------------------------------
    | API version prefix
    |--------------------------------------------------------------------------
    |
    | The version-prefixed mount point for the agent surface. The Hub auto-loads
    | the AgentManagement routes under "api/v1"; this is appended to `url` to
    | form the effective base ("<url>/api/v1/"). Overridable only for forward
    | contract versions — never strip the version.
    |
    */

    'api_prefix' => env('PLATFORM_API_PREFIX', 'api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Enrollment token (PLATFORM_TOKEN)
    |--------------------------------------------------------------------------
    |
    | The ONE-TIME, short-lived ENROLLMENT token (ability `agent:register` only)
    | minted by an operator via POST /api/v1/applications/{application}/agent-tokens
    | and pasted here for the single onboarding handoff (ADR-0007 Addendum D).
    |
    | This is NOT the durable operational secret. `platform-agent:install` (PA1)
    | exchanges it via POST /api/v1/agent/register for a durable RUNTIME PAT
    | (abilities app:backup + app:heartbeat + app:restore) that is stored
    | ENCRYPTED in this application's own database (never written back to .env).
    | After enrollment this value is a spent token and may be discarded.
    |
    */

    'token' => env('PLATFORM_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Bound Application UUID (PLATFORM_APPLICATION_UUID)
    |--------------------------------------------------------------------------
    |
    | The UUID of the Application this agent is bound to, used only as a
    | sanity/match check during onboarding and diagnostics. The wire identity is
    | ALWAYS the token's bound Application — the agent NEVER sends application_id
    | in any request body (ADR-0007 §2.2 per-application isolation invariant).
    |
    */

    'application_uuid' => env('PLATFORM_APPLICATION_UUID'),

    /*
    |--------------------------------------------------------------------------
    | Agent version (reported on the wire)
    |--------------------------------------------------------------------------
    |
    | The package's own published SemVer. It IS the `agent_version` reported on
    | every register/heartbeat/report (ADR-0007 §2.9). The Hub measures it
    | against its `recommended_min` / `compatible_floor` thresholds and returns
    | the verdict (soft `version_warning` on a 2xx, or HTTP 426 hard-block). The
    | package never embeds those thresholds — it only reacts to the Hub verdict.
    |
    | This references PlatformAgent::VERSION (never a hardcoded string) so a
    | PUBLISHED copy of this file keeps tracking the installed package across
    | upgrades instead of freezing the value at publish time (v1.0.4). Do not
    | replace it with a literal.
    |
    */

    'agent_version' => \SidewalkDevelopers\PlatformAgent\PlatformAgent::VERSION,

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    |
    | Timeout, retry count and inter-retry backoff for all /api/v1/agent/* calls
    | made through PlatformClient. A 426 (upgrade-required) is NEVER retried.
    |
    */

    'http' => [
        'timeout' => (int) env('PLATFORM_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('PLATFORM_HTTP_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('PLATFORM_HTTP_RETRIES', 2),
        'retry_delay_ms' => (int) env('PLATFORM_HTTP_RETRY_DELAY_MS', 250),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential store (durable runtime PAT — encrypted at rest)
    |--------------------------------------------------------------------------
    |
    | Where the durable RUNTIME PAT lives after the enrollment -> runtime
    | exchange (ADR-0007 Addendum D). It is encrypted with the customer app's
    | APP_KEY (Laravel Crypt) and stored in the customer DB — NEVER written back
    | to `.env`. The enrollment token (PLATFORM_TOKEN) stays in config; only the
    | durable runtime token uses this store.
    |
    | `connection`  DB connection name (null = the app's default connection).
    | `table`       Table name the package migration creates.
    |
    */

    'store' => [
        'connection' => env('PLATFORM_STORE_CONNECTION'),
        'table' => env('PLATFORM_STORE_TABLE', 'platform_agent_credentials'),

        // NON-SECRET operational state (last backup-run outcome per kind, last
        // scheduled-heartbeat time). Plaintext JSON — secrets never live here.
        'state_table' => env('PLATFORM_STORE_STATE_TABLE', 'platform_agent_state'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry (real heartbeat/report facts — v1.1.0)
    |--------------------------------------------------------------------------
    |
    | The agent measures and reports REAL values on every heartbeat/report
    | (Rule 1: bytes only — a usage percentage is never sent; the Hub derives
    | it):
    |
    | `storage_paths`      Paths whose recursive size is reported as
    |                      `storage_usage_bytes`. Comma-separated in env.
    |                      Default: the application's storage directory.
    | `cache_ttl_seconds`  How long a measured storage size is cached so the
    |                      5-minute heartbeat never repeatedly walks a large
    |                      tree. 0 disables caching (measure every time).
    | `min_free_bytes`     Degrade the computed status when the app base-path
    |                      free space drops below this. 0 = disabled.
    |
    | Computed status: `degraded` when the most recent run of any backup kind
    | failed or free disk is below `min_free_bytes`; `healthy` otherwise. An
    | explicit `platform-agent:report --status=...` always wins.
    |
    */

    'telemetry' => [
        'storage_paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PLATFORM_TELEMETRY_STORAGE_PATHS', storage_path())),
        ))),
        'cache_ttl_seconds' => (int) env('PLATFORM_TELEMETRY_CACHE_TTL', 1800),
        'min_free_bytes' => (int) env('PLATFORM_TELEMETRY_MIN_FREE_BYTES', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup (PLACEHOLDER — no behavior until PA3)
    |--------------------------------------------------------------------------
    |
    | Backups are SPLIT per kind (ADR-0007 Addendum F): two separate spatie
    | commands (`backup:run --only-db` / `--only-files`) producing two separate
    | archives — database and files are NEVER combined. Each kind has its own
    | spatie backup name (disambiguation), cadence and retention.
    |
    | `temp_disk`        Local-only temp disk spatie writes the zip to. NEVER a
    |                    storage node or the Hub (ADR-0007 §7.2).
    | `name`             Base spatie backup name; per-kind names derive as
    |                    "{name}-db" and "{name}-files" (Addendum F.2).
    | `cadence`          Per-kind schedule (DB more frequent than files).
    | `retention_days`   Per-kind retention horizon (recommend more for DB).
    | `tus.threshold_bytes` Single-POST (< threshold) vs tus resumable upload
    |                    (>= threshold) selector for /agent/archives vs
    |                    /agent/uploads (ADR-0007 Addendum C.1). Not hardcoded.
    |
    */

    'backup' => [
        'temp_disk' => env('PLATFORM_BACKUP_TEMP_DISK', 'local'),
        'name' => env('PLATFORM_BACKUP_NAME', 'platform-agent'),

        'kinds' => [
            'database' => [
                'spatie_name' => env('PLATFORM_BACKUP_NAME_DB', null), // defaults to "{name}-db"
                'cadence' => env('PLATFORM_BACKUP_CADENCE_DB', '0 */6 * * *'),
                'retention_days' => (int) env('PLATFORM_BACKUP_RETENTION_DB', 30),
            ],
            'files' => [
                'spatie_name' => env('PLATFORM_BACKUP_NAME_FILES', null), // defaults to "{name}-files"
                'cadence' => env('PLATFORM_BACKUP_CADENCE_FILES', '0 2 * * *'),
                'retention_days' => (int) env('PLATFORM_BACKUP_RETENTION_FILES', 14),
            ],
        ],

        'tus' => [
            // Archives >= this size upload via the tus.io resumable protocol to
            // POST /api/v1/agent/uploads; smaller archives use the single-POST
            // /api/v1/agent/archives path. Config, not hardcoded.
            //
            // INVARIANT: this MUST equal the Hub's single-POST ceiling
            // (AgentManagement config `upload.single_post_max_bytes`, default
            // 64 MiB) so the boundary is crisp — a single-POST archive is always
            // within what the Hub's PHP limits accept, and anything larger takes
            // the chunked tus path (each PATCH chunk << the Hub's post_max_size).
            'threshold_bytes' => (int) env('PLATFORM_BACKUP_TUS_THRESHOLD_BYTES', 67108864), // 64 MiB
            'chunk_size_bytes' => (int) env('PLATFORM_BACKUP_TUS_CHUNK_BYTES', 16777216), // 16 MiB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore (agent-PULL — PA4 / ADR-0011)
    |--------------------------------------------------------------------------
    |
    | `platform-agent:restore {location}` pulls an approved restore job's archive
    | from the Hub, VERIFIES its SHA256 (Rule 4) and DEPOSITS the verified
    | `backup.zip` + a `.sha256` sidecar at the target location. It is
    | NON-DESTRUCTIVE — the operator/customer applies the deposited archive; the
    | agent never extracts or imports it (ADR-0011 §4).
    |
    | `default_location`  Where to deposit when the command is run with no
    |                     {location} argument (e.g. a scheduled poll). A
    |                     directory → the archive lands as "{dir}/{filename}"; a
    |                     full path → used verbatim. Null = the argument is
    |                     required.
    | `download_timeout`  Seconds for the (potentially large) byte pull; separate
    |                     from the short JSON `http.timeout`.
    |
    */

    'restore' => [
        'default_location' => env('PLATFORM_RESTORE_LOCATION'),
        'download_timeout' => (int) env('PLATFORM_RESTORE_DOWNLOAD_TIMEOUT', 600),

        /*
        |----------------------------------------------------------------------
        | Restore discovery push (Reverb/Pusher — latency follow-up to polling)
        |----------------------------------------------------------------------
        |
        | `platform-agent:listen` keeps a long-lived Reverb/Pusher (Pusher
        | protocol) subscription to the Hub's per-Application private channel and
        | drains approved restore jobs the instant the Hub broadcasts, instead of
        | waiting for the next poll (ADR-0007 Addendum B.5 / ADR-0011). Polling is
        | the Rule-6 fallback and is NEVER removed: `:listen` runs a poll sweep on
        | startup and again every `poll_fallback_seconds` of idleness, and the
        | scheduled `platform-agent:restore` poll stays wired regardless.
        |
        | `enabled`               Master switch. Off (default) = poll-only.
        | `key`                   The Hub's broadcaster app key (Reverb/Pusher).
        | `host`/`port`/`scheme`  WebSocket endpoint; host defaults to the Hub host.
        | `channel`               Private channel template; "{application}" is
        |                         replaced with the bound Application UUID. The Hub
        |                         authorizes by the runtime PAT's `app:restore`
        |                         ability + token-bound Application (never trusts a
        |                         client-supplied id).
        | `event`                 Broadcast event name that signals "drain now".
        | `poll_fallback_seconds` Idle interval after which a safety poll sweep
        |                         runs even without a push (Rule 6).
        | `connect_timeout`       WebSocket connect/handshake timeout (seconds).
        |
        | The authoritative restore action ALWAYS routes through the same
        | manifest → pull → SHA256 verify (Rule 4) → non-destructive deposit →
        | report path as the poll command; the push only changes WHEN it runs.
        |
        */
        'push' => [
            'enabled' => (bool) env('PLATFORM_RESTORE_PUSH_ENABLED', false),
            'key' => env('PLATFORM_RESTORE_PUSH_KEY'),
            'host' => env('PLATFORM_RESTORE_PUSH_HOST'),
            'port' => (int) env('PLATFORM_RESTORE_PUSH_PORT', 443),
            'scheme' => env('PLATFORM_RESTORE_PUSH_SCHEME', 'https'),
            'channel' => env('PLATFORM_RESTORE_PUSH_CHANNEL', 'applications.{application}'),
            'event' => env('PLATFORM_RESTORE_PUSH_EVENT', 'restore.requested'),
            'poll_fallback_seconds' => (int) env('PLATFORM_RESTORE_PUSH_POLL_SECONDS', 300),
            'connect_timeout' => (int) env('PLATFORM_RESTORE_PUSH_CONNECT_TIMEOUT', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compatibility (PLACEHOLDER — informational only)
    |--------------------------------------------------------------------------
    |
    | The authoritative version thresholds live HUB-side in config and are NEVER
    | embedded here (ADR-0007 §2.5). This block only records the minimum Hub
    | Agent Contract version this package targets, surfaced in
    | `platform-agent:diagnose` and the CHANGELOG. It drives no blocking logic.
    |
    */

    'compatibility' => [
        'min_hub_contract_version' => '1.2.0',
    ],

];
