<?php
/**
 * ============================================================
 * File     : pages/ppsk/reports.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK reporting hub (P7, spec 4.2). Submit inter-barangay event
 *            reports + meeting minutes up to DILG. PPSK no longer reviews
 *            SK monthly reports — those, like everything else here, now go
 *            straight to DILG (see includes/reports.php).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$ppskUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$formErrors = [];

if (empty($_SESSION['csrf_preports_token'])) {
    $_SESSION['csrf_preports_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_preports_token'], $token)) {
        $formErrors = ['Security validation failed. Please try again.'];
    } else {
        $creator = ['id' => $ppskUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => null];
        $type = (string) ($_POST['type'] ?? 'interbarangay');
        $r = sked_submit_report($creator, $type, (string) ($_POST['title'] ?? ''), (string) ($_POST['content'] ?? ''), null, null, $_FILES['attachment'] ?? null);
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => ucfirst($type) . ' report submitted to DILG.'];
        } else {
            $formErrors = $r['errors'];
        }
    }
}

sked_form_retain(!empty($formErrors));

$submittedToDilg = sked_reports_for_role('dilg', []);
// Only this PPSK's own submissions (interbarangay/minutes are PPSK-authored; dismissal recs shown separately elsewhere).
$ownSubmissions = array_values(array_filter(
    $submittedToDilg,
    static fn($r) => in_array($r['type'], ['interbarangay', 'minutes'], true) && (int) $r['submitted_by'] === $ppskUserId
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Reports</title>
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
        <?php render_sked_navigation($role, 'reports'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Federation Office &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Submit inter-barangay event reports and minutes to DILG.</p>
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

            <div class="row g-4 mb-4">
                <div class="col-lg-7 mx-auto">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Submit to DILG</h2></div>
                        <form method="post" action="reports.php" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_preports_token']); ?>">
                            <?php sked_render_form_errors($formErrors, 'The report could not be submitted:'); ?>
                            <div class="mb-3">
                                <label for="type" class="form-label">Report type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="interbarangay" <?php echo sked_old_selected('type', 'interbarangay', true) ? 'selected' : ''; ?>>Inter-barangay Event Report</option>
                                    <option value="minutes" <?php echo sked_old_selected('type', 'minutes') ? 'selected' : ''; ?>>Minutes of Meeting</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="title" class="form-label">Title</label>
                                <input type="text" class="form-control" id="title" name="title" maxlength="160" value="<?php echo e(sked_old('title')); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="content" class="form-label">Content</label>
                                <textarea class="form-control" id="content" name="content" rows="5" maxlength="4000"><?php echo e(sked_old('content')); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="attachment" class="form-label">Attachment</label>
                                <input type="file" class="form-control" id="attachment" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                                <div class="form-text">PDF, Word, Excel, JPG, or PNG. Max 10 MB. <?php if (!empty($formErrors)): ?><span class="text-warning-emphasis">A previously chosen file must be picked again because browsers never resend file inputs.</span><?php endif; ?></div>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-send-check me-1"></i> Submit to DILG</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="docket-panel">
                <div class="section-heading"><h2>Your Submissions to DILG</h2><span class="section-note"><?php echo count($ownSubmissions); ?> total</span></div>
                <?php if (empty($ownSubmissions)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-send fs-1 d-block mb-2"></i>Nothing submitted yet.</div>
                <?php else: ?>
                    <?php foreach ($ownSubmissions as $r): ?>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title"><?php echo e((string) $r['title']); ?> <span class="badge text-bg-light"><?php echo e(sked_report_type_label((string) $r['type'])); ?></span></div>
                                <div class="docket-sub"><?php echo e(date('M j, Y', strtotime((string) $r['submitted_at']))); ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                <span class="badge <?php echo e(sked_report_status_badge_class((string) $r['status'])); ?>"><?php echo e(sked_report_status_label((string) $r['status'])); ?></span>
                                <div class="action-buttons">
                                    <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                    <?php if (!empty($r['attachment_file_path'])): ?>
                                        <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($r['review_comments'])): ?>
                                <div class="small text-secondary mt-2 w-100"><strong>DILG comments:</strong> <?php echo nl2br(e((string) $r['review_comments'])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
