<?php
/**
 * ============================================================
 * File     : pages/youth/events.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth "Browse Events" (P4). Verified youth see events their
 *            barangay is eligible for and can join (interested) or register
 *            (slots), including team events. Verified-only; joining awards
 *            participation points.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_yevents_token'])) {
    $_SESSION['csrf_yevents_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if (!hash_equals((string) $_SESSION['csrf_yevents_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif (!$isVerified) {
        $flash = ['type' => 'warning', 'msg' => 'Your account must be verified before you can join events.'];
    } elseif ($isDemo) {
        $flash = ['type' => 'warning', 'msg' => 'Demo account: joining is preview-only and was not saved.'];
    } elseif ($action === 'join') {
        $r = sked_join_event($userId, $barangayId, $eventId, (string) ($_POST['team_name'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'You are signed up! Participation points awarded.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'cancel') {
        $r = sked_cancel_participation($userId, $eventId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Your sign-up was cancelled.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'evaluate') {
        $r = sked_submit_evaluation($userId, $eventId, (int) ($_POST['rating'] ?? 0), (string) ($_POST['comments'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Thanks for your feedback! Participation points awarded.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    }
}

$events = ($isVerified && $barangayId > 0) ? sked_events_for_youth($userId, $barangayId) : [];
$ongoingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'ongoing'));
$upcomingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'upcoming'));

$myParticipations = (!$isDemo && $isVerified) ? sked_youth_participations($userId) : [];
$pastParticipations = array_values(array_filter($myParticipations, static fn ($p) => sked_event_time_bucket($p) === 'past'));

$evaluable = (!$isDemo && $isVerified) ? sked_youth_evaluable_events($userId) : [];
$evaluableById = [];
foreach ($evaluable as $ev) { $evaluableById[(int) $ev['id']] = $ev; }

$participantStatusBadge = static function (string $s): string {
    $map = ['attended' => 'success', 'no_show' => 'danger', 'registered' => 'primary', 'interested' => 'info', 'cancelled' => 'secondary'];
    return $map[$s] ?? 'secondary';
};

$renderBrowseTable = function (string $title, array $list, string $icon, string $emptyMsg) use ($isDemo) {
    ?>
    <div class="docket-panel mb-4">
        <div class="section-heading"><h2><?php echo e($title); ?></h2><span class="section-note"><?php echo count($list); ?> available</span></div>
        <?php if (empty($list)): ?>
            <div class="text-center text-secondary py-5"><i class="bi <?php echo e($icon); ?> fs-1 d-block mb-2"></i><?php echo e($emptyMsg); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Event</th><th>Date</th><th>Scope</th><th>Slots</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $ev): ?>
                        <?php
                            $c = sked_participant_counts((int) $ev['id']);
                            $isRegister = $ev['type'] === 'register';
                            $taken = $c['registered'] + $c['attended'];
                            $full = $isRegister && $ev['capacity'] !== null && $taken >= (int) $ev['capacity'];
                            $mine = $ev['my_status'] ?? null;
                            $joined = in_array($mine, ['interested', 'registered', 'attended'], true);
                            $imageUrl = sked_event_image_url($ev, '../public/event_image.php');
                        ?>
                        <tr>
                            <td>
                                <div class="event-list-item">
                                    <span class="event-list-thumb" aria-hidden="true">
                                        <?php if ($imageUrl !== ''): ?>
                                            <img src="<?php echo e($imageUrl); ?>" alt="">
                                        <?php else: ?>
                                            <i class="bi bi-image"></i>
                                        <?php endif; ?>
                                    </span>
                                    <div>
                                        <div class="fw-semibold"><?php echo e((string) $ev['title']); ?></div>
                                        <?php if (!empty($ev['location'])): ?><div class="small text-secondary"><i class="bi bi-geo-alt me-1"></i><?php echo e((string) $ev['location']); ?></div><?php endif; ?>
                                        <?php if (!empty($ev['category'])): ?><span class="badge text-bg-light"><?php echo e((string) $ev['category']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="small text-secondary"><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?></td>
                            <td class="small text-capitalize"><?php echo e((string) $ev['scope']); ?></td>
                            <td class="small">
                                <?php if ($isRegister): ?>
                                    <?php echo $taken; ?><?php echo $ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : ''; ?>
                                <?php else: ?>
                                    <?php echo $c['active']; ?> joined
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($joined): ?>
                                    <span class="badge text-bg-success me-1"><i class="bi bi-check-lg me-1"></i><?php echo $mine === 'attended' ? 'Attended' : 'Signed up'; ?></span>
                                    <?php if ($mine !== 'attended'): ?>
                                    <form method="post" action="events.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_yevents_token']); ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-x-circle"></i></button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($full): ?>
                                    <span class="badge text-bg-danger">Full</span>
                                <?php else: ?>
                                    <form method="post" action="events.php" class="d-inline-flex align-items-center gap-1 justify-content-end">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_yevents_token']); ?>">
                                        <input type="hidden" name="action" value="join">
                                        <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                                        <?php if ((int) $ev['is_team_sport'] === 1): ?>
                                            <input type="text" class="form-control form-control-sm" style="width:120px;" name="team_name" maxlength="100" placeholder="Team name" required>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-sked" type="submit" <?php echo $isDemo ? 'disabled' : ''; ?>>
                                            <i class="bi bi-<?php echo $isRegister ? 'clipboard-check' : 'hand-thumbs-up'; ?> me-1"></i><?php echo $isRegister ? 'Register' : 'Join'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Browse Events</title>
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
        <?php render_sked_navigation($role, 'browse_events'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Browse Events</h1>
                        <p class="text-secondary meta-copy mb-0">Events open to your barangay. Join or register to take part and earn points.</p>
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
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-lock-fill me-1"></i> Joining events unlocks once your Barangay SK verifies your KK profile.
                </div>
            <?php elseif ($isDemo): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill me-1"></i> Demo account: you can browse events, but joining is preview-only.</div>
            <?php endif; ?>

            <?php if ($isVerified): ?>

            <?php
                $renderBrowseTable('Ongoing Events', $ongoingEvents, 'bi-play-circle', 'No events are currently ongoing for your barangay.');
            ?>

            <div class="docket-panel mb-4">
                <div class="section-heading"><h2>Past Events</h2><span class="section-note"><?php echo count($pastParticipations); ?> joined</span></div>
                <?php if (empty($pastParticipations)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-clock-history fs-1 d-block mb-2"></i>No past events you've joined yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Event</th><th>Date</th><th>My Status</th><th>Feedback</th></tr></thead>
                            <tbody>
                            <?php foreach ($pastParticipations as $p): ?>
                                <?php $rateInfo = $evaluableById[(int) $p['id']] ?? null; ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $p['title']); ?></div>
                                        <?php if (!empty($p['team_name'])): ?><div class="small text-secondary">Team: <?php echo e((string) $p['team_name']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo e($p['event_date'] ? date('M j, Y', strtotime((string) $p['event_date'])) : 'TBA'); ?></td>
                                    <td><span class="badge text-bg-<?php echo e($participantStatusBadge((string) $p['my_status'])); ?> text-capitalize"><?php echo e(str_replace('_', ' ', (string) $p['my_status'])); ?></span></td>
                                    <td style="min-width:260px;">
                                        <?php if ($rateInfo === null): ?>
                                            <span class="small text-secondary">—</span>
                                        <?php elseif ($rateInfo['my_rating'] !== null): ?>
                                            <span class="badge text-bg-success d-block mb-1" style="width:fit-content;"><i class="bi bi-star-fill me-1"></i>You rated <?php echo (int) $rateInfo['my_rating']; ?>/5</span>
                                            <?php if (!empty($rateInfo['my_comments'])): ?><div class="small text-secondary fst-italic">"<?php echo e((string) $rateInfo['my_comments']); ?>"</div><?php endif; ?>
                                        <?php else: ?>
                                            <form method="post" action="events.php" class="d-flex flex-wrap align-items-center gap-1">
                                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_yevents_token']); ?>">
                                                <input type="hidden" name="action" value="evaluate">
                                                <input type="hidden" name="event_id" value="<?php echo (int) $p['id']; ?>">
                                                <select name="rating" class="form-select form-select-sm" style="width:auto;" required>
                                                    <option value="">Rate…</option>
                                                    <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> — <?php echo ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][$i]; ?></option><?php endfor; ?>
                                                </select>
                                                <input type="text" name="comments" class="form-control form-control-sm" style="width:150px;" maxlength="500" placeholder="Comment (optional)">
                                                <button class="btn btn-sm btn-sked" type="submit"><i class="bi bi-star"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php
                $renderBrowseTable('Upcoming Events', $upcomingEvents, 'bi-calendar-event', 'No upcoming events for your barangay right now. Check back soon.');
            ?>

            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
