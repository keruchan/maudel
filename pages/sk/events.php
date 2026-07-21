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
$categories = sked_interest_categories();

$flash = ['type' => '', 'msg' => ''];
$reopenModal = false;

if (empty($_SESSION['csrf_events_token'])) {
    $_SESSION['csrf_events_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_events_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } else {
        $creator = ['id' => $skUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $data = $_POST;
        $data['scope'] = 'barangay';
        $r = sked_create_event($creator, $data);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Event "' . e((string) $_POST['title']) . '" created' . (!empty($_POST['publish']) ? ' and published.' : ' as a draft.')]
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
        $reopenModal = !$r['ok'];
    }
}

$events = $barangayId > 0 ? sked_events_for_manager($role, $skUserId, $barangayId) : [];
$participants = $barangayId > 0 ? sked_participants_for_manager($role, $barangayId) : [];

$statusBadge = static function (string $s): string {
    $map = ['draft' => 'secondary', 'published' => 'primary', 'confirmed' => 'info', 'ongoing' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'evaluation' => 'warning', 'closed' => 'dark'];
    return $map[$s] ?? 'secondary';
};
$participantStatusBadge = static function (string $s): string {
    $map = ['attended' => 'success', 'no_show' => 'danger', 'registered' => 'primary', 'interested' => 'info'];
    return $map[$s] ?? 'secondary';
};
$shareBase = (function () {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/SKed/pages/public/event.php?t=';
})();
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
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

            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Your Events</h2>
                    <span class="section-note"><?php echo count($events); ?> total</span>
                </div>
                <?php if (empty($events)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-calendar-x fs-1 d-block mb-2"></i>No events yet. Click "Create Event" to post your first one.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Event</th><th>Date</th><th>Type</th><th>Status</th><th>Participants</th><th>Share Link</th><th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($events as $ev): $c = sked_participant_counts((int) $ev['id']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e((string) $ev['title']); ?></div>
                                        <?php if ((int) $ev['is_team_sport'] === 1): ?><span class="badge text-bg-light">Team</span><?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?></td>
                                    <td class="small"><?php echo $ev['type'] === 'register' ? 'Register' : 'Join'; ?></td>
                                    <td><span class="badge text-bg-<?php echo e($statusBadge((string) $ev['status'])); ?>"><?php echo e(ucfirst((string) $ev['status'])); ?></span></td>
                                    <td class="tabular">
                                        <?php echo $ev['type'] === 'register' ? ($c['registered'] + $c['attended']) . ($ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '') : $c['active']; ?>
                                        <span class="small text-secondary d-block"><?php echo $ev['type'] === 'register' ? 'registered' : 'interested'; ?><?php echo $c['attended'] > 0 ? ' · ' . $c['attended'] . ' attended' : ''; ?></span>
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

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Participants</h2>
                    <span class="section-note"><?php echo count($participants); ?> sign-ups across all events</span>
                </div>
                <?php if (empty($participants)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No participants yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
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
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="createEventModalLabel">Create Event</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" action="events.php" novalidate>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_events_token']); ?>">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Title</label>
                                    <input type="text" class="form-control" id="title" name="title" maxlength="160" required>
                                </div>
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" maxlength="2000"></textarea>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6 mb-3">
                                        <label for="category" class="form-label">Category</label>
                                        <select class="form-select" id="category" name="category">
                                            <option value="">— None —</option>
                                            <?php foreach ($categories as $c): ?><option value="<?php echo e($c); ?>"><?php echo e($c); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="type" class="form-label">Type</label>
                                        <select class="form-select" id="type" name="type">
                                            <option value="interested">Interested / Join (no cap)</option>
                                            <option value="register">Register (limited slots)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="location" class="form-label">Location</label>
                                    <input type="text" class="form-control" id="location" name="location" maxlength="200">
                                </div>
                                <div class="row g-2">
                                    <div class="col-6 mb-3">
                                        <label for="event_date" class="form-label">Event date</label>
                                        <input type="date" class="form-control" id="event_date" name="event_date" min="<?php echo e(date('Y-m-d')); ?>" required>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="registration_deadline" class="form-label">Reg. deadline</label>
                                        <input type="date" class="form-control" id="registration_deadline" name="registration_deadline" min="<?php echo e(date('Y-m-d')); ?>">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="start_time" class="form-label">Start time</label>
                                        <input type="time" class="form-control" id="start_time" name="start_time">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="end_time" class="form-label">End time</label>
                                        <input type="time" class="form-control" id="end_time" name="end_time">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="min_participants" class="form-label">Min. participants</label>
                                        <input type="number" class="form-control" id="min_participants" name="min_participants" min="0" value="0">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="capacity" class="form-label">Capacity <span class="text-secondary fw-normal">(register)</span></label>
                                        <input type="number" class="form-control" id="capacity" name="capacity" min="1" placeholder="e.g. 24">
                                    </div>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="is_team_sport" name="is_team_sport" value="1">
                                    <label class="form-check-label" for="is_team_sport">Team sport (registrants enter a team name)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1" checked>
                                    <label class="form-check-label" for="publish">Publish now (visible to youth)</label>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i>Cancel</button>
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
    <script>
        // Re-open the Create Event modal automatically if the just-submitted form had errors.
        document.addEventListener('DOMContentLoaded', function () {
            var modalEl = document.getElementById('createEventModal');
            if (modalEl && modalEl.dataset.autoshow === '1') {
                new bootstrap.Modal(modalEl).show();
            }
        });
    </script>
</body>
</html>
