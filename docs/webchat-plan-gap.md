# Webchat — plan vs development gap

Living checklist. **Plan/contracts** live in sibling `AI-ApiContracts` (webchat pack).  
**This repo** is the portable Laravel demo implementing those contracts.

| Status | Meaning |
|---|---|
| `done` | Behaves per plan/contract in this app |
| `partial` | Exists but incomplete vs plan |
| `todo` | Not implemented |
| `n/a` | Out of scope for this demo (documented) |

Update a row when you land a PR/commit. Add a short `CHANGELOG.md` entry under `[Unreleased]` for each `todo` → `done`.

Contract IDs below match `*.contract` names in the contracts pack.

---

## Product locks (shared conversation)

| Topic | Plan | Status | App notes / files |
|---|---|---|---|
| Conversation ≡ Thread + JSONL | canon | `done` | `WebchatJsonlStore`, `storage/app/webchat/` |
| Visibility **M-rw** (any admin list/read/send) | canon | `todo` | Still `ownsThread` by creator; need `CanAccessConversation` + shared list |
| Lazy create (no create on open) | canon | `partial` | FE does not create on load; no hydrate/`ListConversations`/localStorage yet |
| Floating: last + New + full link | canon | `todo` | Full page only in app; mock float lives in contracts pack mockup |
| Speak-floor 10m → HTTP **423** | canon | `todo` | No `AssertSpeakFloor` / `AcquireSpeakFloor` |
| Rate limit 10 turns / admin / 60s → **429** | canon | `todo` | No `AssertTurnRateLimit` |
| Stop: initiator only | canon | `partial` | Interrupt flag + worker `interrupted` ok; no initiator gate; no Stop UI |
| AI title + HK 7d | canon | `todo` | Not wired |
| `RedactSecrets` before JSONL+LLM | canon | `todo` | — |
| Attribution on `user_message` | canon | `todo` | Prompt has display name; JSONL line does not |

---

## StartTurn pipeline (target order)

Plan: auth → rate limit → speak-floor → redact → turn lock → acquire floor → enqueue.

| Step | Contract | Status | Notes |
|---|---|---|---|
| Access | `CanAccessConversation` | `todo` | App uses `ownsThread` |
| Rate limit | `AssertTurnRateLimit` | `todo` | |
| Speak floor | `AssertSpeakFloor` | `todo` | |
| Redact | `RedactSecrets` | `todo` | research blueprint in contracts pack |
| Busy lock | `TryAcquireThreadLock` | `done` | 409 busy |
| Acquire floor | `AcquireSpeakFloor` | `todo` | |
| Enqueue | `ProcessChatTurn` | `partial` | Job ok; missing attribution + clear `active_turn_*` |

---

## HTTP surface

| Method | Plan path (admin webchat) | App route | Status |
|---|---|---|---|
| GET list | `/conversations` | — | `todo` |
| POST create | `/threads` | `POST /aipedia/webchat/threads` | `partial` (works; meta fields incomplete) |
| GET hydrate | `/threads/{id}` | `GET …/threads/{id}` | `partial` (no floor/`active_turn_*`) |
| POST turn | `/threads/{id}/turns` | `POST …/turns` | `partial` |
| GET SSE | `/threads/{id}/events` | `GET …/events` | `done` (access still owner-gated) |
| POST interrupt | `/threads/{id}/interrupt` | `POST …/interrupt` | `partial` |

---

## Frontend (production Blade/JS — not mock harness)

| UX | Plan | Status | Notes |
|---|---|---|---|
| Boot: localStorage → GET → list → empty | frontend-guide | `todo` | |
| New (lazy) | frontend-guide | `todo` | |
| Send ↔ Stop when busy | frontend-guide | `todo` | Send only today |
| Floor banner + countdown | frontend-guide | `todo` | |
| Handle 423 / 429 / 409 | frontend-guide | `todo` | |
| User bubble attribution | frontend-guide | `todo` | |
| Mock scenario chips (Seed / Sim *) | mockup only | `n/a` | Never port from contracts `mockup/` |

---

## Already solid (baseline 0.1.0)

- Queue job + JSONL append + SSE stream  
- Stub / LLM agent loop + `search_docs`  
- Thread busy lock → 409  
- Interrupt flag polled in agent loop → `turn.failed` `interrupted`  
- Create-on-first-send path in FE (lazy-ish)

---

## How to use this file

1. Pick next `todo` / `partial` row (prefer P0 access → floor → RL → redact → FE).  
2. Implement against contracts (open full `.contract` file, no line-window).  
3. Flip status here + add `CHANGELOG.md` bullet.  
4. Prefer a Feature/Unit test that locks the new behavior.

Last reviewed: 2026-07-15.
