@extends('layouts.app')

@section('title', 'AI Webchat')
@section('body-class', 'page-webchat')
@section('nav-webchat', 'active')

@push('styles')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/webchat.css') }}">
<link rel="stylesheet" href="{{ asset('css/webchat-conversations.css') }}">
@endpush

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-3 page-title">
        <div>
            <h4 class="mb-0">AI Webchat</h4>
            <p class="text-muted mb-0 small">Shared M-rw · speak-floor 10m · RL 10/min · lazy create. Floating di <a href="{{ route('dashboard') }}">Dashboard</a>.</p>
        </div>
    </div>

    <div class="wc-shell">
        <aside class="wc-rail" aria-label="Shared conversation list">
            <div class="wc-rail__top">
                <div class="wc-rail__heading">
                    <div>
                        <span class="wc-rail__eyebrow">Workspace</span>
                        <strong>Conversations</strong>
                    </div>
                    <span class="wc-rail__count" id="conversationCount" aria-label="Conversation count">—</span>
                </div>
                <button type="button" class="wc-rail__new" id="btnNewChat">
                    <i class="bi bi-plus-lg"></i> New chat
                </button>
            </div>
            <div class="wc-rail__hk">
                <i class="bi bi-people"></i>
                Shared · semua admin lihat &amp; kirim
            </div>
            <ul class="wc-rail__list" id="conversationList"></ul>
        </aside>

        <div class="wc-main">
            <div class="webchat-root" id="webchatRoot">
                <div class="card webchat-card" id="webchat">
                    <div class="wc-room-head" id="roomHead">
                        <div class="wc-room-head__title" id="roomTitle">New chat</div>
                        <div class="wc-room-head__meta" id="roomMeta">Naming…</div>
                        <button type="button" class="wc-room-head__edit" id="btnRename" aria-label="Rename conversation" data-wc-tooltip="Rename conversation">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>
                    <div class="wc-floor-banner" id="chatFloor" hidden></div>
                    <div class="wc-index-banner" id="chatIndexBanner" hidden></div>
                    <div class="wc-toast" id="chatToast" role="status" aria-live="polite" hidden></div>
                    <div class="webchat-messages" id="chatMessages" role="log" aria-live="polite">
                        <div class="chat-welcome">
                            <div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>
                            <h5>Selamat datang di AIPedia</h5>
                            <p class="text-muted small mb-0">Shared room · lazy create on send · Stop hanya initiator.</p>
                        </div>
                    </div>
                    <div class="webchat-status" id="chatStatus" data-state="ready">Ready</div>
                    <div class="webchat-composer">
                        <div class="composer-row">
                            <input type="text" class="composer-input" id="chatInput" placeholder="Ketik pertanyaan..." autocomplete="off">
                            <button class="composer-send" id="chatSend" type="button" aria-label="Kirim">
                                <i class="bi bi-send-fill"></i>
                            </button>
                            <button class="composer-stop" id="chatStop" type="button" aria-label="Stop" hidden>
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="wc-dialog" id="renameDialog" hidden>
        <div class="wc-dialog__backdrop" data-rename-close></div>
        <section class="wc-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="renameDialogTitle" aria-describedby="renameDialogHelp" tabindex="-1">
            <div class="wc-dialog__accent" aria-hidden="true"></div>
            <header class="wc-dialog__header">
                <div class="wc-dialog__icon" aria-hidden="true"><i class="bi bi-pencil-square"></i></div>
                <div>
                    <span class="wc-dialog__eyebrow">Conversation</span>
                    <h5 id="renameDialogTitle">Rename conversation</h5>
                </div>
                <button type="button" class="wc-dialog__close" data-rename-close aria-label="Close rename dialog" data-wc-tooltip="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>
            <form class="wc-dialog__form" id="renameForm" novalidate>
                <label for="renameInput">Conversation title</label>
                <div class="wc-dialog__field">
                    <input type="text" id="renameInput" maxlength="60" autocomplete="off" aria-describedby="renameDialogHelp renameDialogError">
                    <span class="wc-dialog__count" id="renameCount">0/60</span>
                </div>
                <p class="wc-dialog__help" id="renameDialogHelp">Use a short title that makes this room easy to find later.</p>
                <p class="wc-dialog__error" id="renameDialogError" role="alert" hidden></p>
                <div class="wc-dialog__actions">
                    <button type="button" class="wc-dialog__button wc-dialog__button--ghost" data-rename-close>Cancel</button>
                    <button type="submit" class="wc-dialog__button wc-dialog__button--primary" id="renameSubmit">
                        <span>Save title</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
<script>
    window.webchatAdminUserId = {{ (int) ($adminUserId ?? 1) }};
    window.webchatAdminDisplayName = @json($adminDisplayName ?? 'Admin User');
    window.webchatRoutes = {
        threads: "{{ route('aipedia.webchat.threads.create') }}",
        conversations: "{{ route('aipedia.webchat.conversations.index') }}",
        threadBase: "{{ url('/aipedia/webchat/threads') }}"
    };
</script>
<script src="{{ asset('vendor/marked/marked.umd.js') }}"></script>
<script src="{{ asset('vendor/dompurify/purify.min.js') }}"></script>
<script src="{{ asset('js/webchat.js') }}"></script>
@endpush
