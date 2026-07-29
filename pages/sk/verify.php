<?php
/**
 * ============================================================
 * File     : pages/sk/verify.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK Chairman "Membership Validation" queue (P2). Lists youth
 *            who registered under the SK's barangay and are awaiting
 *            verification (residency + KK age 15-30), with Verify / Reject
 *            actions. All actions are barangay-scoped and audited.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/verification.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_verify_token'])) {
    $_SESSION['csrf_verify_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_verify_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $youthId = (int) ($_POST['youth_id'] ?? 0);

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } elseif ($action === 'verify') {
        $r = sked_verify_youth($skUserId, $youthId, $barangayId);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => e($r['name']) . ' has been verified.']
            : ['type' => 'danger', 'msg' => $r['error']];
    } elseif ($action === 'reject') {
        $r = sked_reject_youth($skUserId, $youthId, $barangayId, (string) ($_POST['reason'] ?? ''));
        $flash = $r['ok']
            ? ['type' => 'warning', 'msg' => e($r['name']) . '\'s registration was rejected.']
            : ['type' => 'danger', 'msg' => $r['error']];
    }
}

$pending = $barangayId > 0 ? sked_pending_youth_for_barangay($barangayId) : [];
$counts = $barangayId > 0 ? sked_verification_counts_for_barangay($barangayId) : ['pending' => 0, 'active' => 0, 'rejected' => 0];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Membership Validation</title>

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
        <?php render_sked_navigation($role, 'validation'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Membership Validation</h1>
                        <p class="text-secondary meta-copy mb-0">Review youth who registered under your barangay and confirm their residency and KK eligibility (age 15&ndash;30).</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span>
                            <?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/>
                </svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo $flash['msg']; /* pre-escaped */ ?></div>
            <?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Your SK account isn't linked to a barangay yet, so there's nothing to validate. Contact your PPSK/DILG to set your barangay.
                </div>
            <?php else: ?>

            <section class="row g-3 mb-4" aria-label="Validation summary">
                <div class="col-sm-4">
                    <div class="ledger-card accent-amber">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Pending</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $counts['pending']; ?></div>
                        <div class="ledger-caption">Awaiting your review</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-patch-check"></i></span>
                            <span class="ledger-tag">Verified</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $counts['active']; ?></div>
                        <div class="ledger-caption">Active KK members</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-rust">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-x-circle"></i></span>
                            <span class="ledger-tag">Rejected</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $counts['rejected']; ?></div>
                        <div class="ledger-caption">Not approved</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel">
                <div class="section-heading">
                    <h2>Pending Registrations</h2>
                    <span class="section-note"><?php echo count($pending); ?> to review</span>
                </div>

                <?php if (empty($pending)): ?>
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-check2-circle fs-1 d-block mb-2"></i>
                        No pending registrations. You're all caught up.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="verifyTable">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Age</th>
                                    <th scope="col">Contact / Address</th>
                                    <th scope="col">Registered</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending as $y): ?>
                                    <?php
                                        $age = $y['age'];
                                        $ageEligible = $age !== null && $age >= SKED_MIN_AGE && $age <= SKED_MAX_AGE;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo e((string) $y['name']); ?></div>
                                            <div class="small text-secondary">@<?php echo e((string) $y['username']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($age === null): ?>
                                                <span class="badge text-bg-secondary">Unknown</span>
                                            <?php else: ?>
                                                <span class="badge <?php echo $ageEligible ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo (int) $age; ?> yrs</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <div><?php echo e((string) $y['email']); ?></div>
                                            <?php if (!empty($y['mobile'])): ?><div class="text-secondary"><?php echo e((string) $y['mobile']); ?></div><?php endif; ?>
                                            <div class="text-secondary"><?php echo e((!empty($y['purok']) ? (string) $y['purok'] . ', ' : '') . ($barangayName !== '' ? 'Barangay ' . $barangayName . ', ' : '') . SKED_DEFAULT_MUNICIPALITY . ', ' . SKED_PROVINCE_NAME); ?></div>
                                        </td>
                                        <td class="small text-secondary"><?php echo e(date('M j, Y', strtotime((string) $y['created_at']))); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-column gap-2 align-items-end">
                                                <form method="post" action="verify.php" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_verify_token']); ?>">
                                                    <input type="hidden" name="action" value="verify">
                                                    <input type="hidden" name="youth_id" value="<?php echo (int) $y['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-sked"><i class="bi bi-check-lg me-1"></i>Verify</button>
                                                </form>
                                                <details>
                                                    <summary class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i>Reject</summary>
                                                    <form method="post" action="verify.php" class="mt-2 text-start" style="min-width:230px;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_verify_token']); ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="youth_id" value="<?php echo (int) $y['id']; ?>">
                                                        <label class="form-label small mb-1" for="reason<?php echo (int) $y['id']; ?>">Reason (optional)</label>
                                                        <input type="text" class="form-control form-control-sm mb-2" id="reason<?php echo (int) $y['id']; ?>" name="reason" maxlength="200" placeholder="e.g. not a resident, age not eligible">
                                                        <button type="submit" class="btn btn-sm btn-danger w-100"><i class="bi bi-x-octagon"></i>Confirm rejection</button>
                                                    </form>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php endif; /* barangay assigned */ ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#verifyTable', { pageSize: 12 });
    </script>
</body>
</html>
