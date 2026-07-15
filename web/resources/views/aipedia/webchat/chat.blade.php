@extends('layouts.app')

@section('title', 'AI Webchat')

@section('nav-dashboard', '')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/webchat.css') }}">
@endpush

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-4 page-title">
        <div>
            <h4 class="mb-0">AI Webchat</h4>
            <p class="text-muted mb-0 small">Tanya cara pakai CMS, cari dokumen, atau minta bantuan.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card webchat-card" id="webchat">
                <div class="webchat-messages" id="chatMessages">
                    <div class="chat-welcome">
                        <div class="chat-welcome-icon"><i class="bi bi-stars"></i></div>
                        <h5>Selamat datang di Aipedia AI</h5>
                        <p class="text-muted small">Asisten untuk admin CMS. Mode v0: read + instructor only.</p>
                    </div>
                </div>
                <div class="webchat-status" id="chatStatus">Ready</div>
                <div class="webchat-input-area">
                    <div class="input-group">
                        <input type="text" class="form-control" id="chatInput" placeholder="Ketik pertanyaan..." autocomplete="off">
                        <button class="btn btn-primary" id="chatSend" type="button"><i class="bi bi-send"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    window.webchatRoutes = {
        threads: "{{ route('aipedia.webchat.threads.create') }}",
        threadBase: "{{ url('/aipedia/webchat/threads') }}"
    };
</script>
<script src="{{ asset('js/webchat.js') }}"></script>
@endpush
