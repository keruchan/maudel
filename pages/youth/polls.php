<?php
/**
 * ============================================================
 * File     : pages/youth/polls.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth "Community Polls" (P6, spec 4.3/4.4). Verified youth
 *            answer polls open in their barangay; voting awards points.
 *            Results are shown once a youth has voted (or the poll closed).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/polls.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_ypolls_token'])) {
    $_SESSION['csrf_ypolls_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_ypolls_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif (!$isVerified) {
        $flash = ['type' => 'warning', 'msg' => 'Your account must be verified before you can vote.'];
    } elseif ($isDemo) {
        $flash = ['type' => 'warning', 'msg' => 'Demo account: voting is preview-only and was not saved.'];
    } else {
        $r = sked_cast_poll_vote($userId, $barangayId, (int) ($_POST['poll_id'] ?? 0), (int) ($_POST['option_id'] ?? 0));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Thanks for voting! Participation points awarded.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    }
}

$polls = ($isVerified && $barangayId > 0) ? sked_open_polls_for_youth($barangayId) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Community Polls</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=2">
    <style>.poll-bar{height:8px;border-radius:999px;background:#e5e7f2;overflow:hidden;} .poll-bar>div{height:100%;background:linear-gradient(90deg,#4338ca,#818cf8);}</style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'polls'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Community Polls</h1>
                        <p class="text-secondary meta-copy mb-0">Have your say — your SK uses these results to plan programs.</p>
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

            <?php if (!$isVerified): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-lock-fill me-1"></i> Voting unlocks once your Barangay SK verifies your KK profile.</div>
            <?php elseif ($isDemo): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill me-1"></i> Demo account: you can browse polls, but voting is preview-only.</div>
            <?php endif; ?>

            <?php if ($isVerified): ?>
                <?php if (empty($polls)): ?>
                    <div class="docket-panel text-center text-secondary py-5"><i class="bi bi-bar-chart fs-1 d-block mb-2"></i>No open polls right now. Check back soon.</div>
                <?php else: ?>
                    <div class="row g-3">
                    <?php foreach ($polls as $p):
                        $myVote = $isDemo ? null : sked_youth_poll_vote($userId, (int) $p['id']);
                        $res = sked_poll_results((int) $p['id']);
                    ?>
                        <div class="col-md-6">
                            <div class="registry-card h-100">
                                <span class="registry-icon"><i class="bi bi-bar-chart-steps"></i></span>
                                <h3><?php echo e((string) $p['question']); ?></h3>
                                <?php if (!empty($p['closes_at'])): ?>
                                    <p class="small text-secondary mb-2"><i class="bi bi-clock me-1"></i>Closes <?php echo e(date('M j, Y g:i A', strtotime((string) $p['closes_at']))); ?></p>
                                <?php endif; ?>

                                <?php if ($myVote !== null): ?>
                                    <p class="small text-success mb-2"><i class="bi bi-check-circle-fill me-1"></i>You voted &mdash; thanks for your input!</p>
                                    <?php foreach ($res['options'] as $o): $pct = $res['total'] > 0 ? (int) round($o['votes'] / $res['total'] * 100) : 0; ?>
                                        <div class="small d-flex justify-content-between">
                                            <span><?php echo $o['id'] === $myVote ? '<strong>' : ''; ?><?php echo e((string) $o['option_text']); ?><?php echo $o['id'] === $myVote ? ' <i class="bi bi-check2"></i></strong>' : ''; ?></span>
                                            <span class="text-secondary"><?php echo $o['votes']; ?> (<?php echo $pct; ?>%)</span>
                                        </div>
                                        <div class="poll-bar mb-2"><div style="width:<?php echo $pct; ?>%"></div></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <form method="post" action="polls.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_ypolls_token']); ?>">
                                        <input type="hidden" name="poll_id" value="<?php echo (int) $p['id']; ?>">
                                        <?php foreach ($res['options'] as $o): ?>
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="radio" name="option_id" id="opt<?php echo (int) $o['id']; ?>" value="<?php echo (int) $o['id']; ?>" required>
                                                <label class="form-check-label" for="opt<?php echo (int) $o['id']; ?>"><?php echo e((string) $o['option_text']); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <button type="submit" class="btn btn-sm btn-sked mt-2" <?php echo $isDemo ? 'disabled' : ''; ?>><i class="bi bi-send-check me-1"></i> Submit vote</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
