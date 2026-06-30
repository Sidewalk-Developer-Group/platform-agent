# Security

The Platform Agent is installed **inside a customer's Laravel application** and
holds the credential that lets that application back up to and restore from the
Cloud Hub. The threat model and the controls below are part of the PA5
Definition of Done (security review). To report a vulnerability, email
**business@sidewalkdevelopers.io** — do not open a public issue.

## Authentication — Sanctum PAT only

- The agent authenticates with **Sanctum Personal Access Tokens only**. There is
  **no FTP, no shared password, no anonymous access** anywhere in the package or
  its contract (Hub security requirement).
- Two-token model (ADR-0007 Addendum D):
  - **Enrollment token** (`PLATFORM_TOKEN`) — short-lived, single-use, ability
    `agent:register` only. Used once by `platform-agent:install` to enroll.
  - **Runtime PAT** — durable, abilities `app:backup` + `app:heartbeat` +
    `app:restore`. Obtained from the enrollment exchange and used for all
    operational calls.
- The runtime PAT is stored **encrypted at rest** (Laravel `Crypt` / the
  customer app's `APP_KEY`) in the customer database via the package migration —
  **never written back to `.env`**, never to logs, never to disk in plaintext.
- Tokens travel **only** as the `Authorization: Bearer` header — never in a URL,
  query string, or request body.

## Per-application isolation

- The agent **never sends `application_id`** in any request body. Identity is
  always the runtime PAT's bound Application (Hub-derived). A compromised or
  misconfigured agent cannot act on another customer's Application.
- The restore push channel (`platform-agent:listen`) is a **private** channel
  authorized server-side by the runtime PAT; the Hub binds the channel to the
  token's Application, so the client-supplied channel id is not trusted.

## Restore integrity (Rule 4) and non-destructiveness

- Every pulled restore archive is **SHA256-verified before it is deposited**. A
  mismatch **aborts**: the partial download is deleted and a failure (with
  reason) is reported — there is no silent corruption.
- Restore is **non-destructive**: the agent only deposits `backup.zip` + a
  `.sha256` sidecar at the target location. It **never extracts, imports, or
  executes** the archive — the operator applies it.
- The Hub **never pushes into customer infrastructure**; the agent always pulls.
  Push (`listen`) only changes *when* a pull runs, never the trust boundary.

## Observability — no secrets, no silent failures

- **No secret is ever logged.** Audited surfaces (`PlatformClient`,
  `DatabaseCredentialStore`, the restore subsystem) log only endpoint, HTTP
  status, non-secret metadata, and exception *messages* — never token values,
  ciphertext, or credentials. `BackupRunReporter` additionally **redacts**
  secret-shaped substrings (passwords, bearer tokens, DSN credentials) out of
  failure messages before they leave the host.
- **No silent failures.** Every non-2xx envelope, checksum mismatch, download
  failure, and report rejection is logged and surfaced; restore outcomes are
  reported back to the Hub as the authoritative record.
- A `426 Upgrade Required` (below the Hub `compatible_floor`) is a **hard block**:
  it is never retried and never swallowed into a generic failure.

## Transport

- All Hub calls are HTTPS. The native-stream WebSocket connector used by the
  restore-push listener verifies TLS peer + peer name (`verify_peer`,
  `verify_peer_name`, SNI) on `wss://`.
- The package adds **no WebSocket dependency** — the RFC 6455 client is built on
  native PHP streams — reducing third-party supply-chain surface in customer apps.

## Supported versions

The published version IS the `agent_version` reported on the wire. Security
fixes are released on the latest `1.x` line. Keep the agent at or above the Hub's
`recommended_min`; below `compatible_floor` the Hub hard-blocks (426) until you
upgrade.
