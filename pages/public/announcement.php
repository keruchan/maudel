<?php
/**
 * ============================================================
 * File     : pages/public/announcement.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Public, no-login detail page for a single announcement —
 *            where the landing-page carousel's "Announcement" cards route
 *            to (mirrors pages/public/event.php's role for events). Draft
 *            announcements are treated as not found; no share token exists
 *            for announcements (unlike events) since there's nothing to
 *            join/register, so a plain ?id= is sufficient here.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/announcements.php';
require_once __DIR__ . '/../../includes/barangays.php';

$announcementId = (int) ($_GET['id'] ?? 0);
$announcement = sked_get_announcement($announcementId);

$visible = $announcement !== null && $announcement['status'] === 'published';

$scopeLabel = '';
if ($visible) {
    if ($announcement['scope'] === 'barangay') {
        $scopeLabel = 'Barangay ' . sked_barangay_name((int) $announcement['barangay_id']);
    } elseif ($announcement['scope'] === 'municipal') {
        $scopeLabel = 'Municipality-wide';
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
    <title>SKed | <?php echo $visible ? e((string) $announcement['title']) : 'Announcement'; ?></title>
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
                <i class="bi bi-megaphone fs-1 text-secondary"></i>
                <h1 class="h4 mt-3">Announcement not found</h1>
                <p class="text-secondary">This announcement link is invalid or no longer available.</p>
                <a href="../index.php" class="btn btn-sked mt-2">Go to SKed</a>
                </div>
            </div>
        <?php else: ?>
            <div class="event-card">
                <?php $imageUrl = sked_announcement_image_url($announcement, 'announcement_image.php'); ?>
                <?php if ($imageUrl !== ''): ?>
                    <div class="event-hero-image">
                        <img src="<?php echo e($imageUrl); ?>" alt="<?php echo e((string) $announcement['title']); ?>">
                    </div>
                <?php endif; ?>
                <div class="event-card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge text-bg-primary"><i class="bi bi-megaphone me-1"></i>Announcement</span>
                    <?php if ((int) $announcement['pinned'] === 1): ?><span class="badge text-bg-warning-emphasis"><i class="bi bi-pin-angle-fill me-1"></i>Pinned</span><?php endif; ?>
                </div>
                <h1 class="h3 mb-3"><?php echo e((string) $announcement['title']); ?></h1>

                <div class="meta-line"><i class="bi bi-geo-alt"></i> <?php echo e($scopeLabel); ?></div>
                <div class="meta-line"><i class="bi bi-clock-history"></i> Posted <?php echo e(date('l, F j, Y', strtotime((string) $announcement['created_at']))); ?></div>
                <?php if (!empty($announcement['created_by_name'])): ?>
                    <div class="meta-line"><i class="bi bi-person-badge"></i> <?php echo e((string) $announcement['created_by_name']); ?></div>
                <?php endif; ?>

                <hr>
                <p class="mb-0" style="white-space:pre-line;"><?php echo e((string) $announcement['content']); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <p class="text-center text-secondary small mt-4 mb-0">Powered by SKed &middot; Sangguniang Kabataan</p>
    </div>
</body>
</html>
