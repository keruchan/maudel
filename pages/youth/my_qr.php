<?php
/**
 * ============================================================
 * File     : pages/youth/my_qr.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : The youth's KK ID card (P19) — their permanent membership
 *            number plus the QR an SK official scans to mark them present
 *            at events. Styled and laid out as a real ID card, and
 *            printable (the @media print rules in dashboard.css hide
 *            everything except .print-area).
 *
 * The QR is rendered client-side from the token by QRious (CDN), the same
 * way Chart.js is already used for analytics — no PHP image library and no
 * generated files on disk to keep in sync.
 *
 * Verified-only: an unverified account has no KK membership to identify,
 * and attendance would be rejected anyway.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/attendance.php';

require_role('youth');

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Youth Member';
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$purok = trim((string) ($_SESSION['purok'] ?? ''));
$todayLabel = date('l, F j, Y');
$isVerified = sked_is_verified();
$isDemo = $userId < 1000;

$identity = ($isVerified && !$isDemo) ? sked_ensure_youth_qr($userId) : null;
$qrPayload = $identity !== null ? sked_qr_payload_youth($identity['qr_token']) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | My KK ID</title>
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
        <?php render_sked_navigation($role, 'my_qr'); ?>
        <main class="main kk-id-main" id="main-content">
            <section class="page-header mb-4 no-print">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Youth Portal &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">My KK ID</h1>
                        <p class="text-secondary meta-copy mb-0">Your Katipunan ng Kabataan membership card. Show the QR to your SK at an event to be marked present.</p>
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

            <?php if ($isDemo): ?>
                <div class="alert alert-info no-print" role="alert"><i class="bi bi-info-circle-fill me-1"></i> Demo account: KK ID cards are only issued to real registered accounts.</div>
            <?php elseif (!$isVerified): ?>
                <div class="alert alert-warning no-print" role="alert">
                    <i class="bi bi-lock-fill me-1"></i> Your KK ID is issued once your Barangay SK verifies your membership.
                </div>
            <?php else: ?>

            <div class="row g-4 kk-id-layout">
                <div class="col-lg-6 kk-id-card-col">
                    <div class="print-area">
                        <div class="kk-id-card-surface" id="kkCardSurface">
                            <button type="button" class="kk-id-exit-fullscreen no-print" id="kkFullscreenExit" aria-label="Exit full screen">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <div class="kk-id-card" id="kkIdCard">
                            <div class="kk-id-head">
                                <span class="kk-id-seal" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
                                <div>
                                    <div class="kk-id-org">Sangguniang Kabataan</div>
                                    <div class="kk-id-sub">Siniloan, Laguna &middot; Katipunan ng Kabataan</div>
                                </div>
                            </div>
                            <div class="kk-id-body">
                                <div>
                                    <div class="kk-id-name"><?php echo e($displayName); ?></div>
                                    <div class="kk-id-field">Address <strong><?php echo e(($purok !== '' ? $purok . ', ' : '') . ($barangayName !== '' ? 'Barangay ' . $barangayName . ', ' : '') . SKED_DEFAULT_MUNICIPALITY . ', ' . SKED_PROVINCE_NAME); ?></strong></div>
                                    <div class="kk-id-field">Status <strong>Verified KK Member</strong></div>
                                    <div class="kk-id-no"><?php echo e($identity['kk_id_no']); ?></div>
                                </div>
                                <div class="kk-id-qr"><canvas id="kkQr"></canvas></div>
                            </div>
                            <div class="kk-id-foot">
                                <i class="bi bi-shield-check me-1"></i>Present this card to your SK at any SKed event. Do not share a photo of the QR — it marks attendance as you.
                            </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3 no-print kk-id-actions">
                        <button type="button" class="btn btn-sked" id="kkFullscreen"><i class="bi bi-arrows-fullscreen me-1"></i><span data-fullscreen-label>Full screen</span></button>
                        <button type="button" class="btn btn-sked" onclick="window.print();"><i class="bi bi-printer me-1"></i> Print ID card</button>
                        <button type="button" class="btn btn-outline-secondary" id="downloadQr"><i class="bi bi-download me-1"></i> Save QR image</button>
                    </div>
                </div>

                <div class="col-lg-6 no-print kk-id-help-col">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>How attendance works</h2></div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title"><span class="badge text-bg-primary me-1">1</span>Your SK scans this card</div>
                                <div class="docket-sub">The normal way. Show the QR at the registration desk and you are marked present on the spot.</div>
                            </div>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title"><span class="badge text-bg-secondary me-1">2</span>Or you scan the event QR</div>
                                <div class="docket-sub">Backup only, and only when your SK switches it on for that event. <a href="scan.php">Open self check-in</a>.</div>
                            </div>
                        </div>
                        <div class="docket-row">
                            <div>
                                <div class="docket-title"><span class="badge text-bg-success me-1">3</span>Evaluate to finalize</div>
                                <div class="docket-sub">After you are marked present, submit the event evaluation on <a href="events.php">Browse Events</a> to finalize your attendance and earn the extra points.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($identity !== null): ?>
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script>
        (function () {
            var canvas = document.getElementById('kkQr');
            if (!canvas || typeof QRious === 'undefined') { return; }
            new QRious({
                element: canvas,
                value: <?php echo json_encode($qrPayload, JSON_UNESCAPED_SLASHES); ?>,
                size: 320,
                level: 'M',
                background: '#ffffff',
                foreground: '#14133b'
            });

            var dl = document.getElementById('downloadQr');
            if (dl) {
                dl.addEventListener('click', function () {
                    var link = document.createElement('a');
                    link.download = <?php echo json_encode($identity['kk_id_no'] . '-qr.png'); ?>;
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                });
            }

            var fullscreenButton = document.getElementById('kkFullscreen');
            var fullscreenExit = document.getElementById('kkFullscreenExit');
            var fullscreenTarget = document.getElementById('kkCardSurface');
            var page = document.querySelector('.kk-id-main');
            var fullscreenLabel = fullscreenButton ? fullscreenButton.querySelector('[data-fullscreen-label]') : null;

            function nativeFullscreenElement() {
                return document.fullscreenElement || document.webkitFullscreenElement || null;
            }

            function setFullscreenState(active) {
                if (fullscreenTarget) {
                    fullscreenTarget.classList.toggle('is-fullscreen', active);
                }
                if (page) {
                    page.classList.toggle('kk-id-immersive', active);
                }
                document.body.classList.toggle('kk-id-fullscreen-active', active);
                if (fullscreenLabel) {
                    fullscreenLabel.textContent = active ? 'Exit full screen' : 'Full screen';
                }
                if (fullscreenButton) {
                    fullscreenButton.setAttribute('aria-pressed', active ? 'true' : 'false');
                }
            }

            function requestFullscreen() {
                if (!fullscreenTarget) { return Promise.reject(); }
                if (fullscreenTarget.requestFullscreen) {
                    return fullscreenTarget.requestFullscreen();
                }
                if (fullscreenTarget.webkitRequestFullscreen) {
                    fullscreenTarget.webkitRequestFullscreen();
                    return Promise.resolve();
                }
                return Promise.reject();
            }

            function exitFullscreen() {
                if (document.exitFullscreen && document.fullscreenElement) {
                    document.exitFullscreen();
                    return;
                }
                if (document.webkitExitFullscreen && document.webkitFullscreenElement) {
                    document.webkitExitFullscreen();
                    return;
                }
                setFullscreenState(false);
            }

            if (fullscreenButton && fullscreenTarget) {
                fullscreenButton.addEventListener('click', function () {
                    if (nativeFullscreenElement() || fullscreenTarget.classList.contains('is-fullscreen')) {
                        exitFullscreen();
                        return;
                    }
                    requestFullscreen()
                        .then(function () { setFullscreenState(true); })
                        .catch(function () { setFullscreenState(true); });
                });
            }

            if (fullscreenExit) {
                fullscreenExit.addEventListener('click', exitFullscreen);
            }

            document.addEventListener('fullscreenchange', function () {
                setFullscreenState(nativeFullscreenElement() === fullscreenTarget);
            });
            document.addEventListener('webkitfullscreenchange', function () {
                setFullscreenState(nativeFullscreenElement() === fullscreenTarget);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && fullscreenTarget && fullscreenTarget.classList.contains('is-fullscreen')) {
                    exitFullscreen();
                }
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
