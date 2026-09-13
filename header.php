<?php
include_once __DIR__ . '/config/db.php';
include_once __DIR__ . '/category_meta.php';
include_once __DIR__ . '/translations.php';
startTranslationBuffer();

// Detect if the current user is Customer for theme injection
$isCustomer = ($_SESSION['role'] ?? '') === 'Customer';
$isDriver = strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'driver';
$userRole = trim((string)($_SESSION['role'] ?? 'User')) ?: 'User';
$userRoleLabel = $userRole === 'Admin' ? 'Administrator' : $userRole;
$roleClassMap = ['Admin' => 'admin', 'Staff' => 'staff', 'Driver' => 'driver', 'Customer' => 'customer'];
$roleClass = $roleClassMap[$userRole] ?? 'guest';
// Determine asset/link base in case some pages live in a subdirectory (legacy layout)
$assetBase = basename(dirname($_SERVER['SCRIPT_FILENAME'])) !== basename(__DIR__) ? '../' : '';
$homePageByRole = [
    'Admin' => 'index.php',
    'Staff' => 'dashboard_staff.php',
    'Customer' => 'customer_home.php',
    'Driver' => 'driver_dashboard.php',
];
$homePage = $assetBase . ($homePageByRole[$_SESSION['role'] ?? ''] ?? 'auth/login.php');
$language = currentLanguage();

// Build the top ticker by comparing current live prices against the last
// snapshot stored in the session, so arrows/percentages reflect real change
// since the visitor's last page load (e.g. after a Live Pricing update).
$tickerItems = [];
try {
    $tickerRows = $conn->query("SELECT waste_id, category_name, unit_price FROM waste_categories WHERE status = 'Active' ORDER BY waste_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!isset($_SESSION['price_snapshot']) || !is_array($_SESSION['price_snapshot'])) {
        $_SESSION['price_snapshot'] = [];
    }
    foreach ($tickerRows as $row) {
        $current = (float) $row['unit_price'];
        $prev = isset($_SESSION['price_snapshot'][$row['waste_id']]) ? (float) $_SESSION['price_snapshot'][$row['waste_id']] : $current;
        $direction = $current > $prev ? 'up' : ($current < $prev ? 'down' : 'flat');
        $meta = wasteMeta($row['category_name']);
        $tickerItems[] = [
            'code' => $meta['code'],
            'price' => $current,
            'direction' => $direction,
        ];
        $_SESSION['price_snapshot'][$row['waste_id']] = $current;
    }
} catch (PDOException $e) {
    // Keep page usable even if pricing data can't be loaded.
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recyclon Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $assetBase . 'assets/css/style.css'; ?>">
    <link rel="stylesheet" href="assets/css/redirect.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <?php if ($isCustomer): ?>
        <style>
            :root {
                --nav-bg: #065f46;
                --topbar-bg: #065f46;
                --primary: #047857;
                --primary-light: #10b981;
                --primary-dark: #065f46;
                --soft-bg: #f8fafc;
                --warm-bg: #fefce8;
                --card-bg: #ffffff;
                --cream: #fffbeb;
                --accent: #f59e0b;
                --accent-light: #fde68a;
                --muted-green: #bbf7d0;
                --border: #e2e8f0;
            }

            body {
                background: linear-gradient(180deg, #f8fafc 0%, #f0fdf4 100%) !important;
            }

            .topbar {
                background: #065f46 !important;
                box-shadow: 0 10px 24px rgba(6, 95, 70, 0.16) !important;
            }

            .topbar .navbar-brand .brand-text {
                font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
                letter-spacing: .06em !important;
                font-size: 1.05rem !important;
                color: #d1fae5 !important;
                text-shadow: 0 1px 1px rgba(6, 78, 59, .35);
            }

            .topbar .navbar-brand small {
                color: #b7e4c9 !important;
                opacity: .9 !important;
            }

            .topbar .topbar-status,
            .topbar .avatar-pill {
                background: rgba(255, 255, 255, .16) !important;
            }

            .topbar .badge.bg-success {
                background: #3d966c !important;
                color: #f0fdf4 !important;
            }

            .topbar .dropdown > .btn-outline-light {
                border-color: rgba(209, 250, 229, .45) !important;
                color: #ecfdf5 !important;
                background: rgba(6, 78, 59, .16) !important;
            }

            .topbar .dropdown > .btn-outline-light:hover,
            .topbar .dropdown > .btn-outline-light:focus {
                background: rgba(6, 78, 59, .28) !important;
                border-color: #a7f3d0 !important;
            }

            .sidebar {
                background: #073b2d !important;
            }

            .sidebar-link {
                color: #d1fae5 !important;
            }

            .sidebar-link:hover {
                background: rgba(255, 255, 255, 0.09) !important;
                color: #fff !important;
            }

            .sidebar-link.active {
                background: #d1fae5 !important;
                color: #047857 !important;
                box-shadow: inset 3px 0 #10b981, 0 8px 18px rgba(6, 95, 70, 0.18) !important;
            }

            .sidebar-fleet-title {
                color: #86efac !important;
            }

            .fleet-dot-active {
                background: #22c55e !important;
            }

            .panel,
            .card {
                border-radius: 18px !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.055) !important;
            }

            .metric-card {
                background: #ffffff !important;
                border-left: 4px solid #10b981 !important;
            }

            .metric-label {
                color: #15803d !important;
            }

            .metric-value {
                color: #14532d !important;
            }

            .metric-value--green {
                color: #16a34a !important;
            }

            .metric-value--orange {
                color: #d97706 !important;
            }

            .metric-value--red {
                color: #dc2626 !important;
            }

            .status-pill {
                background: #dcfce7 !important;
                color: #166534 !important;
            }

            .table thead th {
                background: #f1f5f9 !important;
                color: #334155 !important;
                border-bottom: 1px solid #e2e8f0 !important;
            }

            .badge-status-pending {
                background: #fef3c7 !important;
                color: #92400e !important;
            }

            .badge-status-confirmed {
                background: #dbeafe !important;
                color: #1e40af !important;
            }

            .badge-status-completed {
                background: #dcfce7 !important;
                color: #166534 !important;
            }

            .badge-status-cancelled {
                background: #fee2e2 !important;
                color: #991b1b !important;
            }

            .total-value-card {
                background: linear-gradient(135deg, #166534 0%, #14532d 100%) !important;
            }

            .form-control:focus {
                border-color: #22c55e !important;
                box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2) !important;
            }

            .footer {
                background: rgba(255, 255, 255, 0.95) !important;
                border-top: 1px solid #dcfce7 !important;
            }

            .records-tab.active {
                background: #166534 !important;
                border-color: #166534 !important;
                color: #fff !important;
            }

            .modal-header {
                background: #166534 !important;
            }

            .reports-tab.active {
                background: #166534 !important;
                border-color: #166534 !important;
                color: #fff !important;
            }

            .btn-primary,
            .btn-primary:hover,
            .btn-primary:active {
                background: #047857 !important;
                border-color: #047857 !important;
                color: #ffffff !important;
            }

            .btn-outline-primary {
                border-color: #10b981 !important;
                color: #047857 !important;
            }

            .btn-outline-primary:hover {
                background: #ecfdf5 !important;
                border-color: #047857 !important;
            }

            a:not(.text-danger) {
                color: #16a34a !important;
            }

            a:not(.text-danger):hover {
                color: #15803d !important;
            }

            a.text-danger,
            .dropdown-item.text-danger {
                color: #b91c1c !important;
                font-weight: 700;
            }

            a.text-danger:hover,
            a.text-danger:focus-visible,
            .dropdown-item.text-danger:hover,
            .dropdown-item.text-danger:focus-visible {
                color: #991b1b !important;
                background: #fef2f2 !important;
            }

            .page-title h1 {
                color: #065f46 !important;
            }

            .price-row {
                background: #f0fdf4 !important;
                border: 1px solid #dcfce7 !important;
            }

            .booking-row {
                background: #fffbeb !important;
                border: 1px solid #fde68a !important;
            }

            .gps-legend {
                background: #f0fdf4 !important;
                border: 1px solid #bbf7d0 !important;
            }

            .gps-map-header {
                background: #15803d !important;
            }

            .nav-tabs .nav-link.active {
                background: #166534 !important;
                color: #fff !important;
                border-color: #166534 !important;
            }

            .nav-tabs .nav-link {
                color: #166534 !important;
            }

            .summary-tile {
                background: #ffffff !important;
                border: 1px solid #dcfce7 !important;
                border-radius: 16px !important;
            }

            .summary-tile-label {
                color: #15803d !important;
            }

            .bg-primary {
                background: #16a34a !important;
            }

            .text-primary {
                color: #16a34a !important;
            }

            .progress-bar {
                background: #22c55e !important;
            }
        </style>
    <?php endif; ?>
</head>

<body class="app-shell role-<?php echo htmlspecialchars($roleClass, ENT_QUOTES, 'UTF-8'); ?>">
    <header class="navbar navbar-expand-lg navbar-dark topbar shadow-sm fixed-top<?php echo $isDriver ? ' driver-header' : ''; ?>">
        <div class="container-fluid">

            <!-- Logo -->
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo htmlspecialchars($homePage, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="brand-full-lockup">
                    <img src="<?php echo $assetBase . 'assets/img/logo-recyclon-dark-transparent.png'; ?>" alt="Recyclon — Go Green, Recycle, Smart" class="brand-full-logo">
                </div>
            </a>

            <!-- Right side actions (always visible) -->
            <div class="d-flex align-items-center gap-2 d-lg-none">
                <span id="topbar-time-mobile" class="badge bg-success d-none d-sm-inline"></span>
                <div class="language-switcher" aria-label="Select language">
                    <a class="language-button <?= $language === 'ms' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=ms">BM</a>
                    <a class="language-button <?= $language === 'en' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=en">ENG</a>
                    <a class="language-button <?= $language === 'zh' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=zh">CN</a>
                </div>
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-1"
                        data-bs-toggle="dropdown">
                        <span class="avatar-pill" style="width:30px;height:30px;font-size:0.8rem;">
                            <?= strtoupper(substr(htmlspecialchars($_SESSION['name'] ?? 'User'), 0, 1)) ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo $assetBase . 'profile.php'; ?>"><?= htmlspecialchars(t('profile')) ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?php echo $assetBase . 'logout.php'; ?>"><?= htmlspecialchars(t('logout')) ?></a></li>
                    </ul>
                </div>
                <button class="navbar-toggler border-0 ms-1"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#topNavbar"
                    aria-controls="topNavbar"
                    aria-expanded="false"
                    aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <!-- Desktop right + collapsible nav -->
            <div class="collapse navbar-collapse" id="topNavbar">
                <div class="d-flex flex-column gap-3 mt-3 mt-lg-0">

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <!-- Clock -->
                        <span id="topbar-time-desktop" class="badge bg-success d-none d-lg-inline"></span>

                        <!-- Profile -->
                        <div class="dropdown d-none d-lg-block">
                            <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2"
                                data-bs-toggle="dropdown">
                                <span class="avatar-pill">
                                    <?= strtoupper(substr(htmlspecialchars($_SESSION['name'] ?? 'User'), 0, 1)) ?>
                                </span>
                                <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?php echo $assetBase . 'profile.php'; ?>"><?= htmlspecialchars(t('profile')) ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?php echo $assetBase . 'logout.php'; ?>"><?= htmlspecialchars(t('logout')) ?></a></li>
                            </ul>
                        </div>
                        <span class="role-indicator d-none d-lg-inline" aria-label="User role">
                            <?php echo htmlspecialchars($userRoleLabel); ?>
                        </span>

                    </div>
                </div>
            </div>

            <!-- Language (last control on the far right) -->
            <div class="language-switcher d-none d-lg-inline-flex ms-auto" aria-label="Select language">
                <a class="language-button <?= $language === 'ms' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=ms">BM</a>
                <a class="language-button <?= $language === 'en' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=en">ENG</a>
                <a class="language-button <?= $language === 'zh' ? 'active' : '' ?>" href="<?= $assetBase ?>language.php?lang=zh">CN</a>
            </div>

        </div>
    </header>

    <style>
        .language-switcher { display: inline-flex; align-items: center; gap: .15rem; }
        .language-button {
            padding: .25rem .4rem;
            border: 1px solid transparent;
            border-radius: .35rem;
            background: transparent;
            color: rgba(255, 255, 255, .75);
            font-size: .75rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .language-button { text-decoration: none; }
        .language-button:hover, .language-button:focus { color: #fff; border-color: rgba(255, 255, 255, .55); }
        .language-button.active { color: #fff; background: rgba(255, 255, 255, .18); }
        .page-title h1,
        .page-title h2,
        .page-title h3 {
            font-weight: 700 !important;
        }

        /*
         * FIX: header height is no longer hardcoded. It's measured live by the
         * script below and stored in --header-height, so .page-layout padding
         * and the customer navbar's sticky offset (see nav.php) always match
         * the real rendered height of .topbar — including on mobile where it
         * can wrap to a taller multi-line header.
         */
        .topbar {
            position: fixed !important;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 9999;
        }

        .page-layout {
            padding-top: var(--header-height, 75px);
        }
    </style>

    <script>
        function updateClock() {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            const ss = String(now.getSeconds()).padStart(2, '0');
            const timeStr = `${hh}:${mm}:${ss}`;
            const mobile = document.getElementById('topbar-time-mobile');
            const desktop = document.getElementById('topbar-time-desktop');
            if (mobile) mobile.textContent = timeStr;
            if (desktop) desktop.textContent = timeStr;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // FIX: measure the real header height and expose it as a CSS variable
        // so every other component (page padding, sticky customer nav) can
        // read the SAME number instead of guessing a fixed pixel value.
        function setHeaderHeightVar() {
            const header = document.querySelector('.topbar');
            if (header) {
                document.documentElement.style.setProperty('--header-height', header.offsetHeight + 'px');
            }
        }
        setHeaderHeightVar();
        window.addEventListener('load', setHeaderHeightVar);
        window.addEventListener('resize', setHeaderHeightVar);
        // Re-measure after fonts/images finish loading, since the logo image
        // can change the header's height slightly after first paint.
        window.addEventListener('DOMContentLoaded', setHeaderHeightVar);
    </script>

    <main class="page-layout">
