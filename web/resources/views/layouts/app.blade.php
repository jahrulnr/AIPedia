<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') | AIPedia</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/font/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    @stack('styles')
</head>
<body class="@yield('body-class')">

<!-- Topbar -->
<header class="topbar">
    <a href="{{ route('dashboard') }}" class="brand">
        <i class="bi bi-layers-fill"></i>
        <span>AIPedia</span>
    </a>
    <button class="toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
        <i class="bi bi-list"></i>
    </button>

    <div class="topbar-end">
        <div class="dropdown user-menu">
            <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Crect width='36' height='36' rx='18' fill='%230d6efd'/%3E%3Ctext x='18' y='23' font-size='14' font-family='Arial' fill='white' text-anchor='middle' font-weight='bold'%3EAU%3C/text%3E%3C/svg%3E" alt="Admin" class="user-avatar">
                <span class="d-none d-md-inline">Admin User</span>
                <i class="bi bi-chevron-down small"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#"><i class="bi bi-person-gear me-2"></i> Edit Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<!-- Sidebar -->
<aside class="sidebar">
    <ul class="sidebar-menu">
        <li class="menu-title">Contents</li>
        <li>
            <a href="{{ route('dashboard') }}" class="@yield('nav-dashboard', '')"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </li>
        <li>
            <a href="{{ route('aipedia.webchat.index') }}" class="@yield('nav-webchat', '')"><i class="bi bi-stars"></i> AI Webchat</a>
        </li>
        @yield('sidebar-extra')
    </ul>
</aside>

<!-- Overlay -->
<div class="overlay" id="overlay"></div>

<!-- Main content -->
<main class="main-content">
    @yield('content')
</main>

<footer class="footer">
    <div class="d-flex justify-content-between">
        <span>&copy; <script>document.write(new Date().getFullYear())</script> AIPedia.</span>
        <span class="text-muted">Crafted with care</span>
    </div>
</footer>

<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/apexcharts/apexcharts.min.js') }}"></script>
<script src="{{ asset('js/main.js') }}"></script>
@stack('scripts')
</body>
</html>
