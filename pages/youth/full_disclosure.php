<?php
/**
 * ============================================================
 * File     : pages/youth/full_disclosure.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth-facing Full Disclosure Board. Shows uploaded Youth
 *            Development Plan documents (CBYDP/ABYIP/Annual Budget/Monthly
 *            Purchase Request — see includes/plan_documents.php) for the
 *            signed-in youth's own barangay only. Project Charters were
 *            retired as a feature (see docs/old_reporting_process.md) and
 *            no longer appear here.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/plan_documents.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');

$documents = $barangayId > 0 ? sked_plan_documents_for_barangay($barangayId) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Full Disclosure Board</title>
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
        <?php render_sked_navigation($role, 'full_disclosure'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Full Disclosure Board</h1>
                        <p class="text-secondary meta-copy mb-0">Publicly available Youth Development Plan documents for your barangay.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your account is not linked to a barangay yet.</div>
            <?php else: ?>
                <section class="row g-3 mb-4" aria-label="Disclosure summary">
                    <div class="col-md-12">
                        <div class="ledger-card accent-teal"><span class="ledger-tag">Plan Documents</span><div class="ledger-value tabular"><?php echo count($documents); ?></div><div class="ledger-caption">CBYDP, ABYIP, budgets &amp; purchase requests disclosed</div></div>
                    </div>
                </section>

                <section class="docket-panel">
                    <div class="section-heading"><h2>Youth Development Plan Documents</h2><span class="section-note"><?php echo count($documents); ?> disclosed</span></div>
                    <?php if (empty($documents)): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-data fs-1 d-block mb-2"></i>No documents have been disclosed yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="plansDisclosureTable">
                                <thead><tr><th>Type</th><th>Period</th><th>Uploaded</th><th class="text-end" data-tt-nosort>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($documents as $d): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e(sked_plan_doc_type_label((string) $d['doc_type'])); ?></td>
                                        <td><?php echo e((string) $d['period_label']); ?></td>
                                        <td class="small text-secondary"><?php echo e(date('M j, Y', strtotime((string) $d['uploaded_at']))); ?></td>
                                        <td class="text-end">
                                            <a href="../public/plan_document.php?id=<?php echo (int) $d['id']; ?>" target="_blank" class="btn btn-sm btn-sked"><i class="bi bi-eye me-1"></i>View Attachment</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#plansDisclosureTable', { pageSize: 10, filters: [{ label: 'Type' }] });
    </script>
</body>
</html>
