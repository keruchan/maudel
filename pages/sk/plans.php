<?php
/**
 * ============================================================
 * File     : pages/sk/plans.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth Development Plans — simple file-upload replacement for
 *            the old structured CBYDP/ABYIP data entry (see
 *            docs/old_reporting_process.md for the pre-rewrite design).
 *            SK just uploads a finished document with a date scope; no
 *            field-by-field authoring, no draft/finalized workflow. Covers
 *            4 document types: CBYDP, ABYIP, Annual Budget, and Monthly
 *            Itemized List of Purchase Request. Every upload is
 *            immediately public — see pages/public/plan_document.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/plan_documents.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$formErrors = [];

if (empty($_SESSION['csrf_plandocs_token'])) {
    $_SESSION['csrf_plandocs_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? 'upload');
    if (!hash_equals((string) $_SESSION['csrf_plandocs_token'], $token)) {
        $formErrors = ['Security validation failed. Please try again.'];
    } elseif ($action === 'delete') {
        $r = sked_delete_plan_document((int) ($_POST['document_id'] ?? 0), $barangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Document deleted.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } else {
        $creator = ['id' => $userId, 'name' => $displayName, 'barangay_id' => $barangayId];
        $r = sked_create_plan_document($creator, $_POST, $_FILES['document'] ?? []);
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Document uploaded and is now publicly viewable.'];
        } else {
            $formErrors = $r['errors'];
        }
    }
}

sked_form_retain(!empty($formErrors));

$typeFilter = (string) ($_GET['type'] ?? '');
$documents = $barangayId > 0 ? sked_plan_documents_for_barangay($barangayId, $typeFilter) : [];
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
                        <div class="eyebrow">Youth Development Plans &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Youth Development Plans</h1>
                        <p class="text-secondary meta-copy mb-0">Upload your CBYDP, ABYIP, Annual Budget, and Monthly Purchase Requests. Every upload is public — anyone can view it.</p>
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
                        <div class="section-heading"><h2>Upload a Document</h2></div>
                        <form method="post" action="plans.php" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_plandocs_token']); ?>">
                            <input type="hidden" name="action" value="upload">
                            <?php sked_render_form_errors($formErrors, 'The document could not be uploaded:'); ?>

                            <div class="mb-3">
                                <label for="doc_type" class="form-label">Document type</label>
                                <select class="form-select" id="doc_type" name="doc_type" required onchange="document.getElementById('monthField').style.display = this.value==='purchase_request' ? 'block' : 'none';">
                                    <option value="">— Select —</option>
                                    <?php foreach (SKED_PLAN_DOC_TYPES as $t): ?>
                                        <option value="<?php echo e($t); ?>" <?php echo sked_old_selected('doc_type', $t) ? 'selected' : ''; ?>><?php echo e(sked_plan_doc_type_label($t)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="year" class="form-label">Year</label>
                                <input type="number" class="form-control" id="year" name="year" min="2020" max="2100" value="<?php echo e(sked_old('year', (string) date('Y'))); ?>" required>
                                <div class="form-text">For CBYDP this is the first year of the 3-year cycle.</div>
                            </div>
                            <div class="mb-3" id="monthField" style="display:<?php echo sked_old_selected('doc_type', 'purchase_request') ? 'block' : 'none'; ?>;">
                                <label for="month" class="form-label">Month</label>
                                <select class="form-select" id="month" name="month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo sked_old_selected('month', (string) $m, $m === (int) date('n')) ? 'selected' : ''; ?>><?php echo e(date('F', mktime(0, 0, 0, $m, 1))); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="document" class="form-label">File</label>
                                <input type="file" class="form-control" id="document" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" required>
                                <div class="form-text">PDF, Word, Excel, JPG, or PNG. Max 10 MB. <?php if (!empty($formErrors)): ?><span class="text-warning-emphasis">A previously chosen file must be picked again — browsers never resend file inputs.</span><?php endif; ?></div>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-upload me-1"></i> Upload</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Uploaded Documents</h2>
                            <span class="section-note"><?php echo count($documents); ?> total</span>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <a href="plans.php" class="btn btn-sm <?php echo $typeFilter === '' ? 'btn-sked' : 'btn-outline-secondary'; ?>">All</a>
                            <?php foreach (SKED_PLAN_DOC_TYPES as $t): ?>
                                <a href="plans.php?type=<?php echo e($t); ?>" class="btn btn-sm <?php echo $typeFilter === $t ? 'btn-sked' : 'btn-outline-secondary'; ?>"><?php echo e(sked_plan_doc_type_label($t)); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php if (empty($documents)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-file-earmark-arrow-up fs-1 d-block mb-2"></i>No documents uploaded yet.</div>
                        <?php else: ?>
                            <?php foreach ($documents as $d): ?>
                                <div class="docket-row">
                                    <div>
                                        <div class="docket-title"><?php echo e(sked_plan_doc_type_label((string) $d['doc_type'])); ?> <span class="badge text-bg-light"><?php echo e((string) $d['period_label']); ?></span></div>
                                        <div class="docket-sub">Uploaded <?php echo e(date('M j, Y', strtotime((string) $d['uploaded_at']))); ?> &middot; <?php echo e((string) $d['file_original_name']); ?></div>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="../public/plan_document.php?id=<?php echo (int) $d['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-eye"></i><span>View Attachment</span></a>
                                        <form method="post" action="plans.php" class="d-inline" onsubmit="return confirm('Delete this document? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_plandocs_token']); ?>">
                                            <input type="hidden" name="document_id" value="<?php echo (int) $d['id']; ?>">
                                            <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
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
