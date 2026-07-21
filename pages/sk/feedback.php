<?php
/**
 * ============================================================
 * File     : pages/sk/feedback.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK "Feedback / Concerns" inbox (P15). Every message a youth
 *            in this barangay has sent, oldest open one first; mark
 *            reviewed once addressed (notifies the youth).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/feedback.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_skfeedback_token'])) {
    $_SESSION['csrf_skfeedback_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_skfeedback_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $r = sked_mark_feedback_reviewed((int) ($_POST['feedback_id'] ?? 0), $skUserId, $barangayId);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Marked reviewed.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    }
}

$messages = $barangayId > 0 ? sked_feedback_for_barangay($barangayId) : [];
$openCount = count(array_filter($messages, static fn($m) => $m['status'] === 'open'));
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
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Feedback / Concerns</h1>
                        <p class="text-secondary meta-copy mb-0">Messages sent directly to you by youth in your barangay.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Messages</h2>
                    <span class="section-note"><?php echo $openCount; ?> open &middot; <?php echo count($messages); ?> total</span>
                </div>
                <?php if (empty($messages)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-chat-left-text fs-1 d-block mb-2"></i>Nothing yet.</div>
                <?php else: ?>
                    <?php foreach ($messages as $f): ?>
                        <div class="docket-row d-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="docket-title"><?php echo e((string) $f['name']); ?> <span class="small text-secondary fw-normal">@<?php echo e((string) $f['username']); ?></span></div>
                                    <div class="docket-sub"><?php echo e(date('M j, Y g:i A', strtotime((string) $f['created_at']))); ?></div>
                                </div>
                                <span class="badge <?php echo $f['status'] === 'reviewed' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $f['status']); ?></span>
                            </div>
                            <p class="mb-2 mt-2"><?php echo nl2br(e((string) $f['message'])); ?></p>
                            <?php if ($f['status'] === 'open'): ?>
                                <form method="post" action="feedback.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_skfeedback_token']); ?>">
                                    <input type="hidden" name="feedback_id" value="<?php echo (int) $f['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check-lg me-1"></i>Mark reviewed</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
