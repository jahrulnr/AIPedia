(function () {
    'use strict';

    const messagesEl = document.getElementById('chatMessages');
    const inputEl = document.getElementById('chatInput');
    const sendBtn = document.getElementById('chatSend');
    const stopBtn = document.getElementById('chatStop');
    const statusEl = document.getElementById('chatStatus');
    const floorEl = document.getElementById('chatFloor');
    const newBtn = document.getElementById('chatNew');
    const toastEl = document.getElementById('chatToast');

    const adminUserId = Number(window.webchatAdminUserId || 1);
    const storageKey = 'webchat.thread_id.' + adminUserId;

    let threadId = null;
    let turnId = null;
    let es = null;
    let busy = false;
    let isInitiator = false;
    let floorRemainingSec = 0;
    let floorHolderId = null;
    let floorTimer = null;

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
        sendBtn.disabled = busy || floorBlocked;
        inputEl.disabled = busy || floorBlocked;
        if (stopBtn) {
            stopBtn.hidden = !busy;
            stopBtn.disabled = !(busy && isInitiator);
        }
        sendBtn.hidden = !!busy;
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
        floorEl.textContent = 'Admin #' + floorHolderId + ' menahan floor · sisa ' + m + 'm ' + String(s).padStart(2, '0') + 's';
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

    function clearMessages(welcome) {
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

    function appendBubble(role, html, who) {
        const div = document.createElement('div');
        div.className = 'chat-message ' + role;
        let head = '';
        if (who) {
            head = '<div class="chat-who">' + escapeHtml(who) + '</div>';
        }
        div.innerHTML = head + html;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendPlain(role, text, who) {
        appendBubble(role, escapeHtml(text), who);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

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
        es.addEventListener('turn.completed', function () {
            setStatus('Ready');
            busy = false;
            isInitiator = false;
            updateComposer();
            inputEl.focus();
            es.close();
        });
        es.addEventListener('turn.failed', function (e) {
            onTurnFailed(e.data);
            es.close();
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
        try {
            const data = typeof rawData === 'string' ? JSON.parse(rawData) : rawData;
            code = (data && data.error && data.error.code) ? String(data.error.code) : '';
            detail = (data && data.error && data.error.message) ? String(data.error.message) : '';
        } catch (err) {
            detail = '';
        }
        if (code === 'interrupted') {
            setStatus('Interrupted · floor tetap Anda');
            showToast('Stop · floor tidak dilepas');
        } else {
            setStatus('Failed: ' + (detail || 'error').slice(0, 200));
            appendBubble('agent', '<strong>Failed</strong><div class="tool-result">' + escapeHtml(detail || 'error') + '</div>');
        }
        busy = false;
        isInitiator = false;
        updateComposer();
        inputEl.focus();
    }

    function renderItem(item) {
        if (!item) return;
        const type = item.type || '';
        if (type === 'user_message') {
            const who = item.admin_display_name || ('Admin #' + (item.admin_user_id || '?'));
            const mine = Number(item.admin_user_id) === adminUserId;
            appendPlain('user' + (mine ? '' : ' peer'), item.text || '', who + (mine ? ' · you' : ''));
        } else if (type === 'agent_message') {
            appendPlain('agent', item.text || '');
        } else if (type === 'tool_call') {
            appendBubble('tool', '<span class="tool-name">' + escapeHtml(item.name || '') + '</span><div class="tool-result">' + escapeHtml(JSON.stringify(item.arguments || {})) + '</div>');
        } else if (type === 'tool_result') {
            const envelope = item.envelope || {};
            const ok = envelope.ok ? 'ok' : 'fail';
            const text = envelope.ok ? JSON.stringify(envelope.data).slice(0, 200) : (envelope.error?.message || 'error');
            appendBubble('tool', '<span class="tool-name">result: ' + ok + '</span><div class="tool-result">' + escapeHtml(text) + '</div>');
        }
    }

    function hydrateItems(items) {
        clearMessages(false);
        (items || []).forEach(function (line) {
            if (line.type === 'thread.started') return;
            renderItem(line);
        });
        if (!(items || []).length) clearMessages(true);
    }

    async function boot() {
        startFloorTicker();
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            try {
                const snap = await getThread(saved, 0);
                threadId = saved;
                applyFloorFromPayload(snap);
                hydrateItems(snap.items || []);
                setStatus('Hydrated ' + saved);
                updateComposer();
                return;
            } catch (e) {
                localStorage.removeItem(storageKey);
            }
        }
        try {
            const list = await listConversations();
            const rows = (list && list.conversations) || [];
            if (rows.length) {
                const id = rows[0].thread_id;
                const snap = await getThread(id, 0);
                threadId = id;
                localStorage.setItem(storageKey, id);
                applyFloorFromPayload(snap);
                hydrateItems(snap.items || []);
                setStatus('Opened latest shared room');
                updateComposer();
                return;
            }
        } catch (e) {
            // empty
        }
        threadId = null;
        clearMessages(true);
        setStatus('Ready · kirim untuk lazy create');
        updateComposer();
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
        clearMessages(true);
        setStatus('New · lazy (create on send)');
        refreshFloorBanner();
        updateComposer();
        inputEl.focus();
    }

    async function send() {
        const message = inputEl.value.trim();
        if (!message || busy) return;
        if (floorRemainingSec > 0 && floorHolderId !== adminUserId) {
            showToast('423 floor_locked · sisa ' + floorRemainingSec + 's');
            return;
        }

        busy = true;
        isInitiator = true;
        updateComposer();
        inputEl.value = '';
        appendPlain('user', message, 'You');

        try {
            if (!threadId) {
                const created = await createThread();
                threadId = created.thread_id;
                localStorage.setItem(storageKey, threadId);
            }
            const started = await startTurn(threadId, message);
            turnId = started.turn_id;
            applyFloorFromPayload(started);
            floorHolderId = adminUserId;
            floorRemainingSec = 0;
            refreshFloorBanner();
            setStatus('Queued');
            openEvents(threadId, started.seq_head || 0);
        } catch (err) {
            if (err.status === 429) {
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
                appendBubble('agent', '<strong>Failed</strong><div class="tool-result">' + escapeHtml(String(err.message || err)) + '</div>');
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
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') send();
    });

    window.webchatUi = { newChat: newChat, boot: boot };
    boot();
})();
