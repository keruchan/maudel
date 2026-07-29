<?php
/**
 * ============================================================
 * File     : pages/youth/leaderboard.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Barangay-scoped youth engagement leaderboard. Shows the top
 *            10 verified youth in the signed-in youth's barangay based on
 *            participation points.
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
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$leaders = $barangayId > 0 ? sked_top_youth_by_engagement($barangayId, 10) : [];
$myRank = null;
$myPoints = $isDemo ? 0 : sked_total_points($userId);
foreach ($leaders as $idx => $leader) {
    if ((int) $leader['id'] === $userId) {
        $myRank = $idx + 1;
        break;
    }
}
$topPoints = !empty($leaders) ? max(1, (int) $leaders[0]['points']) : 1;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Top Youth</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=2">
    <style>
        .rank-chip { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--mist-150); color: var(--ink-700); font-weight: 800; }
        .rank-chip.rank-1 { background: var(--gold-100); color: var(--gold-600); }
        .rank-chip.rank-2 { background: var(--mist-200); color: var(--ink-700); }
        .rank-chip.rank-3 { background: var(--coral-100); color: var(--coral-600); }
        .leader-progress { height: 8px; border-radius: 999px; background: #e5e7f2; overflow: hidden; }
        .leader-progress > div { height: 100%; background: linear-gradient(90deg, #0f766e, #4338ca); }
        tr.is-me { background: rgba(67, 56, 202, 0.06); }
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'leaderboard'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Top 10 Youth</h1>
                        <p class="text-secondary meta-copy mb-0">Barangay youth ranked by engagement points from profiles, events, attendance, evaluations, and polls.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your account is not linked to a barangay yet.</div>
            <?php elseif (!$isVerified): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill me-1"></i>You can view your barangay leaderboard now. Your own engagement points start counting once your KK membership is verified.</div>
            <?php endif; ?>

            <?php if ($barangayId > 0): ?>
                <section class="row g-3 mb-4" aria-label="Leaderboard summary">
                    <div class="col-sm-4">
                        <div class="ledger-card accent-teal"><span class="ledger-tag">Displayed</span><div class="ledger-value tabular"><?php echo count($leaders); ?></div><div class="ledger-caption">Top youth in your barangay</div></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="ledger-card accent-amber"><span class="ledger-tag">My Rank</span><div class="ledger-value tabular"><?php echo $myRank !== null ? '#' . (int) $myRank : 'Outside Top 10'; ?></div><div class="ledger-caption"><?php echo (int) $myPoints; ?> engagement points</div></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="ledger-card"><span class="ledger-tag">Top Score</span><div class="ledger-value tabular"><?php echo !empty($leaders) ? (int) $leaders[0]['points'] : 0; ?></div><div class="ledger-caption">Highest points this barangay</div></div>
                    </div>
                </section>

                <section class="docket-panel">
                    <div class="section-heading">
                        <h2>Barangay Leaderboard</h2>
                        <span class="section-note">Top 10 by points</span>
                    </div>
                    <?php if (empty($leaders)): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-trophy fs-1 d-block mb-2"></i>No engagement points have been recorded yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="leaderboardTable">
                                <thead><tr><th scope="col">Rank</th><th scope="col">Youth</th><th scope="col">Level</th><th scope="col">Engagement</th><th scope="col" class="text-end">Points</th></tr></thead>
                                <tbody>
                                <?php foreach ($leaders as $idx => $leader):
                                    $rank = $idx + 1;
                                    $points = (int) $leader['points'];
                                    $level = sked_engagement_level($points);
                                    $pct = (int) round(min(100, max(0, $points / $topPoints * 100)));
                                    $isMe = (int) $leader['id'] === $userId;
                                ?>
                                    <tr class="<?php echo $isMe ? 'is-me' : ''; ?>">
                                        <td data-tt-sort="<?php echo $rank; ?>"><span class="rank-chip rank-<?php echo min(3, $rank); ?>"><?php echo $rank; ?></span></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo e((string) $leader['name']); ?><?php echo $isMe ? ' <span class="badge text-bg-primary ms-1">You</span>' : ''; ?></div>
                                            <div class="small text-secondary">@<?php echo e((string) $leader['username']); ?></div>
                                        </td>
                                        <td><span class="badge text-bg-light text-secondary border"><i class="bi <?php echo e((string) $level['level']['icon']); ?> me-1"></i><?php echo e((string) $level['level']['label']); ?></span></td>
                                        <td style="min-width:180px;">
                                            <div class="small text-secondary mb-1"><?php echo (int) $leader['engagement_count']; ?> point-earning action<?php echo (int) $leader['engagement_count'] === 1 ? '' : 's'; ?></div>
                                            <div class="leader-progress"><div style="width:<?php echo $pct; ?>%"></div></div>
                                        </td>
                                        <td class="text-end tabular fw-semibold" data-tt-sort="<?php echo $points; ?>"><?php echo $points; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#leaderboardTable', { pageSize: 10, searchPlaceholder: 'Search leaderboard...' });
    </script>
</body>
</html>
