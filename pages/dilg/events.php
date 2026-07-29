<?php
/**
 * ============================================================
 * File     : pages/dilg/events.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG "Programs & Events" (P15) — read-only municipality-wide
 *            event oversight. sked_events_for_manager('dilg', ...) already
 *            returns every event regardless of scope/barangay; this page
 *            was the only piece missing. DILG can already open
 *            pages/manage/event.php for any event (sked_can_manage_event
 *            returns true unconditionally for 'dilg') — no new authorization
 *            logic needed, just a listing.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$events = sked_events_for_manager('dilg', 0, null);
$ongoingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'ongoing'));
$pastEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'past'));
$upcomingEvents = array_values(array_filter($events, static fn ($e) => sked_event_time_bucket($e) === 'upcoming'));

$scopeLabel = static function (array $ev) {
    if ($ev['scope'] === 'barangay') {
        return 'Brgy. ' . sked_barangay_name((int) $ev['barangay_id']);
    }
    return ucfirst((string) $ev['scope']);
};

$renderEventsTable = function (string $title, array $list, string $icon, string $emptyMsg, string $tableId) use ($scopeLabel) {
    ?>
    <div class="docket-panel mb-4">
        <div class="section-heading">
            <h2><?php echo e($title); ?></h2>
            <span class="section-note"><?php echo count($list); ?> total</span>
        </div>
        <?php if (empty($list)): ?>
            <div class="text-center text-secondary py-5"><i class="bi <?php echo e($icon); ?> fs-1 d-block mb-2"></i><?php echo e($emptyMsg); ?></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table align-middle" id="<?php echo e($tableId); ?>">
                <thead><tr><th>Event</th><th>Scope</th><th>Date</th><th>Type</th><th>Status</th><th class="text-end">Action</th></tr></thead>
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
                                            <span class="small text-secondary tabular"><?php echo $ev['type'] === 'register' ? ($c['registered'] + $c['attended']) . ($ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '') . ' registered' : $c['active'] . ' interested'; ?></span>
                                        </span>
                                    </div>
                                </td>
                        <td class="small"><?php echo e($scopeLabel($ev)); ?></td>
                        <td class="small text-secondary"><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?></td>
                        <td class="small"><?php echo $ev['type'] === 'register' ? 'Register' : 'Join'; ?></td>
                        <td>
                            <span class="badge text-bg-<?php echo e(sked_event_display_badge_class($ev)); ?>"><?php echo e(sked_event_display_status_label($ev)); ?></span>
                            <?php if (sked_event_needs_closeout($ev)): ?>
                                <div class="small text-warning-emphasis mt-1" title="The event date has passed but the status was never advanced."><i class="bi bi-exclamation-triangle-fill me-1"></i>Needs closing out</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="../manage/event.php?id=<?php echo (int) $ev['id']; ?>"><i class="bi bi-eye me-1"></i>View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
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
    <title>SKed | Programs &amp; Events</title>
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
        <?php render_sked_navigation($role, 'events'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Programs &amp; Events</h1>
                        <p class="text-secondary meta-copy mb-0">Every event across every barangay and the federation, municipality-wide.</p>
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

            <?php
                $renderEventsTable('Ongoing Events', $ongoingEvents, 'bi-play-circle', 'No events are currently ongoing.', 'ongoingEventsTable');
                $renderEventsTable('Upcoming Events', $upcomingEvents, 'bi-calendar-x', 'No upcoming events yet.', 'upcomingEventsTable');
                $renderEventsTable('Past Events', $pastEvents, 'bi-clock-history', 'No past events yet.', 'pastEventsTable');
            ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        ['ongoingEventsTable', 'upcomingEventsTable', 'pastEventsTable'].forEach(function (id) {
            new SkedTableTools('#' + id, { pageSize: 8, filters: [{ label: 'Scope' }, { label: 'Type' }, { label: 'Status' }] });
        });
    </script>
</body>
</html>
