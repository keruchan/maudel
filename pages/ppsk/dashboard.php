<?php
/**
 * ============================================================
 * File     : pages/ppsk/dashboard.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Landing dashboard for the PPSK (Pederasyon ng mga SK
 *            President) role — live municipality-wide figures.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/compliance.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/turnover.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';

require_role('ppsk');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');
$fedEvents = sked_events_for_manager('ppsk', $userId, null);
$ongoingCount = count(array_filter($fedEvents, static fn($e) => sked_event_time_bucket($e) === 'ongoing'));
$upcomingCount = count(array_filter($fedEvents, static fn($e) => sked_event_time_bucket($e) === 'upcoming'));
$topRecommendations = sked_recommend_categories(null, 3);
$totalBarangays = count(sked_barangays());
$activeSkCouncils = count(sked_compliance_overview());
$youthSummary = sked_youth_profile_summary();
$totalYouth = array_sum(array_column($youthSummary, 'total_youth'));
$pendingChairpersonReports = count(sked_reports_for_role('ppsk', ['type' => 'monthly', 'status' => 'submitted']));
$pendingRosterCount = count(sked_pending_roster_rows());
$totalPlansCount = count(sked_cbydp_list_all()) + count(sked_abyip_list_all());
$topRecommendation = sked_recommend_categories(null, 1);
$topFocusCategory = $topRecommendation[0]['category'] ?? null;
$skWithStrikesCount = count(array_filter(sked_compliance_overview(), static fn ($r) => (int) $r['strikes'] > 0));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | PPSK Federation Dashboard</title>

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
                        <div class="eyebrow">Federation Office &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">SK Federation Dashboard</h1>
                        <p class="text-secondary meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Coordinate the SK councils and consolidated youth programs across barangays.</p>
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

            <section class="mb-5" aria-label="PPSK dashboard data links">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card stagger-1 text-reset text-decoration-none d-block" href="compliance.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-diagram-3"></i></span>
                                <span class="ledger-tag">Councils</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $activeSkCouncils; ?>/<?php echo (int) $totalBarangays; ?></div>
                            <div class="ledger-caption">Active barangay SK councils</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust stagger-2 text-reset text-decoration-none d-block" href="../manage/youth_profiles.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-people"></i></span>
                                <span class="ledger-tag">Registry</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo number_format($totalYouth); ?></div>
                            <div class="ledger-caption">Consolidated Profiles</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal stagger-3 text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-calendar-event"></i></span>
                                <span class="ledger-tag">Ongoing</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $ongoingCount; ?></div>
                            <div class="ledger-caption">Ongoing Federation Events</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber stagger-4 text-reset text-decoration-none d-block" href="plans.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-clipboard-data"></i></span>
                                <span class="ledger-tag">Plans</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $totalPlansCount; ?></div>
                            <div class="ledger-caption">Youth Development Plans</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust text-reset text-decoration-none d-block" href="analytics.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-bar-chart-line"></i></span>
                                <span class="ledger-tag">Insight</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.1rem;"><?php echo e($topFocusCategory ?? '—'); ?></div>
                            <div class="ledger-caption">Recommended Focus Areas</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card text-reset text-decoration-none d-block" href="reports.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                                <span class="ledger-tag">Pending</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingChairpersonReports; ?></div>
                            <div class="ledger-caption">Reports awaiting review</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal text-reset text-decoration-none d-block" href="compliance.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-shield-exclamation"></i></span>
                                <span class="ledger-tag">Flagged</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $skWithStrikesCount; ?></div>
                            <div class="ledger-caption">SK Compliance</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber text-reset text-decoration-none d-block" href="turnover.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-arrow-left-right"></i></span>
                                <span class="ledger-tag">Vacancies</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingRosterCount; ?></div>
                            <div class="ledger-caption">Turnover of Power</div>
                        </a>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Federation Docket</h2>
                            <span class="section-note">Today</span>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Councils reporting on time</div>
                                <div class="docket-sub">Barangays with current submissions</div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $activeSkCouncils; ?> active</span>
                        </div>
                        <a class="docket-row text-reset text-decoration-none" href="events.php">
                            <div>
                                <div class="docket-title">Upcoming federation events <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Events scheduled this cycle</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $upcomingCount; ?> planned</span>
                        </a>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title">Requests from chairpersons</div>
                                <div class="docket-sub">Items awaiting federation action</div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $pendingChairpersonReports; ?> pending</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>Federation Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Council coordination</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Event planning</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Consolidated data</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Reporting</span>
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
