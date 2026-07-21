<?php
/**
 * ============================================================
 * File     : pages/sk/abyip.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK's ABYIP list + create (P12) — normally derived from a
 *            CBYDP cycle for one calendar year, or started blank if the
 *            barangay has no CBYDP yet. Detail/edit lives in
 *            pages/manage/abyip_plan.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_abyip_token'])) {
    $_SESSION['csrf_abyip_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_abyip_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $creator = ['id' => $userId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $year = (int) ($_POST['calendar_year'] ?? 0);
        $prepared = (string) ($_POST['prepared_by_name'] ?? '');
        $cbydpId = (int) ($_POST['cbydp_plan_id'] ?? 0);

        $r = $cbydpId > 0
            ? sked_abyip_create_from_cbydp($creator, $cbydpId, $year, $prepared)
            : sked_abyip_create_blank($creator, $year, $prepared);

        if ($r['ok']) {
            header('Location: ../manage/abyip_plan.php?id=' . $r['plan_id']);
            exit;
        }
        $flash = ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    }
}

$plans = $barangayId > 0 ? sked_abyip_list_for_barangay($barangayId) : [];
$cbydps = $barangayId > 0 ? sked_cbydp_list_for_barangay($barangayId) : [];
$defaultYear = (int) date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | ABYIP</title>
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
        <?php render_sked_navigation($role, 'abyip'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Development Planning &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">ABYIP</h1>
                        <p class="text-secondary meta-copy mb-0">Annual Barangay Youth Investment Program — one calendar year's budgeted PPAs, by Center of Participation.</p>
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

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Start a New Year</h2></div>
                        <form method="post" action="abyip.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_abyip_token']); ?>">
                            <div class="mb-3">
                                <label for="calendar_year" class="form-label">Calendar year</label>
                                <input type="number" class="form-control" id="calendar_year" name="calendar_year" value="<?php echo (int) $defaultYear; ?>" min="2020" max="2100" required>
                            </div>
                            <div class="mb-3">
                                <label for="cbydp_plan_id" class="form-label">Derive from CBYDP <span class="text-secondary fw-normal">(recommended)</span></label>
                                <select class="form-select" id="cbydp_plan_id" name="cbydp_plan_id">
                                    <option value="">— Start blank instead —</option>
                                    <?php foreach ($cbydps as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>">CY <?php echo (int) $c['cy_year_start']; ?>–<?php echo (int) $c['cy_year_start'] + 2; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($cbydps)): ?><small class="text-secondary">No CBYDP yet — <a href="cbydp.php">create one first</a> to derive from, or start blank.</small><?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="prepared_by_name" class="form-label">Prepared by <span class="text-secondary fw-normal">(SK Secretary)</span></label>
                                <input type="text" class="form-control" id="prepared_by_name" name="prepared_by_name" maxlength="150" placeholder="Full name">
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-plus-lg me-1"></i>Create ABYIP</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Your Barangay's Plans</h2><span class="section-note"><?php echo count($plans); ?> total</span></div>
                        <?php if (empty($plans)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-cash-coin fs-1 d-block mb-2"></i>No ABYIP yet. Start one on the left.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead><tr><th>Year</th><th>Source</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($plans as $p): ?>
                                        <tr>
                                            <td class="fw-semibold">CY <?php echo (int) $p['calendar_year']; ?></td>
                                            <td><?php echo $p['cbydp_plan_id'] ? '<span class="badge text-bg-light">From CBYDP</span>' : '<span class="badge text-bg-light">Blank</span>'; ?></td>
                                            <td><span class="badge <?php echo $p['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $p['status']); ?></span></td>
                                            <td class="text-end"><a href="../manage/abyip_plan.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-folder2-open"></i>Open</a></td>
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
</body>
</html>
