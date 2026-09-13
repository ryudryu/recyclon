<?php
$page = $page ?? basename($_SERVER['PHP_SELF']);

$role = $_SESSION['role'] ?? '';

$navItems = [];

if ($role === 'Staff') {
    $navItems = [
        'dashboard_staff.php' => ['label' => 'Dashboard', 'icon' => 'home'],
        'live_pricing.php' => ['label' => 'Live Pricing', 'icon' => 'dollar'],
        'new_booking.php' => ['label' => 'New Booking', 'icon' => 'plus'],
        'records.php' => ['label' => 'Records', 'icon' => 'records'],
        'gps_tracker.php' => ['label' => 'GPS Tracker', 'icon' => 'gps'],
        'revenue_summary.php' => ['label' => 'Revenue Summary', 'icon' => 'chart'],
    ];
} elseif ($role === 'Admin') {
    $navItems = [
        'index.php' => ['label' => 'Dashboard', 'icon' => 'home'],
        'live_pricing.php' => ['label' => 'Live Pricing', 'icon' => 'dollar'],
        'new_booking.php' => ['label' => 'New Booking', 'icon' => 'plus'],
        'records.php' => ['label' => 'Records', 'icon' => 'records'],
        'gps_tracker.php' => ['label' => 'GPS Tracker', 'icon' => 'gps'],
        'revenue_summary.php' => ['label' => 'Revenue Summary', 'icon' => 'chart'],
        'user_detail.php' => ['label' => 'User Detail', 'icon' => 'users'],
    ];
} elseif ($role === 'Customer') {
    $navItems = [
        'customer_home.php' => ['label' => 'Home', 'icon' => 'home'],
        'dashboard_cus.php' => ['label' => 'Dashboard', 'icon' => 'home'],
        'customer_tracking.php' => ['label' => 'Track Pickup', 'icon' => 'gps'],
        'new_booking.php' => ['label' => 'New Booking', 'icon' => 'plus'],
    ];
} elseif ($role === 'Driver') {
    $navItems = [
        'driver_dashboard.php' => ['label' => 'Driver Dashboard', 'icon' => 'home'],
    ];
} else {
    $navItems = [];
}

function sidebarIcon($name) {
    $icons = [
        'home' => '<path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" />',
        'dollar' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v12M15 9.5c0-1.5-1.3-2.5-3-2.5s-3 1-3 2.3c0 3 6 1.3 6 4.3 0 1.4-1.3 2.4-3 2.4s-3-1-3-2.5"/>',
        'plus' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'records' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3.5h6M8 9h8M8 13h8M8 17h5"/>',
        'gps' => '<path d="M3 11l17-8-8 17-2-7-7-2z"/>',
        'calculator' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h1M12 11h1M16 11h1M8 14h1M12 14h1M16 14h1M8 17h1M12 17h1M16 17h1"/>',
        'chart' => '<path d="M4 20V10M11 20V4M18 20v-7"/><path d="M3 20h18"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'profile' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21a7 7 0 0 1 14 0"/>',
    ];
    return $icons[$name] ?? '';
}

if ($role === 'Customer') {
    // Customer gets a horizontal card nav instead of sidebar
    ?>
    <style>
        .page-body { flex-direction: column !important; }
        .customer-navbar {
            display: flex;
            gap: 0.6rem;
            padding: 0.85rem 1rem;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #e2e8f0;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            position: sticky;
            /*
             * FIX: was a hardcoded `top: 64px`, which only matched the
             * desktop header height. On mobile the .topbar header is taller
             * (wraps/stacks), so this sticky nav was sticking underneath the
             * header instead of right below it, and the fixed header
             * (z-index: 9999) drew over the top of it.
             *
             * --header-height is set by the script in header.php, which
             * measures .topbar's real rendered height on load/resize, so
             * this value always matches — mobile or desktop.
             */
            top: var(--header-height, 64px);
            z-index: 30;
        }
        .customer-navbar::-webkit-scrollbar {
            display: none;
        }
        .customer-navbar .nav-card {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #047857;
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.15s ease;
            box-shadow: 0 4px 14px rgba(15,23,42,0.05);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .customer-navbar .nav-card:hover {
            background: #ecfdf5;
            border-color: #10b981;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(6,95,70,0.1);
            text-decoration: none;
        }
        .customer-navbar .nav-card.active {
            background: #047857;
            border-color: #047857;
            color: #fff;
            box-shadow: 0 4px 12px rgba(21,128,61,0.2);
        }
        .customer-navbar .nav-card svg { flex-shrink: 0; }
        @media (max-width: 768px) {
            .customer-navbar { padding: 0.75rem 1rem; gap: 0.5rem; }
            .customer-navbar .nav-card { padding: 0.55rem 0.85rem; font-size: 0.78rem; }
        }
    </style>
    <div class="customer-navbar">
        <?php foreach ($navItems as $file => $item): ?>
            <?php $isActive = $page === $file; ?>
            <a class="nav-card<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo $file; ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo sidebarIcon($item['icon']); ?></svg>
                <?php echo htmlspecialchars($item['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
} else {
    // Admin / Staff get the sidebar
    ?>
<aside class="sidebar">
    <button class="sidebar-toggle" aria-label="Toggle sidebar">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
    </button>
    <nav class="sidebar-nav nav nav-pills flex-column gap-2">
        <?php foreach ($navItems as $file => $item): ?>
            <?php $isActive = $page === $file; ?>
            <a class="sidebar-link nav-link d-flex align-items-center<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo $file; ?>" title="<?php echo htmlspecialchars($item['label']); ?>">
                <svg class="sidebar-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo sidebarIcon($item['icon']); ?></svg>
                <span class="flex-grow-1"><?php echo htmlspecialchars($item['label']); ?></span>
                <?php if ($isActive): ?><span class="sidebar-chevron">&rsaquo;</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="sidebar-backdrop"></div>
<script>
(function() {
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.querySelector('.sidebar-toggle');
    const backdrop = document.querySelector('.sidebar-backdrop');
    if (!sidebar || !toggleBtn) return;

    const state = localStorage.getItem('sidebarState') || 'collapsed';
    sidebar.classList.add(state);

    function openSidebar() {
        sidebar.classList.remove('collapsed');
        sidebar.classList.add('expanded');
        localStorage.setItem('sidebarState', 'expanded');
        if (backdrop && window.innerWidth <= 768) backdrop.style.display = 'block';
    }

    function closeSidebar() {
        sidebar.classList.remove('expanded');
        sidebar.classList.add('collapsed');
        localStorage.setItem('sidebarState', 'collapsed');
        if (backdrop) backdrop.style.display = 'none';
    }

    toggleBtn.addEventListener('click', function() {
        if (sidebar.classList.contains('collapsed')) {
            openSidebar();
        } else {
            closeSidebar();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('expanded')) {
            closeSidebar();
        }
    });

    document.querySelectorAll('.sidebar-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768 && sidebar.classList.contains('expanded')) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && backdrop) {
            backdrop.style.display = 'none';
        }
        if (window.innerWidth <= 768 && sidebar.classList.contains('expanded') && backdrop) {
            backdrop.style.display = 'block';
        }
    });
})();
</script>
<?php } ?>
