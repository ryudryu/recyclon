<?php
require_once __DIR__ . '/config/session.php';


// Revenue summary
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header('Location: auth/login.php');
    exit;
}

$page = basename(__FILE__);
include 'header.php';

function periodBreakdown($conn, $whereSql) {
    $totalValue = 0.0;
    $totalJobs = 0;
    $rows = [];
    try {
        // FIX: filter sale_items directly by which sales qualify for this period,
        // instead of joining sale_items unconditionally and only filtering the
        // sales table. Previously SUM(si.subtotal) summed ALL sale_items for a
        // category regardless of date, so daily/monthly/yearly totals were identical.
        $stmt = $conn->query(
            "SELECT wc.category_name,
                    COUNT(DISTINCT si.sale_id) AS jobs,
                    COALESCE(SUM(si.subtotal), 0) AS value,
                    COALESCE(SUM(si.weight_kg), 0) AS weight
             FROM waste_categories wc
             LEFT JOIN sale_items si
                    ON si.waste_id = wc.waste_id
                   AND si.sale_id IN (
                        SELECT s.sale_id
                        FROM sales s
                        INNER JOIN booking b ON b.booking_id = s.booking_id
                        WHERE s.payment_status = 'Paid'
                          AND b.status = 'Completed' $whereSql
                   )
             GROUP BY wc.waste_id, wc.category_name
             ORDER BY wc.waste_id ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $totalValue += (float) $r['value']; }

        $jobsStmt = $conn->query(
            "SELECT COUNT(DISTINCT s.sale_id)
             FROM sales s
             INNER JOIN booking b ON b.booking_id = s.booking_id
             WHERE s.payment_status = 'Paid'
               AND b.status = 'Completed' $whereSql"
        );
        $totalJobs = (int) $jobsStmt->fetchColumn();
    } catch (PDOException $e) {
        $rows = [];
    }
    return ['rows' => $rows, 'total' => $totalValue, 'jobs' => $totalJobs];
}

$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear = max(2000, min($currentYear, $selectedYear));
$selectedMonth = max(1, min(12, $selectedMonth));
$monthStart = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$displayMonth = date('F Y', strtotime($monthStart));

$today = periodBreakdown($conn, "AND s.sale_date::date = CURRENT_DATE");
$month = periodBreakdown($conn, "AND YEAR(s.sale_date) = $selectedYear AND MONTH(s.sale_date) = $selectedMonth");
$year = periodBreakdown($conn, "AND YEAR(s.sale_date) = $selectedYear");

try {
    $activeDaysThisMonth = (int) $conn->query("SELECT COUNT(DISTINCT DATE(s.sale_date))
        FROM sales s
        INNER JOIN booking b ON b.booking_id = s.booking_id
        WHERE s.payment_status = 'Paid'
          AND b.status = 'Completed'
          AND YEAR(s.sale_date)=$selectedYear
          AND MONTH(s.sale_date)=$selectedMonth")->fetchColumn();
} catch (PDOException $e) {
    $activeDaysThisMonth = 0;
}
$avgPerDay = $activeDaysThisMonth > 0 ? $month['total'] / $activeDaysThisMonth : 0;

$trendEndExclusive = $monthEnd;
if ($selectedYear === $currentYear && $selectedMonth === (int)date('n')) {
    $trendEndExclusive = date('Y-m-d', strtotime('tomorrow'));
}
$trendEndDate = date('Y-m-d', strtotime($trendEndExclusive . ' -1 day'));
$trendStartDate = max($monthStart, date('Y-m-d', strtotime($trendEndDate . ' -6 days')));

// ===== Selected-month revenue trend (for the line chart) =====
try {
    $trendStmt = $conn->query(
        "SELECT DATE(s.sale_date) AS d, SUM(s.total_amount) AS total
         FROM sales s
         INNER JOIN booking b ON b.booking_id = s.booking_id
         WHERE s.payment_status = 'Paid'
           AND b.status = 'Completed'
           AND s.sale_date >= '{$trendStartDate} 00:00:00'
           AND s.sale_date < '{$trendEndExclusive} 00:00:00'
         GROUP BY DATE(s.sale_date)
         ORDER BY d ASC"
    );
    $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trendRows = [];
}

$trendMap = [];
foreach ($trendRows as $r) {
    $trendMap[$r['d']] = (float) $r['total'];
}

$trendLabels = [];
$trendValues = [];
for ($timestamp = strtotime($trendStartDate); $timestamp <= strtotime($trendEndDate); $timestamp += 86400) {
    $date = date('Y-m-d', $timestamp);
    $trendLabels[] = date('j M', strtotime($date));
    $trendValues[] = round($trendMap[$date] ?? 0, 2);
}

// ===== Category share for the year (for the doughnut chart) =====
$catLabels = [];
$catValues = [];
$catColors = [];
foreach ($year['rows'] as $row) {
    if ((float) $row['value'] > 0) {
        $meta = wasteMeta($row['category_name']);
        $catLabels[] = $row['category_name'];
        $catValues[] = round((float) $row['value'], 2);
        $catColors[] = $meta['bar'];
    }
}

function renderPeriodCard($title, $data, $note, $bg) {
    echo '<div class="col-12 col-md-4"><div class="rev-period-card" style="background:' . $bg . ';">';
    echo '<div class="rev-period-header"><div><div class="rev-period-label">' . htmlspecialchars($title) . '</div><div class="rev-period-value">RM ' . number_format($data['total'], 2) . '</div></div>';
    echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-opacity="0.35" stroke-width="1.6"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 3v3M16 3v3"/></svg></div>';
    echo '<div class="text-muted small mb-3">' . htmlspecialchars($note) . '</div>';

    foreach ($data['rows'] as $row) {
        $meta = wasteMeta($row['category_name']);
        $pct = $data['total'] > 0 ? round(((float) $row['value'] / $data['total']) * 100) : 0;
        echo '<div class="rev-cat-row">';
        echo '<div class="rev-cat-top"><span>' . $meta['icon'] . ' ' . htmlspecialchars($row['category_name']) . ' (' . (int) $row['jobs'] . ' ' . ((int) $row['jobs'] === 1 ? 'job' : 'jobs') . ')</span><span>RM ' . number_format($row['value'], 2) . ' <span class="text-muted">' . $pct . '%</span></span></div>';
        echo '<div class="rev-cat-track"><div class="rev-cat-fill" style="width:' . $pct . '%; background:' . $meta['bar'] . ';"></div></div>';
        if ((float) $row['weight'] > 0) {
            echo '<div class="rev-cat-note">' . number_format($row['weight'], 1) . ' kg collected</div>';
        }
        echo '</div>';
    }
    if (empty($data['rows']) || $data['total'] == 0) {
        echo '<div class="text-muted small mt-2">No completed jobs this period</div>';
    }
    echo '</div></div>';
}
?>

<div class="page-body">
    <?php
 include 'sidebar.php'; ?>

    <section class="content-panel">
        <div class="page-title mb-4">
            <h1 class="display-6 fw-bold">Revenue Summary</h1>
            <p class="text-secondary mb-0">Based on all completed bookings · Prices applied at current live rates</p>
        </div>

        <form method="get" class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div>
                    <label class="form-label fw-bold mb-1" for="revenueMonth">Month</label>
                    <select class="form-select" id="revenueMonth" name="month">
                        <?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?>
                            <option value="<?= $monthNumber ?>" <?= $selectedMonth === $monthNumber ? 'selected' : '' ?>><?= htmlspecialchars(date('F', mktime(0, 0, 0, $monthNumber, 1))) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold mb-1" for="revenueYear">Year</label>
                    <select class="form-select" id="revenueYear" name="year">
                        <?php for ($yearNumber = $currentYear; $yearNumber >= 2000; $yearNumber--): ?>
                            <option value="<?= $yearNumber ?>" <?= $selectedYear === $yearNumber ? 'selected' : '' ?>><?= $yearNumber ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Apply Period</button>
                <span class="text-muted small">Showing <?= htmlspecialchars($displayMonth) ?></span>
            </div>
        </form>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="rev-top-card" style="background:#d7f6ff;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="rev-top-label" style="color:#1d4ed8;">TODAY</div>
                        <span class="metric-dot" style="position:static; background:#1d4ed8;"></span>
                    </div>
                    <div class="rev-top-value" style="color:#1d4ed8;">RM <?php
 echo number_format($today['total'], 2); ?></div>
                    <div class="rev-top-note"><?php
 echo $today['jobs']; ?> completed jobs</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="rev-top-card" style="background:#eafff1;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="rev-top-label" style="color:#16a34a;">SELECTED MONTH</div>
                        <span class="metric-dot" style="position:static; background:#16a34a;"></span>
                    </div>
                    <div class="rev-top-value" style="color:#16a34a;">RM <?php
 echo number_format($month['total'], 2); ?></div>
                    <div class="rev-top-note">Avg RM <?php
 echo number_format($avgPerDay, 2); ?>/day</div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="rev-top-card" style="background:#fff8ea;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="rev-top-label" style="color:#d97706;">SELECTED YEAR</div>
                        <span class="metric-dot" style="position:static; background:#d97706;"></span>
                    </div>
                    <div class="rev-top-value" style="color:#d97706;">RM <?php
 echo number_format($year['total'], 2); ?></div>
                    <div class="rev-top-note"><?php
 echo $year['jobs']; ?> total jobs</div>
                </div>
            </div>
        </div>

        <!-- ===== Sales Graph Analysis ===== -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0">Revenue Trend — <?= htmlspecialchars($displayMonth) ?></h6>
                            <span class="badge bg-light text-dark" style="font-size:0.75rem;">RM per day</span>
                        </div>
                        <?php
 if (array_sum($trendValues) > 0): ?>
                            <canvas id="revenueTrendChart" height="120"></canvas>
                        <?php
 else: ?>
                            <div class="text-muted small py-5 text-center">No paid sales in <?= htmlspecialchars($displayMonth) ?>.</div>
                        <?php
 endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0">Category Share — <?php
 echo $selectedYear; ?></h6>
                        </div>
                        <?php
 if (!empty($catValues)): ?>
                            <canvas id="categoryShareChart" height="220"></canvas>
                        <?php
 else: ?>
                            <div class="text-muted small py-5 text-center">No completed jobs in <?= htmlspecialchars($selectedYear) ?>.</div>
                        <?php
 endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <?php

            renderPeriodCard('DAILY — ' . date('j M Y'), $today, $today['jobs'] . ' completed · ' . count(array_filter($today['rows'], fn($r) => $r['jobs'] > 0)) . ' categories', '#d7f6ff');
            renderPeriodCard('MONTHLY — ' . $displayMonth, $month, $month['jobs'] . ' completed · ' . $activeDaysThisMonth . ' active days', '#eafff1');
            renderPeriodCard('YEARLY — ' . $selectedYear, $year, $year['jobs'] . ' completed · all categories', '#fff8ea');
            ?>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
    const trendLabels = <?php
 echo json_encode($trendLabels); ?>;
    const trendValues = <?php
 echo json_encode($trendValues); ?>;
    const catLabels = <?php
 echo json_encode($catLabels); ?>;
    const catValues = <?php
 echo json_encode($catValues); ?>;
    const catColors = <?php
 echo json_encode($catColors); ?>;

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[character]));

    function renderTrendFallback(canvas) {
        if (!canvas) return;
        const width = 720;
        const height = 260;
        const left = 46;
        const right = 16;
        const top = 16;
        const bottom = 36;
        const max = Math.max(...trendValues, 1);
        const xStep = (width - left - right) / Math.max(trendValues.length - 1, 1);
        const y = value => height - bottom - ((Number(value) / max) * (height - top - bottom));
        const points = trendValues.map((value, index) => `${left + index * xStep},${y(value)}`).join(' ');
        const grid = [0, .25, .5, .75, 1].map(ratio => {
            const lineY = height - bottom - ratio * (height - top - bottom);
            return `<line x1="${left}" y1="${lineY}" x2="${width - right}" y2="${lineY}" stroke="#e5eaf2" stroke-width="1" />`;
        }).join('');
        const labels = trendLabels.map((label, index) =>
            `<text x="${left + index * xStep}" y="${height - 10}" text-anchor="middle" fill="#64748b" font-size="11">${escapeHtml(label)}</text>`
        ).join('');
        const dots = trendValues.map((value, index) =>
            `<circle cx="${left + index * xStep}" cy="${y(value)}" r="4" fill="#1d4ed8" />`
        ).join('');
        canvas.outerHTML = `<svg class="chart-fallback-trend" viewBox="0 0 ${width} ${height}" role="img" aria-label="Revenue trend for the last seven days">${grid}<polyline points="${points}" fill="none" stroke="#1d4ed8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />${dots}${labels}</svg>`;
    }

    function renderCategoryFallback(canvas) {
        if (!canvas) return;
        const total = catValues.reduce((sum, value) => sum + Number(value), 0);
        const rows = catLabels.map((label, index) => {
            const value = Number(catValues[index] || 0);
            const pct = total > 0 ? (value / total) * 100 : 0;
            return `<div class="chart-fallback-category-row"><div class="chart-fallback-category-label"><span>${escapeHtml(label)}</span><span>RM ${value.toFixed(2)} (${pct.toFixed(1)}%)</span></div><div class="chart-fallback-category-track"><div class="chart-fallback-category-fill" style="width:${pct}%;background:${catColors[index] || '#1d4ed8'};"></div></div></div>`;
        }).join('');
        canvas.outerHTML = `<div class="chart-fallback-category" role="img" aria-label="Revenue category share">${rows}</div>`;
    }

    const trendCanvas = document.getElementById('revenueTrendChart');
    const categoryCanvas = document.getElementById('categoryShareChart');
    if (typeof Chart !== 'function') {
        renderTrendFallback(trendCanvas);
        renderCategoryFallback(categoryCanvas);
        return;
    }

    try {
    if (trendCanvas && trendValues.some(v => v > 0)) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Revenue (RM)',
                    data: trendValues,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29, 78, 216, 0.1)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 3,
                    pointBackgroundColor: '#1d4ed8',
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return 'RM ' + ctx.parsed.y.toFixed(2); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function (v) { return 'RM ' + v; } },
                        grid: { color: '#f1f5f9' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    if (categoryCanvas && catValues.length > 0) {
        new Chart(categoryCanvas, {
            type: 'doughnut',
            data: {
                labels: catLabels,
                datasets: [{
                    data: catValues,
                    backgroundColor: catColors,
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const total = catValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': RM ' + ctx.parsed.toFixed(2) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    } catch (chartError) {
        renderTrendFallback(document.getElementById('revenueTrendChart'));
        renderCategoryFallback(document.getElementById('categoryShareChart'));
    }
})();
</script>

<?php
 include 'footer.php'; ?>
