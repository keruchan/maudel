<?php
/**
 * ============================================================
 * File     : pages/sk/kk_members.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK Chairman's "KK Members" registry — the complete directory
 *            of every Katipunan ng Kabataan youth registered under the SK's
 *            barangay, of any status (pending / verified / rejected). This
 *            is the master roster the SK nav's "KK Members" item should open
 *            (previously it wrongly pointed at verify.php, the pending-only
 *            validation queue). Read-mostly: each row links out to the right
 *            action page — Membership Validation for pending youth, the
 *            KK Profiling form to view/complete a profile.
 *
 * Distinct from the sibling pages:
 *   - verify.php     : pending-only queue, verify/reject actions
 *   - profiling.php  : profiling-completion roster, assisted-entry links
 *   - members.php    : SK OFFICIALS roster (chairman/kagawad), not youth
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
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';

$statusFilter = (string) ($_GET['status'] ?? '');
if (!in_array($statusFilter, ['pending', 'active', 'rejected'], true)) {
    $statusFilter = '';
}

$members = $barangayId > 0 ? sked_kk_members_for_barangay($barangayId, $statusFilter) : [];
$counts = $barangayId > 0 ? sked_verification_counts_for_barangay($barangayId) : ['pending' => 0, 'active' => 0, 'rejected' => 0];
$totalMembers = $counts['pending'] + $counts['active'] + $counts['rejected'];

$statusBadge = static function (string $s): string {
    return match ($s) {
        'active' => 'text-bg-success',
        'pending' => 'text-bg-warning',
        'rejected' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
};
$statusLabel = static fn (string $s): string => $s === 'active' ? 'Verified' : ucfirst($s);
$sexLabel = static fn (?string $s): string => $s === 'male' ? 'M' : ($s === 'female' ? 'F' : '—');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | KK Members</title>
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
        <?php render_sked_navigation($role, 'kk_members'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">KK Members</h1>
                        <p class="text-secondary meta-copy mb-0">Every Katipunan ng Kabataan youth registered in your barangay — the complete membership registry.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your SK account isn't linked to a barangay yet, so there are no members to show.</div>
            <?php else: ?>

            <section class="row g-3 mb-4" aria-label="Membership summary">
                <div class="col-sm-3 col-6">
                    <a class="ledger-card d-block text-reset text-decoration-none <?php echo $statusFilter === '' ? 'accent-teal' : ''; ?>" href="kk_members.php">
                        <span class="ledger-tag">All</span>
                        <div class="ledger-value tabular"><?php echo (int) $totalMembers; ?></div>
                        <div class="ledger-caption">Registered KK members</div>
                    </a>
                </div>
                <div class="col-sm-3 col-6">
                    <a class="ledger-card d-block text-reset text-decoration-none <?php echo $statusFilter === 'active' ? 'accent-teal' : ''; ?>" href="kk_members.php?status=active">
                        <span class="ledger-tag">Verified</span>
                        <div class="ledger-value tabular"><?php echo (int) $counts['active']; ?></div>
                        <div class="ledger-caption">Active members</div>
                    </a>
                </div>
                <div class="col-sm-3 col-6">
                    <a class="ledger-card d-block text-reset text-decoration-none <?php echo $statusFilter === 'pending' ? 'accent-amber' : ''; ?>" href="kk_members.php?status=pending">
                        <span class="ledger-tag">Pending</span>
                        <div class="ledger-value tabular"><?php echo (int) $counts['pending']; ?></div>
                        <div class="ledger-caption">Awaiting validation</div>
                    </a>
                </div>
                <div class="col-sm-3 col-6">
                    <a class="ledger-card d-block text-reset text-decoration-none <?php echo $statusFilter === 'rejected' ? 'accent-rust' : ''; ?>" href="kk_members.php?status=rejected">
                        <span class="ledger-tag">Rejected</span>
                        <div class="ledger-value tabular"><?php echo (int) $counts['rejected']; ?></div>
                        <div class="ledger-caption">Not approved</div>
                    </a>
                </div>
            </section>

            <?php if ($counts['pending'] > 0 && $statusFilter !== 'rejected'): ?>
                <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
                    <span><i class="bi bi-hourglass-split me-1"></i><?php echo (int) $counts['pending']; ?> member<?php echo $counts['pending'] === 1 ? '' : 's'; ?> awaiting validation.</span>
                    <a href="verify.php" class="btn btn-sm btn-sked">Go to Membership Validation</a>
                </div>
            <?php endif; ?>

            <section class="docket-panel">
                <div class="section-heading">
                    <h2><?php echo $statusFilter === '' ? 'All Members' : $statusLabel($statusFilter) . ' Members'; ?></h2>
                    <span class="section-note"><?php echo count($members); ?> shown</span>
                </div>

                <?php if (empty($members)): ?>
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-people fs-1 d-block mb-2"></i>
                        <?php echo $statusFilter === '' ? 'No KK members registered under your barangay yet.' : 'No ' . $statusLabel($statusFilter) . ' members.'; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="membersTable">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Age / Sex</th>
                                    <th scope="col">Contact / Address</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">KK Profile</th>
                                    <th scope="col" class="text-end">Points</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $m): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo e((string) $m['name']); ?></div>
                                            <div class="small text-secondary">@<?php echo e((string) $m['username']); ?></div>
                                        </td>
                                        <td class="small"><?php echo $m['age'] !== null ? (int) $m['age'] . ' yrs' : '—'; ?> &middot; <?php echo e($sexLabel($m['sex_assigned_at_birth'] ?? null)); ?></td>
                                        <td class="small">
                                            <div><?php echo e((string) ($m['email'] ?? '')); ?></div>
                                            <?php if (!empty($m['mobile'])): ?><div class="text-secondary"><?php echo e((string) $m['mobile']); ?></div><?php endif; ?>
                                            <div class="text-secondary"><?php echo e((!empty($m['purok']) ? (string) $m['purok'] . ', ' : '') . ($barangayName !== '' ? 'Barangay ' . $barangayName . ', ' : '') . SKED_DEFAULT_MUNICIPALITY . ', ' . SKED_PROVINCE_NAME); ?></div>
                                        </td>
                                        <td><span class="badge <?php echo $statusBadge((string) $m['status']); ?>"><?php echo e($statusLabel((string) $m['status'])); ?></span></td>
                                        <td>
                                            <?php if ($m['has_profile']): ?>
                                                <span class="badge text-bg-success"><i class="bi bi-check-lg me-1"></i>Complete</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary"><i class="bi bi-dash-circle me-1"></i>Not yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end tabular"><?php echo (int) $m['points']; ?></td>
                                        <td class="text-end">
                                            <?php if ($m['status'] === 'pending'): ?>
                                                <a href="verify.php" class="btn btn-sm btn-sked">Validate</a>
                                            <?php elseif ($m['status'] === 'active'): ?>
                                                <a href="../manage/kk_profile.php?youth_id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-clipboard2-data me-1"></i><?php echo $m['has_profile'] ? 'View profile' : 'Fill profile'; ?></a>
                                            <?php else: ?>
                                                <span class="text-secondary small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js"></script>
    <script>
        new SkedTableTools('#membersTable', {
            pageSize: 12,
            searchPlaceholder: 'Search by name or username…',
            filters: [{ label: 'Status' }, { label: 'KK Profile' }]
        });
    </script>
</body>
</html>
