<?php
require_once __DIR__ . '/config/session.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff', 'Customer'], true)) {
    header('Location: auth/login.php');
    exit;
}
$page = basename(__FILE__);
include 'header.php';

$priceCategories = [];
$activityLog = [];
$message = '';
$errors = [];
$isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['Admin', 'Staff'], true);
$csrfToken = ensure_csrf_token();

try {
    $priceCategories = $conn->query("SELECT waste_id, category_name, unit_price, unit, status FROM waste_categories ORDER BY waste_id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Unable to load pricing data.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token()) {
    if (!$isAdmin) {
        $errors[] = 'You are not authorized to update prices.';
    } else {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $newPrice = trim($_POST['new_price'] ?? '');
        $newStatus = trim($_POST['status'] ?? 'Active');
        $newUnit = trim($_POST['unit'] ?? 'kg');

        if ($categoryId <= 0) {
            $errors[] = 'Please select a category.';
        } elseif ($newPrice === '' || !is_numeric($newPrice)) {
            $errors[] = 'Please enter a valid price.';
        } else {
            try {
                $conn->beginTransaction();
                $old = $conn->prepare("SELECT unit_price FROM waste_categories WHERE waste_id = ?");
                $old->execute([$categoryId]);
                $oldPrice = (float) $old->fetchColumn();

                $newPriceFloat = (float) $newPrice;

                // Update current price
                $stmt = $conn->prepare("UPDATE waste_categories SET unit_price = ?, status = ?, unit = ?
                WHERE waste_id = ?");

                $stmt->execute([$newPriceFloat,$newStatus,$newUnit,$categoryId
                ]);

                // Only create price history when the price actually changes
                if ($newPriceFloat != $oldPrice) {
                    $log = $conn->prepare("INSERT INTO price_history (waste_id, old_price, new_price) VALUES (?, ?, ?)");

                    $log->execute([
                        $categoryId,
                        $oldPrice,
                        $newPriceFloat
                    ]);
                }
                $conn->commit();
                $message = 'Price updated successfully.';
                $priceCategories = $conn->query("SELECT waste_id, category_name, unit_price, unit, status FROM waste_categories ORDER BY waste_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $conn->rollBack();
                $errors[] = 'The price could not be updated.';
            }
        }
    }
}

try {
   $activityLog = $conn->query(
    "SELECT ph.old_price, ph.new_price, ph.changed_at, wc.category_name
     FROM price_history ph
     JOIN waste_categories wc ON wc.waste_id = ph.waste_id
     WHERE ph.old_price <> ph.new_price
     ORDER BY ph.changed_at DESC
     LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activityLog = [];
}
?>

<div class="page-body">
    <?php include 'sidebar.php'; ?>

    <section class="content-panel">
        <div class="page-title mb-4">
            <h1 class="display-6 fw-bold">Market Price</h1>
            <p class="text-secondary mb-0">Prices auto-update as the admin overrides them below.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success mb-3"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mb-3">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Admin Override</h6>
                    <form method="post" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category_id" required>
                                <option value="">Choose category</option>
                                <?php foreach ($priceCategories as $category): ?>
                                    <option value="<?php echo $category['waste_id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Price</label>
                            <input type="number" step="0.01" class="form-control" name="new_price" placeholder="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Unit</label>
                            <input type="text" class="form-control" name="unit" value="kg">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="price-grid mb-4">
            <?php foreach ($priceCategories as $category): if ($category['status'] !== 'Active') continue;
                $meta = wasteMeta($category['category_name']); ?>
                <div class="price-card">
                    <div class="price-card-top">
                        <div>
                            <div class="price-card-label"><?php echo htmlspecialchars($category['category_name']); ?></div>
                        </div>
                        <div class="price-card-icon" style="background: <?php echo $meta['bg']; ?>;"><?php echo $meta['icon']; ?></div>
                    </div>
                    <div class="price-card-value"><?php echo number_format($category['unit_price'], 2); ?></div>
                    <div class="price-card-unit">RM per <?php echo htmlspecialchars($category['unit']); ?></div>
                    <div class="price-card-footer">
                        <span>Status: <?php echo htmlspecialchars($category['status']); ?></span>
                        <span><?php echo date('H:i:s'); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold mb-2">Price Activity Log</h6>
                <?php if (!empty($activityLog)): ?>
                    <?php foreach ($activityLog as $entry):
                        $meta = wasteMeta($entry['category_name']);
                        $rising = $entry['new_price'] > $entry['old_price'];
                        $falling = $entry['new_price'] < $entry['old_price'];
                    ?>
                        <div class="activity-row">
                            <div>
                                <span class="activity-dot" style="background: <?php echo $meta['bar']; ?>;"></span>
                                <strong><?php echo htmlspecialchars($entry['category_name']); ?></strong>
                                <span class="text-muted ms-2">RM <?php echo number_format($entry['new_price'], 2); ?>/kg</span>
                                <?php if ($rising): ?>
                                    <span class="ms-2 fw-bold" style="color: #16a34a;">
                                        ▲ Rising
                                    </span>
                                <?php elseif ($falling): ?>
                                    <span class="ms-2 fw-bold" style="color: #dc2626;">
                                        ▼ Falling
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small">Updated <?php echo date('H:i:s', strtotime($entry['changed_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">No price changes logged yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include 'footer.php'; ?>
