<?php
/**
 * ============================================================
 * File     : pages/dilg/dashboard.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Landing dashboard for the DILG (Superadmin) role. Was still
 *            the original P0 skeleton (hardcoded metrics, every module
 *            card linking to itself) even after every other DILG page was
 *            built out in later phases — rebuilt with live figures and
 *            correct routing (P15 follow-up).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/compliance.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/turnover.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/analytics.php';

require_role('dilg');

$currentRole = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$youthSummary = sked_youth_profile_summary();
$totalYouth = array_sum(array_column($youthSummary, 'total_youth'));
$totalBarangays = count(sked_barangays());
$activeSkCouncils = count(sked_compliance_overview());

$allEvents = sked_events_for_manager('dilg', 0, null);
$ongoingEventsCount = count(array_filter($allEvents, static fn($e) => sked_event_time_bucket($e) === 'ongoing'));

$pendingTurnoverCount = count(sked_pending_turnover_reports());
$pendingDismissalsCount = count(sked_reports_for_role('dilg', ['type' => 'dismissal_recommendation', 'status' => 'submitted']));

$pendingReportsCount = count(sked_reports_for_role('dilg', ['status' => 'submitted']));
$topRecommendation = sked_recommend_categories(null, 1);
$topFocusCategory = $topRecommendation[0]['category'] ?? null;
$auditLogCount = (int) sked_db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | DILG Oversight Dashboard</title>

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
                        <div class="eyebrow">DILG Oversight &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Youth Governance Oversight Dashboard</h1>
                        <p class="text-secondary meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. A system-wide view of youth profiling and SK events across all barangays.</p>
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

            <section class="mb-5" aria-label="DILG dashboard data links">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card stagger-1 text-reset text-decoration-none d-block" href="sk_councils.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-diagram-3"></i></span>
                                <span class="ledger-tag">Councils</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $activeSkCouncils; ?> / <?php echo (int) $totalBarangays; ?></div>
                            <div class="ledger-caption">SK Councils</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber stagger-2 text-reset text-decoration-none d-block" href="../manage/youth_profiles.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-people"></i></span>
                                <span class="ledger-tag">Registry</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo number_format($totalYouth); ?></div>
                            <div class="ledger-caption">Youth Profiles</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal stagger-3 text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-calendar-event"></i></span>
                                <span class="ledger-tag">Ongoing</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $ongoingEventsCount; ?></div>
                            <div class="ledger-caption">Ongoing Programs &amp; Events</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust stagger-4 text-reset text-decoration-none d-block" href="analytics.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-bar-chart-line"></i></span>
                                <span class="ledger-tag">Insight</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.1rem;"><?php echo e($topFocusCategory ?? '—'); ?></div>
                            <div class="ledger-caption">Analytics</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust text-reset text-decoration-none d-block" href="turnover.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-arrow-left-right"></i></span>
                                <span class="ledger-tag">Pending</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingTurnoverCount; ?></div>
                            <div class="ledger-caption">Turnover of Power</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal text-reset text-decoration-none d-block" href="audit.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-journal-text"></i></span>
                                <span class="ledger-tag">Entries</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo number_format($auditLogCount); ?></div>
                            <div class="ledger-caption">Audit Log</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber text-reset text-decoration-none d-block" href="reports.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                                <span class="ledger-tag">Pending</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingReportsCount; ?></div>
                            <div class="ledger-caption">Reports</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust text-reset text-decoration-none d-block" href="compliance.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-shield-exclamation"></i></span>
                                <span class="ledger-tag">Flagged</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $pendingDismissalsCount; ?></div>
                            <div class="ledger-caption">Dismissal Review</div>
                        </a>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Oversight Docket</h2>
                            <span class="section-note">Today</span>
                        </div>
                        <a class="docket-row text-reset text-decoration-none" href="sk_councils.php">
                            <div>
                                <div class="docket-title">SK councils reporting</div>
                                <div class="docket-sub">Barangays with an active SK chairman</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $activeSkCouncils; ?> / <?php echo $totalBarangays; ?></span>
                        </a>
                        <a class="docket-row text-reset text-decoration-none" href="turnover.php">
                            <div>
                                <div class="docket-title">Pending turnover approvals</div>
                                <div class="docket-sub">Incoming officers awaiting activation</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $pendingTurnoverCount; ?> pending</span>
                        </a>
                        <a class="docket-row text-reset text-decoration-none" href="compliance.php">
                            <div>
                                <div class="docket-title">Flagged records</div>
                                <div class="docket-sub">Dismissal recommendations awaiting review</div>
                            </div>
                            <span class="count-badge tabular"><?php echo $pendingDismissalsCount; ?> pending</span>
                        </a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>System Snapshot</h2>
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
                            <span class="text-secondary"><span class="status-dot"></span>Federation oversight</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Audit &amp; access</span>
                            <span class="status-ready">Ready</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
