# Pinned Hub Agent-Surface Contract Fixtures

These JSON fixtures are **copied verbatim** from the Hub repository's canonical
source of truth:

```
application/docs/api/AgentManagement/fixtures/
```

They are the **frozen Hub Agent Contract** request/response shapes for the
`/api/v1/agent/*` surface (plus the operator `agent-tokens` mint). The package
pins its contract tests against `fixture.body` so it can validate the wire
contract **without a live Hub** (ADR-0007 §2.8). They are vendored here so the
package CI is fully self-contained.

## Contract version

**Hub Agent Contract v1.2.0.** History:

- **v1.1.0** — `archives` + `backup-runs` carry the additive split-backup
  discriminator `kind ∈ {database, files}` (ADR-0007 Addendum F).
- **v1.2.0** — the enrollment-exchange ships (ADR-0007 Addendum D): `register`
  returns a `data.runtime_token` block (durable runtime PAT, abilities
  `app:backup`/`app:heartbeat`/`app:restore`) and single-use-consumes the
  enrollment token; `agent-tokens` mints an enrollment-only token + `expires_at`.
  See `register.success.with-runtime-token.planned.json` and
  `agent-tokens.success.json` (both now `shipped`, `_contract` headers `1.2.0`).

## Status legend (from the source README)

- **shipped** — matches the live R4 Hub code today; safe to pin now.
- **planned** — the binding contract for an unbuilt endpoint/shape (R4a / R4b /
  the Addendum-D R4-surface adjustment). Pinned for forward readiness; do not
  assume the live Hub returns it until the corresponding Hub item closes.

## Updating

A change to any shipped shape is a **contract change**: it requires a new ADR, a
bumped contract version on the Hub side, a refreshed copy here, and a package
SemVer bump (ADR-0007 §2.9). Re-copy from the Hub source above — never hand-edit
a fixture body.

## Envelope

Every fixture is `{ "_contract": {…metadata…}, "body": {…the exact wire body…} }`.
Tests pin against `fixture.body`. All bodies use the canonical `ApiResponse`
envelope `{ success, message, data, errors, meta }`.
