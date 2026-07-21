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

$statusBadge = static function (string $s): string {
    $map = ['draft' => 'secondary', 'published' => 'primary', 'confirmed' => 'info', 'ongoing' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'evaluation' => 'warning', 'closed' => 'dark'];
    return $map[$s] ?? 'secondary';
};
$scopeLabel = static function (array $ev) {
    if ($ev['scope'] === 'barangay') {
        return 'Brgy. ' . sked_barangay_name((int) $ev['barangay_id']);
    }
    return ucfirst((string) $ev['scope']);
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
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

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>All Events</h2>
                    <span class="section-note"><?php echo count($events); ?> total</span>
                </div>
                <?php if (empty($events)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-calendar-x fs-1 d-block mb-2"></i>No events yet.</div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle">
                        <thead><tr><th>Event</th><th>Scope</th><th>Date</th><th>Type</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $ev): $c = sked_participant_counts((int) $ev['id']); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo e((string) $ev['title']); ?></div>
                                    <div class="small text-secondary tabular"><?php echo $ev['type'] === 'register' ? ($c['registered'] + $c['attended']) . ($ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '') . ' registered' : $c['active'] . ' interested'; ?></div>
                                </td>
                                <td class="small"><?php echo e($scopeLabel($ev)); ?></td>
                                <td class="small text-secondary"><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?></td>
                                <td class="small"><?php echo $ev['type'] === 'register' ? 'Register' : 'Join'; ?></td>
                                <td><span class="badge text-bg-<?php echo e($statusBadge((string) $ev['status'])); ?>"><?php echo e(ucfirst((string) $ev['status'])); ?></span></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="../manage/event.php?id=<?php echo (int) $ev['id']; ?>"><i class="bi bi-eye me-1"></i>View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
