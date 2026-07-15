@extends('layouts.app')

@section('title', 'Dashboard')

@section('nav-dashboard', 'active')

@push('scripts')
    @include('aipedia.webchat.partials.float', ['adminUserId' => 1, 'adminDisplayName' => 'Admin User'])
@endpush

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-4 page-title">
        <div>
            <h4 class="mb-0">Welcome back!</h4>
            <p class="text-muted mb-0 small">Here's what's happening today.</p>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="#">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Overview</li>
            </ol>
        </nav>
    </div>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small">Total Users</p>
                        <h4 class="mb-0 fw-bold">1,234</h4>
                    </div>
                    <div class="icon-box bg-primary-subtle text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small">Active Sessions</p>
                        <h4 class="mb-0 fw-bold">856</h4>
                    </div>
                    <div class="icon-box bg-success-subtle text-success">
                        <i class="bi bi-activity"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small">Total Files</p>
                        <h4 class="mb-0 fw-bold">3,420</h4>
                    </div>
                    <div class="icon-box bg-info-subtle text-info">
                        <i class="bi bi-folder"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-muted mb-1 small">Cache Hit Rate</p>
                        <h4 class="mb-0 fw-bold">92%</h4>
                    </div>
                    <div class="icon-box bg-warning-subtle text-warning">
                        <i class="bi bi-lightning"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart & Quick Actions -->
    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card widget-card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Activity Overview</span>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">This Year</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#">This Year</a></li>
                            <li><a class="dropdown-item" href="#">Last Year</a></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body">
                    <div id="activityChart" style="height: 320px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card widget-card h-100">
                <div class="card-header">Quick Actions</div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="#" class="btn btn-primary"><i class="bi bi-gear me-2"></i>Config Manager</a>
                        <a href="#" class="btn btn-info text-white"><i class="bi bi-cloud me-2"></i>S3 File Manager</a>
                        <a href="#" class="btn btn-secondary"><i class="bi bi-person-plus me-2"></i>Manage Users</a>
                        <a href="#" class="btn btn-success"><i class="bi bi-arrow-clockwise me-2"></i>Clear Cache</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity & System Status -->
    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card widget-card">
                <div class="card-header">Recent Activity</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="recentActivityTable">
                                <tr><td colspan="3" class="text-center py-4 text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card widget-card">
                <div class="card-header">System Status</div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <h5 class="mb-1 fw-bold">8.1</h5>
                                <p class="text-muted small mb-0">PHP Version</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3 text-center">
                                <h5 class="mb-1 fw-bold">8.83</h5>
                                <p class="text-muted small mb-0">Laravel Version</p>
                            </div>
                        </div>
                    </div>
                    <h6 class="fw-bold mb-2 small">Services</h6>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="text-muted small">Database</span>
                        <span class="badge bg-success">Connected</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="text-muted small">Cache</span>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="text-muted small">S3 Storage</span>
                        <span class="badge bg-warning text-dark">Checking</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <span class="text-muted small">Queue</span>
                        <span class="badge bg-info">Running</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
