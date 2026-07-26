<?php
/**
 * ============================================================
 * File     : pages/youth/scan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : FALLBACK attendance for youth (P19) — scan the event QR the
 *            SK has displayed or printed, and be marked present.
 *
 * Presented throughout as the backup path: the page says so, and the
 * server refuses any event whose official has not switched self check-in
 * on. The normal route remains "show your KK ID card and let the SK scan
 * it" (pages/youth/my_qr.php).
 *
 * On success the youth is pushed straight at the evaluation, since that
 * is what finalizes the attendance.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/attendance.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

if (empty($_SESSION['csrf_attendance_token'])) {
    $_SESSION['csrf_attendance_token'] = bin2hex(random_bytes(32));
}

$pendingEvaluations = (!$isDemo && $isVerified) ? sked_pending_evaluations_for_youth($userId) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Self Check-in</title>
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
        <?php render_sked_navigation($role, 'self_checkin'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Self Check-in</h1>
                        <p class="text-secondary meta-copy mb-0">Backup method — scan the event QR your SK is displaying.</p>
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

            <div class="alert alert-info" role="status">
                <i class="bi bi-info-circle-fill me-1"></i>
                The usual way to be marked present is to show your <a href="my_qr.php">KK ID card</a> and let your SK scan it.
                Use this page only when your SK tells you to.
            </div>

            <?php if ($isDemo): ?>
                <div class="alert alert-warning"><i class="bi bi-info-circle-fill me-1"></i> Demo account: check-in is preview-only and is not saved.</div>
            <?php elseif (!$isVerified): ?>
                <div class="alert alert-warning"><i class="bi bi-lock-fill me-1"></i> Check-in unlocks once your Barangay SK verifies your KK membership.</div>
            <?php else: ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Scan the event QR</h2></div>

                        <div id="cameraUnavailable" class="alert alert-warning d-none" role="alert">
                            <i class="bi bi-camera-video-off me-1"></i><span id="cameraUnavailableMsg"></span>
                        </div>

                        <div id="scanResult" class="d-none mb-3" role="status"></div>

                        <div class="scan-viewport mb-3" id="reader"></div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sked" id="btnStart"><i class="bi bi-camera me-1"></i> Start camera</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="btnStop"><i class="bi bi-stop-circle me-1"></i> Stop</button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Finalize your attendance</h2>
                            <span class="section-note"><?php echo count($pendingEvaluations); ?> pending</span>
                        </div>
                        <?php if (empty($pendingEvaluations)): ?>
                            <div class="text-secondary small py-3">
                                Nothing pending. Once you are marked present at an event, it will appear here until you submit its evaluation.
                            </div>
                        <?php else: ?>
                            <p class="small text-secondary">You were marked present at these events. Submit each evaluation to finalize your attendance and earn the evaluation points.</p>
                            <?php foreach ($pendingEvaluations as $pe): ?>
                                <a class="docket-row text-reset text-decoration-none" href="events.php">
                                    <div>
                                        <div class="docket-title"><?php echo e((string) $pe['title']); ?> <i class="bi bi-arrow-right small text-secondary"></i></div>
                                        <div class="docket-sub">Attended <?php echo e($pe['attended_at'] ? date('M j, Y', strtotime((string) $pe['attended_at'])) : ''); ?></div>
                                    </div>
                                    <span class="count-badge">Evaluate</span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($isVerified && !$isDemo): ?>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="../../js/qr-attendance.js"></script>
    <script>
        (function () {
            var btnStart = document.getElementById('btnStart');
            var btnStop = document.getElementById('btnStop');
            var out = document.getElementById('scanResult');

            var scanner = new SkedScanner({
                mount: 'reader',
                action: 'self_scan',
                csrf: <?php echo json_encode((string) $_SESSION['csrf_attendance_token']); ?>,
                api: '../api/attendance.php',
                onResult: function (data) {
                    out.classList.remove('d-none', 'alert-success', 'alert-warning', 'alert-danger');
                    out.classList.add('alert');
                    if (data.result === 'marked') {
                        out.classList.add('alert-success');
                        out.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + data.message +
                            ' <a class="alert-link" href="events.php">Submit your evaluation now</a> to finalize it.';
                        scanner.stop();
                    } else if (data.result === 'duplicate') {
                        out.classList.add('alert-warning');
                        out.textContent = data.message;
                    } else {
                        out.classList.add('alert-danger');
                        out.textContent = data.message || 'That code was not accepted.';
                    }
                },
                onStateChange: function (running) {
                    btnStart.classList.toggle('d-none', running);
                    btnStop.classList.toggle('d-none', !running);
                }
            });

            if (!SkedScanner.cameraAvailable()) {
                document.getElementById('cameraUnavailableMsg').textContent = SkedScanner.unavailableReason()
                    .replace(' You can still type the ID below.', ' Ask your SK to scan your KK ID card instead.');
                document.getElementById('cameraUnavailable').classList.remove('d-none');
                btnStart.disabled = true;
            }

            btnStart.addEventListener('click', function () { scanner.start(); });
            btnStop.addEventListener('click', function () { scanner.stop(); });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
