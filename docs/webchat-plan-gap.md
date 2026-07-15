# Webchat — plan vs development gap

Living checklist. **Plan/contracts** live in sibling `AI-ApiContracts` (webchat pack).  
**This repo** is the portable Laravel demo implementing those contracts.

| Status | Meaning |
|---|---|
| `done` | Behaves per plan/contract in this app |
| `partial` | Exists but incomplete vs plan |
| `todo` | Not implemented |
| `n/a` | Out of scope for this demo (documented) |

Update a row when you land a PR/commit. Add a short `CHANGELOG.md` entry under `[Unreleased]` / dated section for each `todo` → `done`.

---

## Product locks (shared conversation)

| Topic | Plan | Status | App notes / files |
|---|---|---|---|
| Conversation ≡ Thread + JSONL | canon | `done` | `WebchatJsonlStore` |
| Visibility **M-rw** | canon | `done` | `canAccessConversation`; `GET /conversations` |
| Lazy create (no create on open) | canon | `done` | Boot hydrate/list/empty; create on send; New clears key |
| Floating: last + New + full link | canon | `partial` | Full page New+hydrate; no dashboard float widget yet |
| Speak-floor 10m → **423** | canon | `done` | `WebchatSpeakFloor` |
| Rate limit 10/min → **429** | canon | `done` | `WebchatTurnRateLimit` |
| Stop: initiator only | canon | `done` | Interrupt 403 for non-initiator; Stop UI |
| AI title + HK 7d | canon | `todo` | Not wired |
| `RedactSecrets` | canon | `done` | `WebchatRedactSecrets` in StartTurn |
| Attribution on `user_message` | canon | `done` | Job + FE bubbles |

---

## StartTurn pipeline

| Step | Contract | Status |
|---|---|---|
| Access | `CanAccessConversation` | `done` |
| Rate limit | `AssertTurnRateLimit` | `done` |
| Speak floor | `AssertSpeakFloor` | `done` |
| Redact | `RedactSecrets` | `done` |
| Busy lock | `TryAcquireThreadLock` | `done` |
| Acquire floor | `AcquireSpeakFloor` | `done` |
| Enqueue | `ProcessChatTurn` | `done` (+ attribution, clear active_turn) |

---

## HTTP surface

| Method | App route | Status |
|---|---|---|
| GET list | `GET /aipedia/webchat/conversations` | `done` |
| POST create | `POST …/threads` | `done` |
| GET hydrate | `GET …/threads/{id}` (+ floor fields) | `done` |
| POST turn | `POST …/turns` | `done` |
| GET SSE | `GET …/events` | `done` |
| POST interrupt | `POST …/interrupt` (initiator) | `done` |

---

## Frontend

| UX | Status |
|---|---|
| Boot localStorage → GET → list → empty | `done` |
| New (lazy) | `done` |
| Send ↔ Stop | `done` |
| Floor banner | `done` |
| Handle 423 / 429 / 409 | `done` |
| Attribution | `done` |
| Mock scenario chips | `n/a` |

---

## Deferred

- Auto title job + housekeep idle > 7d  
- Dashboard floating launcher (full page covers main demo)

Last reviewed: 2026-07-15 (M-rw land).
