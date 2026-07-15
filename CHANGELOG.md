# Changelog — AIPedia

All notable changes to this demo app. Format inspired by [Keep a Changelog](https://keepachangelog.com/).

**Plan ↔ code gap tracker (living):** [`docs/webchat-plan-gap.md`](docs/webchat-plan-gap.md)  
Update that matrix whenever a contract/plan item is implemented or deferred.

Contracts source of truth: sibling repo `AI-ApiContracts` (webchat pack) — do not hardcode private host CMS paths in this tree.

---

## [Unreleased]

### Added

- Repo-local `aipedia-doc-authoring` skill with bilingual document templates, metadata-first lexical retrieval SOP, natural-prose review guidance, and dependency-free Bash/PowerShell frontmatter-pair validators. Document identity comes from its path and display title from H1, without duplicate `name`/`title` metadata.
- Prefix-based LLM provider configuration via `WEBCHAT_LLM_PROVIDERS` and `WEBCHAT_LLM_{PROVIDER}_*` env keys; deprecated single-provider env keys removed.
- Per-item model metadata in JSONL/SSE events and model badges for reasoning, tool calls, and responses; host tool results identify `executor=host_tool`.
- Runtime provider routing with transient retry, circuit state, failover, and round-robin selection; provider remains pinned after a successful response within a turn.
- Token-estimated context compaction that appends a summary checkpoint without deleting the original JSONL transcript and preserves recent turns.
- Per-provider model capability/policy budgets: context window, max input tokens, max output tokens, request limits, and cache read/write usage normalization.
- Retry failed turn: `POST /aipedia/webchat/threads/{id}/retry` — same `turn_id`, SSE `turn.resumed`, keeps prior tool/reasoning history (no new user message). FE Retry on Failed (not for `interrupted`).
- AI auto-title after first completed turn (`ProcessThreadTitleJob` / stub truncate).
- Manual rename: `PATCH /aipedia/webchat/threads/{id}` (`title_source=manual` locks auto).
- Housekeep idle conversations: `webchat:housekeep-conversations` (scheduler daily 03:15, TTL 7d).
- Dashboard floating launcher: last + New + full-page link (`partials/float`).
- Webchat prompt-injection boundary: user text, session hints, documentation content, filenames, excerpts, errors, and tool payloads are untrusted data rather than instructions; docs-tool envelopes expose `meta.data_is_untrusted=true`.

### Added

- Docs search index: `WebchatDocsIndex` → `storage/.../docs_index.json`; `ReindexWebchatDocsJob` queued once from `infra/start.sh`; AI locked (HTTP 503) until ready via `webchat:reindex-docs` (no post-boot reindex/schedule). `search_docs` reads the index (`--sync` for ops/tests).

### Changed

- Added tool-argument healing for low/medium models: optional numeric and boolean parameters accept string representations at provider validation, then normalize to native types before tool execution.
- Webchat hydrate now collapses repeated failures from the same retried turn after refresh; HTTP 413 is always eligible for provider failover, and `.env.example` documents provider routing settings.
- Full-page webchat UI aligned to mockup: conversation rail, room head/rename, IBM Plex, composer/Stop, `.msg` bubbles (no mock scenario chips).
- Replaced native browser rename prompt and title tooltips with an accessible branded dialog, inline validation, focus management, custom tooltips, polished status/toast feedback, and reduced-motion support.
- Agent bubbles: sanitized GFM rendering through Marked and DOMPurify, including tables, lists, blockquotes, and fenced code; Thinking + Tool Calls disclosures (mockup-like); persist/stream `reasoning` JSONL from provider.

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
- Portable naming rules in `AGENTS.md` (AIPedia prefixes; no private CMS codenames in tree).

### Notes

- Baseline uses per-session ownership checks; shared M-rw from contracts pack is **not** shipped yet (track in plan-gap).
