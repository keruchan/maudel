<?php
/**
 * ============================================================
 * File     : pages/manage/event_qr.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : FALLBACK attendance capture (P19) — displays/prints the
 *            event's own QR so youth can check themselves in when the
 *            primary flow (official scans each ID card) is not workable,
 *            e.g. one official facing a very long queue.
 *
 * Self check-in is OFF until an official switches it on here, because
 * anyone who can see the poster can scan it. That switch is the whole
 * point of this page: the spec asks for the reverse flow to be used only
 * when the first way fails, so it is opt-in per event rather than always
 * live, and every self-scan is recorded with its method for auditing.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/attendance.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';

$eventId = (int) ($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = sked_get_event($eventId);
if ($event === null || !sked_can_manage_event($role, $barangayId, $event)) {
    header('Location: ' . $linkBase . 'events.php');
    exit;
}

if (empty($_SESSION['csrf_eventqr_token'])) {
    $_SESSION['csrf_eventqr_token'] = bin2hex(random_bytes(32));
}

$flash = ['type' => '', 'msg' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf_eventqr_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'toggle_self_scan') {
        $enable = !empty($_POST['enable']);
        sked_set_event_self_scan($eventId, $enable, $userId);
        $flash = $enable
            ? ['type' => 'success', 'msg' => 'Self check-in is now ON for this event. Display or print the QR below.']
            : ['type' => 'info', 'msg' => 'Self check-in is now OFF. Only officials scanning ID cards can mark attendance.'];
        $event = sked_get_event($eventId);
    }
}

$token = sked_ensure_event_attendance_token($eventId);
$qrPayload = $token !== null ? sked_qr_payload_event($token) : '';
$selfScanOn = (int) ($event['self_scan_enabled'] ?? 0) === 1;
$summary = sked_attendance_summary($eventId);
$todayLabel = date('l, F j, Y');
$barangayLabel = $event['scope'] === 'barangay' ? 'Barangay ' . sked_barangay_name((int) $event['barangay_id']) : ucfirst((string) $event['scope']) . ' event';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Event QR</title>
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
        <?php render_sked_navigation($role, 'scan_attendance', $linkBase); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4 no-print">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow"><?php echo e($barangayLabel); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Event QR &mdash; Self Check-in</h1>
                        <p class="text-secondary meta-copy mb-0">Backup attendance method for <strong><?php echo e((string) $event['title']); ?></strong>.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?>
                        <a class="btn-logout-outline text-decoration-none" href="scan.php?event_id=<?php echo (int) $eventId; ?>"><i class="bi bi-upc-scan me-1"></i> Scanner</a>
                        <a class="btn-logout-outline text-decoration-none" href="event.php?id=<?php echo (int) $eventId; ?>"><i class="bi bi-arrow-left me-1"></i> Event</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?> no-print" role="alert"><?php echo e($flash['msg']); ?></div><?php endif; ?>

            <div class="alert <?php echo $selfScanOn ? 'alert-success' : 'alert-secondary'; ?> d-flex flex-wrap align-items-center justify-content-between gap-2 no-print" role="status">
                <span>
                    <i class="bi <?php echo $selfScanOn ? 'bi-unlock-fill' : 'bi-lock-fill'; ?> me-1"></i>
                    Self check-in is <strong><?php echo $selfScanOn ? 'ON' : 'OFF'; ?></strong> for this event.
                    <?php if (!$selfScanOn): ?>
                        Youth scanning this QR will be turned away until you switch it on.
                    <?php else: ?>
                        Any youth in scope who scans this QR marks themselves present.
                    <?php endif; ?>
                </span>
                <form method="post" action="event_qr.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_eventqr_token']); ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int) $eventId; ?>">
                    <input type="hidden" name="action" value="toggle_self_scan">
                    <input type="hidden" name="enable" value="<?php echo $selfScanOn ? '0' : '1'; ?>">
                    <button class="btn btn-sm <?php echo $selfScanOn ? 'btn-outline-secondary' : 'btn-sked'; ?>" type="submit">
                        <?php echo $selfScanOn ? 'Switch OFF' : 'Switch ON'; ?>
                    </button>
                </form>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="print-area">
                        <div class="event-qr-poster">
                            <div class="eyebrow">Sangguniang Kabataan &middot; Siniloan, Laguna</div>
                            <div class="event-qr-title mt-2"><?php echo e((string) $event['title']); ?></div>
                            <div class="text-secondary small mt-1">
                                <?php echo e($event['event_date'] ? date('F j, Y', strtotime((string) $event['event_date'])) : 'Date TBA'); ?>
                                <?php if (!empty($event['location'])): ?> &middot; <?php echo e((string) $event['location']); ?><?php endif; ?>
                            </div>
                            <div class="qr-frame"><canvas id="eventQr"></canvas></div>
                            <div class="fw-semibold">Scan to check in</div>
                            <div class="text-secondary small mt-1">Open SKed &rarr; Self Check-in, then scan this code.<br>You must be a verified KK member of a barangay covered by this event.</div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3 no-print">
                        <button type="button" class="btn btn-sked" onclick="window.print();"><i class="bi bi-printer me-1"></i> Print poster</button>
                        <button type="button" class="btn btn-outline-secondary" id="downloadQr"><i class="bi bi-download me-1"></i> Save QR image</button>
                    </div>
                </div>

                <div class="col-lg-5 no-print">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Attendance so far</h2></div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Marked present</span>
                            <span class="status-ready tabular"><?php echo (int) $summary['attended']; ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Scanned by an official</span>
                            <span class="status-ready tabular"><?php echo (int) ($summary['officer_scan'] + $summary['manual']); ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Self check-in (fallback)</span>
                            <span class="status-ready tabular"><?php echo (int) $summary['self_scan']; ?></span>
                        </div>
                        <div class="snapshot-row">
                            <span class="text-secondary"><span class="status-dot"></span>Finalized by evaluation</span>
                            <span class="status-ready tabular"><?php echo (int) $summary['finalized']; ?></span>
                        </div>
                    </div>

                    <div class="docket-panel mt-4">
                        <div class="section-heading"><h2>When to use this</h2></div>
                        <p class="small text-secondary mb-2">
                            Scanning each youth's ID card is the recommended method — the official is present, so attendance
                            cannot be claimed remotely. Use this poster only when that is impractical, and switch it off again
                            afterwards.
                        </p>
                        <a class="btn btn-sm btn-outline-primary" href="scan.php?event_id=<?php echo (int) $eventId; ?>"><i class="bi bi-upc-scan me-1"></i>Go to the ID scanner</a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
    <script>
        (function () {
            var canvas = document.getElementById('eventQr');
            if (!canvas || typeof QRious === 'undefined') { return; }
            new QRious({
                element: canvas,
                value: <?php echo json_encode($qrPayload, JSON_UNESCAPED_SLASHES); ?>,
                size: 520,
                level: 'M',
                background: '#ffffff',
                foreground: '#14133b'
            });
            var dl = document.getElementById('downloadQr');
            if (dl) {
                dl.addEventListener('click', function () {
                    var link = document.createElement('a');
                    link.download = 'event-<?php echo (int) $eventId; ?>-qr.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                });
            }
        })();
    </script>
</body>
</html>
