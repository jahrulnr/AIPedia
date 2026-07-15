{{-- Floating webchat: last + New + full link. No mini-list. No mock scenario chips. --}}
<link rel="stylesheet" href="{{ asset('css/webchat.css') }}">
<link rel="stylesheet" href="{{ asset('css/webchat-float.css') }}">

<div class="wm-float" id="wmFloat" data-full-url="{{ route('aipedia.webchat.index') }}">
    <div class="wm-float__panel" data-wm-float-panel aria-hidden="true" role="dialog" aria-label="Aipedia AI chat">
        <div class="wm-float__head">
            <div class="wm-float__avatar"><i class="bi bi-stars"></i></div>
            <div class="wm-float__title">
                <strong>Aipedia AI</strong>
                <span>Reuse last · + New · full page</span>
            </div>
            <div class="wm-float__actions">
                <button type="button" class="wm-float__icon-btn" data-wm-float-new title="New (lazy)" aria-label="New chat">
                    <i class="bi bi-plus-lg"></i>
                </button>
                <button type="button" class="wm-float__icon-btn" data-wm-float-full title="Open full page" aria-label="Open full page">
                    <i class="bi bi-arrows-fullscreen"></i>
                </button>
                <button type="button" class="wm-float__icon-btn" data-wm-float-close title="Close" aria-label="Close chat">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <div class="card webchat-card" id="webchat">
            <div class="wc-floor-banner" id="chatFloor" hidden></div>
            <div class="wc-toast" id="chatToast" hidden></div>
            <div class="webchat-messages" id="chatMessages">
                <div class="chat-welcome">
                    <div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>
                    <h5>Aipedia AI</h5>
                    <p class="text-muted small mb-0">Shared room · lazy create on send.</p>
                </div>
            </div>
            <div class="webchat-status" id="chatStatus">Ready · open = reuse last (no create)</div>
            <div class="webchat-input-area">
                <div class="input-group">
                    <input type="text" class="form-control" id="chatInput" placeholder="Ketik pertanyaan..." autocomplete="off">
                    <button class="btn btn-primary" id="chatSend" type="button" aria-label="Kirim"><i class="bi bi-send"></i></button>
                    <button class="btn btn-outline-danger" id="chatStop" type="button" hidden aria-label="Stop"><i class="bi bi-stop-fill"></i></button>
                </div>
            </div>
        </div>
    </div>

    <button type="button" class="wm-float__launcher" data-wm-float-toggle aria-expanded="false" aria-label="Open Aipedia AI">
        <i class="bi bi-stars"></i>
        <span class="wm-float__badge" data-wm-float-badge hidden>0</span>
    </button>
</div>

<script>
    window.webchatAdminUserId = {{ (int) ($adminUserId ?? 1) }};
    window.webchatRoutes = {
        threads: "{{ route('aipedia.webchat.threads.create') }}",
        conversations: "{{ route('aipedia.webchat.conversations.index') }}",
        threadBase: "{{ url('/aipedia/webchat/threads') }}"
    };
</script>
<script src="{{ asset('js/webchat.js') }}"></script>
<script src="{{ asset('js/webchat-float.js') }}"></script>
