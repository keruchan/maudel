<?php
/**
 * ============================================================
 * File     : pages/youth/full_disclosure.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth-facing Full Disclosure Board. Shows project charters plus
 *            finalized CBYDP and ABYIP documents for the signed-in youth's
 *            own barangay only.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/charters.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');

$charters = [];
$cbydps = [];
$abyips = [];
if ($barangayId > 0) {
    $charters = sked_charters_for_barangay($barangayId);
    foreach (sked_cbydp_list_for_barangay($barangayId) as $plan) {
        if ((string) $plan['status'] !== 'finalized') {
            continue;
        }
        $detail = sked_cbydp_get((int) $plan['id'], $barangayId);
        if ($detail !== null) {
            $cbydps[] = $detail;
        }
    }
    foreach (sked_abyip_list_for_barangay($barangayId) as $plan) {
        if ((string) $plan['status'] !== 'finalized') {
            continue;
        }
        $detail = sked_abyip_get((int) $plan['id'], $barangayId);
        if ($detail !== null) {
            $abyips[] = $detail;
        }
    }
}
$charterStatusBadge = static fn(string $s) => ['upcoming' => 'primary', 'ongoing' => 'info', 'completed' => 'success'][$s] ?? 'secondary';
$charterStatusLabel = static fn(string $s) => ['upcoming' => 'Upcoming', 'ongoing' => 'Current', 'completed' => 'Past'][$s] ?? ucfirst($s);
$fmtMoney = static fn($amt) => $amt === null ? 'Not specified' : 'PHP ' . number_format((float) $amt, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Full Disclosure Board</title>
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
        <?php render_sked_navigation($role, 'full_disclosure'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Full Disclosure Board</h1>
                        <p class="text-secondary meta-copy mb-0">Publicly available project charters, CBYDP, and ABYIP documents for your barangay.</p>
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
                <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your account is not linked to a barangay yet.</div>
            <?php else: ?>
                <section class="row g-3 mb-4" aria-label="Disclosure summary">
                    <div class="col-md-4">
                        <div class="ledger-card"><span class="ledger-tag">Project Charters</span><div class="ledger-value tabular"><?php echo count($charters); ?></div><div class="ledger-caption">Barangay projects disclosed</div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="ledger-card accent-teal"><span class="ledger-tag">CBYDP</span><div class="ledger-value tabular"><?php echo count($cbydps); ?></div><div class="ledger-caption">Finalized plans disclosed</div></div>
                    </div>
                    <div class="col-md-4">
                        <div class="ledger-card accent-amber"><span class="ledger-tag">ABYIP</span><div class="ledger-value tabular"><?php echo count($abyips); ?></div><div class="ledger-caption">Finalized investment programs disclosed</div></div>
                    </div>
                </section>

                <section class="docket-panel mb-4">
                    <div class="section-heading"><h2>Project Charters</h2><span class="section-note"><?php echo count($charters); ?> disclosed</span></div>
                    <?php if (empty($charters)): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-check fs-1 d-block mb-2"></i>No project charter has been disclosed yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="charterDisclosureTable">
                                <thead><tr><th>Project</th><th>Status</th><th>Timeline</th><th class="text-end">Budget</th><th class="text-end">Success Rate</th></tr></thead>
                                <tbody>
                                <?php foreach ($charters as $c): $sr = sked_charter_success_rate($c); ?>
                                    <tr>
                                        <td style="min-width:260px;">
                                            <div class="fw-semibold"><?php echo e((string) $c['title']); ?></div>
                                            <?php if (!empty($c['description'])): ?><div class="small text-secondary"><?php echo e((string) $c['description']); ?></div><?php endif; ?>
                                        </td>
                                        <td><span class="badge text-bg-<?php echo e($charterStatusBadge((string) $c['status'])); ?>"><?php echo e($charterStatusLabel((string) $c['status'])); ?></span></td>
                                        <td class="small text-secondary" data-tt-sort="<?php echo !empty($c['start_date']) ? e((string) strtotime((string) $c['start_date'])) : ''; ?>">
                                            <?php echo e(!empty($c['start_date']) ? date('M j, Y', strtotime((string) $c['start_date'])) : 'Not set'); ?>
                                            -
                                            <?php echo e(!empty($c['end_date']) ? date('M j, Y', strtotime((string) $c['end_date'])) : 'Open ended'); ?>
                                        </td>
                                        <td class="text-end tabular" data-tt-sort="<?php echo e((string) ($c['budget_amount'] ?? '')); ?>"><?php echo e($fmtMoney($c['budget_amount'])); ?></td>
                                        <td class="text-end tabular" data-tt-sort="<?php echo $sr['avg'] !== null ? e((string) $sr['avg']) : ''; ?>">
                                            <?php echo $sr['avg'] !== null ? e((string) $sr['avg']) . '/5' : '<span class="text-secondary small">No evaluations</span>'; ?>
                                            <div class="small text-secondary"><?php echo (int) $sr['count']; ?> eval<?php echo (int) $sr['count'] === 1 ? '' : 's'; ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="docket-panel mb-4">
                    <div class="section-heading"><h2>CBYDP</h2><span class="section-note"><?php echo count($cbydps); ?> disclosed</span></div>
                    <?php if (empty($cbydps)): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-clipboard-data fs-1 d-block mb-2"></i>No finalized CBYDP has been disclosed yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="cbydpDisclosureTable">
                                <thead><tr><th>Cycle</th><th>Status</th><th class="text-end">Budget</th><th>Signed Copy</th><th class="text-end" data-tt-nosort>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($cbydps as $p): ?>
                                    <tr>
                                        <td class="fw-semibold">CY <?php echo (int) $p['cy_year_start']; ?>-<?php echo (int) $p['cy_year_start'] + 2; ?></td>
                                        <td><span class="badge text-bg-success">Finalized</span></td>
                                        <td class="text-end tabular" data-tt-sort="<?php echo e((string) sked_cbydp_total_budget($p)); ?>">PHP <?php echo number_format(sked_cbydp_total_budget($p), 2); ?></td>
                                        <td>
                                            <?php if (!empty($p['signed_file_path'])): ?>
                                                <a href="../manage/plan_file.php?type=cbydp&amp;id=<?php echo (int) $p['id']; ?>" target="_blank" class="text-decoration-none"><i class="bi bi-file-earmark-check me-1"></i><?php echo e((string) $p['signed_file_original_name']); ?></a>
                                            <?php else: ?>
                                                <span class="text-secondary small">No signed copy uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../manage/cbydp_plan.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-eye me-1"></i>View</a>
                                            <a href="../manage/cbydp_export.php?id=<?php echo (int) $p['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Export</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="docket-panel">
                    <div class="section-heading"><h2>ABYIP</h2><span class="section-note"><?php echo count($abyips); ?> disclosed</span></div>
                    <?php if (empty($abyips)): ?>
                        <div class="text-center text-secondary py-5"><i class="bi bi-cash-coin fs-1 d-block mb-2"></i>No finalized ABYIP has been disclosed yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle" id="abyipDisclosureTable">
                                <thead><tr><th>Year</th><th>Source</th><th>Status</th><th class="text-end">Budget</th><th>Signed Copy</th><th class="text-end" data-tt-nosort>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($abyips as $p): $totals = sked_abyip_total_budget($p); ?>
                                    <tr>
                                        <td class="fw-semibold">CY <?php echo (int) $p['calendar_year']; ?></td>
                                        <td><?php echo $p['cbydp_plan_id'] ? '<span class="badge text-bg-light">From CBYDP</span>' : '<span class="badge text-bg-light">Blank</span>'; ?></td>
                                        <td><span class="badge text-bg-success">Finalized</span></td>
                                        <td class="text-end tabular" data-tt-sort="<?php echo e((string) $totals['total']); ?>">PHP <?php echo number_format($totals['total'], 2); ?></td>
                                        <td>
                                            <?php if (!empty($p['signed_file_path'])): ?>
                                                <a href="../manage/plan_file.php?type=abyip&amp;id=<?php echo (int) $p['id']; ?>" target="_blank" class="text-decoration-none"><i class="bi bi-file-earmark-check me-1"></i><?php echo e((string) $p['signed_file_original_name']); ?></a>
                                            <?php else: ?>
                                                <span class="text-secondary small">No signed copy uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../manage/abyip_plan.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-sked"><i class="bi bi-eye me-1"></i>View</a>
                                            <a href="../manage/abyip_export.php?id=<?php echo (int) $p['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Export</a>
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
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#charterDisclosureTable', { pageSize: 10, filters: [{ label: 'Status' }] });
        new SkedTableTools('#cbydpDisclosureTable', { pageSize: 10, filters: [{ label: 'Status' }] });
        new SkedTableTools('#abyipDisclosureTable', { pageSize: 10, filters: [{ label: 'Source' }, { label: 'Status' }] });
    </script>
</body>
</html>
