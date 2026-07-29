<?php
/**
 * Barangay SK officials roster.
 *
 * Declares SK members and positions without changing their auth role. Linked
 * accounts remain youth/community accounts and simply receive a recognition
 * tag wherever the roster is shown.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/sk_members.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$flash = ['type' => '', 'msg' => ''];
$formErrors = [];

if (empty($_SESSION['csrf_sk_members_token'])) {
    $_SESSION['csrf_sk_members_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_sk_members_token'], $token)) {
        if ($action === 'save_member') {
            $formErrors = ['Security validation failed. Please try again.'];
            sked_form_retain(true);
        } else {
            $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
        }
    } elseif ($action === 'save_member') {
        $r = sked_sk_official_save($barangayId, $userId, $_POST);
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'SK member saved.'];
        } else {
            $formErrors = $r['errors'];
            sked_form_retain(true); // keep the roster entry being typed
        }
    } elseif ($action === 'set_status') {
        $r = sked_sk_official_set_status((int) ($_POST['official_id'] ?? 0), $barangayId, (string) ($_POST['status'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'info', 'msg' => 'Roster status updated.'] : ['type' => 'danger', 'msg' => implode(' ', $r['errors'])];
    }
}

$members = $barangayId > 0 ? sked_sk_officials_for_barangay($barangayId, true) : [];
$activeMembers = array_values(array_filter($members, static fn($m) => $m['status'] === 'active'));
$candidates = $barangayId > 0 ? sked_sk_official_candidates($barangayId) : [];
$editId = (int) ($_GET['edit'] ?? (sked_form_retaining() ? ($_POST['official_id'] ?? 0) : 0));
$editing = $editId > 0 ? sked_sk_official_get($editId, $barangayId) : null;
$positions = sked_sk_position_options();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | SK Members</title>
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
        <?php render_sked_navigation($role, 'sk_members'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">SK Members</h1>
                        <p class="text-secondary meta-copy mb-0">Declare SK officials and positions for roll call, attendance tracking, and youth account recognition.</p>
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

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <section class="row g-3 mb-4" aria-label="SK members summary">
                <div class="col-sm-4">
                    <div class="ledger-card">
                        <span class="ledger-tag">Active Officials</span>
                        <div class="ledger-value tabular"><?php echo count($activeMembers); ?></div>
                        <div class="ledger-caption">Current SK roster</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal">
                        <span class="ledger-tag">Linked Accounts</span>
                        <div class="ledger-value tabular"><?php echo count(array_filter($activeMembers, static fn($m) => !empty($m['user_id']))); ?></div>
                        <div class="ledger-caption">Youth/community accounts with tags</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-amber">
                        <span class="ledger-tag">Attendance Rows</span>
                        <div class="ledger-value tabular"><?php echo array_sum(array_map(static fn($m) => (int) $m['total_meetings'], $members)); ?></div>
                        <div class="ledger-caption">From Katitikan roll calls</div>
                    </div>
                </div>
            </section>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2><?php echo $editing ? 'Edit SK Member' : 'Declare SK Member'; ?></h2>
                        </div>
                        <form method="post" action="members.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_sk_members_token']); ?>">

                            <?php sked_render_form_errors($formErrors, 'The SK member could not be saved:'); ?>

                            <input type="hidden" name="action" value="save_member">
                            <input type="hidden" name="official_id" value="<?php echo (int) ($editing['id'] ?? 0); ?>">

                            <div class="mb-3">
                                <label class="form-label">Link youth/community account</label>
                                <select class="form-select" name="user_id">
                                    <option value="0" <?php echo sked_old_selected('user_id', '0', (int) ($editing['user_id'] ?? 0) === 0) ? 'selected' : ''; ?>>No linked account / manual official</option>
                                    <?php foreach ($candidates as $candidate): ?>
                                        <option value="<?php echo (int) $candidate['id']; ?>" <?php echo sked_old_selected('user_id', (string) $candidate['id'], (int) ($editing['user_id'] ?? 0) === (int) $candidate['id']) ? 'selected' : ''; ?>>
                                            <?php echo e((string) $candidate['name']); ?> @<?php echo e((string) $candidate['username']); ?><?php echo !empty($candidate['verified']) ? ' - verified' : ' - pending'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Linked users keep their Youth portal account. SKed only adds the position tag.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Display name</label>
                                <input type="text" name="full_name" class="form-control" maxlength="150" value="<?php echo e(sked_old('full_name', (string) ($editing['full_name'] ?? ''))); ?>" placeholder="Leave blank to use linked account name">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Position</label>
                                <select class="form-select" name="position" required>
                                    <option value="">Select position</option>
                                    <?php foreach ($positions as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>" <?php echo sked_old_selected('position', $value, (string) ($editing['position'] ?? '') === $value) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Committee / assignment</label>
                                <input type="text" name="committee" class="form-control" maxlength="120" value="<?php echo e(sked_old('committee', (string) ($editing['committee'] ?? ''))); ?>" placeholder="Youth Development, Sports, Education...">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Contact number</label>
                                <input type="text" name="contact_no" class="form-control" maxlength="30" value="<?php echo e(sked_old('contact_no', (string) ($editing['contact_no'] ?? ''))); ?>">
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label">Term start</label>
                                    <input type="date" name="term_start" class="form-control" value="<?php echo e(sked_old('term_start', (string) ($editing['term_start'] ?? ''))); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Term end</label>
                                    <input type="date" name="term_end" class="form-control" value="<?php echo e(sked_old('term_end', (string) ($editing['term_end'] ?? ''))); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Roster status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?php echo sked_old_selected('status', 'active', (string) ($editing['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo sked_old_selected('status', 'inactive', (string) ($editing['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-save me-1"></i>Save SK Member</button>
                            <?php if ($editing): ?><a href="members.php" class="btn btn-outline-secondary w-100 mt-2"><i class="bi bi-x-circle"></i>Cancel edit</a><?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Roster &amp; Attendance</h2>
                            <span class="section-note"><?php echo count($members); ?> records</span>
                        </div>
                        <?php if (empty($members)): ?>
                            <div class="text-center text-secondary py-5">
                                <i class="bi bi-person-badge fs-1 d-block mb-2"></i>
                                No SK members declared yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle" id="skOfficialsTable">
                                    <thead>
                                        <tr>
                                            <th>Official</th>
                                            <th>Position</th>
                                            <th>Attendance</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($members as $member): ?>
                                        <?php $rate = sked_sk_official_attendance_rate($member); ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo e((string) $member['full_name']); ?></div>
                                                <div class="small text-secondary">
                                                    <?php if (!empty($member['username'])): ?>
                                                        <i class="bi bi-person-check me-1"></i>Youth account @<?php echo e((string) $member['username']); ?>
                                                    <?php else: ?>
                                                        <i class="bi bi-pencil-square me-1"></i>Manual roster entry
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-primary"><?php echo e((string) $member['position']); ?></span>
                                                <?php if (!empty($member['committee'])): ?><div class="small text-secondary mt-1"><?php echo e((string) $member['committee']); ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($rate === null): ?>
                                                    <span class="text-secondary small">No roll calls yet</span>
                                                <?php else: ?>
                                                    <div class="fw-semibold"><?php echo $rate; ?>% present</div>
                                                    <div class="small text-secondary"><?php echo (int) $member['present_count']; ?> present / <?php echo (int) $member['absent_count']; ?> absent</div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?php echo $member['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $member['status']); ?></span></td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-1">
                                                    <a class="btn btn-sm btn-outline-secondary" href="members.php?edit=<?php echo (int) $member['id']; ?>"><i class="bi bi-pencil"></i></a>
                                                    <form method="post" action="members.php">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_sk_members_token']); ?>">
                                                        <input type="hidden" name="action" value="set_status">
                                                        <input type="hidden" name="official_id" value="<?php echo (int) $member['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $member['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                        <button type="submit" class="btn btn-sm <?php echo $member['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                            <i class="bi <?php echo $member['status'] === 'active' ? 'bi-archive' : 'bi-arrow-counterclockwise'; ?>"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#skOfficialsTable', { pageSize: 10, filters: [{ label: 'Status' }] });
    </script>
</body>
</html>
