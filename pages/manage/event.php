<?php
/**
 * ============================================================
 * File     : pages/manage/event.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared event management detail page (P4 slice B) for SK /
 *            PPSK / DILG. Shows the roster, lets the managing official move
 *            the event through its lifecycle, and take attendance (which
 *            awards attendance points). Access is authorized per role/scope.
 *
 * Lives in pages/manage/ (sibling of role folders); the sidebar is rendered
 * with a role-scoped $linkBase so its dashboard/module links resolve.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/predictive.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$eventsHref = $linkBase . 'events.php';

$eventId = (int) ($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = sked_get_event($eventId);

if ($event === null || !sked_can_manage_event($role, $barangayId, $event)) {
    header('Location: ' . $eventsHref);
    exit;
}

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_mevent_token'])) {
    $_SESSION['csrf_mevent_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_mevent_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed.'];
    } elseif ($action === 'status') {
        $r = sked_set_event_status($userId, $event, (string) ($_POST['new_status'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Event status updated.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'attend') {
        $r = sked_mark_attendance($userId, $eventId, (int) ($_POST['user_id'] ?? 0), !empty($_POST['present']));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Attendance updated.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'attend_all') {
        $roster = sked_event_roster($eventId);
        foreach ($roster as $p) {
            if (in_array($p['status'], ['interested', 'registered'], true)) {
                sked_mark_attendance($userId, $eventId, (int) $p['user_id'], true);
            }
        }
        $flash = ['type' => 'success', 'msg' => 'All pending participants marked present.'];
    }
    $event = sked_get_event($eventId); // refresh after mutation
}

$roster = sked_event_roster($eventId);
$counts = sked_participant_counts($eventId);
$rating = sked_event_rating($eventId);
$nextStatuses = sked_event_next_statuses((string) $event['status']);
$todayLabel = date('l, F j, Y');
$canAttend = in_array($event['status'], ['confirmed', 'ongoing', 'completed', 'evaluation'], true);

// Turnout is only worth predicting while the final headcount isn't settled yet.
$turnoutPrediction = in_array($event['status'], ['draft', 'published', 'confirmed', 'ongoing'], true)
    ? sked_predict_event_turnout($event)
    : null;

$statusBadge = static function (string $s): string {
    $map = ['draft' => 'secondary', 'published' => 'primary', 'confirmed' => 'info', 'ongoing' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'evaluation' => 'warning', 'closed' => 'dark'];
    return $map[$s] ?? 'secondary';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | <?php echo e((string) $event['title']); ?></title>
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
        <?php render_sked_navigation($role, $role === 'ppsk' ? 'events' : 'manage_events', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow"><a href="<?php echo e($eventsHref); ?>" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Events</a> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title"><?php echo e((string) $event['title']); ?> <span class="badge text-bg-<?php echo e($statusBadge((string) $event['status'])); ?> align-middle" style="font-size:.5em;"><?php echo e(ucfirst((string) $event['status'])); ?></span></h1>
                        <p class="text-secondary meta-copy mb-0">
                            <i class="bi bi-calendar3 me-1"></i><?php echo e($event['event_date'] ? date('M j, Y', strtotime((string) $event['event_date'])) : 'TBA'); ?>
                            &middot; <?php echo $event['type'] === 'register' ? 'Register' : 'Join'; ?>
                            <?php if (!empty($event['location'])): ?> &middot; <?php echo e((string) $event['location']); ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="officer-chip"><span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?></span>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo $flash['msg']; ?></div><?php endif; ?>

            <?php $detailImageUrl = sked_event_image_url($event, '../public/event_image.php'); ?>
            <?php if ($detailImageUrl !== ''): ?>
                <div class="event-detail-media mb-4">
                    <img src="<?php echo e($detailImageUrl); ?>" alt="<?php echo e((string) $event['title']); ?>">
                </div>
            <?php endif; ?>

            <section class="row g-3 mb-4">
                <div class="col-sm-3"><div class="ledger-card"><span class="ledger-tag">Signed up</span><div class="ledger-value tabular"><?php echo (int) $counts['active']; ?></div><div class="ledger-caption"><?php echo $event['type'] === 'register' ? 'registered' : 'interested'; ?></div></div></div>
                <div class="col-sm-3"><div class="ledger-card accent-teal"><span class="ledger-tag">Attended</span><div class="ledger-value tabular"><?php echo (int) $counts['attended']; ?></div><div class="ledger-caption">present</div></div></div>
                <div class="col-sm-3"><div class="ledger-card accent-amber"><span class="ledger-tag">Minimum</span><div class="ledger-value tabular"><?php echo (int) $event['min_participants']; ?></div><div class="ledger-caption">required</div></div></div>
                <div class="col-sm-3"><div class="ledger-card accent-rust"><span class="ledger-tag">Rating</span><div class="ledger-value tabular"><?php echo $rating['avg'] !== null ? $rating['avg'] : '—'; ?></div><div class="ledger-caption"><?php echo (int) $rating['count']; ?> evaluations</div></div></div>
            </section>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Participants</h2>
                            <?php if ($canAttend && !empty($roster)): ?>
                            <form method="post" action="event.php?id=<?php echo $eventId; ?>" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_mevent_token']); ?>">
                                <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                                <input type="hidden" name="action" value="attend_all">
                                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all present</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($roster)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No participants yet.</div>
                        <?php else: ?>
                            <div class="table-responsive"><table class="table align-middle">
                                <thead><tr><th>Name</th><?php if ((int) $event['is_team_sport'] === 1): ?><th>Team</th><?php endif; ?><th>Status</th><?php if ($canAttend): ?><th class="text-end">Attendance</th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php foreach ($roster as $p): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?php echo e((string) $p['name']); ?></div><div class="small text-secondary">@<?php echo e((string) $p['username']); ?></div></td>
                                        <?php if ((int) $event['is_team_sport'] === 1): ?><td class="small"><?php echo e((string) ($p['team_name'] ?? '—')); ?></td><?php endif; ?>
                                        <td><span class="badge text-bg-<?php echo $p['status'] === 'attended' ? 'success' : ($p['status'] === 'no_show' ? 'danger' : ($p['status'] === 'cancelled' ? 'secondary' : 'primary')); ?> text-capitalize"><?php echo e(str_replace('_', ' ', (string) $p['status'])); ?></span></td>
                                        <?php if ($canAttend): ?>
                                        <td class="text-end">
                                            <?php if ($p['status'] !== 'cancelled'): ?>
                                            <form method="post" action="event.php?id=<?php echo $eventId; ?>" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_mevent_token']); ?>">
                                                <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                                                <input type="hidden" name="action" value="attend">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $p['user_id']; ?>">
                                                <button name="present" value="1" class="btn btn-sm <?php echo $p['status'] === 'attended' ? 'btn-success' : 'btn-outline-success'; ?>" title="Present"><i class="bi bi-check-lg"></i></button>
                                                <button name="present" value="0" class="btn btn-sm <?php echo $p['status'] === 'no_show' ? 'btn-danger' : 'btn-outline-danger'; ?>" title="Absent"><i class="bi bi-x-lg"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <?php if ($turnoutPrediction !== null): ?>
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>Predicted Participants</h2><span class="section-note">Forecast</span></div>
                        <?php if ($turnoutPrediction['predicted'] === null): ?>
                            <p class="small text-secondary mb-0"><i class="bi bi-graph-up me-1"></i><?php echo e($turnoutPrediction['explanation']); ?></p>
                        <?php else: ?>
                            <div class="ledger-value tabular" style="font-size:2rem;"><?php echo (int) $turnoutPrediction['predicted']; ?></div>
                            <div class="ledger-caption mb-2">likely range <?php echo (int) $turnoutPrediction['low']; ?>–<?php echo (int) $turnoutPrediction['high']; ?></div>
                            <p class="small text-secondary mb-0"><?php echo e($turnoutPrediction['explanation']); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>Lifecycle</h2></div>
                        <p class="small text-secondary">Current: <strong class="text-capitalize"><?php echo e((string) $event['status']); ?></strong></p>
                        <?php if (empty($nextStatuses)): ?>
                            <p class="small text-secondary mb-0">No further transitions available.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                            <?php foreach ($nextStatuses as $ns): ?>
                                <form method="post" action="event.php?id=<?php echo $eventId; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_mevent_token']); ?>">
                                    <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="new_status" value="<?php echo e($ns); ?>">
                                    <button class="btn btn-sm w-100 <?php echo $ns === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-primary'; ?>" type="submit">
                                        Move to “<?php echo e(ucfirst($ns)); ?>”
                                    </button>
                                </form>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="docket-panel">
                        <div class="section-heading"><h2>Details</h2></div>
                        <div class="snapshot-row"><span class="text-secondary">Scope</span><span class="text-capitalize"><?php echo e((string) $event['scope']); ?></span></div>
                        <div class="snapshot-row"><span class="text-secondary">Type</span><span><?php echo $event['type'] === 'register' ? 'Register' : 'Join'; ?></span></div>
                        <?php if ($event['capacity'] !== null): ?><div class="snapshot-row"><span class="text-secondary">Capacity</span><span><?php echo (int) $event['capacity']; ?></span></div><?php endif; ?>
                        <?php if (!empty($event['registration_deadline'])): ?><div class="snapshot-row"><span class="text-secondary">Reg. closes</span><span><?php echo e(date('M j, Y', strtotime((string) $event['registration_deadline']))); ?></span></div><?php endif; ?>
                        <div class="snapshot-row"><span class="text-secondary">Team sport</span><span><?php echo (int) $event['is_team_sport'] === 1 ? 'Yes' : 'No'; ?></span></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
