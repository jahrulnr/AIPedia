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
                <button type="button" class="wc-rail__new" id="btnNewChat">
                    <i class="bi bi-plus-lg"></i> New chat
                </button>
            </div>
            <div class="wc-rail__hk" title="Shared rooms — all admins">
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
                        <div class="wc-room-head__meta" id="roomMeta">title_source=pending</div>
                        <button type="button" class="wc-room-head__edit" id="btnRename" title="Rename (manual)">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </div>
                    <div class="wc-floor-banner" id="chatFloor" hidden></div>
                    <div class="wc-index-banner" id="chatIndexBanner" hidden></div>
                    <div class="wc-toast" id="chatToast" hidden></div>
                    <div class="webchat-messages" id="chatMessages" role="log" aria-live="polite">
                        <div class="chat-welcome">
                            <div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>
                            <h5>Selamat datang di Aipedia AI</h5>
                            <p class="text-muted small mb-0">Shared room · lazy create on send · Stop hanya initiator.</p>
                        </div>
                    </div>
                    <div class="webchat-status" id="chatStatus">Ready</div>
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
<script src="{{ asset('js/webchat.js') }}"></script>
@endpush
