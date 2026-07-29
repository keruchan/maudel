<?php
/**
 * ============================================================
 * File     : pages/sk/analytics.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK deep analytics (P16). Barangay-scoped three-layer view —
 *            Descriptive / Predictive / Prescriptive — over youth
 *            registrations, event participation, membership, interests,
 *            and events. Charts + per-chart insights + OLS forecasts +
 *            rule-based recommendations. See includes/analytics.php (data)
 *            and includes/analytics_view.php (rendering).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/analytics_view.php';
require_once __DIR__ . '/../../includes/feedback.php';
require_once __DIR__ . '/../../includes/feedback_insights.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$scope = $barangayId > 0 ? $barangayId : null;
$hasScope = $barangayId > 0;

if ($hasScope) {
    $bundle = sked_analytics_forecast_bundle($scope, 12, 3);
    $distributions = sked_analytics_distributions($scope);
    $profiling = sked_analytics_profiling_completion($scope);
    $ranked = sked_recommend_categories($scope, 5);
    $openFeedback = sked_open_feedback_count($barangayId);
    $recommendations = sked_analytics_recommendations($scope, $bundle, $openFeedback);
    $feedbackInsights = sked_feedback_insights_bundle($scope);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=2">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'analytics'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Analytics</h1>
                        <p class="text-secondary meta-copy mb-0">Descriptive, predictive, and prescriptive insight into youth engagement in your barangay.</p>
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

            <?php if (!$hasScope): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet, so there's nothing to analyze.</div>
            <?php else: ?>
                <?php sked_render_analytics_body($bundle, $distributions, $profiling, $ranked, $recommendations, 'your barangay', $feedbackInsights); ?>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
