<?php
require_once __DIR__ . '/config/session.php';


session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$page = 'profile.php';
include "header.php";

$userId = (int) $_SESSION['user_id'];
$message = '';
$errors = [];
$csrfToken = ensure_csrf_token();

// Fetch current user
$user = null;

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($user)) {
        $user['ic_number'] = app_decrypt((string)($user['ic_number'] ?? ''), $encryptionKey);
        $user['bank_account_number'] = app_decrypt((string)($user['bank_account_number'] ?? ''), $encryptionKey);
    }
} catch (PDOException $e) {
    $errors[] = 'Could not load profile.';
}

if (!$user) {
    $errors[] = 'User not found.';
}


// ============================================================
// HANDLE PROFILE UPDATE
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token()) {
    $errors[] = 'Invalid or expired form token. Please refresh and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && verify_csrf_token()) {

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $icNumber = trim($_POST['ic_number'] ?? '');

    // NEW: Bank Information
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';


    // ========================================================
    // VALIDATION
    // ========================================================

    if ($name === '') {

        $errors[] = 'Name is required.';

    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $errors[] = 'Invalid email format.';
    }


    // ========================================================
    // PASSWORD VALIDATION
    // ========================================================

    if ($newPassword !== '') {

        if ($currentPassword === '') {

            $errors[] =
                'Current password is required to set a new password.';

        } else {
            $storedPassword = (string)($user['password'] ?? '');
            $passwordInfo = password_get_info($storedPassword);
            $currentPasswordIsValid = ($passwordInfo['algo'] ?? 0) !== 0
                ? password_verify($currentPassword, $storedPassword)
                : ($storedPassword !== '' && hash_equals($storedPassword, $currentPassword));

            if (!$currentPasswordIsValid) {
                $errors[] =
                    'Current password is incorrect.';
            }
        }

        if (empty($errors) && $newPassword !== $confirmPassword) {

            $errors[] =
                'New passwords do not match.';

        } elseif (empty($errors) && strlen($newPassword) < 6) {

            $errors[] =
                'New password must be at least 6 characters.';
        }
    }


    // ========================================================
    // UPDATE DATABASE
    // ========================================================

    if (empty($errors)) {

        try {

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


            // Address
            $updates[] = "address = ?";
            $params[] = $address ?: null;


            // IC Number
            $updates[] = "ic_number = ?";
            $params[] = app_encrypt((string)($icNumber ?? ''), $encryptionKey) ?: null;


            // =================================================
            // NEW: BANK NAME
            // =================================================

            $updates[] = "bank_name = ?";
            $params[] = $bankName ?: null;


            // =================================================
            // NEW: BANK ACCOUNT NUMBER
            // =================================================

            $updates[] = "bank_account_number = ?";
            $params[] = app_encrypt((string)($bankAccountNumber ?? ''), $encryptionKey) ?: null;


            // =================================================
            // PASSWORD
            // =================================================

            if ($newPassword !== '') {

                $updates[] = "password = ?";

                $params[] = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );
            }


            // User ID for WHERE clause
            $params[] = $userId;


            // Build SQL
            $sql =
                "UPDATE users SET "
                . implode(", ", $updates)
                . " WHERE user_id = ?";


            $stmt = $conn->prepare($sql);

            $stmt->execute($params);


            // =================================================
            // UPDATE SESSION
            // =================================================

            $_SESSION['name'] = $name;
            $_SESSION['email'] = $email;


            $message =
                'Profile updated successfully.';


            // =================================================
            // REFRESH USER DATA
            // =================================================

            $stmt = $conn->prepare(
                "SELECT * FROM users WHERE user_id = ?"
            );

            $stmt->execute([$userId]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($user)) {
                $user['ic_number'] = app_decrypt((string)($user['ic_number'] ?? ''), $encryptionKey);
                $user['bank_account_number'] = app_decrypt((string)($user['bank_account_number'] ?? ''), $encryptionKey);
            }


        } catch (PDOException $e) {

            $errors[] =
                'Failed to update profile: '
                . $e->getMessage();
        }
    }
}

?>



<!-- ============================================================
     PAGE BODY
============================================================ -->

<div class="page-body">

    <?php
 include "sidebar.php"; ?>


    <section class="content-panel px-4 py-4">


        <!-- ====================================================
             PAGE TITLE
        ===================================================== -->

        <div class="page-title mb-4">

            <h1 class="display-6">
                My Profile
            </h1>

            <p class="text-secondary mb-0">
                Manage your personal information
            </p>

        </div>



        <!-- ====================================================
             SUCCESS MESSAGE
        ===================================================== -->

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

                    echo htmlspecialchars($message);
                    ?>
                </span>

            </div>

        <?php
 endif; ?>



        <!-- ====================================================
             ERROR MESSAGE
        ===================================================== -->

        <?php
 if (!empty($errors)): ?>

            <div class="alert alert-danger mb-4">

                <ul class="mb-0">

                    <?php
 foreach ($errors as $error): ?>

                        <li>
                            <?php

                            echo htmlspecialchars($error);
                            ?>
                        </li>

                    <?php
 endforeach; ?>

                </ul>

            </div>

        <?php
 endif; ?>



        <!-- ====================================================
             MAIN ROW
        ===================================================== -->

        <div class="row g-4">


            <!-- =================================================
                 PROFILE INFORMATION CARD
            ================================================== -->

            <div class="col-12 col-lg-4">

                <div class="card border-0 shadow-sm h-100">

                    <div class="card-body text-center p-4">


                        <!-- Profile Initial -->
                        <div
                            class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                            style="
                                width: 100px;
                                height: 100px;
                                border-radius: 999px;
                                background: var(--nav-bg);
                                color: #fff;
                                font-size: 2.5rem;
                                font-weight: 800;
                                box-shadow:
                                    0 8px 24px
                                    rgba(12,58,120,0.2);
                            "
                        >

                            <?=
                            strtoupper(
                                substr(
                                    $user['name'] ?? 'U',
                                    0,
                                    1
                                )
                            )
                            ?>

                        </div>


                        <!-- Name -->
                        <h4 class="fw-bold mb-1">

                            <?=
                            htmlspecialchars(
                                $user['name'] ?? 'User'
                            )
                            ?>

                        </h4>


                        <!-- Email -->
                        <p class="text-muted mb-2">

                            <?=
                            htmlspecialchars(
                                $user['email'] ?? 'No email'
                            )
                            ?>

                        </p>


                        <!-- Role -->
                        <span
                            class="badge-status-<?=
                                strtolower(
                                    $user['role'] ?? 'customer'
                                ) === 'admin'
                                    ? 'confirmed'
                                    : (
                                        strtolower(
                                            $user['role'] ?? 'customer'
                                        ) === 'staff'
                                            ? 'pending'
                                            : 'completed'
                                    )
                            ?>"
                            style="font-size: 0.85rem;"
                        >

                            <?=
                            htmlspecialchars(
                                $user['role'] ?? 'Unknown'
                            )
                            ?>

                        </span>


                        <!-- Status -->
                        <span
                            class="badge-status-<?=
                                ($user['status'] ?? 'Active')
                                    === 'Active'
                                    ? 'completed'
                                    : 'cancelled'
                            ?>"
                            style="font-size: 0.85rem;"
                        >

                            <?=
                            htmlspecialchars(
                                $user['status'] ?? 'Active'
                            )
                            ?>

                        </span>



                        <hr class="my-4">



                        <!-- Member Information -->
                        <div class="text-start small text-muted">


                            <div
                                class="d-flex justify-content-between mb-2"
                            >

                                <span>
                                    Member since
                                </span>

                                <strong>

                                    <?=
                                    date(
                                        'd/m/Y',
                                        strtotime(
                                            $user['created_at'] ?? 'now'
                                        )
                                    )
                                    ?>

                                </strong>

                            </div>


                            <div
                                class="d-flex justify-content-between"
                            >

                                <span>
                                    User ID
                                </span>

                                <strong>
                                    #<?= $user['user_id'] ?>
                                </strong>

                            </div>

                        </div>


                    </div>

                </div>

            </div>



            <!-- =================================================
                 EDIT PROFILE FORM
            ================================================== -->

            <div class="col-12 col-lg-8">

                <div class="card border-0 shadow-sm">

                    <div class="card-body p-4">


                        <h5 class="fw-bold mb-4">
                            Edit Profile
                        </h5>


                        <form method="post">
                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="update_profile"
                                value="1"
                            >



                            <div class="row g-3">


                                <!-- =================================
                                     FULL NAME
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Full Name
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="name"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['name'] ?? ''
                                            )
                                        ?>"
                                        required
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>



                                <!-- =================================
                                     EMAIL
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Email
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control"
                                        name="email"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['email'] ?? ''
                                            )
                                        ?>"
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>



                                <!-- =================================
                                     PHONE
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Phone
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="phone"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['phone'] ?? ''
                                            )
                                        ?>"
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>



                                <!-- =================================
                                     IC NUMBER
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        IC Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="ic_number"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['ic_number'] ?? ''
                                            )
                                        ?>"
                                        placeholder="900101-12-3456"
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>



                                <!-- =================================
                                     BANK NAME
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Bank Name
                                    </label>

                                    <select
                                        class="form-select"
                                        name="bank_name"
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                        <option value="">
                                            Select Bank
                                        </option>


                                        <option
                                            value="Maybank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Maybank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Maybank
                                        </option>


                                        <option
                                            value="CIMB Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'CIMB Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            CIMB Bank
                                        </option>


                                        <option
                                            value="Public Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Public Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Public Bank
                                        </option>


                                        <option
                                            value="RHB Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'RHB Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            RHB Bank
                                        </option>


                                        <option
                                            value="Hong Leong Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Hong Leong Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Hong Leong Bank
                                        </option>


                                        <option
                                            value="Bank Islam"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Bank Islam'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Bank Islam
                                        </option>


                                        <option
                                            value="Bank Rakyat"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Bank Rakyat'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Bank Rakyat
                                        </option>


                                        <option
                                            value="AmBank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'AmBank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            AmBank
                                        </option>


                                        <option
                                            value="BSN"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'BSN'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            BSN
                                        </option>


                                        <option
                                            value="UOB Malaysia"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'UOB Malaysia'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            UOB Malaysia
                                        </option>


                                        <option
                                            value="OCBC Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'OCBC Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            OCBC Bank
                                        </option>


                                        <option
                                            value="HSBC Malaysia"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'HSBC Malaysia'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            HSBC Malaysia
                                        </option>


                                        <option
                                            value="Alliance Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Alliance Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Alliance Bank
                                        </option>


                                        <option
                                            value="Affin Bank"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Affin Bank'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Affin Bank
                                        </option>


                                        <option
                                            value="Other"
                                            <?=
                                                ($user['bank_name'] ?? '')
                                                === 'Other'
                                                    ? 'selected'
                                                    : ''
                                            ?>
                                        >
                                            Other
                                        </option>

                                    </select>

                                </div>



                                <!-- =================================
                                     BANK ACCOUNT NUMBER
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Bank Account Number
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="bank_account_number"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['bank_account_number'] ?? ''
                                            )
                                        ?>"
                                        placeholder="Enter bank account number"
                                        maxlength="50"
                                        inputmode="numeric"
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>



                                <!-- =================================
                                     ROLE
                                ================================== -->

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Role
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        value="<?=
                                            htmlspecialchars(
                                                $user['role'] ?? ''
                                            )
                                        ?>"
                                        disabled
                                        style="
                                            border-radius: 10px;
                                            border: 1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                            background: #f8f9fa;
                                        "
                                    >

                                    <small class="text-muted">
                                        Role cannot be changed here
                                    </small>

                                </div>



                                <!-- =================================
                                     ADDRESS
                                ================================== -->

                                <div
                                    class="col-12"
                                    style="position: relative;"
                                >

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Home Address (Malaysia)
                                    </label>


                                    <div
                                        class="input-group"
                                        style="position: relative;"
                                    >

                                        <input
                                            type="text"
                                            class="form-control"
                                            name="address"
                                            id="addressInput"
                                            value="<?=
                                                htmlspecialchars(
                                                    $user['address'] ?? ''
                                                )
                                            ?>"
                                            placeholder="e.g. 12, Jalan Melati, Taman Murni, 05460 Alor Setar, Kedah..."
                                            autocomplete="street-address"
                                            style="
                                                border-radius: 10px;
                                                border:
                                                    1.5px solid
                                                    #e2e8f0;
                                                padding:
                                                    0.6rem 1rem;
                                            "
                                        >


                                        <button
                                            type="button"
                                            class="btn"
                                            onclick="getMyLocationForProfile()"
                                            style="
                                                border:
                                                    1.5px solid
                                                    #e2e8f0;
                                                border-left: 0;
                                                background: #fafcff;
                                                padding:
                                                    0.5rem 1rem;
                                            "
                                            title="Use my location"
                                        >

                                            <svg
                                                width="18"
                                                height="18"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="var(--nav-bg)"
                                                stroke-width="2.5"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >

                                                <circle
                                                    cx="12"
                                                    cy="12"
                                                    r="10"
                                                />

                                                <circle
                                                    cx="12"
                                                    cy="12"
                                                    r="3"
                                                />

                                                <line
                                                    x1="12"
                                                    y1="2"
                                                    x2="12"
                                                    y2="6"
                                                />

                                                <line
                                                    x1="12"
                                                    y1="18"
                                                    x2="12"
                                                    y2="22"
                                                />

                                                <line
                                                    x1="2"
                                                    y1="12"
                                                    x2="6"
                                                    y2="12"
                                                />

                                                <line
                                                    x1="18"
                                                    y1="12"
                                                    x2="22"
                                                    y2="12"
                                                />

                                            </svg>

                                        </button>

                                    </div>


                                    <!-- Address Dropdown -->
                                    <div
                                        id="addressDropdown"
                                        style="
                                            display:none;
                                            position:absolute;
                                            top:100%;
                                            left:0;
                                            right:0;
                                            z-index:40;
                                            background:#fff;
                                            border:
                                                1.5px solid #e2e8f0;
                                            border-radius:12px;
                                            max-height:300px;
                                            overflow-y:auto;
                                            box-shadow:
                                                0 12px 32px
                                                rgba(15,23,42,0.15);
                                            margin-top:3px;
                                        "
                                    ></div>
                                    <div class="form-text mt-2">
                                        Use a Malaysian format: house number, Jalan, Taman, postcode, town, and state.
                                    </div>

                                </div>

                            </div>



                            <!-- =================================================
                                 CHANGE PASSWORD
                            ================================================== -->

                            <hr class="my-4">


                            <h5 class="fw-bold mb-3">
                                Change Password
                            </h5>


                            <p class="text-muted small mb-3">
                                Leave blank to keep your current password.
                            </p>


                            <div class="row g-3">


                                <!-- Current Password -->
                                <div class="col-md-4">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Current Password
                                    </label>

                                    <input
                                        type="password"
                                        class="form-control"
                                        name="current_password"
                                        placeholder="Required to change password"
                                        style="
                                            border-radius: 10px;
                                            border:
                                                1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>


                                <!-- New Password -->
                                <div class="col-md-4">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        New Password
                                    </label>

                                    <input
                                        type="password"
                                        class="form-control"
                                        name="new_password"
                                        placeholder="Min 6 characters"
                                        style="
                                            border-radius: 10px;
                                            border:
                                                1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>


                                <!-- Confirm Password -->
                                <div class="col-md-4">

                                    <label
                                        class="form-label fw-semibold"
                                    >
                                        Confirm Password
                                    </label>

                                    <input
                                        type="password"
                                        class="form-control"
                                        name="confirm_password"
                                        placeholder="Repeat new password"
                                        style="
                                            border-radius: 10px;
                                            border:
                                                1.5px solid #e2e8f0;
                                            padding:
                                                0.6rem 1rem;
                                        "
                                    >

                                </div>

                            </div>



                            <!-- =================================================
                                 SAVE BUTTON
                            ================================================== -->

                            <div class="mt-4">

                                <button
                                    type="submit"
                                    class="btn fw-bold px-4 py-2"
                                    style="
                                        background: var(--nav-bg);
                                        color: #fff;
                                        border-radius: 10px;
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

        </div>

    </section>

</div>



<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>

// ============================================================
// LIVE ADDRESS AUTOCOMPLETE
// ============================================================

const addressInput =
    document.getElementById('addressInput');

const addressDropdown =
    document.getElementById('addressDropdown');

let addressTimer = null;


if (addressInput) {

    addressInput.addEventListener(
        'input',
        function () {

            const query =
                this.value.trim();


            if (addressTimer) {
                clearTimeout(addressTimer);
            }


            if (query.length < 3) {

                addressDropdown.style.display =
                    'none';

                return;
            }


            addressDropdown.innerHTML =
                '<div style="padding:0.75rem 1rem;color:#64748b;font-size:0.85rem;">Searching...</div>';

            addressDropdown.style.display =
                'block';


            addressTimer = setTimeout(
                function () {

                    const url =
                        'https://nominatim.openstreetmap.org/search?q='
                        + encodeURIComponent(
                            query + ', Malaysia'
                        )
                        + '&format=json&limit=8&addressdetails=1&countrycodes=my';


                    fetch(
                        url,
                        {
                            headers: {
                                'User-Agent':
                                    'RecyclonProfile/1.0'
                            }
                        }
                    )

                    .then(
                        r => r.json()
                    )

                    .then(
                        data => {

                            if (
                                !data ||
                                data.length === 0
                            ) {

                                addressDropdown.innerHTML =
                                    '<div style="padding:0.75rem 1rem;color:#94a3b8;font-size:0.85rem;">No results found</div>';

                                return;
                            }


                            let html = '';


                            data.forEach(
                                function (place) {

                                    const displayName =
                                        place.display_name
                                            .split(', ')
                                            .slice(0, 4)
                                            .join(', ');


                                    const type =
                                        place.type ||
                                        'place';


                                    const icon =
                                        type === 'city' ||
                                        type === 'town'
                                            ? '🏙️'
                                            :
                                        type === 'road' ||
                                        type === 'street'
                                            ? '🛣️'
                                            :
                                        type === 'building' ||
                                        type === 'amenity'
                                            ? '🏢'
                                            :
                                            '📍';


                                    html +=
                                        '<div class="addr-item" '
                                        + 'data-address="'
                                        + escHtml(
                                            displayName
                                        )
                                        + '" '
                                        + 'style="padding:0.7rem 1rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.85rem;transition:background 0.15s;display:flex;align-items:center;gap:0.5rem;">'
                                        + '<span>'
                                        + icon
                                        + '</span>'
                                        + '<span style="font-weight:600;line-height:1.3;">'
                                        + displayName
                                        + '</span>'
                                        + '</div>';
                                }
                            );


                            addressDropdown.innerHTML =
                                html;


                            document
                                .querySelectorAll(
                                    '.addr-item'
                                )
                                .forEach(
                                    function (item) {

                                        item.addEventListener(
                                            'click',
                                            function () {

                                                addressInput.value =
                                                    this.getAttribute(
                                                        'data-address'
                                                    );

                                                addressDropdown.style.display =
                                                    'none';
                                            }
                                        );


                                        item.addEventListener(
                                            'mouseenter',
                                            function () {

                                                this.style.background =
                                                    '#eef4ff';
                                            }
                                        );


                                        item.addEventListener(
                                            'mouseleave',
                                            function () {

                                                this.style.background =
                                                    '';
                                            }
                                        );

                                    }
                                );

                        }
                    )

                    .catch(
                        function () {

                            addressDropdown.innerHTML =
                                '<div style="padding:0.75rem 1rem;color:#dc2626;font-size:0.85rem;">Search failed</div>';
                        }
                    );

                },
                350
            );

        }
    );


    // Hide dropdown when clicking outside
    document.addEventListener(
        'click',
        function (e) {

            if (
                !e.target.closest('#addressInput') &&
                !e.target.closest('#addressDropdown')
            ) {

                addressDropdown.style.display =
                    'none';
            }

        }
    );
}



// ============================================================
// GET CURRENT LOCATION
// ============================================================

function getMyLocationForProfile() {

    if (!navigator.geolocation) {

        alert(
            'Geolocation is not supported by your browser.'
        );

        return;
    }


    navigator.geolocation.getCurrentPosition(

        function (pos) {

            const lat =
                pos.coords.latitude.toFixed(6);

            const lng =
                pos.coords.longitude.toFixed(6);


            const url =
                'https://nominatim.openstreetmap.org/reverse?lat='
                + lat
                + '&lon='
                + lng
                + '&format=json&zoom=16';


            fetch(
                url,
                {
                    headers: {
                        'User-Agent':
                            'RecyclonProfile/1.0'
                    }
                }
            )

            .then(
                r => r.json()
            )

            .then(
                data => {

                    const displayName =
                        data.display_name || '';


                    const shortName =
                        displayName
                            .split(', ')
                            .slice(0, 3)
                            .join(', ');


                    if (addressInput) {

                        addressInput.value =
                            shortName ||
                            lat + '°N, ' +
                            lng + '°E';
                    }

                }
            )

            .catch(
                () => {

                    if (addressInput) {

                        addressInput.value =
                            lat + '°N, ' +
                            lng + '°E';
                    }

                }
            );

        },


        function () {

            alert(
                'Could not get your location. Please enable GPS or type an address.'
            );

        },


        {
            enableHighAccuracy: true,
            timeout: 10000
        }

    );
}



// ============================================================
// ESCAPE HTML
// ============================================================

function escHtml(str) {

    const div =
        document.createElement('div');

    div.appendChild(
        document.createTextNode(str)
    );

    return div.innerHTML;
}

</script>



<?php
 include "footer.php"; ?>
