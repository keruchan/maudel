<?php
/**
 * ============================================================
 * File     : pages/sk/profiling.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK Chairman's "KK Profiling" roster (P11 v2). Lists every
 *            youth (pending or active) registered under the SK's barangay
 *            with their profile-completion status, linking to
 *            pages/manage/kk_profile.php for assisted entry — e.g. during
 *            a face-to-face profiling drive, alongside self-service via
 *            pages/youth/profile.php.
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

$youths = $barangayId > 0 ? sked_youths_for_barangay($barangayId) : [];
$verifiedYouths = array_values(array_filter($youths, static fn($y) => (string) $y['status'] === 'active' && !empty($y['verified'])));
$completedCount = count(array_filter($verifiedYouths, static fn($y) => $y['has_profile']));
$eligibleCount = count($verifiedYouths);
$lockedCount = count($youths) - $eligibleCount;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | KK Profiling Roster</title>

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
        <?php render_sked_navigation($role, 'kk_profiling'); ?>

        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">KK Profiling Roster</h1>
                        <p class="text-secondary meta-copy mb-0">Fill out or update a verified KK member's profiling form on their behalf.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Your SK account isn't linked to a barangay yet. Contact your PPSK/DILG to set your barangay.
                </div>
            <?php else: ?>

            <section class="row g-3 mb-4" aria-label="Profiling summary">
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-clipboard2-check"></i></span>
                            <span class="ledger-tag">Profiled</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $completedCount; ?></div>
                        <div class="ledger-caption">Completed KK Profiling</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-amber">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Not yet</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) ($eligibleCount - $completedCount); ?></div>
                        <div class="ledger-caption">Verified, still need profiling</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-rust">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-people"></i></span>
                            <span class="ledger-tag">Total</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo (int) $lockedCount; ?></div>
                        <div class="ledger-caption">Locked until verification</div>
                    </div>
                </div>
            </section>

            <section class="docket-panel">
                <div class="section-heading">
                    <h2>Barangay KK Members</h2>
                    <span class="section-note"><?php echo count($youths); ?> total</span>
                </div>

                <?php if (empty($youths)): ?>
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-person-lines-fill fs-1 d-block mb-2"></i>
                        No KK members registered under your barangay yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle" id="profilingTable">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Age</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">KK Profile</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($youths as $y): $canProfile = (string) $y['status'] === 'active' && !empty($y['verified']); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo e((string) $y['name']); ?></div>
                                            <div class="small text-secondary">@<?php echo e((string) $y['username']); ?></div>
                                        </td>
                                        <td><?php echo $y['age'] !== null ? (int) $y['age'] . ' yrs' : '—'; ?></td>
                                        <td><span class="badge <?php echo $y['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary'; ?> text-capitalize"><?php echo e((string) $y['status']); ?></span></td>
                                        <td>
                                            <?php if (!$canProfile): ?>
                                                <span class="badge text-bg-secondary"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                                            <?php elseif ($y['has_profile']): ?>
                                                <span class="badge text-bg-success"><i class="bi bi-check-lg me-1"></i>Complete</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning"><i class="bi bi-dash-circle me-1"></i>Not yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($canProfile): ?>
                                                <a href="../manage/kk_profile.php?youth_id=<?php echo (int) $y['id']; ?>" class="btn btn-sm btn-sked">
                                                    <i class="bi <?php echo $y['has_profile'] ? 'bi-pencil-square' : 'bi-clipboard-plus'; ?>"></i><?php echo $y['has_profile'] ? 'Edit' : 'Fill out'; ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="KK profiling unlocks after SK verification.">
                                                    <i class="bi bi-lock-fill me-1"></i>Locked
                                                </button>
                                            <?php endif; ?>
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
        new SkedTableTools('#profilingTable', { pageSize: 12, filters: [{ label: 'Status' }, { label: 'KK Profile' }] });
    </script>
</body>
</html>
