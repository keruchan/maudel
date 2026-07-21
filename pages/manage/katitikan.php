<?php
/**
 * ============================================================
 * File     : pages/manage/katitikan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Katitikan (Minutes of the Meeting) detail page (P13) — shared
 *            by SK (full edit, own barangay only) and DILG (view + export
 *            + download signed copy, any barangay, matching the existing
 *            'minutes' report routing from P7). Attendance, Privilege Hour
 *            proposals, and Calendar of Business items are added/removed
 *            one at a time; export/print + signed-copy upload + "Submit to
 *            DILG" (creates a linked reports row) live here too.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/katitikan.php';
require_once __DIR__ . '/../../includes/plan_uploads.php';

require_roles(['sk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$todayLabel = date('l, F j, Y');

$id = (int) ($_GET['id'] ?? $_POST['katitikan_id'] ?? 0);
$k = sked_katitikan_get($id);
if ($k === null) {
    header('Location: ' . ($role === 'sk' ? 'katitikan.php' : 'dashboard.php'));
    exit;
}
$isEditable = $role === 'sk' && (int) $k['barangay_id'] === $sessionBarangayId;
if ($role === 'sk' && !$isEditable) {
    header('Location: katitikan.php');
    exit;
}

$flash = ['type' => '', 'msg' => ''];
if (empty($_SESSION['csrf_katplan_token'])) {
    $_SESSION['csrf_katplan_token'] = bin2hex(random_bytes(32));
}

if ($isEditable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_katplan_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($action === 'update_fields') {
        $r = sked_katitikan_update_fields($id, $sessionBarangayId, $_POST);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Saved.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'add_attendee') {
        $r = sked_katitikan_add_attendee($id, $sessionBarangayId, (string) ($_POST['name'] ?? ''), (string) ($_POST['designation'] ?? ''), (string) ($_POST['status'] ?? 'present'), (int) ($_POST['official_id'] ?? 0) ?: null);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Attendee added.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'add_official_attendees') {
        $r = sked_katitikan_add_official_attendees($id, $sessionBarangayId, $_POST['official_ids'] ?? [], (string) ($_POST['status'] ?? 'present'));
        $added = (int) ($r['added'] ?? 0);
        $skipped = (int) ($r['skipped'] ?? 0);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => $added . ' roster member' . ($added === 1 ? '' : 's') . ' added.' . ($skipped > 0 ? ' ' . $skipped . ' already existed.' : '')]
            : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'delete_attendee') {
        $r = sked_katitikan_delete_attendee((int) ($_POST['attendee_id'] ?? 0), $sessionBarangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Attendee removed.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'add_privilege') {
        $r = sked_katitikan_add_privilege_item($id, $sessionBarangayId, (string) ($_POST['speaker_name'] ?? ''), (string) ($_POST['proposal'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Item added.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'delete_privilege') {
        $r = sked_katitikan_delete_privilege_item((int) ($_POST['item_id'] ?? 0), $sessionBarangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Item removed.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'add_agenda') {
        $r = sked_katitikan_add_agenda_item($id, $sessionBarangayId, (string) ($_POST['category'] ?? ''), (string) ($_POST['description'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Item added.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'delete_agenda') {
        $r = sked_katitikan_delete_agenda_item((int) ($_POST['item_id'] ?? 0), $sessionBarangayId);
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Item removed.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'set_status') {
        $r = sked_katitikan_set_status($id, $sessionBarangayId, (string) ($_POST['status'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Status updated.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'upload_signed') {
        $r = sked_save_signed_upload('katitikan', $id, $userId, $sessionBarangayId, $_FILES['signed_file'] ?? []);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Signed copy uploaded.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    } elseif ($action === 'submit_to_dilg') {
        $submitter = ['id' => $userId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $sessionBarangayId];
        $r = sked_katitikan_submit_to_dilg($submitter, $id);
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Submitted to DILG.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    }
    $k = sked_katitikan_get($id); // refresh
}
$officialRoster = $isEditable ? sked_sk_officials_for_barangay($sessionBarangayId) : [];
$presentCount = count(array_filter($k['attendees'], static fn($a) => $a['attendance_status'] === 'present'));
$absentCount = count(array_filter($k['attendees'], static fn($a) => $a['attendance_status'] === 'absent'));
$linkedAttendanceCount = count(array_filter($k['attendees'], static fn($a) => !empty($a['sk_official_id'])));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Katitikan No. <?php echo e((string) $k['session_no']); ?></title>
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
        <?php render_sked_navigation($role, 'katitikan', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Brgy. <?php echo e($k['barangay_name']); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Minutes — Session No. <?php echo e((string) $k['session_no']); ?>, Series of <?php echo (int) $k['series_year']; ?></h1>
                        <p class="text-secondary meta-copy mb-0">
                            <span class="badge <?php echo $k['status'] === 'finalized' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $k['status']); ?></span>
                            <?php echo e(date('F j, Y', strtotime((string) $k['meeting_date']))); ?> &middot; <?php echo e(sked_format_time_filipino((string) $k['meeting_time'])); ?>
                            <?php if (!$isEditable): ?><span class="ms-2"><i class="bi bi-eye me-1"></i>View-only</span><?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn-logout-outline text-decoration-none" href="katitikan_export.php?id=<?php echo (int) $k['id']; ?>" target="_blank"><i class="bi bi-printer me-1"></i> Export</a>
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="<?php echo e($role === 'sk' ? 'katitikan.php' : 'dashboard.php'); ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <?php if ($isEditable): ?>
            <div class="docket-panel mb-4">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <form method="post" action="katitikan.php?id=<?php echo $id; ?>" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                        <input type="hidden" name="action" value="set_status">
                        <label class="form-label small mb-0">Status</label>
                        <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <option value="draft" <?php echo $k['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="finalized" <?php echo $k['status'] === 'finalized' ? 'selected' : ''; ?>>Finalized</option>
                        </select>
                    </form>
                    <?php if ($k['status'] === 'finalized' && empty($k['report_id'])): ?>
                    <form method="post" action="katitikan.php?id=<?php echo $id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                        <input type="hidden" name="action" value="submit_to_dilg">
                        <button type="submit" class="btn btn-sm btn-sked"><i class="bi bi-send-check me-1"></i>Submit to DILG</button>
                    </form>
                    <?php elseif (!empty($k['report_id'])): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-lg me-1"></i>Submitted to DILG</span>
                    <?php endif; ?>
                    <form method="post" action="katitikan.php?id=<?php echo $id; ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                        <input type="hidden" name="action" value="upload_signed">
                        <input type="file" name="signed_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-sm btn-sked text-nowrap"><i class="bi bi-upload me-1"></i>Upload signed copy</button>
                    </form>
                </div>
                <?php if (!empty($k['signed_file_path'])): ?>
                    <p class="text-secondary small mt-2 mb-0"><i class="bi bi-file-earmark-check me-1"></i>Signed copy on file: <a href="plan_file.php?type=katitikan&amp;id=<?php echo $id; ?>"><?php echo e((string) $k['signed_file_original_name']); ?></a> (uploaded <?php echo e(date('M j, Y', strtotime((string) $k['signed_uploaded_at']))); ?>)</p>
                <?php endif; ?>
            </div>
            <?php elseif (!empty($k['signed_file_path'])): ?>
                <div class="alert alert-info" role="alert"><i class="bi bi-file-earmark-check me-1"></i>Signed copy on file: <a href="plan_file.php?type=katitikan&amp;id=<?php echo $id; ?>"><?php echo e((string) $k['signed_file_original_name']); ?></a> (uploaded <?php echo e(date('M j, Y', strtotime((string) $k['signed_uploaded_at']))); ?>)</div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <!-- Attendance -->
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>Attendance</h2><span class="section-note"><?php echo $presentCount; ?> present &middot; <?php echo $absentCount; ?> absent</span></div>
                        <div class="row g-2 mb-3">
                            <div class="col-4"><div class="count-badge w-100 justify-content-center"><?php echo count($k['attendees']); ?> listed</div></div>
                            <div class="col-4"><div class="count-badge w-100 justify-content-center"><?php echo $linkedAttendanceCount; ?> roster-linked</div></div>
                            <div class="col-4"><div class="count-badge w-100 justify-content-center"><?php echo count($officialRoster); ?> active SK</div></div>
                        </div>
                        <?php if ($isEditable): ?>
                            <?php if (empty($officialRoster)): ?>
                                <div class="alert alert-info py-2 small" role="alert">
                                    <i class="bi bi-person-badge me-1"></i>Add SK members first to use roster-based attendance.
                                    <a href="../sk/members.php" class="alert-link">Open SK Members</a>
                                </div>
                            <?php else: ?>
                                <form method="post" action="katitikan.php?id=<?php echo $id; ?>" class="mb-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                                    <input type="hidden" name="action" value="add_official_attendees">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                        <label class="form-label mb-0">Add from SK roster</label>
                                        <select name="status" class="form-select form-select-sm" style="width:auto;">
                                            <option value="present">Mark selected present</option>
                                            <option value="absent">Mark selected absent</option>
                                        </select>
                                    </div>
                                    <div class="row g-2">
                                        <?php foreach ($officialRoster as $official): ?>
                                            <div class="col-sm-6">
                                                <label class="form-check rounded border px-3 py-2 h-100">
                                                    <input class="form-check-input me-2" type="checkbox" name="official_ids[]" value="<?php echo (int) $official['id']; ?>">
                                                    <span class="fw-semibold"><?php echo e((string) $official['full_name']); ?></span>
                                                    <span class="d-block small text-secondary"><?php echo e((string) $official['position']); ?><?php echo !empty($official['committee']) ? ' &middot; ' . e((string) $official['committee']) : ''; ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-sked w-100 mt-2"><i class="bi bi-check2-square me-1"></i>Add Selected to Roll Call</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($k['attendees'])): ?>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Designation</th>
                                        <th>Status</th>
                                        <?php if ($isEditable): ?><th class="text-end">Action</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($k['attendees'] as $a): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo e((string) $a['name']); ?></div>
                                            <?php if (!empty($a['sk_official_id'])): ?><div class="small text-secondary"><i class="bi bi-person-badge me-1"></i>SK roster</div><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e((string) $a['designation']); ?>
                                            <?php if (!empty($a['roster_committee'])): ?><div class="small text-secondary"><?php echo e((string) $a['roster_committee']); ?></div><?php endif; ?>
                                        </td>
                                        <td><span class="badge <?php echo $a['attendance_status'] === 'present' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $a['attendance_status']); ?></span></td>
                                        <?php if ($isEditable): ?>
                                        <td class="text-end">
                                            <form method="post" action="katitikan.php?id=<?php echo $id; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                                                <input type="hidden" name="action" value="delete_attendee">
                                                <input type="hidden" name="attendee_id" value="<?php echo (int) $a['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center text-secondary py-4"><i class="bi bi-clipboard-check fs-1 d-block mb-2"></i>No attendance added yet.</div>
                        <?php endif; ?>
                        <?php if ($isEditable): ?>
                        <div class="border-top pt-3 mt-3">
                        <label class="form-label small">Manual attendee</label>
                        <form method="post" action="katitikan.php?id=<?php echo $id; ?>" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                            <input type="hidden" name="action" value="add_attendee">
                            <div class="col-12">
                                <select name="official_id" class="form-select form-select-sm">
                                    <option value="0">Manual entry, not linked to roster</option>
                                    <?php foreach ($officialRoster as $official): ?>
                                        <option value="<?php echo (int) $official['id']; ?>"><?php echo e((string) $official['full_name']); ?> - <?php echo e((string) $official['position']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name *"></div>
                            <div class="col-4"><input type="text" name="designation" class="form-control form-control-sm" placeholder="SK Chairperson / Member"></div>
                            <div class="col-2">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                </select>
                            </div>
                            <div class="col-1"><button type="submit" class="btn btn-sm btn-sked w-100"><i class="bi bi-plus"></i></button></div>
                        </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Fixed narrative sections -->
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Session Details</h2></div>
                        <form method="post" action="katitikan.php?id=<?php echo $id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                            <input type="hidden" name="action" value="update_fields">
                            <div class="mb-2"><label class="form-label small mb-1">Invocation led by</label><input type="text" class="form-control form-control-sm" name="invocation_by" value="<?php echo e((string) ($k['invocation_by'] ?? '')); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>></div>
                            <div class="mb-2"><label class="form-label small mb-1">Roll Call notes</label><textarea class="form-control form-control-sm" name="roll_call_notes" rows="2" <?php echo $isEditable ? '' : 'disabled'; ?>><?php echo e((string) ($k['roll_call_notes'] ?? '')); ?></textarea></div>
                            <div class="mb-2"><label class="form-label small mb-1">Reading &amp; Approval of Minutes</label><textarea class="form-control form-control-sm" name="minutes_reading_notes" rows="2" <?php echo $isEditable ? '' : 'disabled'; ?>><?php echo e((string) ($k['minutes_reading_notes'] ?? '')); ?></textarea></div>
                            <div class="mb-2"><label class="form-label small mb-1">Committee Reports</label><textarea class="form-control form-control-sm" name="committee_reports" rows="2" <?php echo $isEditable ? '' : 'disabled'; ?>><?php echo e((string) ($k['committee_reports'] ?? '')); ?></textarea></div>
                            <div class="mb-2"><label class="form-label small mb-1">Announcements</label><textarea class="form-control form-control-sm" name="announcements" rows="2" <?php echo $isEditable ? '' : 'disabled'; ?>><?php echo e((string) ($k['announcements'] ?? '')); ?></textarea></div>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><label class="form-label small mb-1">Adjournment time</label><input type="time" class="form-control form-control-sm" name="adjournment_time" value="<?php echo e($k['adjournment_time'] !== null ? substr((string) $k['adjournment_time'], 0, 5) : ''); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>></div>
                                <div class="col-6"><label class="form-label small mb-1">Adjourned by</label><input type="text" class="form-control form-control-sm" name="adjourned_by" value="<?php echo e((string) ($k['adjourned_by'] ?? '')); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>></div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><label class="form-label small mb-1">Presiding Officer</label><input type="text" class="form-control form-control-sm" name="presiding_officer" value="<?php echo e((string) ($k['presiding_officer'] ?? '')); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>></div>
                                <div class="col-6"><label class="form-label small mb-1">Prepared by (SK Secretary)</label><input type="text" class="form-control form-control-sm" name="prepared_by_name" value="<?php echo e((string) ($k['prepared_by_name'] ?? '')); ?>" <?php echo $isEditable ? '' : 'disabled'; ?>></div>
                            </div>
                            <?php if ($isEditable): ?><button type="submit" class="btn btn-sm btn-sked w-100"><i class="bi bi-save"></i>Save Details</button><?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <!-- Privilege Hour -->
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>V. Privilege Hour</h2><span class="section-note"><?php echo count($k['privilege_items']); ?></span></div>
                        <?php foreach ($k['privilege_items'] as $item): ?>
                            <div class="docket-row">
                                <div><span class="fw-semibold"><?php echo e((string) ($item['speaker_name'] ?? '')); ?></span> <?php echo e((string) $item['proposal']); ?></div>
                                <?php if ($isEditable): ?>
                                <form method="post" action="katitikan.php?id=<?php echo $id; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                                    <input type="hidden" name="action" value="delete_privilege">
                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($k['privilege_items'])): ?><p class="text-secondary small">No items yet.</p><?php endif; ?>
                        <?php if ($isEditable): ?>
                        <form method="post" action="katitikan.php?id=<?php echo $id; ?>" class="row g-2 mt-2">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                            <input type="hidden" name="action" value="add_privilege">
                            <div class="col-4"><input type="text" name="speaker_name" class="form-control form-control-sm" placeholder="Hon. Kag. …"></div>
                            <div class="col-6"><input type="text" name="proposal" class="form-control form-control-sm" placeholder="proposed / suggested / recommended… *" required></div>
                            <div class="col-2"><button type="submit" class="btn btn-sm btn-sked w-100"><i class="bi bi-plus-lg"></i>Add</button></div>
                        </form>
                        <?php endif; ?>
                    </div>

                    <!-- Calendar of Business -->
                    <?php foreach (['unfinished' => 'A. Unfinished Business', 'agenda' => 'B. Agenda', 'new' => 'C. New Business'] as $cat => $label): ?>
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2><?php echo e($label); ?></h2><span class="section-note"><?php echo count($k['agenda_' . $cat]); ?></span></div>
                        <ol class="mb-2">
                        <?php foreach ($k['agenda_' . $cat] as $item): ?>
                            <li class="d-flex justify-content-between align-items-start gap-2">
                                <span><?php echo e((string) $item['description']); ?></span>
                                <?php if ($isEditable): ?>
                                <form method="post" action="katitikan.php?id=<?php echo $id; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                                    <input type="hidden" name="action" value="delete_agenda">
                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                        <?php if (empty($k['agenda_' . $cat])): ?><p class="text-secondary small">None.</p><?php endif; ?>
                        <?php if ($isEditable): ?>
                        <form method="post" action="katitikan.php?id=<?php echo $id; ?>" class="d-flex gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_katplan_token']); ?>">
                            <input type="hidden" name="action" value="add_agenda">
                            <input type="hidden" name="category" value="<?php echo e($cat); ?>">
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Add an item…" required>
                            <button type="submit" class="btn btn-sm btn-sked text-nowrap"><i class="bi bi-plus-lg"></i>Add</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
