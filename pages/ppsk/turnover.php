<?php
/**
 * ============================================================
 * File     : pages/ppsk/turnover.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PPSK turnover-of-power (P8, spec 6.1). Two roles this page
 *            serves depending on data state:
 *              - Outgoing PPSK: submit the turnover report (incoming PPSK
 *                identity + incoming SK roster) for DILG review.
 *              - Incoming PPSK (after DILG activates them): generate
 *                credentials for each incoming SK Chairman from that same
 *                roster (self-service delegation, spec 4.1/6.1 step 4).
 *            Also supports directly designating a single SK Chairman
 *            outside a full election (e.g. filling a vacancy after a P7
 *            dismissal).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/turnover.php';

require_role('ppsk');

$role = (string) $_SESSION['role'];
$ppskUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Pederasyon President';
$todayLabel = date('l, F j, Y');
$barangays = sked_barangays();

$flash = ['type' => '', 'msg' => ''];
$issuedCredentials = null;

if (empty($_SESSION['csrf_pturnover_token'])) {
    $_SESSION['csrf_pturnover_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_pturnover_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($action === 'submit_turnover') {
        $submitter = ['id' => $ppskUserId, 'role' => $role, 'name' => $displayName];
        $roster = [];
        foreach ((array) ($_POST['roster'] ?? []) as $bgyId => $row) {
            $roster[] = ['barangay_id' => (int) $bgyId, 'name' => (string) ($row['name'] ?? ''), 'email' => (string) ($row['email'] ?? ''), 'mobile' => (string) ($row['mobile'] ?? '')];
        }
        $r = sked_submit_turnover_report($submitter, (string) ($_POST['new_name'] ?? ''), (string) ($_POST['new_email'] ?? ''), (string) ($_POST['new_mobile'] ?? ''), $roster);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Turnover report submitted to DILG for review.']
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    } elseif ($action === 'provision_roster') {
        $r = sked_provision_sk_from_roster($ppskUserId, (int) ($_POST['roster_id'] ?? 0));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => $r['promoted']
                ? 'SK Chairman provisioned — the existing verified youth account was promoted.'
                : 'SK Chairman provisioned. Share these one-time credentials with them securely.'];
            $issuedCredentials = $r['credentials'];
        } else {
            $flash = ['type' => 'danger', 'msg' => e($r['error'])];
        }
    } elseif ($action === 'designate_sk') {
        $r = sked_provision_officer($ppskUserId, 'sk', (int) ($_POST['barangay_id'] ?? 0), (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['mobile'] ?? ''));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => $r['promoted']
                ? 'SK Chairman designated — the existing verified youth account was promoted.'
                : 'SK Chairman designated. Share these one-time credentials with them securely.'];
            $issuedCredentials = $r['credentials'];
        } else {
            $flash = ['type' => 'danger', 'msg' => e($r['error'])];
        }
    }
}

$pendingReport = sked_reports_for_role('dilg', ['type' => 'turnover', 'status' => 'submitted']);
$hasPendingSubmission = !empty($pendingReport);
$pendingRoster = sked_pending_roster_rows();

// Barangays with no currently active SK, for the direct-designation dropdown.
$vacantBarangays = array_values(array_filter($barangays, static function ($b) {
    return sked_find_active_officer('sk', (int) $b['id']) === null;
}));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Turnover of Power</title>
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
        <?php render_sked_navigation($role, 'turnover'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Federation Office &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Turnover of Power</h1>
                        <p class="text-secondary meta-copy mb-0">Submit the election turnover report, or provision incoming SK Chairmen.</p>
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
            <?php if ($issuedCredentials !== null): ?>
                <div class="alert alert-info" role="alert">
                    <i class="bi bi-key-fill me-1"></i> <strong>One-time credentials</strong> — shown once, not recoverable afterward. Relay securely:
                    <div class="mt-2"><code>Username: <?php echo e($issuedCredentials['username']); ?></code></div>
                    <div><code>Password: <?php echo e($issuedCredentials['password']); ?></code></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($pendingRoster)): ?>
            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Incoming SK Roster</h2>
                    <span class="section-note"><?php echo count($pendingRoster); ?> to provision</span>
                </div>
                <div class="table-responsive"><table class="table align-middle" id="pendingTurnoverTable">
                    <thead><tr><th>Barangay</th><th>Incoming SK</th><th>Contact</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingRoster as $row): ?>
                        <tr>
                            <td><?php echo e((string) $row['barangay_name']); ?></td>
                            <td><?php echo e((string) $row['name']); ?></td>
                            <td class="small text-secondary"><?php echo e((string) ($row['email'] ?: '—')); ?></td>
                            <td class="text-end">
                                <form method="post" action="turnover.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_pturnover_token']); ?>">
                                    <input type="hidden" name="action" value="provision_roster">
                                    <input type="hidden" name="roster_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-sked"><i class="bi bi-key"></i>Generate credentials</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Submit Turnover Report</h2></div>
                        <?php if ($hasPendingSubmission): ?>
                            <div class="text-center text-secondary py-4"><i class="bi bi-hourglass-split fs-1 d-block mb-2"></i>A turnover report is already pending DILG review.</div>
                        <?php else: ?>
                        <form method="post" action="turnover.php" novalidate onsubmit="return confirm('Submit this turnover report to DILG?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_pturnover_token']); ?>">
                            <input type="hidden" name="action" value="submit_turnover">
                            <h3 class="h6">Incoming PPSK</h3>
                            <div class="row g-2 mb-3">
                                <div class="col-md-4"><input type="text" class="form-control" name="new_name" placeholder="Full name" required></div>
                                <div class="col-md-4"><input type="email" class="form-control" name="new_email" placeholder="Email (optional)"></div>
                                <div class="col-md-4"><input type="tel" class="form-control" name="new_mobile" placeholder="Mobile (optional)"></div>
                            </div>
                            <h3 class="h6">Incoming SK Roster <span class="text-secondary fw-normal small">(fill in barangays with a confirmed nominee)</span></h3>
                            <div class="table-responsive" style="max-height:360px; overflow-y:auto;">
                                <table class="table table-sm">
                                    <thead><tr><th>Barangay</th><th>Name</th><th>Email</th><th>Mobile</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($barangays as $b): ?>
                                        <tr>
                                            <td class="small"><?php echo e($b['name']); ?></td>
                                            <td><input type="text" class="form-control form-control-sm" name="roster[<?php echo (int) $b['id']; ?>][name]" placeholder="Nominee name"></td>
                                            <td><input type="email" class="form-control form-control-sm" name="roster[<?php echo (int) $b['id']; ?>][email]" placeholder="Email"></td>
                                            <td><input type="tel" class="form-control form-control-sm" name="roster[<?php echo (int) $b['id']; ?>][mobile]" placeholder="Mobile"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" class="btn btn-sked mt-2"><i class="bi bi-send-check me-1"></i> Submit to DILG</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Designate SK Directly</h2></div>
                        <p class="text-secondary small">Fill a single vacancy without a full election (e.g. after a dismissal).</p>
                        <?php if (empty($vacantBarangays)): ?>
                            <p class="text-secondary small mb-0">Every barangay currently has an active SK Chairman.</p>
                        <?php else: ?>
                        <form method="post" action="turnover.php" onsubmit="return confirm('Designate this SK Chairman?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_pturnover_token']); ?>">
                            <input type="hidden" name="action" value="designate_sk">
                            <div class="mb-2">
                                <select class="form-select form-select-sm" name="barangay_id" required>
                                    <option value="">Vacant barangay…</option>
                                    <?php foreach ($vacantBarangays as $b): ?><option value="<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <input type="text" class="form-control form-control-sm mb-2" name="name" placeholder="Full name" required>
                            <input type="email" class="form-control form-control-sm mb-2" name="email" placeholder="Email (optional)">
                            <input type="tel" class="form-control form-control-sm mb-2" name="mobile" placeholder="Mobile (optional)">
                            <button type="submit" class="btn btn-sm btn-sked w-100"><i class="bi bi-person-check"></i>Designate</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#pendingTurnoverTable', { pageSize: 10 });
    </script>
</body>
</html>
