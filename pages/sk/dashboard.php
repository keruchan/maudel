<?php
/**
 * ============================================================
 * File     : pages/sk/dashboard.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Landing dashboard for the SK Chairman (barangay) role.
 *            Skeleton pass — layout, navigation, and theme only.
 *            Figures below are static placeholders.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/verification.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/charters.php';
require_once __DIR__ . '/../../includes/analytics.php';

require_role('sk');

$currentRole = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$counts = $barangayId > 0 ? sked_verification_counts_for_barangay($barangayId) : ['pending' => 0, 'active' => 0, 'rejected' => 0];
$pendingCount = (int) $counts['pending'];
$memberCount = (int) $counts['active'];

$barangayEvents = $barangayId > 0 ? sked_events_for_manager('sk', $skUserId, $barangayId) : [];
$upcomingEvents = array_filter($barangayEvents, static fn($e) => in_array($e['status'], ['published', 'confirmed', 'ongoing'], true));
$totalRegistrations = 0;
foreach ($barangayEvents as $e) {
    $totalRegistrations += sked_participant_counts((int) $e['id'])['active'];
}
$allCharters = $barangayId > 0 ? sked_charters_for_barangay($barangayId) : [];
$activeProjects = count(array_filter($allCharters, static fn($c) => $c['status'] !== 'completed'));
$topRecommendations = $barangayId > 0 ? sked_recommend_categories($barangayId, 3) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | SK Chairman Dashboard</title>

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
        <?php render_sked_navigation($currentRole, 'dashboard'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">SK Chairman Dashboard</h1>
                        <p class="text-secondary meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Manage your barangay's youth profiles, events, and programs.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <section class="row g-3 mb-5" aria-label="SK Chairman dashboard metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card stagger-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-people"></i></span>
                            <span class="ledger-tag">Members</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo $memberCount; ?></div>
                        <div class="ledger-caption">KK members (barangay)</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber stagger-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-patch-check"></i></span>
                            <span class="ledger-tag">Pending</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo $pendingCount; ?></div>
                        <div class="ledger-caption">Profiles to validate</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal stagger-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-calendar-event"></i></span>
                            <span class="ledger-tag">Events</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo count($upcomingEvents); ?></div>
                        <div class="ledger-caption">Upcoming events</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-clipboard-data"></i></span>
                            <span class="ledger-tag">Projects</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo $activeProjects; ?></div>
                        <div class="ledger-caption">CBYDP projects</div>
                    </div>
                </div>
            </section>

            <section class="mb-5" aria-label="SK Chairman modules">
                <div class="section-heading">
                    <h2>Barangay Modules</h2>
                    <span class="section-note">4 modules available</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-people"></i></span>
                            <h3>KK Members</h3>
                            <p>Youth profiles registered under your barangay.</p>
                            <a class="link-open" href="verify.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-patch-check"></i></span>
                            <h3>Membership Validation</h3>
                            <p>Review and validate newly submitted youth profiles.</p>
                            <a class="link-open" href="verify.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-calendar-plus"></i></span>
                            <h3>Manage Events</h3>
                            <p>Create events, take attendance, and track participation.</p>
                            <a class="link-open" href="events.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-rust">
                            <span class="registry-icon"><i class="bi bi-megaphone"></i></span>
                            <h3>Announcements</h3>
                            <p>Post notices and program announcements to your youth.</p>
                            <a class="link-open" href="dashboard.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Barangay Docket</h2>
                            <span class="section-note">Today</span>
                        </div>
                        <a class="docket-row text-reset text-decoration-none" href="verify.php">
                            <div>
                                <div class="docket-title">Profiles awaiting validation <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">New youth registrations to review</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $pendingCount; ?> pending</span>
                        </a>
                        <a class="docket-row text-reset text-decoration-none" href="events.php">
                            <div>
                                <div class="docket-title">Upcoming events <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Events scheduled in your barangay</div>
                            </div>
                            <span class="count-badge tabular"><?php echo count($upcomingEvents); ?> planned</span>
                        </a>
                        <a class="docket-row text-reset text-decoration-none" href="events.php">
                            <div>
                                <div class="docket-title">Event registrations <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Youth signed up across your events</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $totalRegistrations; ?> total</span>
                        </a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>Council Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Youth profiling</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Event management</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Attendance</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Announcements</span>
                            <span class="status-ready">Ready</span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!empty($topRecommendations)): ?>
            <section class="mt-4">
                <div class="docket-panel">
                    <div class="section-heading">
                        <h2><i class="bi bi-lightbulb me-1"></i> Recommended Focus Areas</h2>
                        <a href="analytics.php" class="section-note text-decoration-none">See full breakdown <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($topRecommendations as $r): ?>
                        <div class="col-md-4">
                            <div class="ledger-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="ledger-icon"><i class="bi bi-graph-up-arrow"></i></span>
                                    <span class="ledger-tag tabular"><?php echo $r['score']; ?>/100</span>
                                </div>
                                <div class="ledger-value" style="font-size:1.1rem;"><?php echo e($r['category']); ?></div>
                                <div class="ledger-caption"><?php echo e(sked_explain_recommendation($r)); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
