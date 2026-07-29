<?php
/**
 * ============================================================
 * File     : pages/dilg/compliance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG dismissal review (P7, spec 6.4 step 3). Reviews PPSK
 *            dismissal recommendations for repeat-offender SKs. Three
 *            outcomes, not just an immediate binary dismiss:
 *              1. Request Explanation — due-process step, moves the case to
 *                 "Awaiting Explanation" (reuses the reports.status
 *                 'rework' value) and notifies both PPSK and the SK.
 *              2. Process Dismissal — reverts the SK to a regular Youth
 *                 account with a "Former SK Chairman" badge. Allowed
 *                 straight from Pending or after an explanation was
 *                 requested.
 *              3. Mark as Complied — closes the case WITHOUT dismissing;
 *                 clears the SK's compliance strikes so they are not
 *                 immediately re-eligible for escalation on the same old
 *                 strikes ("not subject for dismissal again").
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/compliance.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$dilgUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_dcompliance_token'])) {
    $_SESSION['csrf_dcompliance_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? 'dismiss');
    $reportId = (int) ($_POST['report_id'] ?? 0);
    if (!hash_equals((string) $_SESSION['csrf_dcompliance_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($action === 'request_explanation') {
        $r = sked_request_dismissal_explanation($dilgUserId, $reportId, (string) ($_POST['message'] ?? ''));
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Explanation requested. The SK and PPSK have been notified.']
            : ['type' => 'danger', 'msg' => e($r['error'])];
    } elseif ($action === 'mark_complied') {
        $r = sked_mark_dismissal_complied($dilgUserId, $reportId, (string) ($_POST['comments'] ?? ''));
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Case closed without dismissal. Compliance strikes were cleared — this SK is no longer subject for dismissal on the old strikes.']
            : ['type' => 'danger', 'msg' => e($r['error'])];
    } else {
        $r = sked_process_dismissal($dilgUserId, $reportId);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Dismissal processed. The account is now a regular Youth account with a "' . e($r['badge']) . '" badge.']
            : ['type' => 'danger', 'msg' => e($r['error'])];
    }
}

$allDismissalReports = sked_reports_for_role('dilg', ['type' => 'dismissal_recommendation']);
$pending = array_values(array_filter($allDismissalReports, static fn ($r) => $r['status'] === 'submitted'));
$awaitingExplanation = array_values(array_filter($allDismissalReports, static fn ($r) => $r['status'] === 'rework'));
$resolved = array_values(array_filter($allDismissalReports, static fn ($r) => in_array($r['status'], ['reviewed', 'complied'], true)));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Dismissal Review</title>
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
        <?php render_sked_navigation($role, 'compliance'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Dismissal Review</h1>
                        <p class="text-secondary meta-copy mb-0">PPSK-escalated compliance cases awaiting DILG action.</p>
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

            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Pending Dismissal Recommendations</h2>
                    <span class="section-note"><?php echo count($pending); ?> pending</span>
                </div>
                <?php if (empty($pending)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-shield-check fs-1 d-block mb-2"></i>Nothing pending.</div>
                <?php else: ?>
                    <?php foreach ($pending as $r): $reportId = (int) $r['id']; ?>
                        <div class="docket-row d-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="docket-title"><?php echo e((string) $r['title']); ?></div>
                                    <div class="docket-sub">Reported by <?php echo e((string) $r['submitted_by_name']); ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $r['submitted_at']))); ?></div>
                                </div>
                                <span class="badge text-bg-danger">Action needed</span>
                            </div>
                            <?php if (!empty($r['content'])): ?><p class="small text-secondary mt-2 mb-2"><?php echo nl2br(e((string) $r['content'])); ?></p><?php endif; ?>
                            <div class="action-buttons mt-1">
                                <a href="../manage/report_export.php?id=<?php echo $reportId; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                <?php if (!empty($r['attachment_file_path'])): ?>
                                    <a href="../manage/report_file.php?id=<?php echo $reportId; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#explainModal<?php echo $reportId; ?>"><i class="bi bi-chat-left-question"></i><span>Request Explanation</span></button>
                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#compliedModal<?php echo $reportId; ?>"><i class="bi bi-check2-circle"></i><span>Mark as Complied</span></button>
                                <form method="post" action="compliance.php" class="d-inline" onsubmit="return confirm('Process this dismissal? The SK account will immediately revert to a regular Youth account. This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dcompliance_token']); ?>">
                                    <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                    <input type="hidden" name="action" value="dismiss">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-dash"></i><span>Process Dismissal</span></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($awaitingExplanation)): ?>
            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Awaiting Explanation</h2>
                    <span class="section-note"><?php echo count($awaitingExplanation); ?> awaiting</span>
                </div>
                <?php foreach ($awaitingExplanation as $r): $reportId = (int) $r['id']; ?>
                    <div class="docket-row d-block">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="docket-title"><?php echo e((string) $r['title']); ?></div>
                                <div class="docket-sub">Explanation requested <?php echo e($r['reviewed_at'] ? date('M j, Y', strtotime((string) $r['reviewed_at'])) : ''); ?></div>
                            </div>
                            <span class="badge text-bg-warning">Awaiting Explanation</span>
                        </div>
                        <?php if (!empty($r['review_comments'])): ?>
                            <div class="small text-secondary mt-2 mb-2"><strong>Your message to the SK:</strong> <?php echo nl2br(e((string) $r['review_comments'])); ?></div>
                        <?php endif; ?>
                        <div class="action-buttons mt-1">
                            <a href="../manage/report_export.php?id=<?php echo $reportId; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#compliedModal<?php echo $reportId; ?>"><i class="bi bi-check2-circle"></i><span>Mark as Complied</span></button>
                            <form method="post" action="compliance.php" class="d-inline" onsubmit="return confirm('Process this dismissal? The SK account will immediately revert to a regular Youth account. This cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dcompliance_token']); ?>">
                                <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                <input type="hidden" name="action" value="dismiss">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-dash"></i><span>Process Dismissal</span></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($resolved)): ?>
            <div class="docket-panel">
                <div class="section-heading"><h2>Resolved</h2><span class="section-note"><?php echo count($resolved); ?></span></div>
                <?php foreach ($resolved as $r): ?>
                    <div class="docket-row">
                        <div>
                            <div class="docket-title"><?php echo e((string) $r['title']); ?></div>
                            <div class="docket-sub">Resolved <?php echo e($r['reviewed_at'] ? date('M j, Y', strtotime((string) $r['reviewed_at'])) : ''); ?></div>
                            <?php if (!empty($r['review_comments'])): ?><div class="small text-secondary mt-1">Notes: <?php echo nl2br(e((string) $r['review_comments'])); ?></div><?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge <?php echo e(sked_report_status_badge_class((string) $r['status'])); ?>"><?php echo e(sked_report_status_label((string) $r['status'], true)); ?></span>
                            <div class="action-buttons">
                                <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                <?php if (!empty($r['attachment_file_path'])): ?>
                                    <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php foreach (array_merge($pending, $awaitingExplanation) as $r): $reportId = (int) $r['id']; ?>
                <?php if ($r['status'] === 'submitted'): ?>
                <div class="modal fade" id="explainModal<?php echo $reportId; ?>" tabindex="-1" aria-labelledby="explainModalLabel<?php echo $reportId; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" action="compliance.php">
                                <div class="modal-header">
                                    <h2 class="modal-title h5" id="explainModalLabel<?php echo $reportId; ?>">Request Explanation</h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dcompliance_token']); ?>">
                                    <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                    <input type="hidden" name="action" value="request_explanation">
                                    <p class="small text-secondary">This moves the case to "Awaiting Explanation" and notifies both the SK and PPSK — a due-process step before any dismissal decision.</p>
                                    <label class="form-label" for="explainMsg<?php echo $reportId; ?>">Message to the SK</label>
                                    <textarea class="form-control" id="explainMsg<?php echo $reportId; ?>" name="message" rows="4" maxlength="2000" placeholder="Please explain the missed monthly report deadlines within 7 days…" required></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-chat-left-question me-1"></i>Send Request</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="modal fade" id="compliedModal<?php echo $reportId; ?>" tabindex="-1" aria-labelledby="compliedModalLabel<?php echo $reportId; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post" action="compliance.php">
                                <div class="modal-header">
                                    <h2 class="modal-title h5" id="compliedModalLabel<?php echo $reportId; ?>">Mark as Complied — Not Subject for Dismissal</h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dcompliance_token']); ?>">
                                    <input type="hidden" name="report_id" value="<?php echo $reportId; ?>">
                                    <input type="hidden" name="action" value="mark_complied">
                                    <p class="small text-secondary">Closes this case without dismissing the SK and <strong>clears their compliance strikes</strong>, so they start clean and are not immediately re-eligible for escalation on the same old strikes.</p>
                                    <label class="form-label" for="compliedNotes<?php echo $reportId; ?>">Notes <span class="text-secondary fw-normal">(optional)</span></label>
                                    <textarea class="form-control" id="compliedNotes<?php echo $reportId; ?>" name="comments" rows="4" maxlength="2000" placeholder="e.g. SK submitted the missing reports and explained the delay was due to a barangay emergency."></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1"></i>Mark as Complied</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
