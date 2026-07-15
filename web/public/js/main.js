(function () {
    'use strict';

    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('overlay');
    const toggleBtn = document.getElementById('sidebarToggle');
    const hasArrows = document.querySelectorAll('.has-arrow');

    // Sidebar toggle (desktop collapse / mobile open)
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', () => {
            if (window.innerWidth < 992) {
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            document.body.classList.remove('sidebar-open');
        });
    }

    // Close mobile sidebar on resize to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992) {
            document.body.classList.remove('sidebar-open');
        }
    });

    // Submenu accordion
    hasArrows.forEach(link => {
        link.addEventListener('click', function (e) {
            const submenu = this.nextElementSibling;
            if (submenu && submenu.classList.contains('submenu')) {
                e.preventDefault();
                const isOpen = submenu.classList.contains('open');

                // Close siblings at same level (optional accordion behavior)
                const parent = this.closest('ul');
                if (parent) {
                    parent.querySelectorAll('.submenu.open').forEach(s => {
                        if (s !== submenu) {
                            s.classList.remove('open');
                            s.previousElementSibling?.classList.remove('open');
                        }
                    });
                }

                submenu.classList.toggle('open');
                this.classList.toggle('open');
            }
        });
    });

    // Password visibility toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            togglePassword.querySelector('i').classList.toggle('bi-eye');
            togglePassword.querySelector('i').classList.toggle('bi-eye-slash');
        });
    }

    // Populate recent activity mock data on dashboard
    const activityTable = document.getElementById('recentActivityTable');
    if (activityTable) {
        const activities = [
            { user: 'Admin User', action: 'Logged in to dashboard', time: 'Just now', color: 'bg-primary' },
            { user: 'John Doe', action: 'Updated banner config', time: '5 mins ago', color: 'bg-success' },
            { user: 'Jane Smith', action: 'Uploaded new promo', time: '1 hour ago', color: 'bg-info' },
            { user: 'Mike Brown', action: 'Created new voucher rule', time: '3 hours ago', color: 'bg-warning' },
            { user: 'Sarah Lee', action: 'Exported complaint data', time: 'Yesterday', color: 'bg-secondary' }
        ];

        activityTable.innerHTML = activities.map(a => `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <span class="avatar-initials rounded-circle ${a.color} text-white d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:.75rem;font-weight:600;">${a.user.charAt(0)}</span>
                        </div>
                        <div class="flex-grow-1 ms-2">
                            <h6 class="mb-0 small fw-semibold">${a.user}</h6>
                        </div>
                    </div>
                </td>
                <td><span class="text-muted small">${a.action}</span></td>
                <td><span class="badge bg-light text-dark small">${a.time}</span></td>
            </tr>
        `).join('');
    }

    // Activity chart on dashboard
    const chartEl = document.getElementById('activityChart');
    if (chartEl && typeof ApexCharts !== 'undefined') {
        const options = {
            chart: {
                type: 'area',
                height: 320,
                toolbar: { show: false },
                background: 'transparent',
                fontFamily: 'Inter, sans-serif'
            },
            series: [
                { name: 'Users', data: [12, 19, 27, 33, 28, 35, 51, 42, 39, 45, 48, 52] },
                { name: 'Sessions', data: [8, 15, 22, 25, 20, 28, 35, 30, 27, 32, 35, 38] }
            ],
            xaxis: {
                categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            },
            colors: ['#0d6efd', '#20c997'],
            fill: {
                type: 'gradient',
                gradient: {
                    opacityFrom: 0.5,
                    opacityTo: 0.05
                }
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            grid: {
                borderColor: '#f1f3f5'
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right'
            },
            dataLabels: { enabled: false }
        };
        new ApexCharts(chartEl, options).render();
    } else if (chartEl) {
        chartEl.innerHTML = '<div class="text-center py-5 text-muted">Chart library not available</div>';
    }
})();
