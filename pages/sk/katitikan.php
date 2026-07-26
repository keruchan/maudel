<?php
/**
 * ============================================================
 * File     : pages/sk/katitikan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK's Katitikan (Minutes of the Meeting) list + create (P13).
 *            Detail/edit lives in pages/manage/katitikan.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/katitikan.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$formErrors = [];
if (empty($_SESSION['csrf_katitikan_token'])) {
    $_SESSION['csrf_katitikan_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_katitikan_token'], $token)) {
        $formErrors = ['Security validation failed. Please try again.'];
        sked_form_retain(true);
    } else {
        $creator = ['id' => $userId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $r = sked_katitikan_create($creator, $_POST);
        if ($r['ok']) {
            header('Location: ../manage/katitikan.php?id=' . $r['katitikan_id']);
            exit;
        }
        $formErrors = $r['errors'];
        sked_form_retain(true); // keep the session details already typed
    }
}

$records = $barangayId > 0 ? sked_katitikan_list_for_barangay($barangayId) : [];
$skMembers = $barangayId > 0 ? sked_sk_officials_for_barangay($barangayId) : [];
$finalizedCount = count(array_filter($records, static fn($r) => $r['status'] === 'finalized'));
$draftCount = count($records) - $finalizedCount;
$defaultYear = (int) date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Katitikan (Minutes)</title>
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
        <?php render_sked_navigation($role, 'katitikan'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Council Records &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Katitikan</h1>
                        <p class="text-secondary meta-copy mb-0">Minutes of the Meeting — official record of every SK regular/special session.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="members.php"><i class="bi bi-person-badge me-1"></i> SK Members</a>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <section class="row g-3 mb-4" aria-label="Katitikan summary">
                <div class="col-sm-4">
                    <div class="ledger-card">
                        <span class="ledger-tag">Sessions</span>
                        <div class="ledger-value tabular"><?php echo count($records); ?></div>
                        <div class="ledger-caption">Minutes recorded</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal">
                        <span class="ledger-tag">Finalized</span>
                        <div class="ledger-value tabular"><?php echo $finalizedCount; ?></div>
                        <div class="ledger-caption"><?php echo $draftCount; ?> draft<?php echo $draftCount === 1 ? '' : 's'; ?> remaining</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <a class="ledger-card accent-amber d-block text-reset text-decoration-none" href="members.php">
                        <span class="ledger-tag">SK Roster</span>
                        <div class="ledger-value tabular"><?php echo count($skMembers); ?></div>
                        <div class="ledger-caption">Officials available for roll call</div>
                    </a>
                </div>
            </section>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>New Session</h2></div>
                        <form method="post" action="katitikan.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katitikan_token']); ?>">

                            <?php sked_render_form_errors($formErrors, 'The session could not be created:'); ?>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label">Session No.</label>
                                    <input type="text" class="form-control" name="session_no" maxlength="20" placeholder="001" value="<?php echo e(sked_old('session_no')); ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Series Year</label>
                                    <input type="number" class="form-control" name="series_year" value="<?php echo e(sked_old('series_year', (string) $defaultYear)); ?>" min="2020" max="2100" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Meeting Date</label>
                                    <input type="date" class="form-control" name="meeting_date" value="<?php echo e(sked_old('meeting_date')); ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Meeting Time</label>
                                    <input type="time" class="form-control" name="meeting_time" value="<?php echo e(sked_old('meeting_time')); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Venue</label>
                                    <input type="text" class="form-control" name="venue" maxlength="150" placeholder="Barangay Session Hall" value="<?php echo e(sked_old('venue', 'Barangay Session Hall')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Presiding Officer <span class="text-secondary fw-normal">(defaults to you)</span></label>
                                    <input type="text" class="form-control" name="presiding_officer" maxlength="150" placeholder="<?php echo e($displayName); ?>" value="<?php echo e(sked_old('presiding_officer')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Prepared by <span class="text-secondary fw-normal">(SK Secretary)</span></label>
                                    <input type="text" class="form-control" name="prepared_by_name" maxlength="150" value="<?php echo e(sked_old('prepared_by_name')); ?>">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sked w-100 mt-3"><i class="bi bi-plus-lg me-1"></i>Create Minutes</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Session Records</h2><span class="section-note"><?php echo count($records); ?> total</span></div>
                        <?php if (empty($records)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-journal-text fs-1 d-block mb-2"></i>No minutes recorded yet.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle" id="katitikanTable">
                                    <thead><tr><th>Session</th><th>Date</th><th>Status</th><th>DILG</th><th class="text-end">Action</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($records as $k): ?>
                                        <tr>
                                            <td class="fw-semibold">No. <?php echo e((string) $k['session_no']); ?>, Series of <?php echo (int) $k['series_year']; ?></td>
                                            <td><?php echo e(date('M j, Y', strtotime((string) $k['meeting_date']))); ?></td>
                                            <td><span class="badge <?php echo $k['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $k['status']); ?></span></td>
                                            <td><?php echo !empty($k['report_id']) ? '<span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Submitted</span>' : '<span class="badge text-bg-light">Not yet</span>'; ?></td>
                                            <td class="text-end"><a href="../manage/katitikan.php?id=<?php echo (int) $k['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-journal-text"></i>Open</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js"></script>
    <script>
        new SkedTableTools('#katitikanTable', { pageSize: 10, filters: [{ label: 'Status' }] });
    </script>
</body>
</html>
