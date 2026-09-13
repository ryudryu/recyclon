<?php
$page = basename(__FILE__);

// Standardized includes using __DIR__ pointing to the root directory
$page = basename(__FILE__);
$base_url = '../';
include_once __DIR__ . '/../config/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ../auth/login.php');
    exit;
}
include_once __DIR__ . '/../header.php';


$message = '';
$errors = [];
$csrfToken = ensure_csrf_token();

$form = [
    'category_name' => '',
    'unit_price'    => '',
    'unit'          => 'kg',
    'status'        => 'Active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token()) {
    $form['category_name'] = trim($_POST['category_name'] ?? '');
    $form['unit_price']    = trim($_POST['unit_price'] ?? '');
    $form['unit']          = trim($_POST['unit'] ?? 'kg');
    $form['status']        = trim($_POST['status'] ?? 'Active');

    // Form Validation
    if ($form['category_name'] === '') {
        $errors[] = 'Please enter a category name.';
    }

    if ($form['unit_price'] === '' || !is_numeric($form['unit_price']) || (float)$form['unit_price'] < 0) {
        $errors[] = 'Please enter a valid non-negative unit price.';
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO waste_categories (category_name, unit_price, unit, status) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $form['category_name'],
                (float) $form['unit_price'],
                $form['unit'],
                $form['status']
            ]);

            $newId = $conn->lastInsertId();
            $message = "Waste category '{$form['category_name']}' added successfully! (ID: #{$newId})";

            // Reset form fields after successful insert
            $form = ['category_name' => '', 'unit_price' => '', 'unit' => 'kg', 'status' => 'Active'];
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errors[] = 'A category with this name already exists.';
            } else {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="page-body">

    <link rel="stylesheet" href="../assets/css/style.css">

    <section class="content-panel">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="page-title">
                <h1 class="display-6 fw-bold mb-0">Add Waste Category</h1>
                <p class="text-secondary mb-0">Create new pricing items for the calculator and live pricing list</p>
            </div>
            <a href="../calculator.php" class="btn btn-outline-secondary">Back to Calculator</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mb-4">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success mb-4"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="form-section-title fw-bold text-uppercase mb-3" style="color: #1e3a8a; letter-spacing: 0.5px; font-size: 0.85rem;">
                        Category Details
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category Name *</label>
                            <input type="text" class="form-control" name="category_name" value="<?php echo htmlspecialchars($form['category_name']); ?>" placeholder="e.g. Copper Wire, Cardboard, PET Plastics" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unit Price (RM) *</label>
                            <div class="input-group">
                                <span class="input-group-text">RM</span>
                                <input type="number" step="0.01" min="0" class="form-control" name="unit_price" value="<?php echo htmlspecialchars($form['unit_price']); ?>" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unit Type</label>
                            <input type="text" class="form-control" name="unit" value="<?php echo htmlspecialchars($form['unit']); ?>" placeholder="kg, pcs, unit">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo ($form['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($form['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success fw-bold px-4 py-2">Save Category</button>
                        <a href="../calculator.php" class="btn btn-light border px-4 py-2">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>

<?php include_once __DIR__ . '/../footer.php'; ?>
