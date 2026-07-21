<?php
/**
 * ============================================================
 * File     : pages/manage/kk_profile.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK-assisted KK Profiling entry (P11 v2). Lets a Barangay SK
 *            Chairman fill out or update the KK Profiling questionnaire on
 *            behalf of a youth in their own barangay (e.g. during a
 *            face-to-face profiling drive, before or after verification).
 *            Reuses the exact same form as the youth's own
 *            pages/youth/profile.php via includes/profiling_view.php.
 *
 * Lives in pages/manage/ (sibling of role folders), like pages/manage/event.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/verification.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/profiling_view.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');
$linkBase = '../' . $role . '/';
$rosterHref = '../sk/profiling.php';

$youthId = (int) ($_GET['youth_id'] ?? $_POST['youth_id'] ?? 0);
$youth = $barangayId > 0 ? sked_youth_in_barangay($youthId, $barangayId) : null;

if ($youth === null) {
    header('Location: ' . $rosterHref);
    exit;
}

$errors = [];
$success = false;
$pointsAwarded = false;

if (empty($_SESSION['csrf_kkprofile_token'])) {
    $_SESSION['csrf_kkprofile_token'] = bin2hex(random_bytes(32));
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_kkprofile_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh and try again.';
    } else {
        $result = sked_save_youth_profile($youthId, $_POST, $skUserId, 'sk');
        $success = $result['ok'];
        $errors = $result['errors'];
        $pointsAwarded = $result['points_awarded'];
    }
}

$identity = sked_user_identity($youthId) ?? [];
$profile = sked_get_youth_profile($youthId);
$hasProfile = $profile !== null;
$v = $isPost ? $_POST : sked_profile_view_defaults($profile);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | KK Profiling &mdash; <?php echo e((string) $youth['name']); ?></title>

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
        <?php render_sked_navigation($role, 'kk_profiling', $linkBase); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Assisted Entry &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">KK Profiling &mdash; <?php echo e((string) $youth['name']); ?></h1>
                        <p class="text-secondary meta-copy mb-0">Filled on behalf of this KK member by their Barangay SK. Status: <span class="text-capitalize"><?php echo e((string) $youth['status']); ?></span>.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="<?php echo e($rosterHref); ?>"><i class="bi bi-arrow-left me-1"></i> Roster</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    KK profile saved for <?php echo e((string) $youth['name']); ?>.<?php echo $pointsAwarded ? ' <strong>+' . (int) sked_points_for('profiling_completed') . ' participation points</strong> awarded to them!' : ''; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php if ($hasProfile && !$isPost): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-check2-circle me-1"></i>This KK member already has a saved profile. You can update their answers below.</div>
            <?php endif; ?>

            <?php sked_render_kk_profile_form($v, $identity, false, (string) $_SESSION['csrf_kkprofile_token'], 'kk_profile.php?youth_id=' . $youthId); ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
