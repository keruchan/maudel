<?php
/**
 * ============================================================
 * File     : pages/manage/youth_profiles.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : "Consolidated Profiles" (P15) — municipality-wide youth roster
 *            summary, one row per barangay: total KK members, verified
 *            count, and KK-Profiling completion count. Shared by PPSK and
 *            DILG (both see the same municipality-wide data; there's
 *            nothing barangay-scoped to gate here). Lives in pages/manage/
 *            like the other shared oversight pages (event.php, plan pages).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/profiling.php';

require_roles(['ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$todayLabel = date('l, F j, Y');

$summary = sked_youth_profile_summary();
$totals = [
    'total_youth' => array_sum(array_column($summary, 'total_youth')),
    'active_youth' => array_sum(array_column($summary, 'active_youth')),
    'profiled_count' => array_sum(array_column($summary, 'profiled_count')),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Consolidated Profiles</title>
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
        <?php render_sked_navigation($role, 'youth_profiles', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow"><?php echo e($role === 'dilg' ? 'Oversight Console' : 'Federation Office'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Consolidated Profiles</h1>
                        <p class="text-secondary meta-copy mb-0">Katipunan ng Kabataan membership and KK-Profiling completion, municipality-wide.</p>
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

            <section class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="ledger-card"><span class="ledger-tag">KK Members</span><div class="ledger-value tabular"><?php echo (int) $totals['total_youth']; ?></div><div class="ledger-caption">registered municipality-wide</div></div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal"><span class="ledger-tag">Verified</span><div class="ledger-value tabular"><?php echo (int) $totals['active_youth']; ?></div><div class="ledger-caption">active KK members</div></div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-amber"><span class="ledger-tag">Profiled</span><div class="ledger-value tabular"><?php echo (int) $totals['profiled_count']; ?></div><div class="ledger-caption">completed KK Profiling</div></div>
                </div>
            </section>

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>By Barangay</h2>
                    <span class="section-note"><?php echo count($summary); ?> barangays</span>
                </div>
                <?php if (empty($summary)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No barangay data yet.</div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle">
                        <thead><tr><th>Barangay</th><th>KK Members</th><th>Verified</th><th>Profiled</th></tr></thead>
                        <tbody>
                        <?php foreach ($summary as $row): $pct = $row['total_youth'] > 0 ? round($row['profiled_count'] / $row['total_youth'] * 100) : 0; ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($row['barangay_name']); ?></td>
                                <td class="tabular"><?php echo $row['total_youth']; ?></td>
                                <td class="tabular"><?php echo $row['active_youth']; ?></td>
                                <td class="tabular"><?php echo $row['profiled_count']; ?> <span class="small text-secondary">(<?php echo $pct; ?>%)</span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
