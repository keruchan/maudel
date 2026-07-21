<?php
/**
 * ============================================================
 * File     : pages/dilg/turnover.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG turnover-of-power review (P8, spec 6.1 steps 1-3). Review
 *            a pending turnover report (incoming PPSK identity + SK roster),
 *            activate the new PPSK (retiring the outgoing one), or decline.
 *            Also supports directly designating a PPSK without a report,
 *            for initial bootstrap (spec 4.1: "Designate the PPSK President").
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/turnover.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$dilgUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$issuedCredentials = null;

if (empty($_SESSION['csrf_turnover_token'])) {
    $_SESSION['csrf_turnover_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_turnover_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($action === 'activate') {
        $r = sked_activate_new_ppsk($dilgUserId, (int) ($_POST['report_id'] ?? 0));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => $r['promoted']
                ? 'New PPSK activated — the existing verified youth account was promoted; they sign in with their own credentials.'
                : 'New PPSK activated. Share these one-time credentials with them securely.'];
            $issuedCredentials = $r['credentials'];
        } else {
            $flash = ['type' => 'danger', 'msg' => e($r['error'])];
        }
    } elseif ($action === 'decline') {
        $ok = sked_decline_turnover_report($dilgUserId, (int) ($_POST['report_id'] ?? 0));
        $flash = $ok ? ['type' => 'warning', 'msg' => 'Turnover report declined.'] : ['type' => 'danger', 'msg' => 'Could not update that report.'];
    } elseif ($action === 'designate') {
        $r = sked_provision_officer($dilgUserId, 'ppsk', null, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['mobile'] ?? ''));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => $r['promoted']
                ? 'PPSK designated — the existing verified youth account was promoted.'
                : 'PPSK designated. Share these one-time credentials with them securely.'];
            $issuedCredentials = $r['credentials'];
        } else {
            $flash = ['type' => 'danger', 'msg' => e($r['error'])];
        }
    }
}

$pending = sked_pending_turnover_reports();
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
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
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
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Turnover of Power</h1>
                        <p class="text-secondary meta-copy mb-0">Review election turnover reports and activate the incoming PPSK.</p>
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

            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Pending Turnover Reports</h2>
                    <span class="section-note"><?php echo count($pending); ?> pending</span>
                </div>
                <?php if (empty($pending)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Nothing pending.</div>
                <?php else: ?>
                    <?php foreach ($pending as $r): $roster = sked_turnover_roster_for_report((int) $r['id']); ?>
                        <div class="docket-row d-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="docket-title">Incoming PPSK: <?php echo e((string) $r['new_officer_name']); ?></div>
                                    <div class="docket-sub">Submitted by <?php echo e((string) $r['submitted_by_name']); ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $r['submitted_at']))); ?></div>
                                </div>
                                <span class="badge text-bg-secondary"><?php echo count($roster); ?> SK nominee<?php echo count($roster) === 1 ? '' : 's'; ?></span>
                            </div>
                            <?php if (!empty($roster)): ?>
                                <div class="table-responsive mt-2"><table class="table table-sm">
                                    <thead><tr><th>Barangay</th><th>Incoming SK</th><th>Contact</th></tr></thead>
                                    <tbody><?php foreach ($roster as $row): ?>
                                        <tr><td><?php echo e((string) $row['barangay_name']); ?></td><td><?php echo e((string) $row['name']); ?></td><td class="small text-secondary"><?php echo e((string) ($row['email'] ?: '—')); ?></td></tr>
                                    <?php endforeach; ?></tbody>
                                </table></div>
                            <?php endif; ?>
                            <div class="d-flex gap-2 mt-2">
                                <form method="post" action="turnover.php" onsubmit="return confirm('Activate this PPSK? The current active PPSK (if any) will immediately revert to a Youth account.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_turnover_token']); ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-sked"><i class="bi bi-check-circle me-1"></i> Activate new PPSK</button>
                                </form>
                                <form method="post" action="turnover.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_turnover_token']); ?>">
                                    <input type="hidden" name="action" value="decline">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i>Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="docket-panel">
                <div class="section-heading"><h2>Designate PPSK Directly</h2></div>
                <p class="text-secondary small">For initial bootstrap or an ad-hoc designation outside a formal turnover report.</p>
                <form method="post" action="turnover.php" class="row g-2" onsubmit="return confirm('Designate this PPSK? Any current active PPSK will revert to a Youth account.');">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_turnover_token']); ?>">
                    <input type="hidden" name="action" value="designate">
                    <div class="col-md-4"><input type="text" class="form-control" name="name" placeholder="Full name" required></div>
                    <div class="col-md-4"><input type="email" class="form-control" name="email" placeholder="Email (optional — matches existing youth account)"></div>
                    <div class="col-md-3"><input type="tel" class="form-control" name="mobile" placeholder="Mobile (optional)"></div>
                    <div class="col-md-1"><button type="submit" class="btn btn-sked w-100">Go</button></div>
                </form>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
