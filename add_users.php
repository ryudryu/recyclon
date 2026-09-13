<?php
require_once __DIR__ . '/config/session.php';
if (session_status() === PHP_SESSION_NONE) session_start();


// header.php is loaded after the access check.
// header.php is assumed to already call session_start() and define $conn (mysqli)

/* ------------------------------------------------------------------
   ACCESS CONTROL — only a logged-in Admin can reach this page
------------------------------------------------------------------ */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header('Location: login.php');
    exit();
}

$defaultTempPassword = trim((string)($env['DEFAULT_TEMP_PASSWORD'] ?? ''));
$csrfToken = ensure_csrf_token();

$errors  = [];
$success = '';
$allowedRoles = ['Staff', 'Driver', 'Customer'];
if ($defaultTempPassword === '') {
    $errors[] = 'DEFAULT_TEMP_PASSWORD is not configured.';
}

include "header.php";

// keep entered values so the form re-fills after a validation error
$old = [
    'name'                => '',
    'email'               => '',
    'phone'               => '',
    'ic_number'           => '',
    'role'                => 'Customer',
    'address'             => '',
    'bank_name'           => '',
    'bank_account_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token()) {

    $old['name']                = trim($_POST['name'] ?? '');
    $old['email']               = trim($_POST['email'] ?? '');
    $old['phone']                = trim($_POST['phone'] ?? '');
    $old['ic_number']           = trim($_POST['ic_number'] ?? '');
    $old['role']                = $_POST['role'] ?? 'Customer';
    $old['address']             = trim($_POST['address'] ?? '');
    $old['bank_name']           = trim($_POST['bank_name'] ?? '');
    $old['bank_account_number'] = trim($_POST['bank_account_number'] ?? '');

    /* ---------------- validation ---------------- */
    if ($old['name'] === '') {
        $errors[] = 'Full name is required.';
    }

    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    if (!in_array($old['role'], $allowedRoles, true)) {
        $errors[] = 'Please select a valid role.';
    }

    // check for duplicate email
    if (empty($errors)) {
        $check = $conn->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$old['email']]);
        if ($check->rowCount() > 0) {
            $errors[] = 'This email is already registered.';
        }
    }

    /* ---------------- insert ---------------- */
    if (empty($errors)) {
        $hashed_password = password_hash($defaultTempPassword, PASSWORD_DEFAULT);

        // bank details are only relevant for staff/drivers who receive payroll,
        // so store NULL for customers instead of forcing empty strings
        $bank_name    = ($old['role'] === 'Customer' || $old['bank_name'] === '') ? null : $old['bank_name'];
        $bank_account = ($old['role'] === 'Customer' || $old['bank_account_number'] === '') ? null : $old['bank_account_number'];
        $phone        = $old['phone'] !== '' ? $old['phone'] : null;
        $ic_number    = $old['ic_number'] !== '' ? $old['ic_number'] : null;
        $address      = $old['address'] !== '' ? $old['address'] : null;

        // status and created_at are intentionally left off the form —
        // status defaults to 'Active' at the DB level, created_at is automatic
        $stmt = $conn->prepare(
            'INSERT INTO users (name, email, password, phone, ic_number, role, address, bank_name, bank_account_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $ic_number_enc = app_encrypt((string)($ic_number ?? ''), $encryptionKey);
        $bank_account_enc = app_encrypt((string)($bank_account ?? ''), $encryptionKey);

        $inserted = $stmt->execute([
            $old['name'],
            $old['email'],
            $hashed_password,
            $phone,
            $ic_number_enc,
            $old['role'],
            $address,
            $bank_name,
            $bank_account_enc,
        ]);

        if ($inserted) {
            $success = 'User "' . htmlspecialchars($old['name']) . '" was added successfully. '
                     . 'Temporary password: <strong>' . htmlspecialchars($defaultTempPassword) . '</strong> '
                     . '— please share this with the user and ask them to change it after logging in.';
            // reset the form after a successful insert
            foreach ($old as $key => $val) {
                $old[$key] = ($key === 'role') ? 'Customer' : '';
            }
        } else {
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}
?>

<div class="page-title mb-4 ms-4 mt-4">
    <h1 class="display-6">Add User</h1>
    <p class="text-secondary mb-0">Create a new Staff, Driver, or Customer account</p>
</div>

<?php
 if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php
 foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php
 endforeach; ?>
        </ul>
    </div>
<?php
 endif; ?>

<?php
 if ($success !== ''): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php
 endif; ?>

<form method="POST" action="add_users.php" class="card shadow-sm ms-4 me-4">
    <?= csrf_field() ?>
    <div class="card-body p-4">

        <!-- ACCOUNT INFORMATION -->
        <h6 class="text-uppercase text-primary fw-bold border-bottom pb-2 mb-3">Account Information</h6>

        <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required
                   value="<?= htmlspecialchars($old['name']) ?>" placeholder="Ahmad bin Hassan">
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" required
                       value="<?= htmlspecialchars($old['email']) ?>" placeholder="ahmad@example.com">
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control"
                       value="<?= htmlspecialchars($old['phone']) ?>" placeholder="+60 12-345 6789">
            </div>
        </div>

        <div class="alert alert-info py-2 mb-4">
            New accounts are created with the temporary password <strong>Recyclon123</strong>.
            The user should change it after their first login.
        </div>

        <!-- IDENTIFICATION & ROLE -->
        <h6 class="text-uppercase text-primary fw-bold border-bottom pb-2 mb-3">Identification &amp; Role</h6>

        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label">IC Number</label>
                <input type="text" name="ic_number" class="form-control"
                       value="<?= htmlspecialchars($old['ic_number']) ?>" placeholder="e.g. 990101-10-1234">
            </div>
            <div class="col-md-6">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-select" required>
                    <?php
 foreach ($allowedRoles as $r): ?>
                        <option value="<?= $r ?>" <?= $old['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                    <?php
 endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ADDRESS -->
        <h6 class="text-uppercase text-primary fw-bold border-bottom pb-2 mb-3">Address</h6>
        <div class="mb-4">
            <textarea name="address" class="form-control" rows="2"
                      placeholder="e.g. Jalan Pegawai, Alor Setar..."><?= htmlspecialchars($old['address']) ?></textarea>
        </div>

        <!-- PAYROLL DETAILS -->
        <h6 class="text-uppercase text-primary fw-bold border-bottom pb-2 mb-1">Payroll Details</h6>
        <p class="text-secondary small mb-3">For Staff &amp; Driver only — leave blank for Customer accounts.</p>

        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label">Bank Name</label>
                <input type="text" name="bank_name" class="form-control"
                       value="<?= htmlspecialchars($old['bank_name']) ?>" placeholder="e.g. Maybank">
            </div>
            <div class="col-md-6">
                <label class="form-label">Bank Account Number</label>
                <input type="text" name="bank_account_number" class="form-control"
                       value="<?= htmlspecialchars($old['bank_account_number']) ?>" placeholder="e.g. 1234567890">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="user_detail.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-success">Add User</button>
        </div>

    </div>
</form>

<?php
 include "footer.php"; ?>
