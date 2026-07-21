<?php
/**
 * ============================================================
 * File     : pages/auth/login.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Demo login for the four roles (DILG, PPSK, SK
 *            Chairman, Youth).
 *
 * NOTE (skeleton pass):
 *   There is no database and no password check in this pass. Enter
 *   one of the demo usernames (1, 2, 3, or 4) to open the matching
 *   role dashboard. The password field is present for layout parity
 *   but is ignored. Real authentication replaces this later.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';

// Already signed in? Go straight to the right dashboard.
if (!empty($_SESSION['id']) && !empty($_SESSION['role'])) {
    if (!redirect_by_role((string) $_SESSION['role'])) {
        sked_clear_session();
    }
}

$errors = [];
$loginIdentifier = '';
$timedOut = ($_GET['timeout'] ?? '') === '1';

if (empty($_SESSION['csrf_login_token'])) {
    $_SESSION['csrf_login_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginIdentifier = trim((string) ($_POST['login_identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_login_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($loginIdentifier === '') {
        $errors[] = 'Username is required.';
    }

    if (empty($errors)) {
        // Demo shortcuts (usernames "1"-"4") need no password. Anything else
        // is checked against registered community accounts.
        if (sked_demo_login($loginIdentifier)) {
            unset($_SESSION['csrf_login_token']);
            $dashboardPath = dashboard_path_for_role((string) $_SESSION['role']);
            header('Location: ' . $dashboardPath);
            exit;
        }

        if ($password !== '' && sked_registered_login($loginIdentifier, $password)) {
            unset($_SESSION['csrf_login_token']);
            $dashboardPath = dashboard_path_for_role((string) $_SESSION['role']);
            header('Location: ' . $dashboardPath);
            exit;
        }

        $errors[] = 'We could not sign you in. Check your username/email and password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Login</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="auth.css">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-pane auth-pane-brief">
            <div class="pane-grid" aria-hidden="true"></div>
            <div class="pane-ring" aria-hidden="true"></div>

            <a href="../index.php" class="brand-row text-decoration-none">
                <span class="brand-seal" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
                <span>
                    <span class="brand-word d-block">SKed</span>
                    <span class="brand-sub d-block">Sangguniang Kabataan</span>
                </span>
            </a>

            <div class="pane-body">
                <p class="pane-eyebrow">Youth Profiling &amp; Event Management</p>
                <h1 class="pane-headline">One directory for every SK program.</h1>

                <ul class="feature-list list-unstyled">
                    <li>
                        <span class="feature-icon feature-icon-gold" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
                        <span>
                            <span class="feature-title">Youth profiles</span>
                            <span class="feature-desc">Centralized records across every barangay</span>
                        </span>
                    </li>
                    <li>
                        <span class="feature-icon feature-icon-sky" aria-hidden="true"><i class="bi bi-calendar2-week"></i></span>
                        <span>
                            <span class="feature-title">Event management</span>
                            <span class="feature-desc">Plan, track, and report SK activities</span>
                        </span>
                    </li>
                    <li>
                        <span class="feature-icon feature-icon-coral" aria-hidden="true"><i class="bi bi-clipboard2-check"></i></span>
                        <span>
                            <span class="feature-title">Program oversight</span>
                            <span class="feature-desc">Keep DILG and PPSK reporting audit-ready</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div class="pane-roles">
                <p class="pane-roles-label">Authorized roles</p>
                <div class="role-pills">
                    <span class="role-pill">DILG</span>
                    <span class="role-pill">PPSK</span>
                    <span class="role-pill">SK Chairman</span>
                    <span class="role-pill">Youth</span>
                </div>
            </div>
        </section>

        <section class="auth-pane auth-pane-form">
            <div class="form-wrap">
                <p class="section-label mb-2">Sign in</p>
                <h2 class="auth-title mb-1">Welcome back</h2>
                <p class="text-secondary mb-4">Sign in with your registered username or email and password.</p>

                <?php if ($timedOut): ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="bi bi-clock-history me-1"></i>
                        You were signed out after 30 minutes of inactivity. Please sign in again.
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="login.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_login_token']); ?>">

                    <div class="field-group mb-3">
                        <label for="login_identifier" class="form-label">Username or email</label>
                        <div class="input-affix">
                            <i class="bi bi-person"></i>
                            <input type="text" class="form-control" id="login_identifier" name="login_identifier" value="<?php echo e($loginIdentifier); ?>" autocomplete="username" placeholder="Enter your username or email" required>
                        </div>
                    </div>

                    <div class="field-group mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-affix">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" placeholder="Enter your password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-sked w-100">Login <i class="bi bi-arrow-right ms-1"></i></button>
                </form>

                <p class="auth-switch mt-3 mb-0">New to SKed? <a href="register.php" class="auth-link">Create a youth account</a></p>
            </div>
        </section>
    </main>
</body>
</html>
