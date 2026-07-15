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
| Floating: last + New + full link | canon | `done` | Dashboard `#wmFloat` + full page; no mini-list |
| Speak-floor 10m → **423** | canon | `done` | `WebchatSpeakFloor` |
| Rate limit 10/min → **429** | canon | `done` | `WebchatTurnRateLimit` |
| Stop: initiator only | canon | `done` | Interrupt 403 for non-initiator; Stop UI |
| AI title + HK 7d | canon | `done` | `WebchatTitleService` + `webchat:housekeep-conversations` |
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
| POST retry | `POST …/retry` (`RetryTurn` / `ResumeChatTurn`) | `done` | same `turn_id`; no new `user_message`; keeps tool history |
| GET SSE | `GET …/events` (+ `turn.resumed`) | `done` |
| POST interrupt | `POST …/interrupt` (initiator) | `done` |
| PATCH rename | `PATCH …/threads/{id}` | `done` |

---

## Frontend

| UX | Status |
|---|---|
| Boot localStorage → GET → list → empty | `done` |
| New (lazy) | `done` |
| Send ↔ Stop | `done` |
| Floor banner | `done` |
| Handle 423 / 429 / 409 | `done` |
| Retry on non-interrupted `turn.failed` | `done` | FE button → resume mid-tool; not restart chat |
| Attribution | `done` |
| Dashboard float | `done` |
| Full-page shell (rail + room head + composer) | `done` | matches mockup minus scenario chips |
| Mock scenario chips | `n/a` |

---

## Cron / jobs

| Job | Status |
|---|---|
| `ScheduleThreadTitleIfNeeded` after `turn.completed` | `done` |
| `ProcessThreadTitleJob` (stub/LLM) | `done` |
| `webchat:housekeep-conversations` daily 03:15 | `done` |
| Docs reindex queue once on Docker boot | `done` | `webchat:reindex-docs` in `infra/start.sh`; no watch/schedule |

## Deferred

| Topic | Status | Notes |
|---|---|---|
| Prefix-based LLM provider config | `done` | `WEBCHAT_LLM_PROVIDERS` + `WEBCHAT_LLM_{ID}_*`; deprecated single-provider keys removed |
| Per-item model metadata | `done` | Reasoning, planner/tool calls, responses, and completion events carry provider/model/api/role; host tool results carry executor metadata |
| LLM failover / round-robin execution | `done` | Runtime router retries transient errors, records circuit health, rotates round-robin, and pins the successful provider per turn |
| Context compaction | `done` | Token-estimated checkpoint summary preserves original JSONL and retains recent turns in the LLM prompt |
| Model context/output budgets | `done` | Provider-model metadata bounds input/output requests and compaction uses the lowest enabled-provider input budget |

Last reviewed: 2026-07-15 (RetryTurn / ResumeChatTurn).
