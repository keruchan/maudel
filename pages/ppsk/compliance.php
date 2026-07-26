<?php
/**
 * ============================================================
 * File     : pages/ppsk/compliance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK compliance overview (P7, spec 6.4 step 3). Shows every
 *            active SK's strike count; once a barangay reaches the 3-strike
 *            threshold, PPSK can formally escalate to DILG for dismissal
 *            review.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/compliance.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$ppskUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_pcompliance_token'])) {
    $_SESSION['csrf_pcompliance_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_pcompliance_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $ppsk = ['id' => $ppskUserId, 'role' => $role, 'name' => $displayName];
        $r = sked_escalate_to_dilg($ppsk, (int) ($_POST['sk_user_id'] ?? 0));
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Escalated to DILG for dismissal review.']
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    }
}

$overview = sked_compliance_overview();
$pendingSkIds = array_column(
    sked_reports_for_role('dilg', ['type' => 'dismissal_recommendation', 'status' => 'submitted']),
    'ref_user_id'
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | SK Compliance</title>
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
        <?php render_sked_navigation($role, 'compliance'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Federation Office &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">SK Compliance</h1>
                        <p class="text-secondary meta-copy mb-0">Track monthly-report compliance across barangays.</p>
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
                    <h2>Barangay SK Compliance</h2>
                    <span class="section-note"><?php echo count($overview); ?> active SKs</span>
                </div>
                <?php if (empty($overview)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-shield-check fs-1 d-block mb-2"></i>No active SK accounts yet.</div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle" id="complianceTable">
                        <thead><tr><th>Barangay</th><th>SK Chairman</th><th>Strikes</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview as $row): $strikes = (int) $row['strikes']; $pending = in_array((int) $row['sk_user_id'], $pendingSkIds, true); ?>
                            <tr>
                                <td><?php echo e((string) ($row['barangay_name'] ?? '—')); ?></td>
                                <td><?php echo e((string) $row['name']); ?></td>
                                <td><span class="badge <?php echo $strikes >= SKED_DISMISSAL_STRIKE_THRESHOLD ? 'text-bg-danger' : ($strikes > 0 ? 'text-bg-warning' : 'text-bg-success'); ?>"><?php echo $strikes; ?> / <?php echo SKED_DISMISSAL_STRIKE_THRESHOLD; ?></span></td>
                                <td class="text-end">
                                    <?php if ($pending): ?>
                                        <span class="badge text-bg-secondary">Pending with DILG</span>
                                    <?php elseif ($strikes >= SKED_DISMISSAL_STRIKE_THRESHOLD): ?>
                                        <form method="post" action="compliance.php" onsubmit="return confirm('Escalate this SK to DILG for dismissal review? This cannot be undone from here.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_pcompliance_token']); ?>">
                                            <input type="hidden" name="sk_user_id" value="<?php echo (int) $row['sk_user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-shield-exclamation"></i>Escalate to DILG</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js"></script>
    <script>
        new SkedTableTools('#complianceTable', { pageSize: 10 });
    </script>
</body>
</html>
