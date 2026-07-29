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
require_once __DIR__ . '/../../includes/attendance.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$flash = ['type' => '', 'msg' => ''];
$reopenEventId = null;
$formErrors = [];

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
        if ($r['ok'] && !empty($r['pending'])) {
            $flash = ['type' => 'warning', 'msg' => 'Your registration request has been submitted and is now <strong>Pending</strong>. This is a pre-registration only — please proceed to your SK/PPSK office to finalize it (e.g. settlement of entrance fee/payment, submission of documents). You\'ll be notified once it\'s confirmed.'];
        } else {
            $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'You are signed up! Participation points awarded.'] : ['type' => 'danger', 'msg' => e($r['error'])];
        }
    } elseif ($action === 'cancel') {
        $r = sked_cancel_participation($userId, $eventId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Your sign-up was cancelled.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'evaluate') {
        $answers = [];
        foreach (array_keys(sked_evaluation_criteria()) as $key) {
            $answers[$key] = (int) ($_POST['criteria'][$key] ?? 0);
        }
        $r = sked_submit_evaluation($userId, $eventId, $answers, (string) ($_POST['comments'] ?? ''));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Thanks for your feedback! Participation points awarded.'];
        } else {
            $formErrors = [$r['error']];
            $reopenEventId = $eventId;
        }
    }
}

// Keep everything the user answered when an evaluation modal comes back with errors.
sked_form_retain($reopenEventId !== null);

$events = ($isVerified && $barangayId > 0) ? sked_events_for_youth($userId, $barangayId) : [];
// "For You" matches float to the top of whichever bucket they're already in —
// stable since PHP 8, so this never reorders events beyond that. Nothing is
// hidden; non-matching general events still show, just after the picks.
usort($events, static fn ($a, $b) => (int) ($b['is_for_you'] ?? false) <=> (int) ($a['is_for_you'] ?? false));
$ongoingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'ongoing'));
$upcomingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'upcoming'));

$myParticipations = (!$isDemo && $isVerified) ? sked_youth_participations($userId) : [];
$pastParticipations = array_values(array_filter($myParticipations, static fn ($p) => sked_event_time_bucket($p) === 'past'));

$pendingEvaluations = (!$isDemo && $isVerified) ? sked_pending_evaluations_for_youth($userId) : [];
$evaluable = (!$isDemo && $isVerified) ? sked_youth_evaluable_events($userId) : [];
$evaluableById = [];
foreach ($evaluable as $ev) { $evaluableById[(int) $ev['id']] = $ev; }

$participantStatusBadge = static function (string $s): string {
    $map = ['attended' => 'success', 'no_show' => 'danger', 'registered' => 'primary', 'interested' => 'info', 'pending' => 'warning', 'declined' => 'secondary', 'cancelled' => 'secondary'];
    return $map[$s] ?? 'secondary';
};

$renderBrowseTable = function (string $title, array $list, string $icon, string $emptyMsg, string $tableId) use ($isDemo) {
    ?>
    <div class="docket-panel mb-4">
        <div class="section-heading"><h2><?php echo e($title); ?></h2><span class="section-note"><?php echo count($list); ?> available</span></div>
        <?php if (empty($list)): ?>
            <div class="text-center text-secondary py-5"><i class="bi <?php echo e($icon); ?> fs-1 d-block mb-2"></i><?php echo e($emptyMsg); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle" id="<?php echo e($tableId); ?>">
                    <thead><tr><th>Event</th><th>Date</th><th>Scope</th><th>Slots</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $ev): ?>
                        <?php
                            $c = sked_participant_counts((int) $ev['id']);
                            $isRegister = $ev['type'] === 'register';
                            $taken = $c['registered'] + $c['attended'];
                            $mine = $ev['my_status'] ?? null;
                            $joined = in_array($mine, ['interested', 'pending', 'registered', 'attended'], true);
                            $imageUrl = sked_event_image_url($ev, '../public/event_image.php');
                        ?>
                        <tr data-event-id="<?php echo (int) $ev['id']; ?>" data-event-title="<?php echo e((string) $ev['title']); ?>">
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
                                        <?php if (!empty($ev['is_for_you'])): ?><span class="badge text-bg-warning"><i class="bi bi-stars me-1"></i>For You</span><?php endif; ?>
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
                                    <?php if ($mine === 'pending'): ?>
                                        <span class="badge text-bg-warning me-1"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success me-1"><i class="bi bi-check-lg me-1"></i><?php echo $mine === 'attended' ? 'Attended' : 'Signed up'; ?></span>
                                    <?php endif; ?>
                                    <?php if ($mine !== 'attended'): ?>
                                    <form method="post" action="events.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_yevents_token']); ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-x-circle"></i></button>
                                    </form>
                                    <?php endif; ?>
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=4">
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

            <?php if (!empty($pendingEvaluations)): ?>
                <div class="docket-panel mb-4" style="border-color: var(--gold-100); border-left: 3px solid var(--gold-600);">
                    <div class="section-heading">
                        <h2><i class="bi bi-hourglass-split me-1"></i>Finalize your attendance</h2>
                        <span class="section-note"><?php echo count($pendingEvaluations); ?> pending</span>
                    </div>
                    <p class="small text-secondary">
                        You were marked present at these events. Your attendance is <strong>not final</strong> until you
                        submit the evaluation — it also earns you the evaluation points.
                    </p>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Event</th><th>Attended</th><th>Evaluation</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendingEvaluations as $pe): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $pe['title']); ?></div>
                                        <span class="badge text-bg-light text-capitalize"><?php echo e((string) $pe['status']); ?></span>
                                    </td>
                                    <td class="small text-secondary"><?php echo e($pe['attended_at'] ? date('M j, Y g:i A', strtotime((string) $pe['attended_at'])) : '—'); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-sked" data-bs-toggle="modal" data-bs-target="#evalModal<?php echo (int) $pe['id']; ?>"><i class="bi bi-clipboard2-check me-1"></i>Evaluate</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php
                $renderBrowseTable('Ongoing Events', $ongoingEvents, 'bi-play-circle', 'No events are currently ongoing for your barangay.', 'ongoingEventsTable');
                $renderBrowseTable('Upcoming Events', $upcomingEvents, 'bi-calendar-event', 'No upcoming events for your barangay right now. Check back soon.', 'upcomingEventsTable');
            ?>

            <div class="docket-panel mb-4">
                <div class="section-heading"><h2>Past Events</h2><span class="section-note"><?php echo count($pastParticipations); ?> joined</span></div>
                <?php if (empty($pastParticipations)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-clock-history fs-1 d-block mb-2"></i>No past events you've joined yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="pastEventsTable">
                            <thead><tr><th>Event</th><th>Date</th><th>My Status</th><th>Evaluation <span class="fw-normal text-secondary small">(finalizes attendance)</span></th></tr></thead>
                            <tbody>
                            <?php foreach ($pastParticipations as $p): ?>
                                <?php $rateInfo = $evaluableById[(int) $p['id']] ?? null; ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $p['title']); ?></div>
                                        <?php if (!empty($p['team_name'])): ?><div class="small text-secondary">Team: <?php echo e((string) $p['team_name']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo e($p['event_date'] ? date('M j, Y', strtotime((string) $p['event_date'])) : 'TBA'); ?></td>
                                    <td>
                                        <span class="badge text-bg-<?php echo e($participantStatusBadge((string) $p['my_status'])); ?> text-capitalize"><?php echo e(str_replace('_', ' ', (string) $p['my_status'])); ?></span>
                                        <?php if ((string) $p['my_status'] === 'attended'): ?>
                                            <?php $isFinal = $rateInfo === null || $rateInfo['my_rating'] !== null; ?>
                                            <div class="small mt-1 <?php echo $isFinal ? 'text-success' : 'text-warning-emphasis'; ?>">
                                                <i class="bi <?php echo $isFinal ? 'bi-check-circle-fill' : 'bi-hourglass-split'; ?> me-1"></i><?php echo $isFinal ? 'Finalized' : 'Not final'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:290px;">
                                        <?php if ($rateInfo === null): ?>
                                            <span class="small text-secondary"><?php echo (string) $p['my_status'] === 'attended' ? 'Evaluation not open' : '—'; ?></span>
                                        <?php elseif ($rateInfo['my_rating'] !== null): ?>
                                            <span class="badge text-bg-success d-block mb-1" style="width:fit-content;"><i class="bi bi-star-fill me-1"></i>You rated <?php echo number_format((float) $rateInfo['my_rating'], 2); ?>/5</span>
                                            <?php if (!empty($rateInfo['my_comments'])): ?><div class="small text-secondary fst-italic">"<?php echo e((string) $rateInfo['my_comments']); ?>"</div><?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning mb-1"><i class="bi bi-exclamation-circle me-1"></i>Needs evaluation</span>
                                            <div>
                                                <button type="button" class="btn btn-sm btn-sked" data-bs-toggle="modal" data-bs-target="#evalModal<?php echo (int) $p['id']; ?>"><i class="bi bi-clipboard2-check me-1"></i>Evaluate</button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($pendingEvaluations)): $criteria = sked_evaluation_criteria(); $scaleLabels = sked_evaluation_scale_labels(); ?>
                <?php foreach ($pendingEvaluations as $pe): $isReopening = $reopenEventId === (int) $pe['id']; ?>
                    <div class="modal fade" id="evalModal<?php echo (int) $pe['id']; ?>" tabindex="-1" aria-labelledby="evalModalLabel<?php echo (int) $pe['id']; ?>" aria-hidden="true"<?php echo $isReopening ? ' data-autoshow="1"' : ''; ?>>
                        <div class="modal-dialog modal-xl modal-dialog-scrollable evaluation-dialog">
                            <div class="modal-content evaluation-modal">
                                <div class="modal-header evaluation-modal-header">
                                    <div>
                                        <div class="evaluation-modal-kicker"><i class="bi bi-stars me-1"></i>Event evaluation</div>
                                        <h2 class="modal-title h5" id="evalModalLabel<?php echo (int) $pe['id']; ?>"><?php echo e((string) $pe['title']); ?></h2>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="post" action="events.php" class="evaluation-form">
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_yevents_token']); ?>">
                                        <input type="hidden" name="action" value="evaluate">
                                        <input type="hidden" name="event_id" value="<?php echo (int) $pe['id']; ?>">

                                        <?php if ($isReopening): ?><?php sked_render_form_errors($formErrors); ?><?php endif; ?>

                                        <p class="evaluation-intro">Rate each statement from 1 to 5 stars, then share any suggestions at the end. Submitting finalizes your attendance and awards evaluation points.</p>

                                        <?php $currentGroup = null; foreach ($criteria as $key => $c): ?>
                                            <?php if ($c['group'] !== $currentGroup): $currentGroup = $c['group']; ?>
                                                <h3 class="h6 text-secondary text-uppercase small mt-3 mb-1"><?php echo e($currentGroup); ?></h3>
                                            <?php endif; ?>
                                            <div class="snapshot-row">
                                                <span><?php echo e($c['text']); ?></span>
                                                <select name="criteria[<?php echo e($key); ?>]" class="form-select form-select-sm" style="width:auto;" required aria-label="<?php echo e($c['text']); ?>">
                                                    <option value="">Select…</option>
                                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo ($isReopening && sked_old_selected("criteria[$key]", (string) $i)) ? 'selected' : ''; ?>><?php echo $i; ?> — <?php echo e($scaleLabels[$i]); ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="evaluation-comments">
                                            <label for="evalComments<?php echo (int) $pe['id']; ?>" class="form-label small">What suggestions do you have to improve future SK events? <span class="text-secondary fw-normal">(optional)</span></label>
                                            <textarea id="evalComments<?php echo (int) $pe['id']; ?>" name="comments" class="form-control form-control-sm" rows="3" maxlength="1000"><?php echo e($isReopening ? sked_old('comments') : ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer evaluation-footer">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-sked btn-sm"><i class="bi bi-check-lg me-1"></i>Submit Evaluation</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php sked_render_autoshow_modals_script(); ?>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        ['ongoingEventsTable', 'upcomingEventsTable'].forEach(function (id) {
            new SkedTableTools('#' + id, { pageSize: 8, filters: [{ label: 'Scope' }] });
        });
        new SkedTableTools('#pastEventsTable', { pageSize: 8, filters: [{ label: 'My Status' }] });

        // Landing-page carousel cards link here as "?highlight=<eventId>" so a
        // click routes into the youth's own Browse Events view instead of the
        // no-login public share page. Reuse the table's own search box to pull
        // the row onto page 1 (pagination would otherwise hide it), then scroll
        // + flash it — no changes to table-tools.js itself.
        (function () {
            var highlightId = parseInt(new URLSearchParams(window.location.search).get('highlight'), 10);
            if (!highlightId) { return; }
            var row = document.querySelector('tr[data-event-id="' + highlightId + '"]');
            if (!row) { return; }
            var wrap = row.closest('.table-responsive');
            var toolbar = wrap ? wrap.previousElementSibling : null;
            var searchInput = toolbar ? toolbar.querySelector('input[type="search"]') : null;
            if (searchInput) {
                searchInput.value = row.getAttribute('data-event-title') || '';
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            setTimeout(function () {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.add('tt-row-highlight');
                setTimeout(function () { row.classList.remove('tt-row-highlight'); }, 2600);
            }, 50);
        })();

        document.querySelectorAll('.evaluation-modal .modal-body').forEach(function (body) {
            var section = null;
            var list = null;

            Array.prototype.slice.call(body.children).forEach(function (node) {
                if (node.matches && node.matches('h3.h6')) {
                    section = document.createElement('section');
                    section.className = 'evaluation-section';
                    list = document.createElement('div');
                    list.className = 'evaluation-section-list';

                    node.className = 'evaluation-section-title';
                    body.insertBefore(section, node);
                    section.appendChild(node);
                    section.appendChild(list);
                    return;
                }

                if (section && list && node.classList && node.classList.contains('snapshot-row')) {
                    node.classList.add('evaluation-question-row');
                    list.appendChild(node);
                }
            });
        });

        document.querySelectorAll('.evaluation-modal select[name^="criteria["]').forEach(function (select) {
            var row = select.closest('.snapshot-row');
            if (!row) { return; }

            var wrap = document.createElement('div');
            wrap.className = 'evaluation-rating-wrap';

            var stars = document.createElement('div');
            stars.className = 'eval-star-group';
            stars.setAttribute('role', 'radiogroup');
            stars.setAttribute('aria-label', select.getAttribute('aria-label') || 'Evaluation score');

            var helper = document.createElement('span');
            helper.className = 'eval-star-text';

            function ratingLabel(value) {
                var option = select.querySelector('option[value="' + value + '"]');
                return option ? option.textContent.replace(/\s+/g, ' ').trim() : 'Select rating';
            }

            var starEls = [];

            // Filled gold up to `value`, hollow outline beyond it — no radio
            // inputs anywhere, just buttons whose icon glyph and color change.
            function setStarsFilledTo(value) {
                starEls.forEach(function (star) {
                    var filled = Number(star.dataset.value) <= value;
                    star.querySelector('i').className = 'bi ' + (filled ? 'bi-star-fill' : 'bi-star');
                });
            }

            function commit(value) {
                select.value = String(value);
                starEls.forEach(function (star) {
                    var active = Number(star.dataset.value) <= value;
                    star.classList.toggle('is-active', active);
                    star.setAttribute('aria-checked', Number(star.dataset.value) === value ? 'true' : 'false');
                });
                setStarsFilledTo(value);
                helper.textContent = value > 0 ? ratingLabel(value) : 'Select rating';
            }

            for (var i = 1; i <= 5; i++) {
                (function (i) {
                    var star = document.createElement('button');
                    star.type = 'button';
                    star.className = 'eval-star';
                    star.dataset.value = String(i);
                    star.title = ratingLabel(i);
                    star.setAttribute('aria-label', ratingLabel(i));
                    star.setAttribute('role', 'radio');
                    star.setAttribute('aria-checked', 'false');
                    star.innerHTML = '<i class="bi bi-star"></i>';

                    star.addEventListener('click', function () { commit(i); });
                    star.addEventListener('mouseenter', function () { setStarsFilledTo(i); });
                    star.addEventListener('mouseleave', function () { setStarsFilledTo(Number(select.value) || 0); });
                    star.addEventListener('focus', function () { setStarsFilledTo(i); });
                    star.addEventListener('blur', function () { setStarsFilledTo(Number(select.value) || 0); });

                    starEls.push(star);
                    stars.appendChild(star);
                })(i);
            }

            // A required field that's display:none can silently block native
            // form submission in some browsers with no visible error — the
            // backend already rejects an incomplete submission with a proper
            // in-modal message, so client-side validation isn't load-bearing.
            select.required = false;
            select.classList.add('eval-native-select');
            select.setAttribute('aria-hidden', 'true');
            select.tabIndex = -1;

            wrap.appendChild(stars);
            wrap.appendChild(helper);
            row.appendChild(wrap);
            commit(Number(select.value || 0));
        });
    </script>
</body>
</html>
