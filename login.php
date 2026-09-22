<?php
/**
 * QID Management System - Corporate Login Portal
 */

require_once __DIR__ . '/includes/auth.php';

// If user is already authenticated, redirect to dashboard or requested page
if (is_logged_in()) {
    $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
    unset($_SESSION['redirect_after_login']);
    header("Location: " . $redirect);
    exit;
}

$error_msg = '';
$success_msg = '';

if (isset($_GET['logged_out']) && $_GET['logged_out'] == '1') {
    $success_msg = 'You have been safely logged out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);

    if (empty($username) || empty($password)) {
        $error_msg = 'Please enter both username and password.';
    } else {
        $result = login_user($username, $password, $remember);
        if ($result['success']) {
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            header("Location: " . $redirect);
            exit;
        } else {
            $error_msg = $result['message'];
        }
    }
}

$app_name = get_setting('app_name', 'QID Management System');
$company_name = get_setting('company_name', 'Qatar Business Solutions');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($app_name) ?></title>
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6.5 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bs-font-sans-serif: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --corporate-slate: #0f172a;
            --corporate-navy: #1e293b;
            --qatar-maroon: #8a1538;
            --qatar-maroon-dark: #6e102c;
            --accent-blue: #0284c7;
        }

        body {
            font-family: var(--bs-font-sans-serif);
            background: linear-gradient(135deg, #0b1120 0%, #0f172a 40%, #1e1b4b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            color: #1e293b;
            margin: 0;
        }

        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08);
            overflow: hidden;
            transition: transform 0.2s ease;
        }

        .login-header {
            background: linear-gradient(135deg, var(--corporate-slate) 0%, #1e293b 100%);
            color: #ffffff;
            padding: 36px 32px 28px;
            text-align: center;
            position: relative;
            border-bottom: 3px solid var(--qatar-maroon);
        }

        .brand-icon-box {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--qatar-maroon) 0%, #a21c44 100%);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #ffffff;
            box-shadow: 0 10px 20px rgba(138, 21, 56, 0.4);
            margin-bottom: 16px;
        }

        .login-title {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .login-subtitle {
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .login-body {
            padding: 32px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-control {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background-color: #f8fafc;
        }

        .form-control:focus {
            background-color: #ffffff;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.12);
        }

        .input-group-text {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            background-color: #f8fafc;
            color: #64748b;
        }

        .input-group .form-control:focus + .input-group-text,
        .input-group .form-control:focus {
            border-color: var(--accent-blue);
        }

        .btn-toggle-pwd {
            border-top-right-radius: 10px !important;
            border-bottom-right-radius: 10px !important;
            cursor: pointer;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--qatar-maroon) 0%, var(--qatar-maroon-dark) 100%);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 1rem;
            padding: 13px 20px;
            border-radius: 10px;
            width: 100%;
            box-shadow: 0 4px 12px rgba(138, 21, 56, 0.35);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #a21c44 0%, var(--qatar-maroon) 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(138, 21, 56, 0.45);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            color: #64748b;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Card Header -->
        <div class="login-header">
            <div class="brand-icon-box">
                <i class="fa-solid fa-id-card"></i>
            </div>
            <h1 class="login-title"><?= htmlspecialchars($app_name) ?></h1>
            <div class="login-subtitle"><?= htmlspecialchars($company_name) ?> &bull; Portal Access</div>
        </div>

        <!-- Card Body -->
        <div class="login-body">
            <!-- Alert Messages -->
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger d-flex align-items-center py-2 px-3 mb-4 rounded-3 border-0 shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5 text-danger"></i>
                    <div class="small fw-semibold"><?= htmlspecialchars($error_msg) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success d-flex align-items-center py-2 px-3 mb-4 rounded-3 border-0 shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-check me-2 fs-5 text-success"></i>
                    <div class="small fw-semibold"><?= htmlspecialchars($success_msg) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on">
                <!-- Username -->
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fa-solid fa-user-shield me-1 text-secondary"></i> Username
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                           placeholder="Enter your username" 
                           required 
                           autofocus 
                           autocomplete="username">
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="fa-solid fa-lock me-1 text-secondary"></i> Password
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password" 
                               required 
                               autocomplete="current-password">
                        <button class="btn btn-outline-secondary input-group-text btn-toggle-pwd" 
                                type="button" 
                                id="togglePasswordBtn" 
                                title="Show / Hide Password">
                            <i class="fa-regular fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember_me" id="rememberMe" checked>
                        <label class="form-check-label small text-secondary" for="rememberMe">
                            Stay signed in (30 days)
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-submit">
                    <span>Sign In to Dashboard</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Security & System Notice Footer -->
    <div class="login-footer">
        <div><i class="fa-solid fa-shield-halved me-1 text-muted"></i> Authorized Personnel Only &bull; TLS Encrypted Session</div>
        <div class="mt-1 opacity-75">&copy; <?= date('Y') ?> <?= htmlspecialchars($company_name) ?>. All rights reserved.</div>
    </div>
</div>

<script>
    // Password visibility toggle
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const pwdInput = document.getElementById('password');
    const eyeIcon = document.getElementById('togglePasswordIcon');

    if (toggleBtn && pwdInput && eyeIcon) {
        toggleBtn.addEventListener('click', function() {
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                pwdInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        });
    }
</script>
</body>
</html>
