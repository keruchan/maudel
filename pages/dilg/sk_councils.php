<?php
/**
 * ============================================================
 * File     : pages/dilg/sk_councils.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG "SK Councils" (P15) — read-only municipality-wide roster
 *            of every active barangay SK Chairman and their strike count.
 *            Reuses sked_compliance_overview() (already built for PPSK's
 *            compliance.php); DILG has no escalate action here — that
 *            happens once PPSK escalates to Dismissal Review.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/compliance.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$overview = sked_compliance_overview();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | SK Councils</title>
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
        <?php render_sked_navigation($role, 'sk_councils'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">SK Councils</h1>
                        <p class="text-secondary meta-copy mb-0">Every active barangay SK Chairman, municipality-wide.</p>
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

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Barangay SK Chairmen</h2>
                    <span class="section-note"><?php echo count($overview); ?> active</span>
                </div>
                <?php if (empty($overview)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>No active SK accounts yet.</div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle">
                        <thead><tr><th>Barangay</th><th>SK Chairman</th><th>Compliance Strikes</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview as $row): $strikes = (int) $row['strikes']; ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e((string) ($row['barangay_name'] ?? '—')); ?></td>
                                <td><?php echo e((string) $row['name']); ?></td>
                                <td><span class="badge <?php echo $strikes >= SKED_DISMISSAL_STRIKE_THRESHOLD ? 'text-bg-danger' : ($strikes > 0 ? 'text-bg-warning' : 'text-bg-success'); ?>"><?php echo $strikes; ?> / <?php echo SKED_DISMISSAL_STRIKE_THRESHOLD; ?></span></td>
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
