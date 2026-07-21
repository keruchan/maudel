<?php
/**
 * ============================================================
 * File     : pages/youth/feedback.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth "Feedback / Concerns" (P15). Send a private note
 *            straight to your Barangay SK; track its review status.
 *            Verified-only, same gate as other participation features.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/feedback.php';

require_role('youth');
sked_require_verified();

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isDemo = $userId < 1000;

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_feedback_token'])) {
    $_SESSION['csrf_feedback_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_feedback_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($isDemo) {
        $flash = ['type' => 'warning', 'msg' => 'Demo account: sending is preview-only and was not saved.'];
    } else {
        $submitter = ['id' => $userId, 'barangay_id' => $barangayId];
        $r = sked_submit_feedback($submitter, (string) ($_POST['message'] ?? ''));
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Sent to your Barangay SK.']
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    }
}

$history = (!$isDemo) ? sked_feedback_for_youth($userId) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Feedback / Concerns</title>
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
        <?php render_sked_navigation($role, 'feedback'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Feedback / Concerns</h1>
                        <p class="text-secondary meta-copy mb-0">Send a note straight to your Barangay SK — a suggestion, a concern, anything on your mind.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo $flash['msg']; ?></div><?php endif; ?>
            <?php if ($isDemo): ?><div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill me-1"></i>Demo account: you can try the form, but nothing is saved.</div><?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Send a Message</h2></div>
                        <form method="post" action="feedback.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_feedback_token']); ?>">
                            <div class="mb-3">
                                <label for="message" class="form-label">Your message</label>
                                <textarea class="form-control" id="message" name="message" rows="6" maxlength="2000" placeholder="Share a suggestion or concern with your Barangay SK…" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-send me-1"></i> Send to my SK</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Your Messages</h2><span class="section-note"><?php echo count($history); ?></span></div>
                        <?php if (empty($history)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-chat-left-text fs-1 d-block mb-2"></i>You haven't sent anything yet.</div>
                        <?php else: ?>
                            <?php foreach ($history as $f): ?>
                                <div class="docket-row d-block">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="small text-secondary"><?php echo e(date('M j, Y g:i A', strtotime((string) $f['created_at']))); ?></div>
                                        <span class="badge <?php echo $f['status'] === 'reviewed' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $f['status']); ?></span>
                                    </div>
                                    <p class="mb-0 mt-1"><?php echo nl2br(e((string) $f['message'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
