<?php
require_once __DIR__ . '/config/session.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit();
}

if ($_SESSION['role'] !== 'Customer') {
    header('Location: auth/login.php');
    exit();
}

$page = 'customer_home.php';
require_once __DIR__ . '/config/db.php';

$materials = [];
try {
    $materials = $conn->query(
        "SELECT category_name
         FROM waste_categories
         WHERE status = 'Active'
         ORDER BY waste_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Keep the informational page available when categories are unavailable.
}

include 'header.php';
?>

<style>
    .customer-home {
        --home-green: #166534;
        --home-green-dark: #0d4f38;
        --home-soft: #f0fdf4;
        --home-border: #dcfce7;
        color: #17352a;
    }

    .customer-home .home-hero {
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(220px, 320px);
        align-items: center;
        gap: clamp(1.5rem, 5vw, 5rem);
        min-height: 390px;
        border-radius: 28px;
        padding: clamp(2rem, 5vw, 4.5rem);
        background: linear-gradient(115deg, #0d4f38 0%, #166534 58%, #238b5b 100%);
        color: #fff;
        box-shadow: 0 18px 40px rgba(13, 79, 56, .18);
    }

    .customer-home .home-hero::after {
        content: '';
        position: absolute;
        width: 390px;
        height: 390px;
        right: -90px;
        top: -90px;
        border: 1px solid rgba(255,255,255,.18);
        border-radius: 50%;
        box-shadow: 0 0 0 35px rgba(255,255,255,.05), 0 0 0 70px rgba(255,255,255,.04);
        pointer-events: none;
    }

    .home-hero-copy { position: relative; z-index: 1; max-width: 650px; }
    .home-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        color: #bbf7d0;
        font-size: .78rem;
        font-weight: 800;
        letter-spacing: .13em;
        text-transform: uppercase;
    }
    .home-hero h1 {
        max-width: 680px;
        margin: 1rem 0;
        font-size: clamp(2.35rem, 5vw, 4.45rem);
        line-height: 1.02;
        letter-spacing: -.04em;
    }
    .home-hero p { max-width: 560px; color: #dcfce7; font-size: 1.08rem; line-height: 1.7; }
    .home-actions { position: relative; z-index: 1; display: flex; justify-content: center; align-items: center; }
    .home-actions .btn { width: min(100%, 255px); min-height: 72px; padding: 1rem 1.5rem; border-radius: 15px; font-size: 1.08rem; font-weight: 800; white-space: nowrap; box-shadow: 0 12px 24px rgba(13, 79, 56, .22); }
    .btn-home-primary { background: #facc15; border-color: #facc15; color: #17352a; }
    .btn-home-primary:hover, .btn-home-primary:focus { background: #fde047; border-color: #fde047; color: #17352a; }
    .btn-home-quiet { border: 1px solid rgba(255,255,255,.55); color: #fff; }
    .btn-home-quiet:hover, .btn-home-quiet:focus { background: rgba(255,255,255,.12); border-color: #fff; color: #fff; }

    .home-section { padding-top: clamp(3.5rem, 7vw, 6rem); }
    .home-section-heading { max-width: 620px; margin-bottom: 1.75rem; }
    .home-section-heading h2 { margin: .5rem 0; color: #163f2d; font-size: clamp(1.8rem, 3vw, 2.55rem); letter-spacing: -.03em; }
    .home-section-heading p { color: #64756c; line-height: 1.65; }
    .home-kicker { color: #15803d; font-size: .76rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }

    .home-step, .home-benefit {
        height: 100%;
        border: 1px solid var(--home-border);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 8px 22px rgba(21, 128, 61, .06);
    }
    .home-step { padding: 1.4rem; }
    .home-step-number {
        display: inline-grid;
        width: 42px;
        height: 42px;
        place-items: center;
        margin-bottom: 1.1rem;
        border-radius: 13px;
        background: #dcfce7;
        color: #166534;
        font-weight: 800;
    }
    .home-step h3, .home-benefit h3 { font-size: 1.06rem; color: #214b37; }
    .home-step p, .home-benefit p { margin: 0; color: #65766d; font-size: .93rem; line-height: 1.6; }
    .home-icon { width: 26px; height: 26px; color: #15803d; }

    .home-materials { padding: 1.35rem; border-radius: 22px; background: var(--home-soft); }
    .home-material-chip { display: inline-flex; align-items: center; gap: .65rem; padding: .8rem 1rem; border: 1px solid var(--home-border); border-radius: 14px; background: #fff; color: #24533b; font-weight: 700; }
    .home-material-dot { display: inline-grid; width: 30px; height: 30px; place-items: center; border-radius: 10px; background: #dcfce7; color: #15803d; }

    .home-impact { overflow: hidden; border-radius: 24px; background: #e9f9ef; }
    .home-impact-copy { padding: clamp(1.75rem, 4vw, 3.25rem); }
    .home-impact-copy p { max-width: 560px; color: #527061; line-height: 1.7; }
    .home-impact-art { min-height: 260px; display: grid; place-items: center; background: #c9f1d6; color: #166534; }
    .home-impact-art svg { width: min(70%, 260px); height: auto; }
    .home-benefit { padding: 1.35rem; }
    .home-benefit-icon { display: inline-grid; width: 42px; height: 42px; place-items: center; margin-bottom: 1rem; border-radius: 13px; background: #fef3c7; color: #a16207; }
    .home-final { margin-top: clamp(3.5rem, 7vw, 6rem); padding: clamp(2rem, 5vw, 3.5rem); border-radius: 24px; background: #166534; color: #fff; }
    .home-final h2 { font-size: clamp(1.8rem, 3vw, 2.6rem); letter-spacing: -.03em; }
    .home-final p { color: #dcfce7; }
    @media (max-width: 575.98px) {
        .customer-home .home-hero { grid-template-columns: 1fr; gap: 1rem; border-radius: 20px; }
        .home-hero h1 { font-size: 2.45rem; }
        .home-actions { justify-content: flex-start; }
        .home-actions .btn { width: min(100%, 255px); }
    }
</style>

<div class="page-body">
    <?php include 'sidebar.php'; ?>

    <section class="content-panel px-3 px-md-4 py-4 customer-home">
        <div class="home-hero">
            <div class="home-hero-copy">
                <span class="home-eyebrow">
                    <svg class="home-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 4c-7 .3-12.2 3-13.8 7.1C4.9 14.5 7.4 18 11 18c4.5 0 7.8-5.5 9-14Z"/><path d="M4 21c2.2-4.6 5.8-7.1 11-8.5"/></svg>
                    Recycle smarter with Recyclon
                </span>
                <h1>Turn everyday recycling into a better tomorrow.</h1>
                <p>We make it simple to pass on your recyclable materials. Schedule a pickup, follow its progress, and see today’s rates in one place.</p>
            </div>
            <div class="home-actions">
                <a class="btn btn-home-primary" href="new_booking.php">Schedule a Pickup</a>
            </div>
        </div>

        <section class="home-section" aria-labelledby="how-it-works-title">
            <div class="home-section-heading">
                <span class="home-kicker">Simple from start to finish</span>
                <h2 id="how-it-works-title">How pickup works</h2>
                <p>Three clear steps help your recyclable materials move from your home to their next useful life.</p>
            </div>
            <div class="row row-cols-1 row-cols-md-3 g-3">
                <div class="col"><article class="home-step"><span class="home-step-number">01</span><h3>Separate your materials</h3><p>Sort the recyclable items you want Recyclon to collect and keep them ready for pickup.</p></article></div>
                <div class="col"><article class="home-step"><span class="home-step-number">02</span><h3>Schedule a pickup</h3><p>Choose a convenient date and share your pickup details through a quick booking.</p></article></div>
                <div class="col"><article class="home-step"><span class="home-step-number">03</span><h3>We collect and process</h3><p>Our team collects your materials so they can be sorted and processed responsibly.</p></article></div>
            </div>
        </section>

        <section class="home-section" aria-labelledby="materials-title">
            <div class="home-section-heading">
                <span class="home-kicker">What you can pass on</span>
                <h2 id="materials-title">Recyclable materials</h2>
                <p>Accepted materials are based on the categories currently configured by Recyclon.</p>
            </div>
            <div class="home-materials">
                <div class="d-flex flex-wrap gap-2">
                    <?php if (!empty($materials)): ?>
                        <?php foreach ($materials as $material): ?>
                            <span class="home-material-chip">
                                <span class="home-material-dot" aria-hidden="true">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 3-2 3 2"/><path d="M12 3v6"/><path d="m19 9 2 3-2 3"/><path d="M21 12h-6"/><path d="m5 15-2-3 2-3"/><path d="M3 12h6"/></svg>
                                </span>
                                <?= htmlspecialchars($material['category_name']) ?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="mb-0 text-muted">No recyclable material categories are available right now.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="home-section" aria-labelledby="why-title">
            <div class="home-section-heading">
                <span class="home-kicker">A better way to recycle</span>
                <h2 id="why-title">Why Recyclon</h2>
            </div>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3">
                <?php
                $benefits = [
                    ['title' => 'Convenient pickups', 'text' => 'Choose a pickup that fits your routine.', 'icon' => '<path d="M7 3v4M17 3v4M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/>'],
                    ['title' => 'Clear prices', 'text' => 'See current material rates before booking.', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v12M15 9.5c0-1.5-1.3-2.5-3-2.5s-3 1-3 2.3c0 3 6 1.3 6 4.3 0 1.4-1.3 2.4-3 2.4s-3-1-3-2.5"/>'],
                    ['title' => 'Pickup tracking', 'text' => 'Follow your collection progress with confidence.', 'icon' => '<path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.3"/>'],
                    ['title' => 'Easy management', 'text' => 'Keep your recycling activity in one place.', 'icon' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>'],
                    ['title' => 'Earn from materials', 'text' => 'Get value from items ready to be recycled.', 'icon' => '<path d="M12 3v18M16 7.5c0-1.4-1.7-2.5-4-2.5S8 6.1 8 7.5c0 4 8 1.8 8 5.5 0 1.4-1.7 2.5-4 2.5s-4-1.1-4-2.5"/>'],
                ];
                foreach ($benefits as $benefit): ?>
                    <div class="col"><article class="home-benefit"><span class="home-benefit-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $benefit['icon'] ?></svg></span><h3><?= htmlspecialchars($benefit['title']) ?></h3><p><?= htmlspecialchars($benefit['text']) ?></p></article></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="home-section" aria-labelledby="impact-title">
            <div class="home-impact row g-0 align-items-stretch">
                <div class="col-lg-7 home-impact-copy">
                    <span class="home-kicker">Small actions, meaningful change</span>
                    <h2 id="impact-title" class="mt-2">Recycling keeps useful materials in motion.</h2>
                    <p>When materials are separated and collected thoughtfully, they have a better chance of being sorted, processed, and used again. Your next pickup is a simple step toward a cleaner everyday routine.</p>
                </div>
                <div class="col-lg-5 home-impact-art" aria-hidden="true">
                    <svg viewBox="0 0 260 220" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m131 24 26 45-18 2c-2 19 6 34 22 45"/>
                        <path d="m131 24-26 45 18 2c2 19-6 34-22 45"/>
                        <path d="M84 116c-18 6-31 20-37 39"/>
                        <path d="m47 155 42-1-9 16"/>
                        <path d="m47 155 12 38 10-15"/>
                        <path d="M176 116c18 6 31 20 37 39"/>
                        <path d="m213 155-42-1 9 16"/>
                        <path d="m213 155-12 38-10-15"/>
                        <circle cx="130" cy="112" r="27" fill="#e9f9ef"/>
                        <path d="M118 112h24M130 100v24"/>
                    </svg>
                </div>
            </div>
        </section>

    </section>
</div>

<?php include 'footer.php'; ?>
