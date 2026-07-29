<?php
/**
 * ============================================================
 * File     : pages/dilg/reports.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG consolidated report inbox (P7, spec 4.1).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/reports.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$dilgUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_dreports_token'])) {
    $_SESSION['csrf_dreports_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_dreports_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $reportId = (int) ($_POST['report_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? 'acknowledge');
        $status = match ($action) {
            'rework' => 'rework',
            'reject' => 'rejected',
            default => 'reviewed',
        };
        $target = sked_get_report($reportId);
        $r = sked_review_report($reportId, $dilgUserId, $status, (string) ($_POST['review_comments'] ?? ''));
        if ($r['ok']) {
            $submitterName = $target !== null ? (string) ($target['submitted_by_name'] ?? 'the submitter') : 'the submitter';
            $flash = ['type' => 'success', 'msg' => 'Report marked ' . e(sked_report_status_label($status)) . ' - ' . e($submitterName) . ' has been notified.'];
        } else {
            $flash = ['type' => 'danger', 'msg' => e($r['error'])];
        }
    }
}

$typeFilter = (string) ($_GET['type'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$allReports = sked_reports_for_role('dilg', ['status' => $statusFilter]);
$excludedTypes = ['dismissal_recommendation', 'turnover'];
$reports = array_values(array_filter($allReports, static function ($r) use ($typeFilter, $excludedTypes) {
    if (in_array((string) $r['type'], $excludedTypes, true)) {
        return false;
    }
    return $typeFilter === '' || (string) $r['type'] === $typeFilter;
}));

$statusUrl = static function (string $status, string $type) {
    $query = [];
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($type !== '') {
        $query['type'] = $type;
    }
    return 'reports.php' . (!empty($query) ? '?' . http_build_query($query) : '');
};
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=3">
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
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Monthly reports from SK, and event reports/minutes from PPSK - all in one inbox.</p>
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

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Reports Inbox</h2>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm <?php echo $typeFilter === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo e($statusUrl($statusFilter, '')); ?>">All types</a>
                        <a class="btn btn-sm <?php echo $typeFilter === 'monthly' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo e($statusUrl($statusFilter, 'monthly')); ?>">Monthly</a>
                        <a class="btn btn-sm <?php echo $typeFilter === 'interbarangay' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo e($statusUrl($statusFilter, 'interbarangay')); ?>">Event reports</a>
                        <a class="btn btn-sm <?php echo $typeFilter === 'minutes' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo e($statusUrl($statusFilter, 'minutes')); ?>">Minutes</a>
                        <a class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo e($statusUrl('', $typeFilter)); ?>">All statuses</a>
                        <a class="btn btn-sm <?php echo $statusFilter === 'submitted' ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo e($statusUrl('submitted', $typeFilter)); ?>">Pending only</a>
                    </div>
                </div>
                <?php if (empty($reports)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No reports match these filters.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle report-inbox-table" id="dilgReportsTable">
                            <colgroup>
                                <col class="report-col-title">
                                <col class="report-col-type">
                                <col class="report-col-submitter">
                                <col class="report-col-barangay">
                                <col class="report-col-date">
                                <col class="report-col-status">
                                <col class="report-col-attachment">
                                <col class="report-col-comments">
                                <col class="report-col-actions">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Report</th>
                                    <th>Type</th>
                                    <th>Submitter</th>
                                    <th>Barangay</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th>Attachment</th>
                                    <th>Review Comments</th>
                                    <th data-tt-nosort>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reports as $r): ?>
                                <?php
                                    $reportId = (int) $r['id'];
                                    $barangayName = !empty($r['barangay_id']) ? sked_barangay_name((int) $r['barangay_id']) : 'Municipal';
                                    $submittedAt = strtotime((string) $r['submitted_at']) ?: time();
                                    $hasAttachment = !empty($r['attachment_file_path']);
                                    $status = (string) $r['status'];
                                ?>
                                <tr>
                                    <td class="report-title-cell">
                                        <div class="fw-semibold"><?php echo e((string) $r['title']); ?></div>
                                        <?php if (!empty($r['content'])): ?><div class="report-summary"><?php echo e((string) $r['content']); ?></div><?php endif; ?>
                                    </td>
                                    <td><?php echo e(sked_report_type_label((string) $r['type'])); ?></td>
                                    <td>
                                        <div><?php echo e((string) ($r['submitted_by_name'] ?? 'Unknown')); ?></div>
                                        <div class="small text-secondary text-uppercase"><?php echo e((string) ($r['submitted_by_role'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo e($barangayName); ?></td>
                                    <td data-tt-sort="<?php echo e(date('Y-m-d H:i:s', $submittedAt)); ?>"><?php echo e(date('M j, Y g:i A', $submittedAt)); ?></td>
                                    <td data-tt-value="<?php echo e(sked_report_status_label($status)); ?>"><span class="badge <?php echo e(sked_report_status_badge_class($status)); ?>"><?php echo e(sked_report_status_label($status)); ?></span></td>
                                    <td data-tt-value="<?php echo $hasAttachment ? 'With attachment' : 'No attachment'; ?>">
                                        <?php if ($hasAttachment): ?>
                                            <a href="../manage/report_file.php?id=<?php echo $reportId; ?>" class="report-attachment-link" target="_blank" title="View attachment" aria-label="View attachment">
                                                <i class="bi bi-eye"></i>
                                                <span>View attachment</span>
                                            </a>
                                            <div class="report-attachment-name"><?php echo e((string) ($r['attachment_file_original_name'] ?? 'Attachment')); ?></div>
                                        <?php else: ?>
                                            <span class="text-secondary small">No attachment</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="report-comments-cell">
                                        <?php if (!empty($r['review_comments'])): ?>
                                            <span class="small text-secondary"><?php echo nl2br(e((string) $r['review_comments'])); ?></span>
                                        <?php else: ?>
                                            <span class="small text-secondary">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons report-actions justify-content-start">
                                            <a href="../manage/report_export.php?id=<?php echo $reportId; ?>" class="btn btn-sm btn-outline-secondary icon-action" target="_blank" title="Export report" aria-label="Export report"><i class="bi bi-printer"></i></a>
                                            <?php if (!empty($r['katitikan_id'])): ?>
                                                <a href="../manage/katitikan.php?id=<?php echo (int) $r['katitikan_id']; ?>" class="btn btn-sm btn-outline-secondary icon-action" title="View full minutes" aria-label="View full minutes"><i class="bi bi-journal-text"></i></a>
                                            <?php endif; ?>
                                            <?php if ($status === 'submitted'): ?>
                                                <form method="post" action="reports.php">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dreports_token']); ?>">
                                                    <input type="hidden" name="action" value="acknowledge">
                                                    <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary icon-action" title="Acknowledge report" aria-label="Acknowledge report"><i class="bi bi-check2-circle"></i></button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-outline-warning icon-action" data-bs-toggle="modal" data-bs-target="#reworkReport<?php echo $reportId; ?>" title="Request rework" aria-label="Request rework"><i class="bi bi-arrow-repeat"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger icon-action" data-bs-toggle="modal" data-bs-target="#rejectReport<?php echo $reportId; ?>" title="Reject report" aria-label="Reject report"><i class="bi bi-x-circle"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php foreach ($reports as $r): if ((string) $r['status'] !== 'submitted') { continue; } $reportId = (int) $r['id']; ?>
                        <div class="modal fade" id="reworkReport<?php echo $reportId; ?>" tabindex="-1" aria-labelledby="reworkReportLabel<?php echo $reportId; ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post" action="reports.php">
                                        <div class="modal-header">
                                            <h2 class="modal-title h5" id="reworkReportLabel<?php echo $reportId; ?>">Request Rework</h2>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dreports_token']); ?>">
                                            <input type="hidden" name="action" value="rework">
                                            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                            <p class="small text-secondary">Add clear notes for the submitter, such as incorrect attachment, missing signature, or incomplete period coverage.</p>
                                            <label class="form-label" for="reworkComments<?php echo $reportId; ?>">Comments</label>
                                            <textarea class="form-control" id="reworkComments<?php echo $reportId; ?>" name="review_comments" rows="4" maxlength="2000" required></textarea>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Send for Rework</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="rejectReport<?php echo $reportId; ?>" tabindex="-1" aria-labelledby="rejectReportLabel<?php echo $reportId; ?>" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post" action="reports.php">
                                        <div class="modal-header">
                                            <h2 class="modal-title h5" id="rejectReportLabel<?php echo $reportId; ?>">Reject Report</h2>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dreports_token']); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                            <p class="small text-secondary">Reject only when the submission cannot be accepted as filed. Comments are required and will be sent to the submitter.</p>
                                            <label class="form-label" for="rejectComments<?php echo $reportId; ?>">Comments</label>
                                            <textarea class="form-control" id="rejectComments<?php echo $reportId; ?>" name="review_comments" rows="4" maxlength="2000" required></textarea>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-circle me-1"></i>Reject Report</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#dilgReportsTable', {
            pageSize: 10,
            filters: [{ label: 'Type' }, { label: 'Barangay' }, { label: 'Status' }, { label: 'Attachment' }]
        });
    </script>
</body>
</html>
