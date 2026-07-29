<?php
/**
 * ============================================================
 * File     : pages/ppsk/plans.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK oversight list of every barangay's uploaded Youth
 *            Development Plan documents (CBYDP/ABYIP/Annual Budget/Monthly
 *            Purchase Request — see includes/plan_documents.php). Purely
 *            view-only; uploading stays with each barangay's own SK. These
 *            documents are also fully public (pages/public/plan_document.php
 *            needs no login), so this page is just a convenient filtered
 *            municipality-wide index for PPSK, not a special access path.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/plan_documents.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');

$typeFilter = (string) ($_GET['type'] ?? '');
$documents = sked_plan_documents_all($typeFilter);
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=3">
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
                        <p class="text-secondary meta-copy mb-0">CBYDP, ABYIP, Annual Budgets, and Monthly Purchase Requests uploaded by every barangay.</p>
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
                <div class="section-heading"><h2>Uploaded Documents</h2><span class="section-note"><?php echo count($documents); ?> total</span></div>
                <div class="d-flex gap-2 flex-wrap mb-3">
                    <a href="plans.php" class="btn btn-sm <?php echo $typeFilter === '' ? 'btn-sked' : 'btn-outline-secondary'; ?>">All</a>
                    <?php foreach (SKED_PLAN_DOC_TYPES as $t): ?>
                        <a href="plans.php?type=<?php echo e($t); ?>" class="btn btn-sm <?php echo $typeFilter === $t ? 'btn-sked' : 'btn-outline-secondary'; ?>"><?php echo e(sked_plan_doc_type_label($t)); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($documents)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-file-earmark-arrow-up fs-1 d-block mb-2"></i>No documents uploaded yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="plansOversightTable">
                            <thead><tr><th>Barangay</th><th>Type</th><th>Period</th><th>Uploaded</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($documents as $d): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) $d['barangay_name']); ?></td>
                                    <td><?php echo e(sked_plan_doc_type_label((string) $d['doc_type'])); ?></td>
                                    <td><?php echo e((string) $d['period_label']); ?></td>
                                    <td class="small text-secondary"><?php echo e(date('M j, Y', strtotime((string) $d['uploaded_at']))); ?></td>
                                    <td class="text-end">
                                        <a href="../public/plan_document.php?id=<?php echo (int) $d['id']; ?>" class="btn btn-sm btn-sked" target="_blank"><i class="bi bi-eye"></i>View Attachment</a>
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
        new SkedTableTools('#plansOversightTable', { pageSize: 15, filters: [{ label: 'Type' }] });
    </script>
</body>
</html>
