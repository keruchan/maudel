<?php
/**
 * ============================================================
 * File     : pages/youth/activity.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth "Profile" page (P5 gamification). Shows identity,
 *            verification status, activity level derived from accrued
 *            participation points, progress to the next tier, and the
 *            full points ledger history.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/points.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$account = $isDemo ? null : sked_find_user_by_id($userId);
$barangayName = ($account !== null && $account['barangay_id'] !== null) ? sked_barangay_name((int) $account['barangay_id']) : '';
$formerBadge = $account['former_role_badge'] ?? null;

$totalPoints = $isDemo ? 0 : sked_total_points($userId);
$activity = sked_activity_level($totalPoints);
$history = $isDemo ? [] : sked_points_history($userId, 50);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | My Profile</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
    <style>
        .level-badge { display:inline-flex; align-items:center; justify-content:center; width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg,#4338ca,#818cf8); color:#fff; font-size:1.75rem; }
        .level-progress { height:10px; border-radius:999px; background:#e5e7f2; overflow:hidden; }
        .level-progress > div { height:100%; background:linear-gradient(90deg,#4338ca,#818cf8); }
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'profile'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">My Profile</h1>
                        <p class="text-secondary meta-copy mb-0">Your identity, verification status, and activity on SKed.</p>
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

            <?php if ($isDemo): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill me-1"></i> Demo account: activity and points are not tracked for demo logins.</div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>Identity</h2></div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="avatar-dot" style="width:48px;height:48px;font-size:1.1rem;"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <div>
                                <div class="fw-semibold"><?php echo e($displayName); ?></div>
                                <div class="small text-secondary">Youth / Community</div>
                            </div>
                        </div>
                        <?php if ($formerBadge): ?>
                            <div class="mb-2"><span class="badge text-bg-warning text-dark"><i class="bi bi-patch-check-fill me-1"></i><?php echo e((string) $formerBadge); ?></span></div>
                        <?php endif; ?>
                        <div class="snapshot-row"><span class="text-secondary">Verification</span><span class="badge <?php echo $isVerified ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo $isVerified ? 'Verified' : 'Pending'; ?></span></div>
                        <?php if ($barangayName !== ''): ?><div class="snapshot-row"><span class="text-secondary">Barangay</span><span><?php echo e($barangayName); ?></span></div><?php endif; ?>
                        <?php if ($account !== null): ?><div class="snapshot-row"><span class="text-secondary">Member since</span><span><?php echo e(date('M Y', strtotime((string) $account['created_at']))); ?></span></div><?php endif; ?>
                    </div>

                    <div class="docket-panel">
                        <div class="section-heading"><h2>Activity Level</h2></div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="level-badge"><i class="bi <?php echo e($activity['level']['icon']); ?>"></i></span>
                            <div>
                                <div class="fw-bold fs-5"><?php echo e($activity['level']['label']); ?></div>
                                <div class="text-secondary small"><?php echo (int) $activity['points']; ?> total points</div>
                            </div>
                        </div>
                        <?php if ($activity['next'] !== null): ?>
                            <div class="level-progress mb-2"><div style="width:<?php echo (int) $activity['progress_pct']; ?>%"></div></div>
                            <p class="small text-secondary mb-0"><?php echo (int) $activity['points_to_next']; ?> points to <strong><?php echo e($activity['next']['label']); ?></strong></p>
                        <?php else: ?>
                            <div class="level-progress mb-2"><div style="width:100%"></div></div>
                            <p class="small text-secondary mb-0"><i class="bi bi-stars me-1"></i>You've reached the highest tier!</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Points History</h2>
                            <span class="section-note"><?php echo count($history); ?> entries</span>
                        </div>
                        <?php if (empty($history)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-clock-history fs-1 d-block mb-2"></i>No activity yet. Complete your profile or join an event to start earning points.</div>
                        <?php else: ?>
                            <div class="table-responsive"><table class="table align-middle">
                                <thead><tr><th>Activity</th><th>Date</th><th class="text-end">Points</th></tr></thead>
                                <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td>
                                            <?php echo e(sked_points_action_label((string) $h['action_type'])); ?>
                                            <?php if (!empty($h['event_title'])): ?><span class="text-secondary">&mdash; <?php echo e((string) $h['event_title']); ?></span><?php endif; ?>
                                        </td>
                                        <td class="small text-secondary"><?php echo e(date('M j, Y', strtotime((string) $h['created_at']))); ?></td>
                                        <td class="text-end"><span class="badge text-bg-success">+<?php echo (int) $h['points']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
