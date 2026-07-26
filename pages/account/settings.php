<?php
/**
 * ============================================================
 * File     : pages/account/settings.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared self-service Account Settings page for every role
 *            (DILG, PPSK, SK, Youth). Change password and edit own
 *            contact details. Sensitive fields (name, email, role,
 *            barangay) stay under SK/DILG control for governance
 *            integrity and are shown read-only here.
 *
 * Lives in pages/account/ (a sibling of the role folders), so the
 * shared sidebar is rendered with a role-scoped $linkBase and the
 * dashboard "back" link is resolved from the current folder.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/sk_members.php';

require_roles(sked_roles()); // any signed-in role

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$isDemo = $userId < 1000; // demo accounts (1-4) aren't persisted in the DB
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Account';

// Live account record for registered users (demo accounts have none).
$account = $isDemo ? null : sked_find_user_by_id($userId);

$pwErrors = [];
$pwSuccess = false;
$contactErrors = [];
$contactSuccess = false;

if (empty($_SESSION['csrf_account_token'])) {
    $_SESSION['csrf_account_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_account_token'] ?? '');
    $tokenOk = $sessionToken !== '' && hash_equals($sessionToken, $submittedToken);
    $formName = (string) ($_POST['form'] ?? '');

    if (!$tokenOk) {
        $msg = 'Security validation failed. Please refresh the page and try again.';
        if ($formName === 'contact') { $contactErrors[] = $msg; } else { $pwErrors[] = $msg; }
    } elseif ($isDemo) {
        $msg = 'Demo accounts cannot change their credentials. Sign in with a registered account.';
        if ($formName === 'contact') { $contactErrors[] = $msg; } else { $pwErrors[] = $msg; }
    } elseif ($formName === 'password') {
        $result = sked_update_password(
            $userId,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        $pwSuccess = $result['ok'];
        $pwErrors = $result['errors'];
    } elseif ($formName === 'contact') {
        $result = sked_update_own_contact($userId, (string) ($_POST['mobile'] ?? ''));
        $contactSuccess = $result['ok'];
        $contactErrors = $result['errors'];
        if ($contactSuccess) {
            $account = sked_find_user_by_id($userId); // refresh
        }
    }
}

$linkBase = '../' . $role . '/';
$dashboardHref = dashboard_path_for_role($role) ?? ($linkBase . 'dashboard.php');
$todayLabel = date('l, F j, Y');

// Read-only display values.
$acctEmail = $account['email'] ?? (string) ($_SESSION['username'] ?? '');
$acctMobile = $account['mobile'] ?? '';
$acctBarangay = isset($account['barangay_id']) && $account['barangay_id'] !== null
    ? sked_barangay_name((int) $account['barangay_id']) : '';
$acctPurok = trim((string) ($account['purok'] ?? ''));
$acctStatus = $account['status'] ?? (string) ($_SESSION['status'] ?? 'active');
$officialBadge = (!$isDemo && $role === 'youth') ? sked_sk_official_badge_for_user($userId) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Account Settings</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-shell">
        <?php render_sked_navigation($role, 'account_settings', $linkBase); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow"><?php echo e(sked_role_display_label($role)); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Account Settings</h1>
                        <p class="text-secondary meta-copy mb-0">Manage your sign-in credentials and contact details.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="<?php echo e($dashboardHref); ?>">
                            <i class="bi bi-arrow-left me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <?php if ($isDemo): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    You are signed in with a <strong>demo account</strong>. Credential changes are disabled for demo accounts and available to registered accounts only.
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Account overview -->
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Account Overview</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary">Name</span>
                            <span><?php echo e($displayName); ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary">Role</span>
                            <span><?php echo e(sked_role_display_label($role)); ?></span>
                        </div>
                        <?php if ($officialBadge !== null): ?>
                        <div class="snapshot-row">
                            <span class="text-secondary">Recognized SK Position</span>
                            <span><i class="bi bi-person-badge me-1"></i><?php echo e((string) $officialBadge['position']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="snapshot-row">
                            <span class="text-secondary">Status</span>
                            <span><?php echo e(ucfirst($acctStatus)); ?></span>
                        </div>
                        <?php if ($acctEmail !== ''): ?>
                        <div class="snapshot-row">
                            <span class="text-secondary">Email</span>
                            <span><?php echo e($acctEmail); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($acctBarangay !== ''): ?>
                        <div class="snapshot-row">
                            <span class="text-secondary">Region</span>
                            <span><?php echo e(SKED_REGION_NAME); ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary">Province</span>
                            <span><?php echo e(SKED_PROVINCE_NAME); ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary">Municipality</span>
                            <span><?php echo e(SKED_DEFAULT_MUNICIPALITY); ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary">Barangay</span>
                            <span><?php echo e($acctBarangay); ?></span>
                        </div>
                        <?php if ($acctPurok !== ''): ?>
                        <div class="snapshot-row">
                            <span class="text-secondary">Purok</span>
                            <span><?php echo e($acctPurok); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <p class="text-secondary small mt-3 mb-0">
                            <i class="bi bi-shield-lock me-1"></i>
                            Name, email, role, and address are managed by your SK/DILG to keep records accurate.
                        </p>
                    </div>
                </div>

                <div class="col-lg-7">
                    <!-- Contact details -->
                    <div class="docket-panel mb-4">
                        <div class="section-heading">
                            <h2>Contact Details</h2>
                        </div>
                        <?php if ($contactSuccess): ?>
                            <div class="alert alert-success" role="alert"><i class="bi bi-check-circle-fill me-1"></i> Mobile number updated.</div>
                        <?php endif; ?>
                        <?php if (!empty($contactErrors)): ?>
                            <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($contactErrors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
                        <?php endif; ?>
                        <form method="post" action="settings.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_account_token']); ?>">
                            <input type="hidden" name="form" value="contact">
                            <div class="mb-3">
                                <label for="mobile" class="form-label">Mobile number</label>
                                <input type="tel" class="form-control" id="mobile" name="mobile" value="<?php echo e((string) $acctMobile); ?>" placeholder="09XX XXX XXXX" <?php echo $isDemo ? 'disabled' : ''; ?>>
                            </div>
                            <button type="submit" class="btn btn-sked" <?php echo $isDemo ? 'disabled' : ''; ?>>Save contact details</button>
                        </form>
                    </div>

                    <!-- Change password -->
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Change Password</h2>
                        </div>
                        <?php if ($pwSuccess): ?>
                            <div class="alert alert-success" role="alert"><i class="bi bi-check-circle-fill me-1"></i> Your password has been updated.</div>
                        <?php endif; ?>
                        <?php if (!empty($pwErrors)): ?>
                            <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($pwErrors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
                        <?php endif; ?>
                        <form method="post" action="settings.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_account_token']); ?>">
                            <input type="hidden" name="form" value="password">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" <?php echo $isDemo ? 'disabled' : ''; ?>>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="new_password" class="form-label">New password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" placeholder="At least 6 characters" <?php echo $isDemo ? 'disabled' : ''; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label">Confirm new password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" <?php echo $isDemo ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sked mt-3" <?php echo $isDemo ? 'disabled' : ''; ?>><i class="bi bi-shield-lock"></i>Update password</button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
