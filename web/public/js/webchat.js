(function () {
    'use strict';

    const messagesEl = document.getElementById('chatMessages');
    const inputEl = document.getElementById('chatInput');
    const sendBtn = document.getElementById('chatSend');
    const stopBtn = document.getElementById('chatStop');
    const statusEl = document.getElementById('chatStatus');
    const floorEl = document.getElementById('chatFloor');
    const indexBannerEl = document.getElementById('chatIndexBanner');
    const newBtn = document.getElementById('btnNewChat') || document.getElementById('chatNew');
    const toastEl = document.getElementById('chatToast');
    const listEl = document.getElementById('conversationList');
    const roomTitleEl = document.getElementById('roomTitle');
    const roomMetaEl = document.getElementById('roomMeta');
    const renameBtn = document.getElementById('btnRename');

    if (!messagesEl || !inputEl || !sendBtn || !statusEl) {
        return;
    }

    const adminUserId = Number(window.webchatAdminUserId || 1);
    const adminDisplayName = String(window.webchatAdminDisplayName || 'Admin User');
    const storageKey = 'webchat.thread_id.' + adminUserId;
    const hasRail = !!listEl;

    let threadId = null;
    let turnId = null;
    let es = null;
    let busy = false;
    let isInitiator = false;
    let floorRemainingSec = 0;
    let floorHolderId = null;
    let floorTimer = null;
    let docsIndexUsable = true;
    let docsIndexPoll = null;
    let conversations = [];
    let pendingUserText = null;
    let pendingOptimisticEl = null;
    let seenItemIds = {};
    /** @type {Record<string, {art: HTMLElement, thinkingSteps: string[], tools: Array<{call:string,result:string,ok:boolean}>, text: string}>} */
    let turnUi = {};

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function setStatus(text) {
        statusEl.textContent = text;
    }

    function showToast(text) {
        if (!toastEl) {
            setStatus(text);
            return;
        }
        toastEl.textContent = text;
        toastEl.hidden = false;
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { toastEl.hidden = true; }, 2800);
    }

    function updateComposer() {
        const floorBlocked = floorRemainingSec > 0 && floorHolderId !== adminUserId;
        const indexBlocked = !docsIndexUsable;
        sendBtn.disabled = busy || floorBlocked || indexBlocked;
        inputEl.disabled = busy || floorBlocked || indexBlocked;
        if (stopBtn) {
            stopBtn.hidden = !busy;
            stopBtn.disabled = !(busy && isInitiator);
        }
        sendBtn.hidden = !!busy;
    }

    function applyDocsIndexGate(gate) {
        if (!gate || typeof gate !== 'object') return;
        docsIndexUsable = !!gate.usable;
        if (indexBannerEl) {
            if (docsIndexUsable) {
                indexBannerEl.hidden = true;
                indexBannerEl.textContent = '';
            } else {
                indexBannerEl.hidden = false;
                indexBannerEl.innerHTML =
                    '<i class="bi bi-hourglass-split" aria-hidden="true"></i> ' +
                    escapeHtml(gate.message || 'Docs index belum siap. AI terkunci.');
            }
        }
        if (!docsIndexUsable) {
            setStatus(gate.status === 'building' ? 'Indexing docs…' : 'AI locked · docs index');
            startDocsIndexPoll();
        } else if (docsIndexPoll) {
            clearInterval(docsIndexPoll);
            docsIndexPoll = null;
        }
        updateComposer();
    }

    function startDocsIndexPoll() {
        if (docsIndexPoll) return;
        docsIndexPoll = setInterval(async function () {
            try {
                const list = await listConversations();
                if (list && list.docs_index) {
                    applyDocsIndexGate(list.docs_index);
                    if (docsIndexUsable) {
                        setStatus('Ready');
                        showToast('Docs index siap · AI aktif');
                    }
                }
            } catch (e) {
                // keep polling
            }
        }, 2000);
    }

    function refreshFloorBanner() {
        if (!floorEl) return;
        if (floorRemainingSec <= 0 || !floorHolderId || floorHolderId === adminUserId) {
            floorEl.hidden = true;
            floorEl.textContent = '';
            return;
        }
        const m = Math.floor(floorRemainingSec / 60);
        const s = floorRemainingSec % 60;
        floorEl.hidden = false;
        floorEl.innerHTML =
            '<i class="bi bi-mic-mute-fill" aria-hidden="true"></i> ' +
            '<strong>Admin #' + floorHolderId + '</strong> menahan floor · sisa ' +
            m + 'm ' + String(s).padStart(2, '0') + 's';
    }

    function startFloorTicker() {
        if (floorTimer) clearInterval(floorTimer);
        floorTimer = setInterval(function () {
            if (floorRemainingSec > 0 && floorHolderId !== adminUserId) {
                floorRemainingSec -= 1;
                if (floorRemainingSec <= 0) {
                    floorRemainingSec = 0;
                    floorHolderId = null;
                }
                refreshFloorBanner();
                updateComposer();
            }
        }, 1000);
    }

    function applyFloorFromPayload(data) {
        if (!data) return;
        if (data.floor_holder_admin_id != null) {
            floorHolderId = Number(data.floor_holder_admin_id);
        }
        if (typeof data.floor_remaining_sec === 'number') {
            floorRemainingSec = data.floor_remaining_sec;
        }
        refreshFloorBanner();
        updateComposer();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    /** Safe subset markdown → HTML (escape first). */
    function formatMarkdown(text) {
        let s = escapeHtml(text == null ? '' : String(text));
        const blocks = [];
        s = s.replace(/```([\s\S]*?)```/g, function (_m, code) {
            const i = blocks.length;
            blocks.push('<pre class="msg__pre"><code>' + code.replace(/^\n/, '') + '</code></pre>');
            return '\u0000B' + i + '\u0000';
        });
        s = s.replace(/`([^`\n]+)`/g, '<code class="msg__code">$1</code>');
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
        s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        s = s.replace(/\n/g, '<br>');
        s = s.replace(/\u0000B(\d+)\u0000/g, function (_m, i) {
            return blocks[Number(i)] || '';
        });
        return s;
    }

    function summarizeToolResult(envelope) {
        if (!envelope) return '—';
        if (!envelope.ok) {
            return (envelope.error && envelope.error.message) ? String(envelope.error.message) : 'fail';
        }
        const data = envelope.data || {};
        if (Array.isArray(data.chunks)) {
            const n = data.chunks.length;
            const trunc = envelope.meta && envelope.meta.truncated ? ' · truncated' : '';
            return n + ' hit' + (n === 1 ? '' : 's') + trunc;
        }
        if (typeof data.count === 'number') return data.count + ' hits';
        return 'ok';
    }

    function formatToolCall(name, args) {
        const a = args && typeof args === 'object' ? args : {};
        if (a.query != null && String(a.query) !== '') {
            return String(name || 'tool') + '(' + JSON.stringify(String(a.query)) + ')';
        }
        return String(name || 'tool') + '()';
    }

    function disclosureHtml(kind, block) {
        if (!block) return '';
        const isThink = kind === 'think';
        const steps = block.steps || [];
        const items = block.items || [];
        if (isThink && !steps.length) return '';
        if (!isThink && !items.length) return '';
        const open = !!block.open;
        const title = isThink ? 'Thinking' : 'Tool Calls';
        const summary = block.summary || (isThink
            ? (steps.length + ' step' + (steps.length === 1 ? '' : 's'))
            : (items.length + ' tool call' + (items.length === 1 ? '' : 's')));
        const cls = 'disclosure disclosure--' + (isThink ? 'think' : 'tools') + (open ? ' is-open' : '');
        let body = '';
        if (isThink) {
            body = '<ul class="think-list">' + steps.map(function (s) {
                return '<li>' + escapeHtml(s) + '</li>';
            }).join('') + '</ul>';
        } else {
            body = '<div class="tool-rows">' + items.map(function (row) {
                return (
                    '<div class="tool-row">' +
                    '<div class="tool-row__call">' + escapeHtml(row.call) + '</div>' +
                    '<div class="tool-row__result' + (row.ok === false ? ' is-fail' : '') + '">' +
                    escapeHtml(row.result) + '</div></div>'
                );
            }).join('') + '</div>';
        }
        return (
            '<div class="' + cls + '" data-kind="' + kind + '">' +
            '<button type="button" class="disclosure__toggle" aria-expanded="' + (open ? 'true' : 'false') + '">' +
            '<span class="disclosure__chevron" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>' +
            '<span class="disclosure__title">' + title + '</span>' +
            '<span class="disclosure__summary">' + escapeHtml(summary) + '</span></button>' +
            '<div class="disclosure__body">' + body + '</div></div>'
        );
    }

    function clearMessages(welcome) {
        seenItemIds = {};
        pendingOptimisticEl = null;
        turnUi = {};
        if (welcome) {
            messagesEl.innerHTML =
                '<div class="chat-welcome">' +
                '<div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>' +
                '<h5>Selamat datang di Aipedia AI</h5>' +
                '<p class="text-muted small mb-0">Shared room · lazy create on send · Stop hanya initiator.</p></div>';
        } else {
            messagesEl.innerHTML = '';
        }
    }

    function appendUserMessage(text, who, mine, id) {
        const welcome = messagesEl.querySelector('.chat-welcome');
        if (welcome) welcome.remove();
        const art = document.createElement('article');
        art.className = 'msg msg--user ' + (mine ? 'is-mine' : 'is-peer');
        if (id) art.dataset.id = id;
        art.innerHTML =
            '<div class="msg__who">' + escapeHtml(who) + (mine ? ' · you' : '') + '</div>' +
            '<div class="msg__bubble">' + escapeHtml(text) + '</div>';
        messagesEl.appendChild(art);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return art;
    }

    function paintTurnBubble(state) {
        const inner =
            disclosureHtml('think', {
                open: false,
                summary: state.thinkingSteps.length
                    ? (state.thinkingSteps.length + ' step' + (state.thinkingSteps.length === 1 ? '' : 's'))
                    : 'Reasoning hidden',
                steps: state.thinkingSteps,
            }) +
            disclosureHtml('tools', {
                open: true,
                summary: state.tools.length + ' tool call' + (state.tools.length === 1 ? '' : 's'),
                items: state.tools,
            }) +
            (state.text
                ? '<div class="msg__text">' + formatMarkdown(state.text) + '</div>'
                : '');
        const bubble = state.art.querySelector('.msg__bubble');
        if (bubble) bubble.innerHTML = inner || '<div class="typing" aria-label="Thinking"><span></span><span></span><span></span></div>';
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function ensureTurnUi(turnId) {
        const key = turnId || '_anon';
        if (turnUi[key]) return turnUi[key];
        const welcome = messagesEl.querySelector('.chat-welcome');
        if (welcome) welcome.remove();
        const art = document.createElement('article');
        art.className = 'msg msg--assistant';
        art.dataset.turnId = key;
        art.innerHTML = '<div class="msg__bubble"></div>';
        messagesEl.appendChild(art);
        turnUi[key] = { art: art, thinkingSteps: [], tools: [], text: '' };
        paintTurnBubble(turnUi[key]);
        return turnUi[key];
    }

    function appendError(detail, turnId, retryable) {
        const welcome = messagesEl.querySelector('.chat-welcome');
        if (welcome) welcome.remove();
        const art = document.createElement('article');
        art.className = 'msg msg--assistant msg--error';
        if (turnId) art.dataset.turnId = turnId;
        let actions = '';
        if (retryable && turnId) {
            actions =
                '<div class="msg-error__actions">' +
                '<button type="button" class="btn-retry" data-action="retry" data-turn-id="' +
                escapeHtml(turnId) + '">' +
                '<i class="bi bi-arrow-clockwise"></i> Retry</button></div>';
        }
        art.innerHTML =
            '<div class="msg__bubble"><div class="msg-error">' +
            '<div class="msg-error__head">' +
            '<i class="bi bi-exclamation-triangle-fill msg-error__icon"></i>' +
            '<div><div class="msg-error__title">Failed</div>' +
            '<div class="msg-error__detail">' + escapeHtml(detail || 'error') + '</div></div></div>' +
            actions + '</div></div>';
        messagesEl.appendChild(art);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return art;
    }

    function clearTurnErrors(turnId) {
        messagesEl.querySelectorAll('.msg--error').forEach(function (el) {
            if (!turnId || el.dataset.turnId === turnId) el.remove();
        });
    }

    function retryTurn(id) {
        if (!threadId || !id || !docsIndexUsable) {
            return Promise.reject(new Error('retry_unavailable'));
        }
        return fetch(window.webchatRoutes.threadBase + '/' + threadId + '/retry', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ turn_id: id }),
        }).then(async (r) => {
            const body = await r.json().catch(() => ({}));
            if (!r.ok) {
                const err = new Error(body.code || body.error || ('HTTP ' + r.status));
                err.status = r.status;
                err.body = body;
                throw err;
            }
            return body;
        });
    }

    messagesEl.addEventListener('click', function (e) {
        const retryBtn = e.target.closest('[data-action="retry"]');
        if (retryBtn && messagesEl.contains(retryBtn)) {
            const tid = retryBtn.getAttribute('data-turn-id');
            if (!tid || busy) return;
            busy = true;
            isInitiator = true;
            updateComposer();
            setStatus('Retrying…');
            clearTurnErrors(tid);
            turnId = tid;
            retryTurn(tid).then(function (started) {
                applyFloorFromPayload(started);
                floorHolderId = adminUserId;
                floorRemainingSec = 0;
                refreshFloorBanner();
                openEvents(threadId, started.seq_head || 0);
            }).catch(function (err) {
                busy = false;
                isInitiator = false;
                updateComposer();
                if (err.status === 503) {
                    applyDocsIndexGate((err.body && err.body.docs_index) || { usable: false, status: 'building', message: 'Indexing…' });
                    showToast('503 docs index');
                } else if (err.status === 429) {
                    showToast('429 rate limited');
                } else if (err.status === 423) {
                    showToast('423 floor locked');
                } else if (err.status === 409) {
                    showToast(err.message || '409 not retryable / busy');
                } else {
                    showToast(err.message || 'Retry failed');
                    appendError(String(err.message || err), tid, true);
                }
                setStatus('Ready');
            });
            return;
        }
        const btn = e.target.closest('.disclosure__toggle');
        if (!btn || !messagesEl.contains(btn)) return;
        const panel = btn.closest('.disclosure');
        if (!panel) return;
        const open = !panel.classList.contains('is-open');
        panel.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    function createThread() {
        return fetch(window.webchatRoutes.threads, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        }).then(async (r) => {
            const body = await r.json();
            if (!r.ok) throw Object.assign(new Error(body.error || 'create failed'), { status: r.status, body: body });
            return body;
        });
    }

    function startTurn(id, message) {
        return fetch(window.webchatRoutes.threadBase + '/' + id + '/turns', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ message: message }),
        }).then(async (r) => {
            const body = await r.json().catch(() => ({}));
            if (!r.ok) {
                const err = new Error(body.code || body.error || ('HTTP ' + r.status));
                err.status = r.status;
                err.body = body;
                err.retryAfter = r.headers.get('Retry-After');
                throw err;
            }
            return body;
        });
    }

    function getThread(id, afterSeq) {
        return fetch(window.webchatRoutes.threadBase + '/' + id + '?after_seq=' + (afterSeq || 0), {
            headers: { 'Accept': 'application/json' },
        }).then(async (r) => {
            const body = await r.json().catch(() => ({}));
            if (!r.ok) {
                const err = new Error(body.error || 'get failed');
                err.status = r.status;
                throw err;
            }
            return body;
        });
    }

    function listConversations() {
        return fetch(window.webchatRoutes.conversations, {
            headers: { 'Accept': 'application/json' },
        }).then((r) => r.json());
    }

    function renameThread(id, title) {
        return fetch(window.webchatRoutes.threadBase + '/' + id, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ title: title }),
        }).then(async (r) => {
            const body = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(body.error || 'rename failed');
            return body;
        });
    }

    function interruptTurn() {
        if (!threadId || !turnId || !isInitiator) return Promise.resolve();
        return fetch(window.webchatRoutes.threadBase + '/' + threadId + '/interrupt', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ turn_id: turnId }),
        }).then(async (r) => {
            const body = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(body.error || 'interrupt denied');
            return body;
        });
    }

    function openEvents(id, afterSeq) {
        if (es) es.close();
        const url = window.webchatRoutes.threadBase + '/' + id + '/events?after_seq=' + afterSeq;
        es = new EventSource(url);

        es.addEventListener('item.completed', function (e) {
            const data = JSON.parse(e.data);
            renderItem(data.item);
        });
        es.addEventListener('turn.started', function () {
            setStatus('Thinking…');
        });
        es.addEventListener('turn.resumed', function (e) {
            let tid = turnId;
            try {
                const data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                if (data && data.turn_id) tid = data.turn_id;
            } catch (err) { /* ignore */ }
            clearTurnErrors(tid);
            busy = true;
            isInitiator = true;
            setStatus('Retrying…');
            updateComposer();
        });
        es.addEventListener('turn.completed', function () {
            setStatus('Ready');
            busy = false;
            isInitiator = false;
            pendingUserText = null;
            pendingOptimisticEl = null;
            updateComposer();
            inputEl.focus();
            es.close();
            refreshConversationList();
        });
        es.addEventListener('turn.failed', function (e) {
            onTurnFailed(e.data);
            es.close();
            refreshConversationList();
        });
        es.onerror = function () {
            if (es.readyState === EventSource.CLOSED) {
                busy = false;
                isInitiator = false;
                updateComposer();
            }
        };
    }

    function onTurnFailed(rawData) {
        let detail = '';
        let code = '';
        let failedTurn = turnId;
        try {
            const data = typeof rawData === 'string' ? JSON.parse(rawData) : rawData;
            code = (data && data.error && data.error.code) ? String(data.error.code) : '';
            detail = (data && data.error && data.error.message) ? String(data.error.message) : '';
            if (data && data.turn_id) failedTurn = data.turn_id;
        } catch (err) {
            detail = '';
        }
        if (code === 'interrupted') {
            setStatus('Interrupted · floor tetap Anda');
            showToast('Stop · floor tidak dilepas');
        } else {
            setStatus('Failed · bisa Retry');
            appendError(detail || 'error', failedTurn, true);
        }
        busy = false;
        isInitiator = false;
        pendingUserText = null;
        pendingOptimisticEl = null;
        updateComposer();
        inputEl.focus();
    }

    function applyReasoning(item) {
        const text = String(item.text || '').trim();
        if (!text) return;
        const state = ensureTurnUi(item.turn_id);
        const chunks = text.split(/\n+/).map(function (s) { return s.trim(); }).filter(Boolean);
        state.thinkingSteps = state.thinkingSteps.concat(chunks.length ? chunks : [text]);
        paintTurnBubble(state);
    }

    function applyToolCall(item) {
        const state = ensureTurnUi(item.turn_id);
        const callId = item.call_id || item.id || ('call_' + state.tools.length);
        const existing = state.tools.find(function (t) { return t.callId === callId; });
        if (existing) {
            existing.call = formatToolCall(item.name, item.arguments || {});
        } else {
            state.tools.push({
                callId: callId,
                call: formatToolCall(item.name, item.arguments || {}),
                result: '…',
                ok: true,
            });
        }
        paintTurnBubble(state);
    }

    function applyToolResult(item) {
        const state = ensureTurnUi(item.turn_id);
        const callId = item.call_id || '';
        const envelope = item.envelope || {};
        let row = state.tools.find(function (t) { return t.callId === callId; });
        if (!row) {
            row = {
                callId: callId || ('res_' + state.tools.length),
                call: 'tool',
                result: '—',
                ok: true,
            };
            state.tools.push(row);
        }
        row.ok = !!envelope.ok;
        row.result = summarizeToolResult(envelope);
        paintTurnBubble(state);
    }

    function applyAgentMessage(item) {
        const state = ensureTurnUi(item.turn_id);
        state.text = item.text || '';
        if (item.id) state.art.dataset.id = item.id;
        paintTurnBubble(state);
    }

    function renderItem(item) {
        if (!item) return;
        const id = item.id || '';
        if (id && seenItemIds[id]) return;
        if (id) seenItemIds[id] = true;

        const type = item.type || '';
        if (type === 'user_message') {
            const text = item.text || '';
            if (pendingUserText !== null && text === pendingUserText && pendingOptimisticEl) {
                pendingOptimisticEl.dataset.id = id;
                if (id) seenItemIds[id] = true;
                pendingUserText = null;
                pendingOptimisticEl = null;
                return;
            }
            const aid = Number(item.admin_user_id || 0);
            const mine = aid === adminUserId || (aid === 0 && text && pendingUserText === text);
            const who = item.admin_display_name
                || (aid ? ('Admin #' + aid) : adminDisplayName);
            appendUserMessage(text, who, mine, id);
        } else if (type === 'reasoning') {
            applyReasoning(item);
        } else if (type === 'tool_call') {
            applyToolCall(item);
        } else if (type === 'tool_result') {
            applyToolResult(item);
        } else if (type === 'agent_message') {
            applyAgentMessage(item);
        } else if (type === 'turn.failed' && item.error) {
            const code = item.error.code || '';
            if (code !== 'interrupted') {
                appendError((item.error.message || code || 'error'), item.turn_id || '', true);
            }
        } else if (type === 'turn.resumed') {
            clearTurnErrors(item.turn_id || '');
        }
    }

    function hydrateItems(items) {
        clearMessages(false);
        const lines = items || [];
        // Suppress Failed UI when the same turn later completed (after Retry).
        const completedTurns = {};
        lines.forEach(function (line) {
            if ((line.type || '') === 'turn.completed' && line.turn_id) {
                completedTurns[line.turn_id] = true;
            }
        });
        lines.forEach(function (line) {
            const type = line.type || '';
            if (type === 'thread.started' || type === 'turn.started' || type === 'turn.completed' || type === 'turn.resumed') {
                return;
            }
            if (type === 'turn.failed' && line.turn_id && completedTurns[line.turn_id]) {
                return;
            }
            renderItem(line);
        });
        if (!messagesEl.querySelector('.msg')) clearMessages(true);
    }

    function displayTitle(c) {
        if (!c) return 'New chat';
        if (c.title && String(c.title).trim()) return c.title;
        return 'New chat';
    }

    function relTime(ts) {
        if (!ts) return '';
        const ms = Number(ts) > 1e12 ? Number(ts) : Number(ts) * 1000;
        const diff = Date.now() - ms;
        const h = Math.floor(diff / 3600000);
        if (h < 1) return 'just now';
        if (h < 24) return h + 'h';
        return Math.floor(h / 24) + 'd';
    }

    function updateRoomHead(meta) {
        if (!roomTitleEl) return;
        roomTitleEl.textContent = displayTitle(meta || { title: null });
        if (roomMetaEl) {
            const src = (meta && meta.title_source) || 'pending';
            roomMetaEl.textContent = 'title_source=' + src;
        }
    }

    function renderConversationList() {
        if (!listEl) return;
        listEl.innerHTML = '';
        conversations.forEach(function (c) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wc-conv' + (c.thread_id === threadId ? ' is-active' : '');
            const src = c.title_source || 'pending';
            const pending = !c.title;
            btn.innerHTML =
                '<span class="wc-conv__title' + (pending ? ' is-pending' : '') + '">' +
                escapeHtml(displayTitle(c)) + '</span>' +
                '<span class="wc-pill wc-pill--' + escapeHtml(src) + '">' + escapeHtml(src) + '</span>' +
                '<span class="wc-conv__meta">' +
                '<span class="wc-conv__by">#' + escapeHtml(String(c.created_by_admin_user_id || c.admin_user_id || '?')) + '</span>' +
                '<span>' + escapeHtml(relTime(c.last_activity_at || c.updated_at)) + '</span>' +
                '</span>';
            btn.addEventListener('click', function () {
                openConversation(c.thread_id);
            });
            li.appendChild(btn);
            listEl.appendChild(li);
        });
    }

    async function refreshConversationList() {
        try {
            const list = await listConversations();
            if (list && list.docs_index) {
                applyDocsIndexGate(list.docs_index);
            }
            if (!hasRail) return;
            conversations = (list && list.conversations) || [];
            renderConversationList();
            const active = conversations.find(function (c) { return c.thread_id === threadId; });
            if (active) updateRoomHead(active);
        } catch (e) {
            // ignore
        }
    }

    async function openConversation(id) {
        if (es) es.close();
        try {
            const snap = await getThread(id, 0);
            threadId = id;
            localStorage.setItem(storageKey, id);
            applyFloorFromPayload(snap);
            hydrateItems(snap.items || []);
            setStatus('Hydrated ' + id);
            busy = false;
            isInitiator = false;
            updateComposer();
            await refreshConversationList();
            const active = conversations.find(function (c) { return c.thread_id === id; });
            updateRoomHead(active || { title: null, title_source: 'pending' });
        } catch (e) {
            localStorage.removeItem(storageKey);
            showToast('Thread unavailable');
        }
    }

    async function boot() {
        startFloorTicker();
        await refreshConversationList();
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            try {
                await openConversation(saved);
                return;
            } catch (e) {
                localStorage.removeItem(storageKey);
            }
        }
        if (hasRail && conversations.length) {
            await openConversation(conversations[0].thread_id);
            return;
        }
        if (!hasRail) {
            try {
                const list = await listConversations();
                const rows = (list && list.conversations) || [];
                if (rows.length) {
                    await openConversation(rows[0].thread_id);
                    return;
                }
            } catch (e) {
                // empty
            }
        }
        threadId = null;
        clearMessages(true);
        updateRoomHead({ title: null, title_source: 'pending' });
        setStatus('Ready · kirim untuk lazy create');
        updateComposer();
        renderConversationList();
    }

    function newChat() {
        if (es) es.close();
        threadId = null;
        turnId = null;
        busy = false;
        isInitiator = false;
        localStorage.removeItem(storageKey);
        floorHolderId = null;
        floorRemainingSec = 0;
        pendingUserText = null;
        pendingOptimisticEl = null;
        clearMessages(true);
        updateRoomHead({ title: null, title_source: 'pending' });
        setStatus('New · lazy (create on send)');
        refreshFloorBanner();
        updateComposer();
        renderConversationList();
        inputEl.focus();
    }

    async function send() {
        const message = inputEl.value.trim();
        if (!message || busy) return;
        if (!docsIndexUsable) {
            showToast('503 docs index belum siap');
            return;
        }
        if (floorRemainingSec > 0 && floorHolderId !== adminUserId) {
            showToast('423 floor_locked · sisa ' + floorRemainingSec + 's');
            return;
        }

        busy = true;
        isInitiator = true;
        updateComposer();
        inputEl.value = '';
        pendingUserText = message;
        pendingOptimisticEl = appendUserMessage(message, adminDisplayName, true, null);

        try {
            if (!threadId) {
                const created = await createThread();
                threadId = created.thread_id;
                localStorage.setItem(storageKey, threadId);
                updateRoomHead({ title: null, title_source: 'pending' });
            }
            const started = await startTurn(threadId, message);
            turnId = started.turn_id;
            applyFloorFromPayload(started);
            floorHolderId = adminUserId;
            floorRemainingSec = 0;
            refreshFloorBanner();
            setStatus('Queued');
            openEvents(threadId, started.seq_head || 0);
            refreshConversationList();
        } catch (err) {
            if (pendingOptimisticEl) {
                pendingOptimisticEl.remove();
                pendingOptimisticEl = null;
            }
            pendingUserText = null;
            if (err.status === 503) {
                if (err.body && err.body.docs_index) {
                    applyDocsIndexGate(err.body.docs_index);
                } else {
                    docsIndexUsable = false;
                    applyDocsIndexGate({
                        usable: false,
                        status: 'building',
                        message: 'Docs index sedang dibangun. AI sementara tidak tersedia.',
                    });
                }
                showToast('503 AI locked · indexing');
                setStatus('Indexing docs…');
            } else if (err.status === 429) {
                showToast('429 rate limited · retry ' + (err.retryAfter || '?') + 's');
                setStatus('429 rate limited');
            } else if (err.status === 423) {
                applyFloorFromPayload(err.body || {});
                floorRemainingSec = (err.body && err.body.remaining_sec) || floorRemainingSec;
                floorHolderId = (err.body && err.body.holder_admin_user_id) || floorHolderId;
                refreshFloorBanner();
                showToast('423 floor locked');
                setStatus('Floor locked');
            } else if (err.status === 409) {
                showToast('409 thread busy');
                setStatus('Busy');
            } else {
                setStatus('Failed: ' + (err.message || 'request error'));
                appendError(String(err.message || err));
            }
            busy = false;
            isInitiator = false;
            updateComposer();
        }
    }

    sendBtn.addEventListener('click', send);
    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            interruptTurn().then(function () {
                setStatus('Stopping…');
            }).catch(function (e) {
                showToast(e.message || 'Stop denied');
            });
        });
    }
    if (newBtn) newBtn.addEventListener('click', newChat);
    if (renameBtn) {
        renameBtn.addEventListener('click', function () {
            if (!threadId) {
                showToast('Kirim dulu untuk buat room');
                return;
            }
            const current = roomTitleEl ? roomTitleEl.textContent : '';
            const next = window.prompt('Rename conversation', current === 'New chat' ? '' : current);
            if (next == null) return;
            const title = String(next).trim();
            if (!title) return;
            renameThread(threadId, title).then(function () {
                updateRoomHead({ title: title, title_source: 'manual' });
                refreshConversationList();
                showToast('Renamed');
            }).catch(function (e) {
                showToast(e.message || 'Rename failed');
            });
        });
    }
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') send();
    });

    window.webchatUi = { newChat: newChat, boot: boot, openConversation: openConversation };
    boot();
})();
