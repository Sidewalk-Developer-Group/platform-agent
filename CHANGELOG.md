# Changelog

All notable changes to `sidewalkdevelopers/platform-agent` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the package follows [SemVer](https://semver.org). The published version IS the
`agent_version` reported on the wire (ADR-0007 §2.9).

**Minimum Hub Agent Contract targeted: v1.1.0** (the additive split-backup `kind`
discriminator — ADR-0007 Addendum F).

## [Unreleased]

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
