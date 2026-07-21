<?php
/**
 * ============================================================
 * File     : pages/youth/profile.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth (KK) profiling questionnaire (P11 v2), self-service.
 *            Verified youth fill out the real Brgy. KK Profiling form
 *            (informed consent, Profile, Demographic Characteristics,
 *            KK Assembly, KK Suggestions). Completing it awards
 *            participation points and feeds prescriptive analytics.
 *            Gated: verified accounts only (spec 4.4 / 6.2). The same
 *            form is also reachable by the barangay SK Chairman on a
 *            youth's behalf — see pages/manage/kk_profile.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/profiling_view.php';

require_role('youth');
sked_require_verified(); // profiling unlocks only after SK verification

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');

$errors = [];
$success = false;
$pointsAwarded = false;

if (empty($_SESSION['csrf_profile_token'])) {
    $_SESSION['csrf_profile_token'] = bin2hex(random_bytes(32));
}

// Demo youth (id < 1000) aren't persisted, so saving would fail the FK.
$isDemo = $userId < 1000;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_profile_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh and try again.';
    } elseif ($isDemo) {
        $errors[] = 'This is a demo account, so the questionnaire is preview-only and cannot be saved.';
    } else {
        $result = sked_save_youth_profile($userId, $_POST, $userId, 'youth');
        $success = $result['ok'];
        $errors = $result['errors'];
        $pointsAwarded = $result['points_awarded'];
    }
}

$identity = sked_user_identity($userId) ?? [];
$profile = $isDemo ? null : sked_get_youth_profile($userId);
$hasProfile = $profile !== null;
$v = $isPost ? $_POST : sked_profile_view_defaults($profile);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | KK Profiling</title>

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
        <?php render_sked_navigation($role, 'my_profile'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">KK Profiling</h1>
                        <p class="text-secondary meta-copy mb-0">Katipunan ng Kabataan Database — your Barangay SK uses this to plan youth programs.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <?php if ($isDemo): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Demo account: this questionnaire is preview-only and won't be saved. Register a real youth account to complete your profile.
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    Your KK profile has been saved.<?php echo $pointsAwarded ? ' <strong>+' . (int) sked_points_for('profiling_completed') . ' participation points</strong> awarded!' : ''; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <?php if ($hasProfile && !$isPost): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-check2-circle me-1"></i>You've already completed your KK profile. You can update your answers below anytime.</div>
            <?php endif; ?>

            <?php sked_render_kk_profile_form($v, $identity, $isDemo, (string) $_SESSION['csrf_profile_token'], 'profile.php'); ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
