<?php
/**
 * ============================================================
 * File     : pages/manage/announcements.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared Announcements create + manage page for SK/PPSK/DILG.
 *            Unlike events/reports, announcement creation has no real
 *            per-role fields beyond which scopes are selectable
 *            (sked_allowed_scopes_for_role(), same rule events use) —
 *            so, unlike those two features, this is ONE shared page
 *            rather than a hand-duplicated form per role folder.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/announcements.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$todayLabel = date('l, F j, Y');
$barangays = sked_barangays();
$allowedScopes = sked_allowed_scopes_for_role($role);

$flash = ['type' => '', 'msg' => ''];
$formErrors = [];

if (empty($_SESSION['csrf_announcements_token'])) {
    $_SESSION['csrf_announcements_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? 'create');
    if (!hash_equals((string) $_SESSION['csrf_announcements_token'], $token)) {
        if ($action === 'create') {
            $formErrors = ['Security validation failed. Please try again.'];
        } else {
            $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
        }
    } elseif ($action === 'create') {
        $creator = ['id' => $userId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $data = $_POST;
        if ($role === 'sk') {
            $data['scope'] = 'barangay';
        }
        $r = sked_create_announcement($creator, $data, $_FILES['announcement_image'] ?? null);
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Announcement "' . e((string) $_POST['title']) . '" posted' . (!empty($_POST['publish']) ? ' and published.' : ' as a draft.')];
        } else {
            $formErrors = $r['errors'];
        }
    } else {
        $announcementId = (int) ($_POST['announcement_id'] ?? 0);
        $announcement = sked_get_announcement($announcementId);
        if ($announcement === null || !sked_can_manage_announcement($role, $barangayId, $announcement)) {
            $flash = ['type' => 'danger', 'msg' => 'You cannot manage that announcement.'];
        } elseif ($action === 'publish' || $action === 'unpublish') {
            $r = sked_set_announcement_status($announcementId, $action === 'publish' ? 'published' : 'draft');
            $flash = $r['ok'] ? ['type' => 'success', 'msg' => $action === 'publish' ? 'Announcement published.' : 'Announcement moved back to draft.'] : ['type' => 'danger', 'msg' => e($r['error'])];
        } elseif ($action === 'delete') {
            $r = sked_delete_announcement($announcementId);
            $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Announcement deleted.'] : ['type' => 'danger', 'msg' => e($r['error'])];
        }
    }
}

sked_form_retain(!empty($formErrors));

$announcements = sked_announcements_for_manager($role, $barangayId);
$scopeLabels = ['barangay' => 'Barangay', 'interbarangay' => 'Inter-Barangay', 'municipal' => 'Municipal-Wide'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Announcements</title>
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
        <?php render_sked_navigation($role, 'announcements', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Announcements &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Announcements</h1>
                        <p class="text-secondary meta-copy mb-0">Post notices for youth — no date or registration needed, just a title and a message. Shows on the public landing page.</p>
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
                <div class="col-lg-6">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Post an Announcement</h2></div>
                        <form method="post" action="announcements.php" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_announcements_token']); ?>">
                            <input type="hidden" name="action" value="create">
                            <?php sked_render_form_errors($formErrors, 'The announcement could not be posted:'); ?>

                            <div class="mb-3">
                                <label for="title" class="form-label">Title</label>
                                <input type="text" class="form-control" id="title" name="title" maxlength="160" value="<?php echo e(sked_old('title')); ?>" required>
                            </div>

                            <?php if ($role === 'sk'): ?>
                                <p class="small text-secondary">Posting for <strong>Barangay <?php echo e(sked_barangay_name((int) $barangayId)); ?></strong> only.</p>
                            <?php else: ?>
                                <?php $scopeIsInter = sked_old_selected('scope', 'interbarangay'); ?>
                                <?php $scopeIsBarangay = sked_old_selected('scope', 'barangay'); ?>
                                <div class="mb-3">
                                    <label for="scope" class="form-label">Audience</label>
                                    <select class="form-select" id="scope" name="scope" onchange="
                                        document.getElementById('annBgyPicker').style.display = this.value==='interbarangay' ? 'block' : 'none';
                                        document.getElementById('annBgySingle').style.display = this.value==='barangay' ? 'block' : 'none';
                                    ">
                                        <?php if (in_array('barangay', $allowedScopes, true)): ?>
                                            <option value="barangay" <?php echo $scopeIsBarangay ? 'selected' : ''; ?>>One barangay</option>
                                        <?php endif; ?>
                                        <?php if (in_array('interbarangay', $allowedScopes, true)): ?>
                                            <option value="interbarangay" <?php echo $scopeIsInter ? 'selected' : ''; ?>>Inter-barangay (pick barangays)</option>
                                        <?php endif; ?>
                                        <?php if (in_array('municipal', $allowedScopes, true)): ?>
                                            <option value="municipal" <?php echo sked_old_selected('scope', 'municipal', true) ? 'selected' : ''; ?>>Municipality-wide (all barangays)</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="mb-3" id="annBgySingle" style="display:<?php echo $scopeIsBarangay ? 'block' : 'none'; ?>;">
                                    <label for="barangay_id" class="form-label">Barangay</label>
                                    <select class="form-select" id="barangay_id" name="barangay_id">
                                        <option value="">— Select —</option>
                                        <?php foreach ($barangays as $b): ?>
                                            <option value="<?php echo (int) $b['id']; ?>" <?php echo sked_old_selected('barangay_id', (string) $b['id']) ? 'selected' : ''; ?>><?php echo e($b['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3" id="annBgyPicker" style="display:<?php echo $scopeIsInter ? 'block' : 'none'; ?>;">
                                    <label class="form-label">Target barangays <span class="text-secondary fw-normal">(pick 2+)</span></label>
                                    <div class="row g-1" style="max-height:180px; overflow:auto; border:1px solid #e5e7f2; border-radius:10px; padding:.5rem;">
                                        <?php $pickedBarangays = sked_old_array('target_barangays'); ?>
                                        <?php foreach ($barangays as $b): ?>
                                            <div class="col-6"><div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="target_barangays[]" value="<?php echo (int) $b['id']; ?>" id="atb<?php echo (int) $b['id']; ?>" <?php echo in_array((string) $b['id'], $pickedBarangays, true) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="atb<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></label>
                                            </div></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="content" class="form-label">Message</label>
                                <textarea class="form-control" id="content" name="content" rows="5" maxlength="4000" required><?php echo e(sked_old('content')); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="announcement_image" class="form-label">Image <span class="text-secondary fw-normal">(optional)</span></label>
                                <input type="file" class="form-control" id="announcement_image" name="announcement_image" accept=".jpg,.jpeg,.png,.webp">
                                <div class="form-text">JPG, PNG, or WebP. Max 5 MB. <?php if (!empty($formErrors)): ?><span class="text-warning-emphasis">A previously chosen photo must be picked again — browsers never resend file inputs.</span><?php endif; ?></div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="pinned" name="pinned" value="1" <?php echo sked_old_checked('pinned', '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="pinned">Pin to top of the landing page feed</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1" <?php echo sked_old_checked('publish', '1', true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="publish">Publish now (visible to youth &amp; the public landing page)</label>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-megaphone me-1"></i> Post announcement</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Your Announcements</h2>
                            <span class="section-note"><?php echo count($announcements); ?> total</span>
                        </div>
                        <?php if (empty($announcements)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-megaphone fs-1 d-block mb-2"></i>No announcements posted yet.</div>
                        <?php else: ?>
                            <?php foreach ($announcements as $a): ?>
                                <div class="docket-row d-block">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="docket-title">
                                                <?php if ((int) $a['pinned'] === 1): ?><i class="bi bi-pin-angle-fill text-warning-emphasis me-1" title="Pinned"></i><?php endif; ?>
                                                <?php echo e((string) $a['title']); ?>
                                                <span class="badge text-bg-light"><?php echo e($scopeLabels[$a['scope']] ?? ucfirst((string) $a['scope'])); ?><?php echo $a['scope'] === 'barangay' && !empty($a['barangay_id']) ? ' · ' . e(sked_barangay_name((int) $a['barangay_id'])) : ''; ?></span>
                                            </div>
                                            <div class="docket-sub"><?php echo e(date('M j, Y g:i A', strtotime((string) $a['created_at']))); ?></div>
                                        </div>
                                        <span class="badge <?php echo $a['status'] === 'published' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $a['status']); ?></span>
                                    </div>
                                    <p class="small text-secondary mt-2 mb-2"><?php echo nl2br(e((string) $a['content'])); ?></p>
                                    <div class="action-buttons">
                                        <form method="post" action="announcements.php" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_announcements_token']); ?>">
                                            <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                                            <?php if ($a['status'] === 'published'): ?>
                                                <button type="submit" name="action" value="unpublish" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye-slash me-1"></i>Unpublish</button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="publish" class="btn btn-sm btn-outline-success"><i class="bi bi-send-check me-1"></i>Publish</button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="post" action="announcements.php" class="d-inline" onsubmit="return confirm('Delete this announcement? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_announcements_token']); ?>">
                                            <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                                            <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
