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
    */

    'agent_version' => '0.1.0-dev',

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
            // /api/v1/agent/archives path. ~256 MiB default. Config, not hardcoded.
            'threshold_bytes' => (int) env('PLATFORM_BACKUP_TUS_THRESHOLD_BYTES', 268435456),
            'chunk_size_bytes' => (int) env('PLATFORM_BACKUP_TUS_CHUNK_BYTES', 16777216), // 16 MiB
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
        'min_hub_contract_version' => '1.1.0',
    ],

];
