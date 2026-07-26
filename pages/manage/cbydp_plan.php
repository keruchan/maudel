<?php
/**
 * ============================================================
 * File     : pages/manage/cbydp_plan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : CBYDP detail page (P12) — shared by SK (full edit, own
 *            barangay only), PPSK/DILG (view + export + download signed
 *            copy, any barangay, for oversight). Sections (Center of
 *            Participation) and their youth-development-concern line items
 *            are added/removed one at a time; export/print + signed-copy
 *            upload live here too.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/plan_uploads.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$todayLabel = date('l, F j, Y');

$planId = (int) ($_GET['id'] ?? $_POST['plan_id'] ?? 0);
// Keep whatever was typed when a submission failed, so nothing is retyped.
sked_form_retain(($flash['type'] ?? '') === 'danger');

$plan = sked_cbydp_get($planId);
if ($plan === null) {
    header('Location: ' . ($role === 'sk' ? 'cbydp.php' : 'dashboard.php'));
    exit;
}
$isEditable = $role === 'sk' && (int) $plan['barangay_id'] === $sessionBarangayId;
if ($role === 'sk' && !$isEditable) {
    header('Location: cbydp.php');
    exit;
}

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_cbydpplan_token'])) {
    $_SESSION['csrf_cbydpplan_token'] = bin2hex(random_bytes(32));
}

if ($isEditable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_cbydpplan_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($action === 'add_section') {
        $r = sked_cbydp_add_section($planId, $sessionBarangayId, (string) ($_POST['center'] ?? ''), (string) ($_POST['agenda_statement'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Section added.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'delete_section') {
        $r = sked_cbydp_delete_section((int) ($_POST['section_id'] ?? 0), $sessionBarangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Section removed.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'add_item') {
        $r = sked_cbydp_add_line_item((int) ($_POST['section_id'] ?? 0), $sessionBarangayId, $_POST);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Line item added.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'delete_item') {
        $r = sked_cbydp_delete_line_item((int) ($_POST['item_id'] ?? 0), $sessionBarangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Line item removed.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'set_status') {
        $r = sked_cbydp_set_status($planId, $sessionBarangayId, (string) ($_POST['status'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Status updated.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'upload_signed') {
        $r = sked_save_signed_upload('cbydp', $planId, $userId, $sessionBarangayId, $_FILES['signed_file'] ?? []);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Signed copy uploaded.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    }
    $plan = sked_cbydp_get($planId); // refresh
}

$usedCenters = array_column($plan['sections'], 'center_of_participation');
$availableCenters = array_diff(sked_interest_categories(), $usedCenters);
$totalBudget = sked_cbydp_total_budget($plan);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | CBYDP CY <?php echo (int) $plan['cy_year_start']; ?></title>
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
        <?php render_sked_navigation($role, 'cbydp', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Brgy. <?php echo e($plan['barangay_name']); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">CBYDP CY <?php echo (int) $plan['cy_year_start']; ?>–<?php echo (int) $plan['cy_year_start'] + 2; ?></h1>
                        <p class="text-secondary meta-copy mb-0">
                            <span class="badge <?php echo $plan['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $plan['status']); ?></span>
                            Total budget: ₱<?php echo number_format($totalBudget, 2); ?>
                            <?php if (!$isEditable): ?><span class="ms-2"><i class="bi bi-eye me-1"></i>View-only</span><?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn-logout-outline text-decoration-none" href="cbydp_export.php?id=<?php echo (int) $plan['id']; ?>" target="_blank"><i class="bi bi-printer me-1"></i> Export</a>
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="<?php echo e($role === 'sk' ? 'cbydp.php' : 'dashboard.php'); ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <?php if ($isEditable): ?>
            <div class="docket-panel mb-4">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                        <input type="hidden" name="action" value="set_status">
                        <label class="form-label small mb-0">Plan status</label>
                        <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <option value="draft" <?php echo $plan['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="finalized" <?php echo $plan['status'] === 'finalized' ? 'selected' : ''; ?>>Finalized</option>
                        </select>
                    </form>
                    <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                        <input type="hidden" name="action" value="upload_signed">
                        <input type="file" name="signed_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-sm btn-sked text-nowrap"><i class="bi bi-upload me-1"></i>Upload signed copy</button>
                    </form>
                </div>
                <?php if (!empty($plan['signed_file_path'])): ?>
                    <p class="text-secondary small mt-2 mb-0"><i class="bi bi-file-earmark-check me-1"></i>Signed copy on file: <a href="plan_file.php?type=cbydp&amp;id=<?php echo (int) $planId; ?>"><?php echo e((string) $plan['signed_file_original_name']); ?></a> (uploaded <?php echo e(date('M j, Y', strtotime((string) $plan['signed_uploaded_at']))); ?>)</p>
                <?php endif; ?>
            </div>
            <?php elseif (!empty($plan['signed_file_path'])): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-file-earmark-check me-1"></i>Signed copy on file: <a href="plan_file.php?type=cbydp&amp;id=<?php echo (int) $planId; ?>"><?php echo e((string) $plan['signed_file_original_name']); ?></a> (uploaded <?php echo e(date('M j, Y', strtotime((string) $plan['signed_uploaded_at']))); ?>)</div>
            <?php endif; ?>

            <?php if ($isEditable): ?>
            <div class="docket-panel mb-4">
                <div class="section-heading"><h2>Add a Center of Participation</h2></div>
                <?php if (empty($availableCenters)): ?>
                    <p class="text-secondary small mb-0">All centers have been added to this plan.</p>
                <?php else: ?>
                    <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                        <input type="hidden" name="action" value="add_section">
                        <div class="col-md-4">
                            <label class="form-label">Center of Participation</label>
                            <select name="center" class="form-select" required>
                                <option value="">Select…</option>
                                <?php foreach ($availableCenters as $c): ?><option value="<?php echo e($c); ?>"><?php echo e($c); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Agenda Statement</label>
                            <input type="text" name="agenda_statement" class="form-control" maxlength="1000" value="<?php echo e(sked_old('agenda_statement')); ?>" placeholder="What this section aims to achieve">
                        </div>
                        <div class="col-md-2"><button type="submit" class="btn btn-sked w-100"><i class="bi bi-plus-lg"></i>Add</button></div>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php foreach ($plan['sections'] as $section): ?>
                <div class="docket-panel mb-4">
                    <div class="section-heading">
                        <h2><?php echo e($section['center_of_participation']); ?></h2>
                        <?php if ($isEditable): ?>
                        <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" onsubmit="return confirm('Remove this whole section and its line items?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                            <input type="hidden" name="action" value="delete_section">
                            <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i>Remove section</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($section['agenda_statement'])): ?>
                        <p class="text-secondary"><strong>Agenda Statement:</strong> <?php echo e((string) $section['agenda_statement']); ?></p>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Youth Development Concern</th><th>Objective</th><th>Performance Indicator</th>
                                    <th>Target <?php echo (int) $plan['cy_year_start']; ?></th><th>Target <?php echo (int) $plan['cy_year_start'] + 1; ?></th><th>Target <?php echo (int) $plan['cy_year_start'] + 2; ?></th>
                                    <th>PPAs</th><th>Budget</th><th>Person Responsible</th>
                                    <?php if ($isEditable): ?><th></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['items'] as $item): ?>
                                <tr>
                                    <td><?php echo e((string) $item['youth_development_concern']); ?></td>
                                    <td><?php echo e((string) ($item['objective'] ?? '')); ?></td>
                                    <td><?php echo e((string) ($item['performance_indicator'] ?? '')); ?></td>
                                    <td><?php echo e((string) ($item['target_year1'] ?? '')); ?></td>
                                    <td><?php echo e((string) ($item['target_year2'] ?? '')); ?></td>
                                    <td><?php echo e((string) ($item['target_year3'] ?? '')); ?></td>
                                    <td><?php echo e((string) ($item['ppas'] ?? '')); ?></td>
                                    <td><?php echo $item['budget'] !== null ? number_format((float) $item['budget'], 2) : '—'; ?></td>
                                    <td><?php echo e((string) ($item['person_responsible'] ?? '')); ?></td>
                                    <?php if ($isEditable): ?>
                                    <td>
                                        <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" onsubmit="return confirm('Remove this line item?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($isEditable): ?>
                    <details class="mt-2">
                        <summary class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i>Add a line item</summary>
                        <form method="post" action="cbydp_plan.php?id=<?php echo (int) $planId; ?>" class="row g-2 mt-2">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_cbydpplan_token']); ?>">
                            <input type="hidden" name="action" value="add_item">
                            <input type="hidden" name="section_id" value="<?php echo (int) $section['id']; ?>">
                            <div class="col-md-4"><input type="text" name="youth_development_concern" class="form-control form-control-sm" value="<?php echo e(sked_old('youth_development_concern')); ?>" placeholder="Youth Development Concern *" required></div>
                            <div class="col-md-4"><input type="text" name="objective" class="form-control form-control-sm" value="<?php echo e(sked_old('objective')); ?>" placeholder="Objective"></div>
                            <div class="col-md-4"><input type="text" name="performance_indicator" class="form-control form-control-sm" value="<?php echo e(sked_old('performance_indicator')); ?>" placeholder="Performance Indicator"></div>
                            <div class="col-md-4"><input type="text" name="target_year1" class="form-control form-control-sm" value="<?php echo e(sked_old('target_year1')); ?>" placeholder="Target <?php echo (int) $plan['cy_year_start']; ?>"></div>
                            <div class="col-md-4"><input type="text" name="target_year2" class="form-control form-control-sm" value="<?php echo e(sked_old('target_year2')); ?>" placeholder="Target <?php echo (int) $plan['cy_year_start'] + 1; ?>"></div>
                            <div class="col-md-4"><input type="text" name="target_year3" class="form-control form-control-sm" value="<?php echo e(sked_old('target_year3')); ?>" placeholder="Target <?php echo (int) $plan['cy_year_start'] + 2; ?>"></div>
                            <div class="col-md-6"><input type="text" name="ppas" class="form-control form-control-sm" value="<?php echo e(sked_old('ppas')); ?>" placeholder="PPAs"></div>
                            <div class="col-md-3"><input type="number" step="0.01" min="0" name="budget" class="form-control form-control-sm" value="<?php echo e(sked_old('budget')); ?>" placeholder="Budget"></div>
                            <div class="col-md-3"><input type="text" name="person_responsible" class="form-control form-control-sm" value="<?php echo e(sked_old('person_responsible')); ?>" placeholder="Person Responsible"></div>
                            <div class="col-12"><button type="submit" class="btn btn-sm btn-sked"><i class="bi bi-plus-lg"></i>Add line item</button></div>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($plan['sections'])): ?>
                <div class="docket-panel text-center text-secondary py-5"><i class="bi bi-clipboard-data fs-1 d-block mb-2"></i>No sections yet.</div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
