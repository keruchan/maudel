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
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/sk_members.php';
require_once __DIR__ . '/../../includes/polls.php';
require_once __DIR__ . '/../../includes/reports.php';

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
$ongoingEvents = array_filter($barangayEvents, static fn($e) => sked_event_time_bucket($e) === 'ongoing');
$upcomingEvents = array_filter($barangayEvents, static fn($e) => sked_event_time_bucket($e) === 'upcoming');
$totalRegistrations = 0;
foreach ($barangayEvents as $e) {
    $totalRegistrations += sked_participant_counts((int) $e['id'])['active'];
}
$topRecommendations = $barangayId > 0 ? sked_recommend_categories($barangayId, 3) : [];

$totalMembers = $memberCount + $pendingCount + (int) $counts['rejected'];
$profileSummary = $barangayId > 0 ? sked_youth_profile_summary() : [];
$myProfileRow = null;
foreach ($profileSummary as $row) {
    if ($row['barangay_id'] === $barangayId) { $myProfileRow = $row; break; }
}
$profiledCount = $myProfileRow['profiled_count'] ?? 0;
$activeOfficialsCount = $barangayId > 0 ? count(sked_sk_officials_for_barangay($barangayId)) : 0;
$openPollsCount = $barangayId > 0 ? count(array_filter(sked_polls_for_barangay($barangayId), static fn ($p) => $p['status'] === 'open')) : 0;
$topFocusCategory = $topRecommendations[0]['category'] ?? null;
$monthlyReportSubmitted = $barangayId > 0 ? sked_has_submitted_monthly_report($barangayId, date('Y-m')) : false;
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

            <section class="mb-5" aria-label="SK Chairman dashboard data links">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card stagger-1 text-reset text-decoration-none d-block" href="kk_members.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-people"></i></span>
                                <span class="ledger-tag">Registry</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $totalMembers; ?></div>
                            <div class="ledger-caption">KK Members</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber stagger-2 text-reset text-decoration-none d-block" href="verify.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-patch-check"></i></span>
                                <span class="ledger-tag">Pending</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingCount; ?></div>
                            <div class="ledger-caption">Membership Validation</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal stagger-3 text-reset text-decoration-none d-block" href="profiling.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-clipboard2-data"></i></span>
                                <span class="ledger-tag">Profiled</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $profiledCount; ?></div>
                            <div class="ledger-caption">KK Profiling</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust stagger-4 text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-calendar-plus"></i></span>
                                <span class="ledger-tag">Ongoing</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo count($ongoingEvents); ?></div>
                            <div class="ledger-caption">Ongoing Events</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card text-reset text-decoration-none d-block" href="members.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-person-badge"></i></span>
                                <span class="ledger-tag">Officials</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $activeOfficialsCount; ?></div>
                            <div class="ledger-caption">SK Members</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal text-reset text-decoration-none d-block" href="polls.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-bar-chart-steps"></i></span>
                                <span class="ledger-tag">Polls</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $openPollsCount; ?></div>
                            <div class="ledger-caption">Community Polls open</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber text-reset text-decoration-none d-block" href="analytics.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-bar-chart-line"></i></span>
                                <span class="ledger-tag">Insight</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.1rem;"><?php echo e($topFocusCategory ?? '—'); ?></div>
                            <div class="ledger-caption">Analytics — top focus</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust text-reset text-decoration-none d-block" href="reports.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-file-earmark-text"></i></span>
                                <span class="ledger-tag">This month</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.1rem;"><?php echo $monthlyReportSubmitted ? 'Submitted' : 'Not yet'; ?></div>
                            <div class="ledger-caption">Monthly Reports</div>
                        </a>
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
                            <a class="ledger-card text-reset text-decoration-none d-block" href="analytics.php">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="ledger-icon"><i class="bi bi-graph-up-arrow"></i></span>
                                    <span class="ledger-tag tabular"><?php echo $r['score']; ?>/100</span>
                                </div>
                                <div class="ledger-value" style="font-size:1.1rem;"><?php echo e($r['category']); ?></div>
                                <div class="ledger-caption"><?php echo e(sked_explain_recommendation($r)); ?></div>
                            </a>
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
