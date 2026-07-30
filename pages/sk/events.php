<?php
/**
 * ============================================================
 * File     : pages/sk/events.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK Chairman "Manage Events" (P4, revised). Create barangay
 *            events via a modal (kept off the main view), then see every
 *            event in a table plus a consolidated participants table
 *            across all of them. Per-event lifecycle/attendance actions
 *            still live on pages/manage/event.php (linked via "Manage").
 *            The old standalone "Attendance" nav entry pointed at this
 *            same page and added nothing beyond it — removed; this page's
 *            participants table now covers that.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/events.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$reopenModal = false;
$formErrors = [];

if (empty($_SESSION['csrf_events_token'])) {
    $_SESSION['csrf_events_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_events_token'], $token)) {
        $formErrors = ['Security validation failed. Please try again.'];
        $reopenModal = true;
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } else {
        $creator = ['id' => $skUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $data = $_POST;
        $data['scope'] = 'barangay';
        $r = sked_create_event($creator, $data, $_FILES['event_image'] ?? null);
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Event "' . e((string) $_POST['title']) . '" created' . (!empty($_POST['publish']) ? ' and published.' : ' as a draft.')];
        } else {
            $formErrors = $r['errors'];
            $reopenModal = true;
        }
    }
}

// Keep everything the user typed when the modal is coming back with errors.
sked_form_retain($reopenModal);

$events = $barangayId > 0 ? sked_events_for_manager($role, $skUserId, $barangayId) : [];
$participants = $barangayId > 0 ? sked_participants_for_manager($role, $barangayId) : [];
$ongoingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'ongoing'));
$pastEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'past'));
$upcomingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'upcoming'));

$participantStatusBadge = static function (string $s): string {
    $map = ['attended' => 'success', 'no_show' => 'danger', 'registered' => 'primary', 'interested' => 'info', 'pending' => 'warning', 'declined' => 'secondary'];
    return $map[$s] ?? 'secondary';
};
$shareBase = (function () {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/SKed/pages/public/event.php?t=';
})();

$renderEventsTable = function (string $title, array $list, string $icon, string $emptyMsg, string $tableId) use ($shareBase) {
    ?>
    <div class="docket-panel mb-4">
        <div class="section-heading">
            <h2><?php echo e($title); ?></h2>
            <span class="section-note"><?php echo count($list); ?> total</span>
        </div>
        <?php if (empty($list)): ?>
            <div class="text-center text-secondary py-5"><i class="bi <?php echo e($icon); ?> fs-1 d-block mb-2"></i><?php echo e($emptyMsg); ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle" id="<?php echo e($tableId); ?>">
                    <thead>
                        <tr>
                            <th>Event</th><th>Date</th><th>Type</th><th>Status</th><th>Participants</th><th>Share Link</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $ev): $c = sked_participant_counts((int) $ev['id']); ?>
                        <tr>
                            <td>
                                <?php $imageUrl = sked_event_image_url($ev, '../public/event_image.php'); ?>
                                <div class="event-list-item">
                                    <span class="event-list-thumb" aria-hidden="true">
                                        <?php if ($imageUrl !== ''): ?>
                                            <img src="<?php echo e($imageUrl); ?>" alt="">
                                        <?php else: ?>
                                            <i class="bi bi-image"></i>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        <span class="fw-semibold d-block"><?php echo e((string) $ev['title']); ?></span>
                                        <?php if ((int) $ev['is_team_sport'] === 1): ?><span class="badge text-bg-light">Team</span><?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="small text-secondary"><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?></td>
                            <td class="small"><?php echo $ev['type'] === 'register' ? 'Register' : 'Join'; ?></td>
                            <td>
                                <span class="badge text-bg-<?php echo e(sked_event_display_badge_class($ev)); ?>"><?php echo e(sked_event_display_status_label($ev)); ?></span>
                                <?php if (sked_event_needs_closeout($ev)): ?>
                                    <div class="small text-warning-emphasis mt-1" title="The event date has passed but the status was never advanced."><i class="bi bi-exclamation-triangle-fill me-1"></i>Needs closing out</div>
                                <?php endif; ?>
                            </td>
                            <td class="tabular">
                                <?php echo $ev['type'] === 'register' ? ($c['registered'] + $c['attended']) . ($ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '') : $c['active']; ?>
                                <span class="small text-secondary d-block"><?php echo $ev['type'] === 'register' ? 'registered' : 'interested'; ?><?php echo $c['attended'] > 0 ? ' · ' . $c['attended'] . ' attended' : ''; ?></span>
                                <?php if (sked_event_time_bucket($ev) === 'past'): ?>
                                    <?php $r = sked_event_rating((int) $ev['id']); ?>
                                    <span class="small d-block <?php echo $r['count'] > 0 ? 'text-success' : 'text-secondary'; ?>">
                                        <?php if ($r['count'] > 0): ?>
                                            <i class="bi bi-star-fill me-1"></i><?php echo e((string) $r['avg']); ?>/5 from <?php echo (int) $r['count']; ?> evaluation<?php echo $r['count'] === 1 ? '' : 's'; ?>
                                        <?php else: ?>
                                            <i class="bi bi-star me-1"></i>No evaluations yet
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="input-group input-group-sm" style="max-width:220px;">
                                    <span class="input-group-text" title="Management-only share link"><i class="bi bi-link-45deg"></i></span>
                                    <input type="text" class="form-control" readonly value="<?php echo e($shareBase . (string) $ev['share_token']); ?>" onclick="this.select();" aria-label="Shareable public link">
                                </div>
                            </td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="../manage/event.php?id=<?php echo (int) $ev['id']; ?>"><i class="bi bi-gear me-1"></i>Manage</a></td>
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
    <title>SKed | Manage Events</title>
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
        <?php render_sked_navigation($role, 'manage_events'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Manage Events</h1>
                        <p class="text-secondary meta-copy mb-0">Post barangay events, set capacity or open joining, and track participation.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($barangayId > 0): ?>
                        <button type="button" class="btn btn-sked" data-bs-toggle="modal" data-bs-target="#createEventModal"><i class="bi bi-calendar-plus me-1"></i> Create Event</button>
                        <?php endif; ?>
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo $flash['msg']; ?></div><?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <?php
                $renderEventsTable('Ongoing Events', $ongoingEvents, 'bi-play-circle', 'No events are currently ongoing.', 'ongoingEventsTable');
                $renderEventsTable('Upcoming Events', $upcomingEvents, 'bi-calendar-x', 'No upcoming events. Click "Create Event" to post one.', 'upcomingEventsTable');
                $renderEventsTable('Past Events', $pastEvents, 'bi-clock-history', 'No past events yet.', 'pastEventsTable');
            ?>

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Participants</h2>
                    <span class="section-note"><?php echo count($participants); ?> sign-ups across all events</span>
                </div>
                <?php if (empty($participants)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No participants yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="participantsTable">
                            <thead>
                                <tr><th>Event</th><th>Participant</th><th>Team</th><th>Status</th><th>Joined</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($participants as $p): ?>
                                <tr>
                                    <td><a class="text-decoration-none" href="../manage/event.php?id=<?php echo (int) $p['event_id']; ?>"><?php echo e((string) $p['event_title']); ?></a></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $p['name']); ?></div>
                                        <div class="small text-secondary">@<?php echo e((string) $p['username']); ?></div>
                                    </td>
                                    <td class="small"><?php echo e((string) ($p['team_name'] ?? '—')); ?></td>
                                    <td><span class="badge text-bg-<?php echo e($participantStatusBadge((string) $p['status'])); ?> text-capitalize"><?php echo e(str_replace('_', ' ', (string) $p['status'])); ?></span></td>
                                    <td class="small text-secondary"><?php echo e(date('M j, Y', strtotime((string) $p['joined_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Create Event modal -->
            <div class="modal fade" id="createEventModal" tabindex="-1" aria-labelledby="createEventModalLabel" aria-hidden="true"<?php echo $reopenModal ? ' data-autoshow="1"' : ''; ?>>
                <div class="modal-dialog modal-lg modal-dialog-scrollable create-event-dialog">
                    <div class="modal-content create-event-modal">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="createEventModalLabel">Create Event</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form class="create-event-form" method="post" action="events.php" enctype="multipart/form-data" novalidate>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_events_token']); ?>">

                                <?php sked_render_form_errors($formErrors, 'The event could not be created:'); ?>

                                <div class="mb-3">
                                    <label for="title" class="form-label">Title</label>
                                    <input type="text" class="form-control" id="title" name="title" maxlength="160" value="<?php echo e(sked_old('title')); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" maxlength="2000"><?php echo e(sked_old('description')); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="event_image" class="form-label">Event photo</label>
                                    <input type="file" class="form-control" id="event_image" name="event_image" accept="image/jpeg,image/png,image/webp">
                                    <div class="form-text">
                                        Optional JPG, PNG, or WebP image up to 5 MB.
                                        <?php if ($reopenModal): ?><span class="text-warning-emphasis">A previously chosen photo must be picked again — browsers never resend file inputs.</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="create-event-grid">
                                    <div class="create-event-field">
                                        <label for="type" class="form-label">Type</label>
                                        <select class="form-select" id="type" name="type">
                                            <option value="interested" <?php echo sked_old_selected('type', 'interested', true) ? 'selected' : ''; ?>>Interested / Join (no cap)</option>
                                            <option value="register" <?php echo sked_old_selected('type', 'register') ? 'selected' : ''; ?>>Register (limited slots)</option>
                                        </select>
                                    </div>
                                    <div class="create-event-field">
                                        <label for="location" class="form-label">Location</label>
                                        <input type="text" class="form-control" id="location" name="location" maxlength="200" value="<?php echo e(sked_old('location')); ?>">
                                    </div>
                                </div>
                                <div class="create-event-grid">
                                    <div class="create-event-field">
                                        <label for="event_date" class="form-label">Event date</label>
                                        <input type="date" class="form-control" id="event_date" name="event_date" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e(sked_old('event_date')); ?>" required>
                                    </div>
                                    <div class="create-event-field">
                                        <label for="registration_deadline" class="form-label">Reg. deadline</label>
                                        <input type="date" class="form-control" id="registration_deadline" name="registration_deadline" min="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e(sked_old('registration_deadline')); ?>">
                                    </div>
                                    <div class="create-event-field">
                                        <label for="start_time" class="form-label">Start time</label>
                                        <input type="time" class="form-control" id="start_time" name="start_time" value="<?php echo e(sked_old('start_time')); ?>">
                                    </div>
                                    <div class="create-event-field">
                                        <label for="end_time" class="form-label">End time</label>
                                        <input type="time" class="form-control" id="end_time" name="end_time" value="<?php echo e(sked_old('end_time')); ?>">
                                    </div>
                                    <div class="create-event-field">
                                        <label for="min_participants" class="form-label">Min. participants</label>
                                        <input type="number" class="form-control" id="min_participants" name="min_participants" min="0" value="<?php echo e(sked_old('min_participants', '0')); ?>">
                                    </div>
                                    <div class="create-event-field">
                                        <label for="capacity" class="form-label">Capacity <span class="text-secondary fw-normal">(register)</span></label>
                                        <input type="number" class="form-control" id="capacity" name="capacity" min="1" placeholder="e.g. 24" value="<?php echo e(sked_old('capacity')); ?>">
                                    </div>
                                </div>
                                <div class="create-event-checks">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_team_sport" name="is_team_sport" value="1" <?php echo sked_old_checked('is_team_sport', '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_team_sport">Team sport (registrants enter a team name)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1" <?php echo sked_old_checked('publish', '1', true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="publish">Publish now (visible to youth)</label>
                                    </div>
                                </div>

                                <hr>
                                <?php
                                    // Maps each targeting dimension to its POST field name + label.
                                    $targetFields = [
                                        'classification' => ['field' => 'target_classifications', 'label' => 'Youth Classification'],
                                        'specific_need' => ['field' => 'target_specific_needs', 'label' => 'Specific Needs'],
                                        'sex' => ['field' => 'target_sex', 'label' => 'Sex'],
                                        'interest' => ['field' => 'target_interests', 'label' => 'Interests / Advocacy'],
                                    ];
                                    $anyTargetingRetained = false;
                                    foreach ($targetFields as $tf) {
                                        if (!empty(sked_old_array($tf['field']))) { $anyTargetingRetained = true; break; }
                                    }
                                ?>
                                <a class="d-inline-block mb-2 text-decoration-none" data-bs-toggle="collapse" href="#forYouTargeting" role="button" aria-expanded="false" aria-controls="forYouTargeting">
                                    <i class="bi bi-stars me-1"></i>Target specific youth (optional) <i class="bi bi-chevron-down small"></i>
                                </a>
                                <div class="collapse<?php echo $anyTargetingRetained ? ' show' : ''; ?>" id="forYouTargeting">
                                    <p class="small text-secondary">Leave everything unchecked for a normal event visible to everyone. Checking any box here also surfaces this event as a "For You" pick for youth who match it — nobody is excluded.</p>
                                    <?php foreach (sked_event_targeting_options() as $dimension => $values): $tf = $targetFields[$dimension]; ?>
                                        <div class="mb-2">
                                            <div class="small fw-semibold text-secondary text-uppercase mb-1"><?php echo e($tf['label']); ?></div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($values as $value): $inputId = 'target_' . $dimension . '_' . md5($value); ?>
                                                    <div class="form-check form-check-inline m-0">
                                                        <input class="form-check-input" type="checkbox" id="<?php echo e($inputId); ?>" name="<?php echo e($tf['field']); ?>[]" value="<?php echo e($value); ?>" <?php echo in_array($value, sked_old_array($tf['field']), true) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small" for="<?php echo e($inputId); ?>"><?php echo e(ucfirst($value)); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="targeting_strict" name="targeting_strict" value="1" <?php echo sked_old_checked('targeting_strict', '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="targeting_strict"><strong>Strictly apply</strong> — hide this event completely from youth who don't match any option checked above (instead of just showing it to everyone with a "For You" highlight).</label>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer create-event-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                                <button type="submit" class="btn btn-sked"><i class="bi bi-calendar-plus me-1"></i> Create event</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php sked_render_autoshow_modals_script(); ?>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        ['ongoingEventsTable', 'upcomingEventsTable', 'pastEventsTable'].forEach(function (id) {
            new SkedTableTools('#' + id, { pageSize: 8, filters: [{ label: 'Type' }, { label: 'Status' }] });
        });
        new SkedTableTools('#participantsTable', { pageSize: 12, filters: [{ label: 'Status' }] });
    </script>
</body>
</html>
