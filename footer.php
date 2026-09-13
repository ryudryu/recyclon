<?php
include_once __DIR__ . '/translations.php';
$footerRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
?>

</main>

<footer class="footer border-top py-3" style="background:#f8fafc;">
    <div class="container-fluid px-4">
        <?php if ($footerRole === 'customer'): ?>
            <div class="row gy-3">
                <div class="col-6 col-md-3">
                    <h6 class="fw-bold small mb-2"><?= htmlspecialchars(t('company')) ?></h6>
                    <a href="customer_home.php" class="footer-link d-block small mb-1"><?= htmlspecialchars(t('home')) ?></a>
                    <a href="dashboard_cus.php" class="footer-link d-block small"><?= htmlspecialchars(t('dashboard')) ?></a>
                </div>
                <div class="col-6 col-md-3">
                    <h6 class="fw-bold small mb-2"><?= htmlspecialchars(t('bookings')) ?></h6>
                    <a href="new_booking.php" class="footer-link d-block small mb-1"><?= htmlspecialchars(t('new_booking')) ?></a>
                    <a href="customer_tracking.php" class="footer-link d-block small"><?= htmlspecialchars(t('track_pickup')) ?></a>
                </div>
                <div class="col-6 col-md-2">
                    <h6 class="fw-bold small mb-2"><?= htmlspecialchars(t('information')) ?></h6>
                    <a href="live_pricing.php" class="footer-link d-block small"><?= htmlspecialchars(t('live_pricing')) ?></a>
                </div>
                <div class="col-6 col-md-2">
                    <h6 class="fw-bold small mb-2"><?= htmlspecialchars(t('account')) ?></h6>
                    <a href="profile.php" class="footer-link d-block small mb-1"><?= htmlspecialchars(t('my_profile')) ?></a>
                    <a href="logout.php" class="footer-link d-block small"><?= htmlspecialchars(t('logout')) ?></a>
                </div>
                <div class="col-12 col-md-2 footer-contact">
                    <h6 class="fw-bold small mb-3"><?= htmlspecialchars(t('get_in_touch')) ?></h6>
                    <?php $whatsappLink = 'https://wa.me/60123012137?text=' . rawurlencode('Hi Recyclon, I would like to enquire about my waste collection booking.'); ?>
                    <a href="<?= htmlspecialchars($whatsappLink) ?>" target="_blank" rel="noopener" class="footer-whatsapp-btn">
                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                            <path d="M12.01 2C6.48 2 2 6.48 2 12.01c0 1.99.58 3.85 1.58 5.42L2 22l4.7-1.53a9.94 9.94 0 0 0 5.31 1.53c5.53 0 10.01-4.48 10.01-10.01S17.54 2 12.01 2Zm5.85 14.28c-.24.68-1.39 1.3-1.92 1.35-.49.05-1.02.24-3.4-.72-2.88-1.16-4.73-4.05-4.87-4.24-.14-.19-1.16-1.55-1.16-2.96 0-1.4.73-2.09 1-2.38.24-.26.55-.34.73-.34.19 0 .37 0 .53.01.17.01.4-.06.62.48.24.58.81 2 .88 2.14.07.14.12.31.02.5-.1.19-.15.31-.29.48-.14.17-.3.37-.43.5-.14.14-.29.29-.13.57.17.29.75 1.25 1.61 2.02 1.11.99 2.04 1.3 2.33 1.44.29.14.46.12.63-.07.17-.19.72-.84.92-1.13.19-.29.38-.24.63-.14.26.1 1.65.78 1.93.92.29.14.48.21.55.33.07.12.07.68-.17 1.36Z"/>
                        </svg>
                        <span><?= htmlspecialchars(t('whatsapp_contact')) ?></span>
                    </a>
                </div>
            </div>
            <hr class="my-3">
        <?php endif; ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
            <p class="mb-0 text-muted small"><?= htmlspecialchars(t('copyright')) ?></p>
            <div class="d-flex gap-3 text-muted small">
                <span><?= htmlspecialchars(t('waste_management_system')) ?></span>
                <span class="text-secondary">&bull;</span>
                <span><?= htmlspecialchars(t('final_year_project')) ?></span>
            </div>
        </div>
    </div>
</footer>

<style>
    .footer-whatsapp-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        min-height: 34px;
        border-radius: 8px;
        background: #25D366;
        color: #ffffff;
        text-decoration: none;
        font-weight: 600;
        font-size: 13px;
        line-height: 1;
        position: relative;
        overflow: hidden;
        transition: background 0.2s ease;
    }

    .footer-whatsapp-btn svg {
        display: block;
        width: 17px;
        height: 17px;
        fill: #ffffff;
        flex-shrink: 0;
    }

    .footer-whatsapp-btn span {
        display: block;
        color: #ffffff;
        line-height: 17px;
    }

    .footer-whatsapp-btn::after {

        content: '';

        position: absolute;

        left: 16px;

        right: 16px;

        bottom: 6px;

        height: 1px;

        background: #ffffff;

        transform: scaleX(0);

        transform-origin: left;

        transition: transform 0.25s ease;
    }

    .footer-whatsapp-btn:hover,
    .footer-whatsapp-btn:focus {

        background: #128C7E;

        color: #ffffff;
    }

    .footer-whatsapp-btn:hover::after,
    .footer-whatsapp-btn:focus::after {

        transform: scaleX(1);
    }

    .footer-link {
        color: #475569;
        text-decoration: none;
    }

    .footer-link:hover,
    .footer-link:focus {
        color: #0f766e;
        text-decoration: underline;
    }
</style>

</body>
</html>
