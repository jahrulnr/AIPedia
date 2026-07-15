@extends('layouts.app')

@section('title', 'AI Webchat')

@section('nav-dashboard', '')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/webchat.css') }}">
@endpush

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-3 page-title">
        <div>
            <h4 class="mb-0">AI Webchat</h4>
            <p class="text-muted mb-0 small">Shared M-rw · speak-floor 10m · RL 10/min · lazy create</p>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="chatNew">
            <i class="bi bi-plus-lg"></i> New
        </button>
    </div>

    <div class="card webchat-card" id="webchat">
        <div class="wc-floor-banner" id="chatFloor" hidden></div>
        <div class="wc-toast" id="chatToast" hidden></div>
        <div class="webchat-messages" id="chatMessages">
            <div class="chat-welcome">
                <div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>
                <h5>Selamat datang di Aipedia AI</h5>
                <p class="text-muted small mb-0">Asisten admin · read + instructor.</p>
            </div>
        </div>
        <div class="webchat-status" id="chatStatus">Ready</div>
        <div class="webchat-input-area">
            <div class="input-group">
                <input type="text" class="form-control" id="chatInput" placeholder="Ketik pertanyaan..." autocomplete="off">
                <button class="btn btn-primary" id="chatSend" type="button" aria-label="Kirim"><i class="bi bi-send"></i></button>
                <button class="btn btn-outline-danger" id="chatStop" type="button" hidden aria-label="Stop"><i class="bi bi-stop-fill"></i> Stop</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    window.webchatAdminUserId = {{ (int) ($adminUserId ?? 1) }};
    window.webchatRoutes = {
        threads: "{{ route('aipedia.webchat.threads.create') }}",
        conversations: "{{ route('aipedia.webchat.conversations.index') }}",
        threadBase: "{{ url('/aipedia/webchat/threads') }}"
    };
</script>
<script src="{{ asset('js/webchat.js') }}"></script>
@endpush
