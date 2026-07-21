<?php
/**
 * ============================================================
 * File     : pages/public/event.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Public, no-login landing page for an event's shareable link
 *            (?t=<share_token>) — the link SK/PPSK promote on social media.
 *            The share link itself is surfaced ONLY inside management UIs;
 *            this page is where it points. Shows event details + a CTA to
 *            sign in / register. Draft events are treated as not found.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/barangays.php';

$token = (string) ($_GET['t'] ?? '');
$event = sked_get_event_by_token($token);

// Hide drafts / cancelled-and-closed from the public link.
$visible = $event !== null && in_array($event['status'], ['published', 'confirmed', 'ongoing', 'completed', 'evaluation'], true);

$scopeLabel = '';
if ($visible) {
    if ($event['scope'] === 'barangay') {
        $scopeLabel = 'Barangay ' . sked_barangay_name((int) $event['barangay_id']);
    } elseif ($event['scope'] === 'municipal') {
        $scopeLabel = e((string) $event['municipality']) . ' (municipality-wide)';
    } else {
        $scopeLabel = 'Inter-barangay';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | <?php echo $visible ? e((string) $event['title']) : 'Event'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; background: #f5f5fb; color: #1e1b4b; }
        h1, h2, .brand-word { font-family: 'Sora', sans-serif; }
        .event-wrap { max-width: 640px; margin: 0 auto; padding: 2.5rem 1.25rem; }
        .event-card { overflow:hidden; background: #fff; border: 1px solid #e5e7f2; border-radius: 18px; box-shadow: 0 10px 40px rgba(30,27,75,.06); }
        .event-card-body { padding: 2rem; }
        .event-hero-image { aspect-ratio: 16 / 9; background:#e9ebf7; border-bottom:1px solid #e5e7f2; }
        .event-hero-image img { width:100%; height:100%; display:block; object-fit:cover; }
        .brand-seal { display:inline-flex; width:40px; height:40px; border-radius:12px; background:#4338ca; color:#fff; align-items:center; justify-content:center; }
        .meta-line { display:flex; gap:.5rem; align-items:center; color:#4b4b6a; margin-bottom:.35rem; }
        .btn-sked { background:#4338ca; border-color:#4338ca; color:#fff; }
        .btn-sked:hover { background:#3730a3; color:#fff; }
    </style>
</head>
<body>
    <div class="event-wrap">
        <a href="../index.php" class="text-decoration-none d-inline-flex align-items-center gap-2 mb-4">
            <span class="brand-seal"><i class="bi bi-people-fill"></i></span>
            <span><span class="brand-word d-block fw-bold" style="color:#1e1b4b;">SKed</span><span class="small text-secondary">Sangguniang Kabataan</span></span>
        </a>

        <?php if (!$visible): ?>
            <div class="event-card text-center">
                <div class="event-card-body">
                <i class="bi bi-calendar-x fs-1 text-secondary"></i>
                <h1 class="h4 mt-3">Event not found</h1>
                <p class="text-secondary">This event link is invalid or the event is no longer available.</p>
                <a href="../index.php" class="btn btn-sked mt-2">Go to SKed</a>
                </div>
            </div>
        <?php else: ?>
            <?php $counts = sked_participant_counts((int) $event['id']); $taken = $counts['registered'] + $counts['attended']; ?>
            <div class="event-card">
                <?php $imageUrl = sked_event_image_url($event, 'event_image.php'); ?>
                <?php if ($imageUrl !== ''): ?>
                    <div class="event-hero-image">
                        <img src="<?php echo e($imageUrl); ?>" alt="<?php echo e((string) $event['title']); ?>">
                    </div>
                <?php endif; ?>
                <div class="event-card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge text-bg-primary text-capitalize"><?php echo e((string) $event['type'] === 'register' ? 'Registration' : 'Open to join'); ?></span>
                    <?php if ((int) $event['is_team_sport'] === 1): ?><span class="badge text-bg-secondary">Team event</span><?php endif; ?>
                </div>
                <h1 class="h3 mb-3"><?php echo e((string) $event['title']); ?></h1>

                <div class="meta-line"><i class="bi bi-geo-alt"></i> <?php echo $scopeLabel; ?><?php echo !empty($event['location']) ? ' &middot; ' . e((string) $event['location']) : ''; ?></div>
                <div class="meta-line"><i class="bi bi-calendar3"></i> <?php echo e($event['event_date'] ? date('l, F j, Y', strtotime((string) $event['event_date'])) : 'Date to be announced'); ?>
                    <?php if (!empty($event['start_time'])): ?> &middot; <?php echo e(date('g:i A', strtotime((string) $event['start_time']))); ?><?php endif; ?>
                </div>
                <?php if (!empty($event['category'])): ?><div class="meta-line"><i class="bi bi-tag"></i> <?php echo e((string) $event['category']); ?></div><?php endif; ?>
                <?php if ($event['type'] === 'register' && $event['capacity'] !== null): ?>
                    <div class="meta-line"><i class="bi bi-people"></i> <?php echo $taken; ?> / <?php echo (int) $event['capacity']; ?> slots filled</div>
                <?php endif; ?>
                <?php if (!empty($event['registration_deadline'])): ?>
                    <div class="meta-line"><i class="bi bi-hourglass-split"></i> Register by <?php echo e(date('M j, Y', strtotime((string) $event['registration_deadline']))); ?></div>
                <?php endif; ?>

                <?php if (!empty($event['description'])): ?>
                    <hr>
                    <p class="mb-0" style="white-space:pre-line;"><?php echo e((string) $event['description']); ?></p>
                <?php endif; ?>

                <hr>
                <p class="text-secondary small mb-3">Sign in to SKed with your verified youth account to join this event and earn participation points.</p>
                <div class="d-flex gap-2">
                    <a href="../auth/login.php" class="btn btn-sked"><i class="bi bi-box-arrow-in-right me-1"></i> Sign in to join</a>
                    <a href="../auth/register.php" class="btn btn-outline-secondary">Create an account</a>
                </div>
                </div>
            </div>
        <?php endif; ?>

        <p class="text-center text-secondary small mt-4 mb-0">Powered by SKed &middot; Sangguniang Kabataan</p>
    </div>
</body>
</html>
