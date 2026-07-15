(function () {
    'use strict';

    const messagesEl = document.getElementById('chatMessages');
    const inputEl = document.getElementById('chatInput');
    const sendBtn = document.getElementById('chatSend');
    const statusEl = document.getElementById('chatStatus');

    let threadId = null;
    let turnId = null;
    let es = null;
    let busy = false;

    function setStatus(text) {
        statusEl.textContent = text;
    }

    function appendBubble(role, html, meta = '') {
        const div = document.createElement('div');
        div.className = 'chat-message ' + role;
        div.innerHTML = html + meta;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendPlain(role, text) {
        appendBubble(role, escapeHtml(text));
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function createThread() {
        return fetch(window.webchatRoutes.threads, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
        }).then(r => r.json());
    }

    function startTurn(threadId, message) {
        return fetch(window.webchatRoutes.threadBase + '/' + threadId + '/turns', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ message: message }),
        }).then(r => r.json());
    }

    function openEvents(threadId, afterSeq) {
        if (es) {
            es.close();
        }

        const url = window.webchatRoutes.threadBase + '/' + threadId + '/events?after_seq=' + afterSeq;
        es = new EventSource(url);

        es.onmessage = function (e) {
            if (e.data) {
                handleEvent(e.data);
            }
        };

        es.addEventListener('thread.started', function (e) {
            // no-op
        });

        es.addEventListener('turn.started', function (e) {
            setStatus('Thinking...');
        });

        es.addEventListener('item.completed', function (e) {
            const data = JSON.parse(e.data);
            renderItem(data.item);
        });

        es.addEventListener('turn.completed', function (e) {
            setStatus('Ready');
            busy = false;
            sendBtn.disabled = false;
            inputEl.disabled = false;
            inputEl.focus();
            es.close();
        });

        es.addEventListener('turn.failed', function (e) {
            onTurnFailed(e.data);
            es.close();
        });

        es.onerror = function () {
            // auto reconnect handled by browser; if closed, stop
            if (es.readyState === EventSource.CLOSED) {
                busy = false;
                sendBtn.disabled = false;
                inputEl.disabled = false;
            }
        };
    }

    function onTurnFailed(rawData) {
        let detail = '';
        try {
            const data = typeof rawData === 'string' ? JSON.parse(rawData) : rawData;
            detail = (data && data.error && data.error.message) ? String(data.error.message) : '';
        } catch (err) {
            detail = '';
        }

        const short = detail ? detail.slice(0, 280) : 'unknown error (see laravel.log / JSONL turn.failed)';
        setStatus('Failed: ' + short);
        appendBubble(
            'agent',
            '<strong>Failed</strong><div class="tool-result">' + escapeHtml(detail || short) + '</div>'
        );
        busy = false;
        sendBtn.disabled = false;
        inputEl.disabled = false;
        inputEl.focus();
    }

    function handleEvent(raw) {
        try {
            const data = JSON.parse(raw);
            if (data.type === 'turn.started') {
                setStatus('Thinking...');
            }
            if (data.type === 'turn.completed') {
                setStatus('Ready');
                busy = false;
                sendBtn.disabled = false;
                inputEl.disabled = false;
            }
            if (data.type === 'turn.failed') {
                onTurnFailed(data);
            }
        } catch (e) {
            // ignore
        }
    }

    function renderItem(item) {
        if (!item) return;
        const type = item.type || '';

        if (type === 'user_message') {
            // already rendered on send
        } else if (type === 'agent_message') {
            appendPlain('agent', item.text || '');
        } else if (type === 'tool_call') {
            appendBubble('tool', '<span class="tool-name">' + escapeHtml(item.name || '') + '</span>', '<div class="tool-result">' + escapeHtml(JSON.stringify(item.arguments || {})) + '</div>');
        } else if (type === 'tool_result') {
            const envelope = item.envelope || {};
            const ok = envelope.ok ? 'ok' : 'fail';
            const text = envelope.ok ? JSON.stringify(envelope.data).slice(0, 200) : (envelope.error?.message || 'error');
            appendBubble('tool', '<span class="tool-name">result: ' + ok + '</span>', '<div class="tool-result">' + escapeHtml(text) + '</div>');
        }
    }

    async function send() {
        const message = inputEl.value.trim();
        if (!message || busy) return;

        busy = true;
        sendBtn.disabled = true;
        inputEl.disabled = true;
        inputEl.value = '';
        appendPlain('user', message);

        if (!threadId) {
            const created = await createThread();
            threadId = created.thread_id;
        }

        try {
            const started = await startTurn(threadId, message);
            if (!started || !started.turn_id) {
                throw new Error((started && started.error) ? started.error : 'start_turn failed');
            }
            turnId = started.turn_id;
            setStatus('Queued');
            openEvents(threadId, started.seq_head || 0);
        } catch (err) {
            setStatus('Failed: ' + (err && err.message ? err.message : 'request error'));
            appendBubble('agent', '<strong>Failed</strong><div class="tool-result">' + escapeHtml(String(err && err.message ? err.message : err)) + '</div>');
            busy = false;
            sendBtn.disabled = false;
            inputEl.disabled = false;
        }
    }

    sendBtn.addEventListener('click', send);
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            send();
        }
    });
})();
