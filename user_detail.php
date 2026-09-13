<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

if ($_SESSION['role'] !== "Admin") {
    header("Location: index.php");
    exit();
}

$page = 'user_detail.php';
require_once __DIR__ . '/config/db.php';

$message = '';
$errors = [];
$csrfToken = ensure_csrf_token();

// Handle edit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user']) && verify_csrf_token()) {

    $editId = (int) ($_POST['user_id'] ?? 0);

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $icNumber = trim($_POST['ic_number'] ?? '');

    // NEW: Bank information
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');

    $address = trim($_POST['address'] ?? '');
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($editId <= 0 || $name === '') {

        $errors[] = 'Name is required.';

    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors[] = 'Invalid email format.';

    } else {

        // Prevent admin from deactivating/demoting themselves
        $isOwnAccount = ($editId === (int) $_SESSION['user_id']);

        try {

            $conn->beginTransaction();

            $updates = [];
            $params = [];

            // Name
            $updates[] = "name = ?";
            $params[] = $name;

            // Email
            $updates[] = "email = ?";
            $params[] = $email ?: null;

            // Phone
            $updates[] = "phone = ?";
            $params[] = $phone ?: null;

            // IC Number
            $updates[] = "ic_number = ?";
            $params[] = app_encrypt((string)($icNumber ?? ''), $encryptionKey) ?: null;

            // NEW: Bank Name
            $updates[] = "bank_name = ?";
            $params[] = $bankName ?: null;

            // NEW: Bank Account Number
            $updates[] = "bank_account_number = ?";
            $params[] = app_encrypt((string)($bankAccountNumber ?? ''), $encryptionKey) ?: null;

            // Address
            $updates[] = "address = ?";
            $params[] = $address ?: null;

            // Role and status
            if (!$isOwnAccount) {

                $validRoles = ['Admin', 'Staff', 'Driver', 'Customer'];

                if (in_array($role, $validRoles)) {
                    $updates[] = "role = ?";
                    $params[] = $role;
                }

                $validStatuses = ['Active', 'Inactive'];

                if (in_array($status, $validStatuses)) {
                    $updates[] = "status = ?";
                    $params[] = $status;
                }
            }

            // Password
            if ($newPassword !== '') {

                $updates[] = "password = ?";
                $params[] = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );
            }

            if (!empty($updates)) {

                $params[] = $editId;

                $sql = "UPDATE users SET "
                     . implode(", ", $updates)
                     . " WHERE user_id = ?";

                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $message = 'User updated successfully.';

            } else {

                $errors[] = 'No changes to save.';
            }

            $conn->commit();

        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $errors[] = 'Failed to update user: ' . $e->getMessage();
        }
    }
}


// Fetch users. The complete dataset is loaded before filtering so encrypted
// IC numbers can also be searched after decryption.
$allUsers = [];
$searchQuery = trim((string)($_GET['search'] ?? ''));
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$usersPerPage = 20;

try {

    $allUsers = $conn->query("
        SELECT
            user_id,
            name,
            email,
            phone,
            ic_number,
            bank_name,
            bank_account_number,
            role,
            address,
            status,
            created_at
        FROM users
        ORDER BY user_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allUsers as &$u) {
        $u['ic_number'] = app_decrypt((string)($u['ic_number'] ?? ''), $encryptionKey);
        $u['bank_account_number'] = app_decrypt((string)($u['bank_account_number'] ?? ''), $encryptionKey);
    }
    unset($u);

} catch (PDOException $e) {

    // Fail silently
}


$filteredUsers = $allUsers;
if ($searchQuery !== '') {
    $filteredUsers = array_values(array_filter($allUsers, function (array $user) use ($searchQuery): bool {
        foreach ([
            $user['name'] ?? '',
            $user['email'] ?? '',
            $user['phone'] ?? '',
            $user['ic_number'] ?? '',
            $user['role'] ?? '',
        ] as $value) {
            if (stripos((string)$value, $searchQuery) !== false) {
                return true;
            }
        }
        return false;
    }));
}

$totalResults = count($filteredUsers);
$totalPages = max(1, (int)ceil($totalResults / $usersPerPage));
$currentPage = min($currentPage, $totalPages);
$users = array_slice($filteredUsers, ($currentPage - 1) * $usersPerPage, $usersPerPage);


// Count by role
$roleCounts = [
    'Admin' => 0,
    'Staff' => 0,
    'Driver' => 0,
    'Customer' => 0
];

foreach ($allUsers as $u) {

    $r = $u['role'];

    if (isset($roleCounts[$r])) {
        $roleCounts[$r]++;
    }
}

$totalUsers = count($allUsers);

?>

<?php include "header.php"; ?>

<div class="page-body">

    <?php
 include "sidebar.php"; ?>

    <section class="content-panel px-4 py-4">

        <!-- Page Title -->
        <div class="page-title mb-4">

            <h1 class="display-6">
                User Details
            </h1>

            <p class="text-secondary mb-0">
                Manage all users and roles
            </p>
            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mt-3">
                <a class="btn btn-primary" href="add_users.php">Add New User</a>
                <div class="position-relative" style="max-width: 360px; width: 100%;">
                    <label class="visually-hidden" for="userSearch">Search users</label>
                    <input
                        type="search"
                        id="userSearch"
                        class="form-control"
                        placeholder="Search users..."
                        value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>"
                        autocomplete="off"
                    >
                    <span id="userSearchLoading" class="position-absolute top-50 end-0 translate-middle-y me-3 d-none text-muted small" aria-live="polite">Searching...</span>
                </div>
            </div>
        </div>


        <!-- Success Message -->
        <?php
 if ($message !== ''): ?>

            <div
                class="alert alert-success d-flex align-items-center gap-2 mb-4 py-2 px-3"
                style="
                    border-radius: 10px;
                    border-left: 4px solid #16a34a;
                "
            >

                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#16a34a"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M20 6 9 17l-5-5"/>
                </svg>

                <span
                    style="
                        font-weight: 600;
                        font-size: 0.9rem;
                    "
                >
                    <?php
 echo htmlspecialchars($message); ?>
                </span>

            </div>

        <?php
 endif; ?>


        <!-- Error Message -->
        <?php
 if (!empty($errors)): ?>

            <div class="alert alert-danger mb-4">

                <ul class="mb-0">

                    <?php
 foreach ($errors as $error): ?>

                        <li>
                            <?php
 echo htmlspecialchars($error); ?>
                        </li>

                    <?php
 endforeach; ?>

                </ul>

            </div>

        <?php
 endif; ?>


        <!-- Summary Tiles -->
        <div class="row row-cols-1 row-cols-md-4 g-3 mb-4">

            <!-- Total Users -->
            <div class="col">

                <div class="summary-tile">

                    <div class="summary-tile-label">
                        Total Users
                    </div>

                    <div class="summary-tile-value">
                        <?= $totalUsers ?>
                    </div>

                </div>

            </div>


            <!-- Admins -->
            <div class="col">

                <div class="summary-tile">

                    <div class="summary-tile-label">
                        Admins
                    </div>

                    <div
                        class="summary-tile-value"
                        style="color:#6366f1;"
                    >
                        <?= $roleCounts['Admin'] ?>
                    </div>

                </div>

            </div>


            <!-- Staff -->
            <div class="col">

                <div class="summary-tile">

                    <div class="summary-tile-label">
                        Staff
                    </div>

                    <div
                        class="summary-tile-value"
                        style="color:#f59e0b;"
                    >
                        <?= $roleCounts['Staff'] ?>
                    </div>

                </div>

            </div>


            <!-- Customers -->
            <div class="col">

                <div class="summary-tile">

                    <div class="summary-tile-label">
                        Customers
                    </div>

                    <div
                        class="summary-tile-value"
                        style="color:#10b981;"
                    >
                        <?= $roleCounts['Customer'] ?>
                    </div>

                </div>

            </div>

        </div>


        <!-- Users Table -->
        <div class="card border-0 shadow-sm">

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover mb-0">

                        <thead>

                            <tr>

                                <th>#</th>

                                <th>Name</th>

                                <th>Email</th>

                                <th>Phone</th>

                                <th>IC Number</th>

                                <!-- NEW -->
                                <th>Bank Name</th>

                                <!-- NEW -->
                                <th>Bank Account No.</th>

                                <th>Role</th>

                                <th>Status</th>

                                <th>Address</th>

                                <th>Registered</th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody id="usersTableBody">

                            <?php
 if (!empty($users)): ?>

                                <?php
 foreach ($users as $i => $u):

                                    $isSelf =
                                        ((int)$u['user_id'] ===
                                         (int)$_SESSION['user_id']);

                                ?>

                                    <tr>

                                        <!-- User ID -->
                                        <td>
                                            <?= $u['user_id'] ?>
                                        </td>


                                        <!-- Name -->
                                        <td>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $u['name']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <!-- Email -->
                                        <td class="text-muted">

                                            <?= htmlspecialchars(
                                                $u['email'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- Phone -->
                                        <td>

                                            <?= htmlspecialchars(
                                                $u['phone'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- IC Number -->
                                        <td>

                                            <?= htmlspecialchars(
                                                $u['ic_number'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- NEW: Bank Name -->
                                        <td>

                                            <?= htmlspecialchars(
                                                $u['bank_name'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- NEW: Bank Account -->
                                        <td>

                                            <?= htmlspecialchars(
                                                $u['bank_account_number'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- Role -->
                                        <td>

                                            <span
                                                class="role-badge role-badge-<?= strtolower($u['role']) ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $u['role']
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- Status -->
                                        <td>

                                            <span
                                                class="badge-status-<?=
                                                    $u['status'] === 'Active'
                                                        ? 'completed'
                                                        : 'cancelled'
                                                ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $u['status']
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- Address -->
                                        <td class="small text-muted">

                                            <?= htmlspecialchars(
                                                $u['address'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- Registered -->
                                        <td class="small">

                                            <?= date(
                                                'd/m/Y',
                                                strtotime($u['created_at'])
                                            ) ?>

                                        </td>


                                        <!-- Edit Button -->
                                        <td>

                                            <button
                                                class="btn btn-sm btn-outline-primary edit-user-btn"

                                                data-userid="<?= $u['user_id'] ?>"

                                                data-name="<?= htmlspecialchars(
                                                    $u['name'],
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-email="<?= htmlspecialchars(
                                                    $u['email'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-phone="<?= htmlspecialchars(
                                                    $u['phone'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-ic-number="<?= htmlspecialchars(
                                                    $u['ic_number'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-bank-name="<?= htmlspecialchars(
                                                    $u['bank_name'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-bank-account="<?= htmlspecialchars(
                                                    $u['bank_account_number'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-address="<?= htmlspecialchars(
                                                    $u['address'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-role="<?= htmlspecialchars(
                                                    $u['role'],
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-status="<?= htmlspecialchars(
                                                    $u['status'],
                                                    ENT_QUOTES
                                                ) ?>"

                                                data-self="<?= $isSelf ? '1' : '0' ?>"

                                                title="<?=
                                                    $isSelf
                                                        ? 'You cannot edit your own role or status'
                                                        : 'Edit user'
                                                ?>"
                                            >

                                                Edit

                                            </button>

                                        </td>

                                        

                                    </tr>

                                <?php
 endforeach; ?>


                            <?php
 else: ?>

                                <tr>

                                    <td
                                        colspan="12"
                                        class="text-center py-4 text-muted"
                                    >
                                        No users found
                                    </td>

                                </tr>

                            <?php
 endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <?php if ($totalPages > 1): ?>
            <nav id="userPagination" class="d-flex justify-content-center mt-4" aria-label="User table pages">
                <ul class="pagination mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $currentPage - 1 ?>&amp;search=<?= urlencode($searchQuery) ?>" data-page="<?= $currentPage - 1 ?>" aria-label="Previous">Previous</a>
                    </li>
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $pageNumber ?>&amp;search=<?= urlencode($searchQuery) ?>" data-page="<?= $pageNumber ?>" <?= $pageNumber === $currentPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $currentPage + 1 ?>&amp;search=<?= urlencode($searchQuery) ?>" data-page="<?= $currentPage + 1 ?>" aria-label="Next">Next</a>
                    </li>
                </ul>
            </nav>
        <?php else: ?>
            <div id="userPagination"></div>
        <?php endif; ?>

    </section>

</div>



<!-- ========================================================= -->
<!-- EDIT USER MODAL -->
<!-- ========================================================= -->

<div
    class="modal fade"
    id="editUserModal"
    tabindex="-1"
>

    <div
        class="modal-dialog modal-lg modal-dialog-centered"
    >

        <div
            class="modal-content"
            style="
                border-radius: 18px;
                border: none;
                box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            "
        >

            <form
                method="post"
                id="editUserForm"
            >
                <?= csrf_field() ?>

                <!-- Modal Header -->
                <div
                    class="modal-header"
                    style="
                        background: var(--nav-bg);
                        color: #fff;
                        border-radius: 18px 18px 0 0;
                        padding: 1.25rem 1.5rem;
                    "
                >

                    <h5 class="modal-title fw-bold">
                        Edit User
                    </h5>

                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <!-- Modal Body -->
                <div class="modal-body p-4">

                    <input
                        type="hidden"
                        name="edit_user"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="user_id"
                        id="editUserId"
                    >


                    <div class="row g-3">


                        <!-- Full Name -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="name"
                                id="editName"
                                required
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- Email -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Email
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                name="email"
                                id="editEmail"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- Phone -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Phone
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="phone"
                                id="editPhone"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- IC Number -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                IC Number
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="ic_number"
                                id="editIcNumber"
                                placeholder="e.g. 900101-12-3456"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- NEW: Bank Name -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Bank Name
                            </label>

                            <select
                                class="form-select"
                                name="bank_name"
                                id="editBankName"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                                <option value="">
                                    Select Bank
                                </option>

                                <option value="Maybank">
                                    Maybank
                                </option>

                                <option value="CIMB Bank">
                                    CIMB Bank
                                </option>

                                <option value="Public Bank">
                                    Public Bank
                                </option>

                                <option value="RHB Bank">
                                    RHB Bank
                                </option>

                                <option value="Hong Leong Bank">
                                    Hong Leong Bank
                                </option>

                                <option value="Bank Islam">
                                    Bank Islam
                                </option>

                                <option value="Bank Rakyat">
                                    Bank Rakyat
                                </option>

                                <option value="AmBank">
                                    AmBank
                                </option>

                                <option value="BSN">
                                    BSN
                                </option>

                                <option value="UOB Malaysia">
                                    UOB Malaysia
                                </option>

                                <option value="OCBC Bank">
                                    OCBC Bank
                                </option>

                                <option value="HSBC Malaysia">
                                    HSBC Malaysia
                                </option>

                                <option value="Alliance Bank">
                                    Alliance Bank
                                </option>

                                <option value="Affin Bank">
                                    Affin Bank
                                </option>

                                <option value="Other">
                                    Other
                                </option>

                            </select>

                        </div>


                        <!-- NEW: Bank Account Number -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Bank Account Number
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="bank_account_number"
                                id="editBankAccount"
                                placeholder="Enter account number"
                                maxlength="50"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- Role -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Role
                            </label>

                            <select
                                class="form-select"
                                name="role"
                                id="editRole"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                                <option value="Admin">
                                    Admin
                                </option>

                                <option value="Staff">
                                    Staff
                                </option>

                                <option value="Driver">
                                    Driver
                                </option>

                                <option value="Customer">
                                    Customer
                                </option>

                            </select>

                            <small
                                class="text-muted"
                                id="editRoleNote"
                            ></small>

                        </div>


                        <!-- Status -->
                        <div class="col-md-6">

                            <label class="form-label fw-semibold">
                                Status
                            </label>

                            <select
                                class="form-select"
                                name="status"
                                id="editStatus"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                                <option value="Active">
                                    Active
                                </option>

                                <option value="Inactive">
                                    Inactive
                                </option>

                            </select>

                            <small
                                class="text-muted"
                                id="editStatusNote"
                            ></small>

                        </div>


                        <!-- Address -->
                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                Address
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                name="address"
                                id="editAddress"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>


                        <!-- Password -->
                        <div class="col-12">

                            <hr>

                            <label class="form-label fw-semibold">

                                Reset Password

                                <small class="text-muted">
                                    (leave blank to keep current)
                                </small>

                            </label>

                            <input
                                type="password"
                                class="form-control"
                                name="new_password"
                                id="editPassword"
                                placeholder="Enter new password"
                                style="
                                    border-radius: 10px;
                                    border: 1.5px solid #e2e8f0;
                                    padding: 0.6rem 1rem;
                                "
                            >

                        </div>

                    </div>

                </div>


                <!-- Modal Footer -->
                <div
                    class="modal-footer px-4 pb-4 border-0"
                >

                    <button
                        type="button"
                        class="btn btn-light fw-bold"
                        data-bs-dismiss="modal"
                        style="
                            border-radius: 10px;
                            padding: 0.6rem 1.5rem;
                        "
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn fw-bold"
                        style="
                            background: var(--nav-bg);
                            color: #fff;
                            border-radius: 10px;
                            padding: 0.6rem 1.5rem;
                            border: none;
                        "
                    >
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>



<!-- ========================================================= -->
<!-- JAVASCRIPT -->
<!-- ========================================================= -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const modal =
            new bootstrap.Modal(
                document.getElementById(
                    'editUserModal'
                )
            );


        document.addEventListener(
            'click',
            function (event) {

                const btn = event.target.closest('.edit-user-btn');
                if (!btn) return;

                        const userId =
                            btn.getAttribute(
                                'data-userid'
                            );

                        const name =
                            btn.getAttribute(
                                'data-name'
                            );

                        const email =
                            btn.getAttribute(
                                'data-email'
                            );

                        const phone =
                            btn.getAttribute(
                                'data-phone'
                            );

                        const icNumber =
                            btn.getAttribute(
                                'data-ic-number'
                            );

                        // NEW
                        const bankName =
                            btn.getAttribute(
                                'data-bank-name'
                            );

                        // NEW
                        const bankAccount =
                            btn.getAttribute(
                                'data-bank-account'
                            );

                        const address =
                            btn.getAttribute(
                                'data-address'
                            );

                        const role =
                            btn.getAttribute(
                                'data-role'
                            );

                        const status =
                            btn.getAttribute(
                                'data-status'
                            );

                        const isSelf =
                            btn.getAttribute(
                                'data-self'
                            ) === '1';


                        // Set values
                        document.getElementById(
                            'editUserId'
                        ).value = userId;


                        document.getElementById(
                            'editName'
                        ).value = name;


                        document.getElementById(
                            'editEmail'
                        ).value =
                            email === '—'
                                ? ''
                                : email;


                        document.getElementById(
                            'editPhone'
                        ).value =
                            phone === '—'
                                ? ''
                                : phone;


                        document.getElementById(
                            'editIcNumber'
                        ).value =
                            icNumber === '—'
                                ? ''
                                : icNumber;


                        // NEW: Bank Name
                        document.getElementById(
                            'editBankName'
                        ).value =
                            bankName === '—'
                                ? ''
                                : bankName;


                        // NEW: Bank Account
                        document.getElementById(
                            'editBankAccount'
                        ).value =
                            bankAccount === '—'
                                ? ''
                                : bankAccount;


                        document.getElementById(
                            'editAddress'
                        ).value =
                            address === '—'
                                ? ''
                                : address;


                        document.getElementById(
                            'editRole'
                        ).value = role;


                        document.getElementById(
                            'editStatus'
                        ).value = status;


                        document.getElementById(
                            'editPassword'
                        ).value = '';


                        // Role and status controls
                        const roleSelect =
                            document.getElementById(
                                'editRole'
                            );

                        const statusSelect =
                            document.getElementById(
                                'editStatus'
                            );

                        const roleNote =
                            document.getElementById(
                                'editRoleNote'
                            );

                        const statusNote =
                            document.getElementById(
                                'editStatusNote'
                            );


                        // If editing own account,
                        // lock role/status
                        if (isSelf) {

                            roleSelect.disabled =
                                true;

                            statusSelect.disabled =
                                true;

                            roleNote.textContent =
                                '⛔ You cannot change your own role';

                            statusNote.textContent =
                                '⛔ You cannot deactivate yourself';

                        } else {

                            roleSelect.disabled =
                                false;

                            statusSelect.disabled =
                                false;

                            roleNote.textContent =
                                '';

                            statusNote.textContent =
                                '';
                        }


                        modal.show();

            }
        );

        const searchInput = document.getElementById('userSearch');
        const searchLoading = document.getElementById('userSearchLoading');
        let searchDebounce;

        function loadUsers(page) {
            const params = new URLSearchParams({
                page: String(page),
                search: searchInput.value.trim()
            });

            searchLoading.classList.remove('d-none');
            searchInput.setAttribute('aria-busy', 'true');

            fetch('user_detail.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Search request failed');
                    return response.text();
                })
                .then(function (html) {
                    const pageDocument = new DOMParser().parseFromString(html, 'text/html');
                    const newBody = pageDocument.getElementById('usersTableBody');
                    const newPagination = pageDocument.getElementById('userPagination');
                    const currentBody = document.getElementById('usersTableBody');
                    const currentPagination = document.getElementById('userPagination');

                    if (newBody && currentBody) currentBody.replaceWith(newBody);
                    if (newPagination && currentPagination) currentPagination.replaceWith(newPagination);

                    const nextUrl = 'user_detail.php?' + params.toString();
                    window.history.replaceState({}, '', nextUrl);
                })
                .catch(function () {
                    // Keep the current results visible if a live-search request fails.
                })
                .finally(function () {
                    searchLoading.classList.add('d-none');
                    searchInput.removeAttribute('aria-busy');
                });
        }

        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchDebounce);
            searchDebounce = window.setTimeout(function () {
                loadUsers(1);
            }, 400);
        });

        document.addEventListener('click', function (event) {
            const pageLink = event.target.closest('#userPagination a[data-page]');
            if (!pageLink || pageLink.closest('.disabled')) return;

            event.preventDefault();
            loadUsers(parseInt(pageLink.getAttribute('data-page'), 10) || 1);
        });

    }
);

</script>


<?php
 include "footer.php"; ?>
