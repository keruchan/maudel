<?php
/**
 * ============================================================
 * File     : pages/sk/charters.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK "CBYDP / Projects" tracker (P6, spec 4.3). Create and track
 *            project charters (upcoming/ongoing/completed) with an
 *            info-only budget field (no approval workflow), filterable by
 *            status and date range, with a success rate derived from a
 *            linked event's youth evaluations.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/charters.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_charters_token'])) {
    $_SESSION['csrf_charters_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_charters_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } elseif ((string) ($_POST['form'] ?? '') === 'status') {
        $r = sked_update_charter((int) ($_POST['charter_id'] ?? 0), $barangayId, $_POST);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Project status updated.'] : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    } else {
        $creator = ['id' => $skUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $r = sked_create_charter($creator, $_POST);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Project "' . e((string) $_POST['title']) . '" added to the charter.']
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    }
}

// Filters (GET, so the list stays bookmarkable/shareable).
$filterStatus = (string) ($_GET['status'] ?? '');
$filterFrom = (string) ($_GET['date_from'] ?? '');
$filterTo = (string) ($_GET['date_to'] ?? '');
$filters = array_filter(['status' => $filterStatus, 'date_from' => $filterFrom, 'date_to' => $filterTo]);

$charters = $barangayId > 0 ? sked_charters_for_barangay($barangayId, $filters) : [];
$linkableEvents = $barangayId > 0 ? sked_events_for_manager('sk', $skUserId, $barangayId) : [];

$statusBadge = static fn(string $s) => ['upcoming' => 'primary', 'ongoing' => 'info', 'completed' => 'success'][$s] ?? 'secondary';
$fmtMoney = static fn($amt) => $amt === null ? '—' : '₱' . number_format((float) $amt, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | CBYDP / Projects</title>
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
        <?php render_sked_navigation($role, 'projects'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">CBYDP / Projects</h1>
                        <p class="text-secondary meta-copy mb-0">Track your barangay's youth development projects — upcoming, current, and past.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>New Project</h2></div>
                        <form method="post" action="charters.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_charters_token']); ?>">
                            <div class="mb-3">
                                <label for="title" class="form-label">Project title</label>
                                <input type="text" class="form-control" id="title" name="title" maxlength="160" required>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2" maxlength="2000"></textarea>
                            </div>
                            <div class="row g-2">
                                <div class="col-6 mb-3">
                                    <label for="start_date" class="form-label">Start date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="end_date" class="form-label">End date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="budget_amount" class="form-label">Budget <span class="text-secondary fw-normal">(info only)</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" step="0.01" min="0" class="form-control" id="budget_amount" name="budget_amount" placeholder="0.00">
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="upcoming">Upcoming</option>
                                        <option value="ongoing">Current</option>
                                        <option value="completed">Past</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="event_id" class="form-label">Link to event <span class="text-secondary fw-normal">(optional — powers success rate)</span></label>
                                <select class="form-select" id="event_id" name="event_id">
                                    <option value="0">— None —</option>
                                    <?php foreach ($linkableEvents as $ev): ?>
                                        <option value="<?php echo (int) $ev['id']; ?>"><?php echo e((string) $ev['title']); ?><?php echo $ev['event_date'] ? ' (' . e(date('M j, Y', strtotime((string) $ev['event_date']))) . ')' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-clipboard-plus me-1"></i> Add project</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="docket-panel mb-3">
                        <form method="get" action="charters.php" class="row g-2 align-items-end">
                            <div class="col-sm-4">
                                <label for="f_status" class="form-label small mb-1">Status</label>
                                <select class="form-select form-select-sm" id="f_status" name="status">
                                    <option value="">All</option>
                                    <option value="upcoming" <?php echo $filterStatus === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                    <option value="ongoing" <?php echo $filterStatus === 'ongoing' ? 'selected' : ''; ?>>Current</option>
                                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Past</option>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <label for="f_from" class="form-label small mb-1">From</label>
                                <input type="date" class="form-control form-control-sm" id="f_from" name="date_from" value="<?php echo e($filterFrom); ?>">
                            </div>
                            <div class="col-sm-3">
                                <label for="f_to" class="form-label small mb-1">To</label>
                                <input type="date" class="form-control form-control-sm" id="f_to" name="date_to" value="<?php echo e($filterTo); ?>">
                            </div>
                            <div class="col-sm-2">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel"></i>Filter</button>
                            </div>
                        </form>
                    </div>

                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Projects</h2>
                            <span class="section-note"><?php echo count($charters); ?> shown</span>
                        </div>
                        <?php if (empty($charters)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-data fs-1 d-block mb-2"></i>No projects match these filters.</div>
                        <?php else: ?>
                            <?php foreach ($charters as $c): $sr = sked_charter_success_rate($c); ?>
                                <div class="docket-row d-block">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="docket-title"><?php echo e((string) $c['title']); ?>
                                                <span class="badge text-bg-<?php echo e($statusBadge((string) $c['status'])); ?> ms-1"><?php echo $c['status'] === 'ongoing' ? 'Current' : ($c['status'] === 'completed' ? 'Past' : 'Upcoming'); ?></span>
                                            </div>
                                            <div class="docket-sub">
                                                <?php if (!empty($c['start_date']) || !empty($c['end_date'])): ?>
                                                    <i class="bi bi-calendar3 me-1"></i><?php echo e($c['start_date'] ? date('M j, Y', strtotime((string) $c['start_date'])) : '…'); ?> &ndash; <?php echo e($c['end_date'] ? date('M j, Y', strtotime((string) $c['end_date'])) : '…'); ?>
                                                <?php endif; ?>
                                                &middot; Budget: <?php echo $fmtMoney($c['budget_amount']); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <span class="count-badge tabular"><?php echo $sr['avg'] !== null ? $sr['avg'] . '/5' : '—'; ?></span>
                                            <div class="small text-secondary"><?php echo $sr['count']; ?> eval<?php echo $sr['count'] === 1 ? '' : 's'; ?></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($c['description'])): ?><p class="small text-secondary mt-2 mb-2"><?php echo e((string) $c['description']); ?></p><?php endif; ?>
                                    <form method="post" action="charters.php" class="d-inline-flex align-items-center gap-2 mt-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_charters_token']); ?>">
                                        <input type="hidden" name="form" value="status">
                                        <input type="hidden" name="charter_id" value="<?php echo (int) $c['id']; ?>">
                                        <select name="status" class="form-select form-select-sm" style="width:auto;">
                                            <option value="upcoming" <?php echo $c['status'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                            <option value="ongoing" <?php echo $c['status'] === 'ongoing' ? 'selected' : ''; ?>>Current</option>
                                            <option value="completed" <?php echo $c['status'] === 'completed' ? 'selected' : ''; ?>>Past</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i>Update</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
