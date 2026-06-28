document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const storageKey = 'ntn_erp_sidebar_collapsed';

    function applySidebarState() {
        if (!sidebar) {
            return;
        }

        const collapsed = localStorage.getItem(storageKey) === '1';
        if (window.innerWidth >= 992) {
            sidebar.classList.toggle('collapsed', collapsed);
            body.classList.toggle('sidebar-collapsed', collapsed);
            sidebar.classList.remove('mobile-open');
            sidebarBackdrop?.classList.remove('show');
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
        }
    }

    function toggleSidebar() {
        if (!sidebar) {
            return;
        }

        if (window.innerWidth >= 992) {
            const nextState = !sidebar.classList.contains('collapsed');
            sidebar.classList.toggle('collapsed', nextState);
            body.classList.toggle('sidebar-collapsed', nextState);
            localStorage.setItem(storageKey, nextState ? '1' : '0');
        } else {
            sidebar.classList.toggle('mobile-open');
            sidebarBackdrop?.classList.toggle('show', sidebar.classList.contains('mobile-open'));
        }
    }

    function closeMobileSidebar() {
        if (window.innerWidth < 992 && sidebar) {
            sidebar.classList.remove('mobile-open');
            sidebarBackdrop?.classList.remove('show');
        }
    }

    function autoDismissFlash() {
        document.querySelectorAll('.flash-message').forEach(function (alert) {
            window.setTimeout(function () {
                if (window.bootstrap?.Alert) {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                } else {
                    alert.remove();
                }
            }, 3000);
        });
    }

    function highlightActiveMenu() {
        const currentUrl = window.location.pathname.replace(/\/$/, '');
        document.querySelectorAll('#sidebar .nav-link').forEach(function (link) {
            const linkUrl = new URL(link.href, window.location.origin).pathname.replace(/\/$/, '');
            if (currentUrl === linkUrl || currentUrl.startsWith(linkUrl + '/')) {
                link.classList.add('active');
            }
        });
    }

    sidebarToggle?.addEventListener('click', toggleSidebar);
    sidebarBackdrop?.addEventListener('click', closeMobileSidebar);
    window.addEventListener('resize', applySidebarState);

    applySidebarState();
    autoDismissFlash();
    highlightActiveMenu();

    document.querySelectorAll('#sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', closeMobileSidebar);
    });

    if (mainContent && window.innerWidth < 992) {
        mainContent.addEventListener('click', closeMobileSidebar);
    }
});

function confirmDelete(msg) {
    return window.confirm(msg || 'Bạn có chắc chắn muốn xóa dữ liệu này không?');
}

function formatVND(amount) {
    const value = Number(amount || 0);
    return new Intl.NumberFormat('vi-VN').format(value) + ' VNĐ';
}
