<?php
require_once __DIR__ . '/../config/session.php';



session_start();
include '../config/db.php';
require_once __DIR__ . '/../translations.php';
startTranslationBuffer();
require_once __DIR__ . '/../config/rate_limit.php';
$csrfToken = ensure_csrf_token();

$message = "";

if (isset($_POST['login']) && !verify_csrf_token()) {
    $message = 'Invalid or expired form token. Please refresh and try again.';
}

if (isset($_POST['login']) && verify_csrf_token() && trim((string)($_POST['website'] ?? '')) === '') {

    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $rateKey = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate = checkLoginRateLimit($rateKey);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $message = 'Enter a valid email and password.';
    } elseif (!$rate['allowed']) {
        $message = 'Too many login attempts. Please try again later.';
    } else {

    $stmt = $conn->prepare("
        SELECT *
        FROM users
        WHERE email = ?
        AND status = 'Active'
    ");

    $stmt->execute([$email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {

        $storedPassword = $user['password'];
        $passwordValid = false;

        $passwordInfo = password_get_info((string)$storedPassword);

        // Support every password_hash() algorithm and migrate old plain-text
        // records when the user successfully signs in.
        if (($passwordInfo['algo'] ?? 0) !== 0) {
            $passwordValid = password_verify(
                $password,
                $storedPassword
            );
        } else {

            if ($storedPassword !== '' && hash_equals($storedPassword, $password)) {

                $passwordValid = true;

                /*
                 * Convert old password
                 * to secure password hash
                 */
                $newHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $update = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE user_id = ?
                ");

                $update->execute([
                    $newHash,
                    $user['user_id']
                ]);
            }
        }


        /*
         * Password is correct
         */
        if ($passwordValid) {

            clearLoginRateLimit($rateKey);

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];


            /*
             * Redirect based on role
             */
            if ($user['role'] == "Admin") {

                header("Location: ../index.php");
                exit();
            } elseif ($user['role'] == "Customer") {

                header("Location: ../customer_home.php");
                exit();
            } elseif ($user['role'] == "Staff") {

                header("Location: ../dashboard_staff.php");
                exit();
            } elseif ($user['role'] == "Driver") {

                header("Location: ../driver_dashboard.php");
                exit();
            } else {

                $message = "Invalid user role.";
            }
        } else {

            $message = "Incorrect password.";
        }
    } else {

        $message = "Account not found or inactive.";
    }
    }
}

if (isset($_POST['login']) && trim((string)($_POST['website'] ?? '')) !== '') {
    $message = 'Unable to process this login request.';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>Login</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

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

            width: 400px;

            background: white;

            border-radius: 25px;

            padding: 40px;

            box-shadow:
                0 8px 25px rgba(0, 0, 0, .15);
        }


        .form-control {

            border: none;

            border-bottom:
                2px solid #ddd;

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

            padding: 10px;
        }


        .btn-custom:hover {

            background: #ffc107;
        }


        .google-btn {

            width: 100%;

            background: white;

            border: 1px solid #ccc;

            color: #333;

            font-weight: bold;

            padding: 10px;

            text-decoration: none;

            display: flex;

            justify-content: center;

            align-items: center;

            border-radius: 6px;
        }


        .google-btn:hover {

            background: #f5f5f5;

            color: #333;
        }


        .google-icon {

            width: 20px;

            height: 20px;

            margin-right: 10px;
        }


        .divider {

            display: flex;

            align-items: center;

            margin: 20px 0;

            color: #777;
        }


        .divider::before,
        .divider::after {

            content: "";

            flex: 1;

            height: 1px;

            background: #ddd;
        }


        .divider span {

            padding: 0 10px;
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

        .card-box {
            width: min(100%, 440px);
            border: 1px solid rgba(255,255,255,.5);
            border-radius: 18px;
            padding: clamp(1.5rem, 5vw, 2.5rem);
            box-shadow: 0 24px 60px rgba(6, 50, 36, .25);
        }

        .card-box h2 { color: #065f46; font-weight: 800; letter-spacing: -.04em; }
        .field-label { display: block; margin: 1rem 0 .4rem; color: #475569; font-size: .82rem; font-weight: 800; }
        .form-control { margin-bottom: 0; min-height: 48px; padding: .75rem .9rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
        .form-control:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.16); }
        .btn-custom { min-height: 48px; margin-top: 1.25rem; border-radius: 10px; background: #047857; color: #ffffff; box-shadow: 0 10px 20px rgba(6,95,70,.18); }
        .btn-custom:hover, .btn-custom:focus-visible { background: #065f46; color: #ffffff; }
        .google-btn { min-height: 48px; border: 1px solid #e2e8f0; border-radius: 10px; color: #334155; }
        .google-btn:hover, .google-btn:focus-visible { background: #f8fafc; color: #047857; border-color: #10b981; }
        .divider { margin: 1.5rem 0; color: #64748b; font-size: .78rem; font-weight: 800; }
        .card-box a { color: #047857; font-weight: 700; }
        .card-box a:hover { color: #065f46; }
        @media (max-width: 480px) { .card-box { padding: 1.35rem; } }
    </style>

</head>


<body>

    <div class="card-box">

        <h2 class="text-center mb-4">
            Login
        </h2>


        <?php
 if ($message != ""): ?>

            <div class="alert alert-danger">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php
 endif; ?>


        <!-- NORMAL LOGIN -->

        <form method="POST">
            <?= csrf_field() ?>

            <div class="d-none" aria-hidden="true">
                <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <label class="field-label" for="login-email">Email address</label>
            <input
                type="email"
                name="email"
                id="login-email"
                class="form-control"
                autocomplete="email"
                required>

            <label class="field-label" for="login-password">Password</label>
            <input
                type="password"
                name="password"
                id="login-password"
                class="form-control"
                autocomplete="current-password"
                required>


            <button
                type="submit"
                name="login"
                class="btn btn-custom">
                Login
            </button>

        </form>


        <!-- DIVIDER -->

        <div class="divider">

            <span>OR</span>

        </div>


        <!-- GOOGLE LOGIN -->

        <a
            href="google-login.php"
            class="google-btn">

            <img
                src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg"
                class="google-icon"
                alt="Google">

            Continue with Google

        </a>


        <!-- REGISTER -->

        <p class="text-center mt-3">

            Don't have an account?

            <a href="register.php">
                Register
            </a>

        </p>

    </div>

</body>

</html>
