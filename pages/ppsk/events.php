<?php
/**
 * ============================================================
 * File     : pages/ppsk/events.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK "Federation Events" (P4). Create municipal
 *            (municipality-wide) or inter-barangay events (targeting a set
 *            of barangays) and track participation across barangays.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/events.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$ppskUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');
$categories = sked_interest_categories();
$barangays = sked_barangays();

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_pevents_token'])) {
    $_SESSION['csrf_pevents_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_pevents_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $creator = ['id' => $ppskUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => null];
        $r = sked_create_event($creator, $_POST);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Event "' . e((string) $_POST['title']) . '" created' . (!empty($_POST['publish']) ? ' and published.' : ' as a draft.')]
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    }
}

$events = sked_events_for_manager($role, $ppskUserId, null);

$statusBadge = static function (string $s): string {
    $map = ['draft' => 'secondary', 'published' => 'primary', 'confirmed' => 'info', 'ongoing' => 'info', 'completed' => 'success', 'cancelled' => 'danger', 'evaluation' => 'warning', 'closed' => 'dark'];
    return $map[$s] ?? 'secondary';
};
$shareBase = (function () {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/SKed/pages/public/event.php?t=';
})();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Federation Events</title>
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
                        <div class="eyebrow">Federation Office &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Federation Events</h1>
                        <p class="text-secondary meta-copy mb-0">Create municipality-wide or inter-barangay events across Siniloan.</p>
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

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Create Event</h2></div>
                        <form method="post" action="events.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_pevents_token']); ?>">
                            <div class="mb-3">
                                <label for="title" class="form-label">Title</label>
                                <input type="text" class="form-control" id="title" name="title" maxlength="160" required>
                            </div>
                            <div class="mb-3">
                                <label for="scope" class="form-label">Scope</label>
                                <select class="form-select" id="scope" name="scope" onchange="document.getElementById('bgyPicker').style.display = this.value==='interbarangay' ? 'block':'none';">
                                    <option value="municipal">Municipality-wide (all barangays)</option>
                                    <option value="interbarangay">Inter-barangay (pick barangays)</option>
                                </select>
                            </div>
                            <div class="mb-3" id="bgyPicker" style="display:none;">
                                <label class="form-label">Target barangays <span class="text-secondary fw-normal">(pick 2+)</span></label>
                                <div class="row g-1" style="max-height:180px; overflow:auto; border:1px solid #e5e7f2; border-radius:10px; padding:.5rem;">
                                    <?php foreach ($barangays as $b): ?>
                                        <div class="col-6"><div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="target_barangays[]" value="<?php echo (int) $b['id']; ?>" id="tb<?php echo (int) $b['id']; ?>">
                                            <label class="form-check-label small" for="tb<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></label>
                                        </div></div>
                                    <?php endforeach; ?>
                                </div>
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
                                        <option value="interested">Interested / Join</option>
                                        <option value="register">Register (slots)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="location" class="form-label">Location</label>
                                <input type="text" class="form-control" id="location" name="location" maxlength="200">
                            </div>
                            <div class="row g-2">
                                <div class="col-6 mb-3"><label for="event_date" class="form-label">Event date</label><input type="date" class="form-control" id="event_date" name="event_date" min="<?php echo e(date('Y-m-d')); ?>" required></div>
                                <div class="col-6 mb-3"><label for="registration_deadline" class="form-label">Reg. deadline</label><input type="date" class="form-control" id="registration_deadline" name="registration_deadline" min="<?php echo e(date('Y-m-d')); ?>"></div>
                                <div class="col-6 mb-3"><label for="min_participants" class="form-label">Min. participants</label><input type="number" class="form-control" id="min_participants" name="min_participants" min="0" value="0"></div>
                                <div class="col-6 mb-3"><label for="capacity" class="form-label">Capacity <span class="text-secondary fw-normal">(register)</span></label><input type="number" class="form-control" id="capacity" name="capacity" min="1" placeholder="e.g. 60"></div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="is_team_sport" name="is_team_sport" value="1">
                                <label class="form-check-label" for="is_team_sport">Team sport</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1" checked>
                                <label class="form-check-label" for="publish">Publish now</label>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-calendar-plus me-1"></i> Create event</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Federation Events</h2><span class="section-note"><?php echo count($events); ?> total</span></div>
                        <?php if (empty($events)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-calendar-x fs-1 d-block mb-2"></i>No federation events yet.</div>
                        <?php else: ?>
                            <?php foreach ($events as $ev): $c = sked_participant_counts((int) $ev['id']); ?>
                                <div class="docket-row d-block">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="docket-title"><?php echo e((string) $ev['title']); ?>
                                                <span class="badge text-bg-<?php echo e($statusBadge((string) $ev['status'])); ?> ms-1"><?php echo e(ucfirst((string) $ev['status'])); ?></span>
                                                <span class="badge text-bg-light ms-1 text-capitalize"><?php echo e((string) $ev['scope']); ?></span>
                                            </div>
                                            <div class="docket-sub"><i class="bi bi-calendar3 me-1"></i><?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?> &middot; <?php echo $ev['type'] === 'register' ? 'Register' : 'Join'; ?></div>
                                        </div>
                                        <div class="text-end">
                                            <span class="count-badge tabular"><?php echo $ev['type'] === 'register' ? ($c['registered'] + $c['attended']) . ($ev['capacity'] !== null ? ' / ' . (int) $ev['capacity'] : '') : $c['active']; ?></span>
                                            <div class="small text-secondary"><?php echo $ev['type'] === 'register' ? 'registered' : 'interested'; ?></div>
                                        </div>
                                    </div>
                                    <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
                                        <a class="btn btn-sm btn-outline-primary" href="../manage/event.php?id=<?php echo (int) $ev['id']; ?>"><i class="bi bi-gear me-1"></i>Manage</a>
                                        <span class="small text-secondary">Min: <?php echo (int) $ev['min_participants']; ?></span>
                                        <div class="input-group input-group-sm ms-auto" style="max-width:320px;">
                                            <span class="input-group-text" title="Management-only share link"><i class="bi bi-link-45deg"></i></span>
                                            <input type="text" class="form-control" readonly value="<?php echo e($shareBase . (string) $ev['share_token']); ?>" onclick="this.select();" aria-label="Shareable public link">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
