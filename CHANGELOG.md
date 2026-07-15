# Changelog — Aipedia

All notable changes to this demo app. Format inspired by [Keep a Changelog](https://keepachangelog.com/).

**Plan ↔ code gap tracker (living):** [`docs/webchat-plan-gap.md`](docs/webchat-plan-gap.md)  
Update that matrix whenever a contract/plan item is implemented or deferred.

Contracts source of truth: sibling repo `AI-ApiContracts` (webchat pack) — do not hardcode private host CMS paths in this tree.

---

## [Unreleased]

### Added

- Retry failed turn: `POST /aipedia/webchat/threads/{id}/retry` — same `turn_id`, SSE `turn.resumed`, keeps prior tool/reasoning history (no new user message). FE Retry on Failed (not for `interrupted`).
- AI auto-title after first completed turn (`ProcessThreadTitleJob` / stub truncate).
- Manual rename: `PATCH /aipedia/webchat/threads/{id}` (`title_source=manual` locks auto).
- Housekeep idle conversations: `webchat:housekeep-conversations` (scheduler daily 03:15, TTL 7d).
- Dashboard floating launcher: last + New + full-page link (`partials/float`).

### Added

- Docs search index: `WebchatDocsIndex` → `storage/.../docs_index.json`; `ReindexWebchatDocsJob` queued once from `infra/start.sh`; AI locked (HTTP 503) until ready via `webchat:reindex-docs` (no post-boot reindex/schedule). `search_docs` reads the index (`--sync` for ops/tests).

### Changed

- Full-page webchat UI aligned to mockup: conversation rail, room head/rename, IBM Plex, composer/Stop, `.msg` bubbles (no mock scenario chips).
- Agent bubbles: lightweight markdown render; Thinking + Tool Calls disclosures (mockup-like); persist/stream `reasoning` JSONL from provider.

---

## [0.2.0] — 2026-07-15

### Added

- Shared M-rw access: `CanAccessConversation`, `GET /aipedia/webchat/conversations`.
- Speak-floor (10m) → HTTP 423; rate limit 10/min → 429 + `Retry-After`.
- `RedactSecrets` before enqueue/JSONL; `user_message` attribution fields.
- Interrupt initiator-only (403 otherwise); FE Stop + floor banner + New/lazy boot.
- Gap tracker: `docs/webchat-plan-gap.md`.
- Tests: floor lock, redact, rate limit, shared list, interrupt gate.

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
