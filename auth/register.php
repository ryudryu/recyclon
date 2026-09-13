<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../translations.php';
startTranslationBuffer();
$csrfToken = ensure_csrf_token();

$message = "";

if (isset($_POST['register']) && !verify_csrf_token()) {
    $message = 'Invalid or expired form token. Please refresh and try again.';
}

if (isset($_POST['register']) && verify_csrf_token() && trim((string)($_POST['website'] ?? '')) === '') {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    // Public registration may only create customer accounts.
    $role = 'Customer';

    $rawPassword = (string)($_POST['password'] ?? '');
    if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($rawPassword) < 8 || strlen($rawPassword) > 128) {
        $message = 'Enter a valid name, email, and password of 8–128 characters.';
    } else {

    // Check existing email
    $check = $conn->prepare("SELECT user_id FROM users WHERE email=?");
    $check->execute([$email]);

    if ($check->fetchColumn() !== false) {

        $message = "Email already exists.";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO users
            (name,email,password,role,status)
            VALUES
            (?,?,?,?,?)
        ");

        if ($stmt->execute([
            $name,
            $email,
            $password,
            $role,
            "Active"
        ])) {

            header("Location: login.php");
            exit();
        } else {

            $message = "Registration failed.";
        }
    }
    }
}

if (isset($_POST['register']) && trim((string)($_POST['website'] ?? '')) !== '') {
    $message = 'Unable to process this registration request.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Register</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            height: 100vh;

            display: flex;

            justify-content: center;

            align-items: center;

            background:
                /* Top layer: Semi-transparent gradient */
                /* Bottom layer: Your background image */
                url('../assets/img/register_login_background.jpg');

            /* Recommended supporting properties for the image */
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;

            font-family: Arial, sans-serif;
        }


        .card-box {
            width: 420px;
            background: #fff;
            border-radius: 25px;
            padding: 40px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, .15);
        }

        .form-control {
            border: none;
            border-bottom: 2px solid #ddd;
            border-radius: 0;
            margin-bottom: 20px;
        }

        .form-control:focus {
            box-shadow: none;
            border-color: #ffc107;
        }

        .btn-custom {
            width: 100%;
            background: #FFD54F;
            border: none;
            font-weight: bold;
        }

        .btn-custom:hover {
            background: #ffc107;
        }

        body {
            min-height: 100vh;
            height: auto;
            padding: 2rem 1rem;
            background: linear-gradient(135deg, rgba(6,95,70,.82), rgba(16,185,129,.58)), url('../assets/img/register_login_background.jpg');
            background-size: cover;
            background-position: center;
            color: #17221c;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .card-box { width: min(100%, 460px); border: 1px solid rgba(255,255,255,.5); border-radius: 18px; padding: clamp(1.5rem, 5vw, 2.5rem); box-shadow: 0 24px 60px rgba(6, 50, 36, .25); }
        .card-box h2 { color: #065f46; font-weight: 800; letter-spacing: -.04em; }
        .field-label { display: block; margin: 1rem 0 .4rem; color: #475569; font-size: .82rem; font-weight: 800; }
        .form-control { margin-bottom: 0; min-height: 48px; padding: .75rem .9rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .form-control:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.16); }
        .btn-custom { min-height: 48px; margin-top: 1.25rem; border-radius: 10px; background: #047857; color: #ffffff; box-shadow: 0 10px 20px rgba(6,95,70,.18); }
        .btn-custom:hover, .btn-custom:focus-visible { background: #065f46; color: #ffffff; }
        .card-box a { color: #047857; font-weight: 700; }
        .card-box a:hover { color: #065f46; }
        @media (max-width: 480px) { .card-box { padding: 1.35rem; } }
    </style>

</head>

<body>

    <div class="card-box">

        <h2 class="text-center mb-4">Register</h2>

        <?php
        if ($message != "") {
            echo "<div class='alert alert-danger'>$message</div>";
        }
        ?>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="d-none" aria-hidden="true">
                <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <label class="field-label" for="register-name">Full name</label>
            <input
                type="text"
                name="name"
                id="register-name"
                class="form-control"
                autocomplete="name"
                required>

            <label class="field-label" for="register-email">Email address</label>
            <input
                type="email"
                name="email"
                id="register-email"
                class="form-control"
                autocomplete="email"
                required>

            <label class="field-label" for="register-password">Password</label>
            <input
                type="password"
                name="password"
                id="register-password"
                class="form-control"
                autocomplete="new-password"
                required>

            <button
                type="submit"
                name="register"
                class="btn btn-custom">
                Register
            </button>

            <p class="text-center mt-3">
                Already have an account?
                <a href="login.php">Login</a>
            </p>

        </form>

    </div>

</body>

</html>
