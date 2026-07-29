<?php
/**
 * ============================================================
 * File     : pages/ppsk/plans.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK oversight list of every barangay's CBYDP/ABYIP (P12) —
 *            view-only; editing stays with each barangay's own SK. Links
 *            into the shared pages/manage/{cbydp,abyip}_plan.php detail
 *            pages (which render read-only for this role) and their
 *            export views.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');

$cbydps = sked_cbydp_list_all();
$abyips = sked_abyip_list_all();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Youth Development Plans</title>
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
        <?php render_sked_navigation($role, 'plans'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Federation Oversight &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Youth Development Plans</h1>
                        <p class="text-secondary meta-copy mb-0">CBYDP and ABYIP across every barangay — view, export, and check for signed copies on file.</p>
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

            <div class="docket-panel mb-4">
                <div class="section-heading"><h2>CBYDP</h2><span class="section-note"><?php echo count($cbydps); ?> plans</span></div>
                <?php if (empty($cbydps)): ?>
                    <div class="text-center text-secondary py-4">No CBYDP submitted yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="cbydpOversightTable">
                            <thead><tr><th>Barangay</th><th>Cycle</th><th>Status</th><th>Signed Copy</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($cbydps as $p): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) $p['barangay_name']); ?></td>
                                    <td>CY <?php echo (int) $p['cy_year_start']; ?>–<?php echo (int) $p['cy_year_start'] + 2; ?></td>
                                    <td><span class="badge <?php echo $p['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $p['status']); ?></span></td>
                                    <td><?php echo !empty($p['signed_file_path']) ? '<span class="badge text-bg-success"><i class="bi bi-check-lg"></i> On file</span>' : '<span class="badge text-bg-light">None yet</span>'; ?></td>
                                    <td class="text-end">
                                        <a href="../manage/cbydp_plan.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-eye"></i>View</a>
                                        <a href="../manage/cbydp_export.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-download"></i>Export</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="docket-panel">
                <div class="section-heading"><h2>ABYIP</h2><span class="section-note"><?php echo count($abyips); ?> plans</span></div>
                <?php if (empty($abyips)): ?>
                    <div class="text-center text-secondary py-4">No ABYIP submitted yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="abyipOversightTable">
                            <thead><tr><th>Barangay</th><th>Year</th><th>Status</th><th>Signed Copy</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($abyips as $p): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) $p['barangay_name']); ?></td>
                                    <td>CY <?php echo (int) $p['calendar_year']; ?></td>
                                    <td><span class="badge <?php echo $p['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $p['status']); ?></span></td>
                                    <td><?php echo !empty($p['signed_file_path']) ? '<span class="badge text-bg-success"><i class="bi bi-check-lg"></i> On file</span>' : '<span class="badge text-bg-light">None yet</span>'; ?></td>
                                    <td class="text-end">
                                        <a href="../manage/abyip_plan.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-eye"></i>View</a>
                                        <a href="../manage/abyip_export.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-download"></i>Export</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#cbydpOversightTable', { pageSize: 10, filters: [{ label: 'Status' }] });
        new SkedTableTools('#abyipOversightTable', { pageSize: 10, filters: [{ label: 'Status' }] });
    </script>
</body>
</html>
