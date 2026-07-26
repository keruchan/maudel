<?php
/**
 * ============================================================
 * File     : pages/sk/polls.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK community polls (P6, spec 4.3). Create polls for barangay
 *            youth, publish/close them, and view live results.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/polls.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];
$formErrors = [];

if (empty($_SESSION['csrf_polls_token'])) {
    $_SESSION['csrf_polls_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $formName = (string) ($_POST['form'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_polls_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } elseif ($formName === 'status') {
        $r = sked_set_poll_status((int) ($_POST['poll_id'] ?? 0), $barangayId, (string) ($_POST['new_status'] ?? ''));
        $flash = $r['ok'] ? ['type' => 'success', 'msg' => 'Poll updated.'] : ['type' => 'danger', 'msg' => e($r['error'])];
    } else {
        $creator = ['id' => $skUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $options = array_filter(array_map('trim', (array) ($_POST['options'] ?? [])), static fn($o) => $o !== '');
        $r = sked_create_poll($creator, (string) ($_POST['question'] ?? ''), $options, !empty($_POST['publish']), (string) ($_POST['category'] ?? ''));
        if ($r['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Poll created' . (!empty($_POST['publish']) ? ' and published.' : ' as a draft.')];
        } else {
            $formErrors = $r['errors'];
            sked_form_retain(true); // keep the question and options the SK typed
        }
    }
}

$polls = $barangayId > 0 ? sked_polls_for_barangay($barangayId) : [];
$statusBadge = static fn(string $s) => ['draft' => 'secondary', 'open' => 'primary', 'closed' => 'dark'][$s] ?? 'secondary';
$categories = sked_interest_categories();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Community Polls</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
    <style>.poll-bar{height:8px;border-radius:999px;background:#e5e7f2;overflow:hidden;} .poll-bar>div{height:100%;background:linear-gradient(90deg,#4338ca,#818cf8);}</style>
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'polls'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Community Polls</h1>
                        <p class="text-secondary meta-copy mb-0">Ask your barangay youth for input — results feed program planning.</p>
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

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>New Poll</h2></div>
                        <form method="post" action="polls.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_polls_token']); ?>">

                            <?php sked_render_form_errors($formErrors, 'The poll could not be created:'); ?>

                            <div class="mb-3">
                                <label for="question" class="form-label">Question</label>
                                <input type="text" class="form-control" id="question" name="question" maxlength="300" value="<?php echo e(sked_old('question')); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="category" class="form-label">Topic <span class="text-secondary fw-normal">(optional — feeds program recommendations)</span></label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">— None —</option>
                                    <?php foreach ($categories as $c): ?><option value="<?php echo e($c); ?>" <?php echo sked_old_selected('category', $c) ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <label class="form-label">Answer options <span class="text-secondary fw-normal">(2&ndash;6, blank ones ignored)</span></label>
                            <?php $oldOptions = sked_old_array('options'); ?>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input type="text" class="form-control mb-2" name="options[]" maxlength="150" value="<?php echo e($oldOptions[$i - 1] ?? ''); ?>" placeholder="Option <?php echo $i; ?><?php echo $i > 2 ? ' (optional)' : ''; ?>" <?php echo $i <= 2 ? 'required' : ''; ?>>
                            <?php endfor; ?>
                            <div class="form-check mb-3 mt-1">
                                <input class="form-check-input" type="checkbox" id="publish" name="publish" value="1" <?php echo sked_old_checked('publish', '1', true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="publish">Publish now (open for votes)</label>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-bar-chart-steps me-1"></i> Create poll</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Your Polls</h2>
                            <span class="section-note"><?php echo count($polls); ?> total</span>
                        </div>
                        <?php if (empty($polls)): ?>
                            <div class="text-center text-secondary py-5"><i class="bi bi-bar-chart fs-1 d-block mb-2"></i>No polls yet. Create your first one.</div>
                        <?php else: ?>
                            <?php foreach ($polls as $p): $res = sked_poll_results((int) $p['id']); ?>
                                <div class="docket-row d-block">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div class="docket-title"><?php echo e((string) $p['question']); ?>
                                            <span class="badge text-bg-<?php echo e($statusBadge((string) $p['status'])); ?> ms-1"><?php echo e(ucfirst((string) $p['status'])); ?></span>
                                            <?php if (!empty($p['category'])): ?><span class="badge text-bg-light ms-1"><?php echo e((string) $p['category']); ?></span><?php endif; ?>
                                        </div>
                                        <span class="count-badge tabular"><?php echo $res['total']; ?> votes</span>
                                    </div>
                                    <?php foreach ($res['options'] as $o): $pct = $res['total'] > 0 ? (int) round($o['votes'] / $res['total'] * 100) : 0; ?>
                                        <div class="small d-flex justify-content-between"><span><?php echo e((string) $o['option_text']); ?></span><span class="text-secondary"><?php echo $o['votes']; ?> (<?php echo $pct; ?>%)</span></div>
                                        <div class="poll-bar mb-2"><div style="width:<?php echo $pct; ?>%"></div></div>
                                    <?php endforeach; ?>
                                    <?php if ($p['status'] !== 'closed'): ?>
                                    <form method="post" action="polls.php" class="mt-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_polls_token']); ?>">
                                        <input type="hidden" name="form" value="status">
                                        <input type="hidden" name="poll_id" value="<?php echo (int) $p['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $p['status'] === 'draft' ? 'open' : 'closed'; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $p['status'] === 'draft' ? 'btn-outline-primary' : 'btn-outline-dark'; ?>">
                                            <?php echo $p['status'] === 'draft' ? 'Publish' : 'Close poll'; ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
