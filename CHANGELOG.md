# Changelog — Aipedia

All notable changes to this demo app. Format inspired by [Keep a Changelog](https://keepachangelog.com/).

**Plan ↔ code gap tracker (living):** [`docs/webchat-plan-gap.md`](docs/webchat-plan-gap.md)  
Update that matrix whenever a contract/plan item is implemented or deferred.

Contracts source of truth: sibling repo `AI-ApiContracts` (webchat pack) — do not hardcode private host CMS paths in this tree.

---

## [Unreleased]

### Added

- Gap tracker: `docs/webchat-plan-gap.md` for plan vs development status.

### Planned (from shared conversation + AI-ApiContracts)

See gap matrix — M-rw shared rooms, speak-floor 10m, turn rate limit 10/min, `RedactSecrets`, initiator-only Stop, message attribution, FE floor banner / New / hydrate.

---

## [0.1.0] — 2026-07-15

### Added

- Initial public repo ([AIPedia](https://github.com/jahrulnr/AIPedia)): Laravel 8.83 + Docker (PHP-FPM/nginx/supervisor).
- Webchat Phase 0–2 baseline: JSONL store, queue job, SSE events, stub/LLM turn, `search_docs`.
- Docs knowledge as git submodule at `web/docs/webchat` → `jahrulnr/dev-docs`.
- Hardened `.gitignore` (`.env`, debugbar, runtime JSONL, testing storage).
- Portable naming rules in `AGENTS.md` (Aipedia prefixes; no private CMS codenames in tree).

### Notes

- Baseline uses per-session ownership checks; shared M-rw from contracts pack is **not** shipped yet (track in plan-gap).
