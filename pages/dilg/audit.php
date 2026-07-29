<?php
/**
 * ============================================================
 * File     : pages/dilg/audit.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG audit trail viewer (P10, spec 3/8: "log these as audit
 *            trail entries... useful both for DILG oversight and for
 *            resolving disputes over strikes/dismissals"). Read-only —
 *            the audit_log table has been written to since P2; this is
 *            the first page that surfaces it.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/audit.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$actionFilter = (string) ($_GET['action'] ?? '');
$dateFrom = (string) ($_GET['date_from'] ?? '');
$dateTo = (string) ($_GET['date_to'] ?? '');

$entries = sked_audit_log_entries(['action' => $actionFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo], 200);
$allActions = sked_audit_distinct_actions();

/** Human-readable label for an audit action code, e.g. "youth_verified" -> "Youth verified". */
$actionLabel = static function (string $action): string {
    return ucfirst(str_replace('_', ' ', $action));
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Audit Log</title>
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
        <?php render_sked_navigation($role, 'audit_log'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Audit Log</h1>
                        <p class="text-secondary meta-copy mb-0">Who did what, when — verification, dismissal, turnover, and provisioning actions.</p>
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

            <div class="docket-panel mb-3">
                <form method="get" action="audit.php" class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label for="f_action" class="form-label small mb-1">Action</label>
                        <select class="form-select form-select-sm" id="f_action" name="action">
                            <option value="">All actions</option>
                            <?php foreach ($allActions as $a): ?>
                                <option value="<?php echo e($a); ?>" <?php echo $actionFilter === $a ? 'selected' : ''; ?>><?php echo e($actionLabel($a)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label for="f_from" class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" id="f_from" name="date_from" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="col-sm-3">
                        <label for="f_to" class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" id="f_to" name="date_to" value="<?php echo e($dateTo); ?>">
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel"></i>Filter</button>
                    </div>
                </form>
            </div>

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Entries</h2>
                    <span class="section-note"><?php echo count($entries); ?> shown (latest 200 max)</span>
                </div>
                <?php if (empty($entries)): ?>
                    <div class="text-center text-secondary py-5"><i class="bi bi-journal-text fs-1 d-block mb-2"></i>No audit entries match these filters.</div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-sm align-middle" id="auditTable">
                        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
                        <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td class="small text-secondary text-nowrap"><?php echo e(date('M j, Y g:i A', strtotime((string) $entry['created_at']))); ?></td>
                                <td class="small">
                                    <?php if (!empty($entry['actor_name'])): ?>
                                        <?php echo e((string) $entry['actor_name']); ?> <span class="text-secondary">(<?php echo e((string) $entry['actor_role']); ?>)</span>
                                    <?php else: ?>
                                        <span class="text-secondary fst-italic">System</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-light"><?php echo e($actionLabel((string) $entry['action'])); ?></span></td>
                                <td class="small text-secondary">
                                    <?php if (!empty($entry['entity_type'])): ?>
                                        <?php echo e((string) $entry['entity_type']); ?>#<?php echo e((string) $entry['entity_id']); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?php echo e((string) ($entry['details'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/table-tools.js?v=4"></script>
    <script>
        new SkedTableTools('#auditTable', { pageSize: 20, filters: [{ label: 'Action' }] });
    </script>
</body>
</html>
