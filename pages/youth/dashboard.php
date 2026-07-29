<?php
/**
 * ============================================================
 * File     : pages/youth/dashboard.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Landing dashboard for the Youth / Community role — live
 *            per-user figures (points, registrations, feedback).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/points.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/sk_members.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/feedback.php';
require_once __DIR__ . '/../../includes/polls.php';

require_role('youth');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;
$totalPoints = $isDemo ? 0 : sked_total_points($userId);
$hasProfile = !$isDemo && sked_has_youth_profile($userId);
$engagement = sked_engagement_level($totalPoints);
$officialBadge = !$isDemo ? sked_sk_official_badge_for_user($userId) : null;
$myParticipations = $isDemo ? [] : sked_youth_participations($userId);
$registeredEventCount = count($myParticipations);
$activeRegistrationCount = count(array_filter($myParticipations, static fn ($e) => in_array($e['status'], ['published', 'confirmed', 'ongoing'], true)));
$myOpenFeedbackCount = $isDemo ? 0 : count(array_filter(sked_feedback_for_youth($userId), static fn ($f) => $f['status'] === 'open'));

$eligibleEvents = ($isVerified && $barangayId > 0) ? sked_events_for_youth($userId, $barangayId) : [];
$ongoingCount = count(array_filter($eligibleEvents, static fn ($e) => sked_event_time_bucket($e) === 'ongoing'));
$upcomingOpenCount = count(array_filter($eligibleEvents, static fn ($e) => sked_event_time_bucket($e) === 'upcoming'));
$openPollsCount = ($isVerified && $barangayId > 0) ? count(sked_open_polls_for_youth($barangayId)) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Youth Dashboard</title>

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
        <?php render_sked_navigation($currentRole, 'dashboard'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Youth Services Dashboard</h1>
                        <p class="meta-copy mb-0">Welcome back, <?php echo e($displayName); ?>. Your profile, events, and announcements in one place.</p>
                        <?php if ($officialBadge !== null): ?>
                            <div class="mt-2">
                                <span class="badge text-bg-primary"><i class="bi bi-person-badge me-1"></i><?php echo e((string) $officialBadge['position']); ?></span>
                                <span class="small text-secondary ms-2">Recognized SK member of Brgy. <?php echo e((string) $officialBadge['barangay_name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <form method="post" action="../auth/logout.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                            <button type="submit" class="btn-logout-outline">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>

                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <?php if (!$isVerified): ?>
            <div class="d-flex align-items-start gap-3 mb-4 p-3" style="border:1px solid var(--gold-100); background:var(--gold-100); color:#8a5a12; border-radius:var(--radius-lg);" role="status">
                <i class="bi bi-hourglass-split fs-5 mt-1"></i>
                <div>
                    <div class="fw-bold">Profile verification pending</div>
                    <p class="small mb-0">Your KK profile hasn't been verified by your Barangay SK yet. You can explore SKed and read announcements now — completing your KK profile, joining events, and earning participation points all unlock once verification is complete.</p>
                </div>
            </div>
            <?php endif; ?>

            <section class="mb-5" aria-label="Youth dashboard data links">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card <?php echo $isVerified ? '' : 'accent-amber'; ?> stagger-1 text-reset text-decoration-none d-block" href="profile.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-person-vcard"></i></span>
                                <span class="ledger-tag">Profile</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.35rem;"><?php echo $isVerified ? 'Verified' : 'Pending'; ?></div>
                            <div class="ledger-caption">KK membership status</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card <?php echo $hasProfile ? 'accent-teal' : 'accent-amber'; ?> stagger-2 text-reset text-decoration-none d-block" href="profile.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-clipboard2-data"></i></span>
                                <span class="ledger-tag">KK Form</span>
                            </div>
                            <div class="ledger-value" style="font-size:1.35rem;"><?php echo $hasProfile ? 'Complete' : 'Incomplete'; ?></div>
                            <div class="ledger-caption">KK profiling record</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card <?php echo $isVerified ? 'accent-teal' : 'accent-amber'; ?> stagger-3 text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi <?php echo $isVerified ? 'bi-calendar-check' : 'bi-lock'; ?>"></i></span>
                                <span class="ledger-tag">Registered</span>
                            </div>
                            <div class="ledger-value <?php echo $isVerified ? 'tabular' : ''; ?>" style="<?php echo $isVerified ? '' : 'font-size:1.35rem;'; ?>"><?php echo $isVerified ? (int) $registeredEventCount : 'Locked'; ?></div>
                            <div class="ledger-caption"><?php echo $isVerified ? 'Events joined' : 'Unlocks after verification'; ?></div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal stagger-4 text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-play-circle"></i></span>
                                <span class="ledger-tag">Ongoing</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $ongoingCount; ?></div>
                            <div class="ledger-caption">Ongoing events</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber text-reset text-decoration-none d-block" href="events.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-calendar-event"></i></span>
                                <span class="ledger-tag">Upcoming</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $upcomingOpenCount; ?></div>
                            <div class="ledger-caption">Upcoming events</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-rust text-reset text-decoration-none d-block" href="feedback.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-chat-left-text"></i></span>
                                <span class="ledger-tag">Feedback</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $myOpenFeedbackCount; ?></div>
                            <div class="ledger-caption">Open concerns</div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-amber text-reset text-decoration-none d-block" href="activity.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi <?php echo $isVerified ? e($engagement['level']['icon']) : 'bi-lock'; ?>"></i></span>
                                <span class="ledger-tag">Points</span>
                            </div>
                            <div class="ledger-value <?php echo $isVerified ? 'tabular' : ''; ?>" style="<?php echo $isVerified ? '' : 'font-size:1.35rem;'; ?>"><?php echo $isVerified ? (int) $totalPoints : 'Locked'; ?></div>
                            <div class="ledger-caption"><?php echo $isVerified ? e($engagement['level']['label']) : 'Unlocks after verification'; ?></div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <a class="ledger-card accent-teal text-reset text-decoration-none d-block" href="polls.php">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="ledger-icon"><i class="bi bi-bar-chart-steps"></i></span>
                                <span class="ledger-tag">Polls</span>
                            </div>
                            <div class="ledger-value tabular"><?php echo (int) $openPollsCount; ?></div>
                            <div class="ledger-caption">Community polls open</div>
                        </a>
                    </div>
                </div>
            </section>

            <section class="row g-3">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>My Activity</h2>
                            <span class="section-note">Current</span>
                        </div>
                        <a class="docket-row text-reset text-decoration-none" href="<?php echo $isVerified ? 'profile.php' : 'dashboard.php'; ?>">
                            <div>
                                <div class="docket-title">Youth profile <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Status: <span class="badge <?php echo $isVerified ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo $isVerified ? 'Verified' : 'Pending verification'; ?></span><?php echo $isVerified ? ' &middot; ' . ($hasProfile ? '<span class="text-success">Profile complete</span>' : '<span class="text-warning-emphasis">Profile not filled out</span>') : ''; ?></div>
                            </div>
                            <span class="count-badge tabular">KK member</span>
                        </a>
                        <?php if ($isVerified): ?>
                        <a class="docket-row text-reset text-decoration-none" href="events.php">
                            <div>
                                <div class="docket-title">My event registrations <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Active registrations for upcoming events</div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $activeRegistrationCount; ?> active</span>
                        </a>
                        <a class="docket-row text-reset text-decoration-none" href="activity.php">
                            <div>
                                <div class="docket-title">Engagement level &amp; points <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub"><?php echo e($engagement['level']['label']); ?><?php echo $engagement['next'] !== null ? ' &middot; ' . (int) $engagement['points_to_next'] . ' pts to ' . e($engagement['next']['label']) : ' &middot; Highest tier reached'; ?></div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $totalPoints; ?> pts</span>
                        </a>
                        <?php else: ?>
                        <div class="docket-row" style="opacity:.62;">
                            <div>
                                <div class="docket-title"><i class="bi bi-lock-fill small me-1"></i>My event registrations</div>
                                <div class="docket-sub">Locked until your KK profile is verified</div>
                            </div>
                            <span class="count-badge tabular">Locked</span>
                        </div>
                        <?php endif; ?>
                        <a class="docket-row text-reset text-decoration-none" href="feedback.php">
                            <div>
                                <div class="docket-title">Feedback / concerns <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub">Submitted concerns awaiting a reply</div>
                            </div>
                            <span class="count-badge tabular"><?php echo (int) $myOpenFeedbackCount; ?> open</span>
                        </a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="snapshot-panel">
                        <div class="section-heading">
                            <h2>Account Snapshot</h2>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Account type</span>
                            <span class="status-ready">Youth</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Profiling services</span>
                            <span class="status-ready">Ready</span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Verification status</span>
                            <span class="status-ready" <?php echo $isVerified ? '' : 'style="background:var(--gold-100);color:var(--gold-600);"'; ?>><?php echo $isVerified ? 'Verified' : 'Pending'; ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Event services</span>
                            <span class="status-ready" <?php echo $isVerified ? '' : 'style="background:var(--gold-100);color:var(--gold-600);"'; ?>><?php echo $isVerified ? 'Ready' : 'Locked'; ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Feedback channel</span>
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
