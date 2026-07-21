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

require_role('youth');

$currentRole = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;
$totalPoints = $isDemo ? 0 : sked_total_points($userId);
$hasProfile = !$isDemo && sked_has_youth_profile($userId);
$activity = sked_activity_level($totalPoints);
$officialBadge = !$isDemo ? sked_sk_official_badge_for_user($userId) : null;
$myParticipations = $isDemo ? [] : sked_youth_participations($userId);
$registeredEventCount = count($myParticipations);
$activeRegistrationCount = count(array_filter($myParticipations, static fn ($e) => in_array($e['status'], ['published', 'confirmed', 'ongoing'], true)));
$myOpenFeedbackCount = $isDemo ? 0 : count(array_filter(sked_feedback_for_youth($userId), static fn ($f) => $f['status'] === 'open'));
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

            <section class="row g-3 mb-5" aria-label="Youth dashboard metrics">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card <?php echo $isVerified ? '' : 'accent-amber'; ?> stagger-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-person-vcard"></i></span>
                            <span class="ledger-tag">Profile</span>
                        </div>
                        <div class="ledger-value" style="font-size:1.35rem;"><?php echo $isVerified ? 'Verified' : 'Pending'; ?></div>
                        <div class="ledger-caption">KK membership status</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card <?php echo $isVerified ? 'accent-teal' : 'accent-amber'; ?> stagger-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi <?php echo $isVerified ? 'bi-calendar-check' : 'bi-lock'; ?>"></i></span>
                            <span class="ledger-tag">Active</span>
                        </div>
                        <div class="ledger-value <?php echo $isVerified ? 'tabular' : ''; ?>" style="<?php echo $isVerified ? '' : 'font-size:1.35rem;'; ?>"><?php echo $isVerified ? (int) $registeredEventCount : 'Locked'; ?></div>
                        <div class="ledger-caption"><?php echo $isVerified ? 'Registered events' : 'Unlocks after verification'; ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <a class="ledger-card accent-amber stagger-3 text-reset text-decoration-none d-block" href="<?php echo $isVerified ? 'activity.php' : 'dashboard.php'; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi <?php echo $isVerified ? e($activity['level']['icon']) : 'bi-lock'; ?>"></i></span>
                            <span class="ledger-tag">Points</span>
                        </div>
                        <div class="ledger-value <?php echo $isVerified ? 'tabular' : ''; ?>" style="<?php echo $isVerified ? '' : 'font-size:1.35rem;'; ?>"><?php echo $isVerified ? (int) $totalPoints : 'Locked'; ?></div>
                        <div class="ledger-caption"><?php echo $isVerified ? e($activity['level']['label']) : 'Unlocks after verification'; ?></div>
                    </a>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust stagger-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-chat-left-text"></i></span>
                            <span class="ledger-tag">Feedback</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $myOpenFeedbackCount; ?></div>
                        <div class="ledger-caption">Open concerns</div>
                    </div>
                </div>
            </section>

            <section class="mb-5" aria-label="Youth service modules">
                <div class="section-heading">
                    <h2>Youth Services</h2>
                    <span class="section-note">4 service areas</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <?php if ($isVerified): ?>
                        <div class="registry-card">
                            <span class="registry-icon"><i class="bi bi-person-vcard"></i></span>
                            <h3>Youth Profile (KK)</h3>
                            <p><?php echo $hasProfile ? 'Your KK profile is complete. Review or update it anytime.' : 'Complete your KK profile to earn points and shape SK programs.'; ?></p>
                            <a class="link-open" href="profile.php"><?php echo $hasProfile ? 'Update profile' : 'Complete profile'; ?> <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <?php else: ?>
                        <div class="registry-card" style="opacity:.62;" aria-disabled="true">
                            <span class="registry-icon"><i class="bi bi-lock-fill"></i></span>
                            <h3>Youth Profile (KK)</h3>
                            <p>Profiling unlocks once your Barangay SK verifies your account.</p>
                            <span class="link-open is-locked" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <?php if ($isVerified): ?>
                        <div class="registry-card tone-teal">
                            <span class="registry-icon"><i class="bi bi-calendar-event"></i></span>
                            <h3>Browse Events</h3>
                            <p>Discover and register for upcoming SK events and programs.</p>
                            <a class="link-open" href="events.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <?php else: ?>
                        <div class="registry-card tone-teal" style="opacity:.62;" aria-disabled="true">
                            <span class="registry-icon"><i class="bi bi-lock-fill"></i></span>
                            <h3>Browse Events</h3>
                            <p>Locked until your Barangay SK verifies your KK profile.</p>
                            <span class="link-open is-locked" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-amber">
                            <span class="registry-icon"><i class="bi bi-megaphone"></i></span>
                            <h3>Announcements</h3>
                            <p>Official SK notices, schedules, and program updates.</p>
                            <a class="link-open" href="dashboard.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="registry-card tone-rust">
                            <span class="registry-icon"><i class="bi bi-chat-left-text"></i></span>
                            <h3>Feedback / Concerns</h3>
                            <p>Send suggestions and concerns to your SK Council.</p>
                            <a class="link-open" href="dashboard.php">Open module <i class="bi bi-arrow-right"></i></a>
                        </div>
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
                                <div class="docket-title">Activity level &amp; points <i class="bi bi-arrow-right small text-secondary"></i></div>
                                <div class="docket-sub"><?php echo e($activity['level']['label']); ?><?php echo $activity['next'] !== null ? ' &middot; ' . (int) $activity['points_to_next'] . ' pts to ' . e($activity['next']['label']) : ' &middot; Highest tier reached'; ?></div>
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
                        <a class="docket-row text-reset text-decoration-none" href="dashboard.php">
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
