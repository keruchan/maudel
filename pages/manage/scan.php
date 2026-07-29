<?php
/**
 * ============================================================
 * File     : pages/manage/scan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : PRIMARY attendance capture (P19) — the SK/PPSK official scans
 *            each youth's KK ID QR at the door and they are marked present
 *            immediately. Shared by sk/ppsk/dilg via the $linkBase nav
 *            pattern, same as pages/manage/event.php.
 *
 * Scanning posts to pages/api/attendance.php so the queue keeps moving
 * without a page reload; results stream into a live feed. A manual
 * "type the KK ID" box sits beside the camera and always works — that is
 * the documented fallback when the camera is unavailable (e.g. the app is
 * being reached over plain http on a LAN address, where browsers withhold
 * getUserMedia entirely).
 *
 * DILG does NOT get this page — DILG's role on events/programs is
 * oversight/viewing only, not taking attendance (see also pages/manage/
 * event.php, which hides all mutating actions for role === 'dilg').
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/attendance.php';

require_roles(['sk', 'ppsk']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'Official';
$linkBase = '../' . $role . '/';
$todayLabel = date('l, F j, Y');

if (empty($_SESSION['csrf_attendance_token'])) {
    $_SESSION['csrf_attendance_token'] = bin2hex(random_bytes(32));
}

// Events this official may take attendance for, right now.
$manageable = array_values(array_filter(
    sked_events_for_manager($role, $userId, $barangayId),
    static fn ($e) => in_array((string) $e['status'], SKED_ATTENDANCE_OPEN_STATUSES, true)
));

$eventId = (int) ($_GET['event_id'] ?? 0);
$event = $eventId > 0 ? sked_get_event($eventId) : null;
if ($event !== null && !sked_can_manage_event($role, $barangayId, $event)) {
    $event = null;
    $eventId = 0;
}
if ($event === null && count($manageable) === 1) {
    $event = sked_get_event((int) $manageable[0]['id']);
    $eventId = (int) $event['id'];
}

$summary = $eventId > 0 ? sked_attendance_summary($eventId) : null;
$recentScans = $eventId > 0 ? sked_recent_attendance_scans($eventId, 20) : [];
$attendanceOpen = $event !== null && in_array((string) $event['status'], SKED_ATTENDANCE_OPEN_STATUSES, true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Scan Attendance</title>
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
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Attendance &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Scan Attendance</h1>
                        <p class="text-secondary meta-copy mb-0">Scan each youth's KK ID card to mark them present. This is the recommended way to take attendance.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="<?php echo e($linkBase); ?>events.php"><i class="bi bi-arrow-left me-1"></i> Events</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <form method="get" action="scan.php" class="docket-panel mb-4">
                <div class="section-heading"><h2>Event</h2><span class="section-note"><?php echo count($manageable); ?> open for attendance</span></div>
                <?php if (empty($manageable)): ?>
                    <div class="text-center text-secondary py-4">
                        <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                        No event is currently open for attendance. An event accepts attendance once it is <strong>Confirmed</strong> or <strong>Ongoing</strong>.
                    </div>
                <?php else: ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="event_id" class="form-label">Taking attendance for</label>
                            <select class="form-select" id="event_id" name="event_id" onchange="this.form.submit();">
                                <option value="">— Select an event —</option>
                                <?php foreach ($manageable as $ev): ?>
                                    <option value="<?php echo (int) $ev['id']; ?>" <?php echo (int) $ev['id'] === $eventId ? 'selected' : ''; ?>>
                                        <?php echo e((string) $ev['title']); ?> — <?php echo e($ev['event_date'] ? date('M j, Y', strtotime((string) $ev['event_date'])) : 'TBA'); ?> (<?php echo e(ucfirst((string) $ev['status'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Switch</button>
                            <?php if ($eventId > 0): ?>
                                <a class="btn btn-outline-primary w-100" href="event_qr.php?id=<?php echo (int) $eventId; ?>"><i class="bi bi-qr-code me-1"></i>Event QR</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($event !== null && $attendanceOpen): ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-person-check"></i></span>
                            <span class="ledger-tag">Present</span>
                        </div>
                        <div class="ledger-value tabular" id="statAttended"><?php echo (int) $summary['attended']; ?></div>
                        <div class="ledger-caption">Marked present</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-teal">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-check2-circle"></i></span>
                            <span class="ledger-tag">Finalized</span>
                        </div>
                        <div class="ledger-value tabular" id="statFinalized"><?php echo (int) $summary['finalized']; ?></div>
                        <div class="ledger-caption">Evaluation submitted</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-amber">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span class="ledger-tag">Awaiting</span>
                        </div>
                        <div class="ledger-value tabular" id="statPending"><?php echo (int) $summary['pending_evaluation']; ?></div>
                        <div class="ledger-caption">Evaluation pending</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="ledger-card accent-rust">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-phone"></i></span>
                            <span class="ledger-tag">Self-scan</span>
                        </div>
                        <div class="ledger-value tabular" id="statSelf"><?php echo (int) $summary['self_scan']; ?></div>
                        <div class="ledger-caption">Used the fallback QR</div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="docket-panel">
                        <div class="section-heading">
                            <h2>Scanner</h2>
                            <span class="section-note"><?php echo e((string) $event['title']); ?></span>
                        </div>

                        <div id="cameraUnavailable" class="alert alert-warning d-none" role="alert">
                            <i class="bi bi-camera-video-off me-1"></i><span id="cameraUnavailableMsg"></span>
                        </div>

                        <div id="scanResult" class="d-none" role="status" aria-live="polite"></div>

                        <div class="scan-viewport mb-3" id="reader"></div>

                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-sked" id="btnStart"><i class="bi bi-camera me-1"></i> Start camera</button>
                            <button type="button" class="btn btn-outline-secondary d-none" id="btnStop"><i class="bi bi-stop-circle me-1"></i> Stop</button>
                        </div>

                        <hr>

                        <label for="manualCode" class="form-label fw-semibold">Manual entry <span class="text-secondary fw-normal">— type the KK ID printed on the card</span></label>
                        <form class="d-flex gap-2" id="manualForm" autocomplete="off">
                            <input type="text" class="form-control" id="manualCode" placeholder="e.g. KK-2026-001208" maxlength="40">
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Mark</button>
                        </form>
                        <div class="form-text">Works without a camera — use this if scanning is unavailable.</div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Live feed</h2><span class="section-note">This session</span></div>
                        <div class="scan-feed" id="scanFeed">
                            <div class="text-secondary small py-3" id="feedEmpty">Scans will appear here as you go.</div>
                        </div>
                    </div>

                    <div class="docket-panel mt-4">
                        <div class="section-heading"><h2>Earlier scans</h2><span class="section-note"><?php echo count($recentScans); ?></span></div>
                        <?php if (empty($recentScans)): ?>
                            <div class="text-secondary small py-3">No scans recorded for this event yet.</div>
                        <?php else: ?>
                            <div class="scan-feed">
                                <?php foreach ($recentScans as $s): ?>
                                    <?php
                                        $cls = $s['result'] === 'marked' ? 'scan-ok' : ($s['result'] === 'duplicate' ? 'scan-dup' : 'scan-err');
                                        $who = !empty($s['name']) ? (string) $s['name'] : 'Unknown code';
                                    ?>
                                    <div class="scan-feed-item <?php echo $cls; ?>">
                                        <span class="scan-dot"></span>
                                        <div>
                                            <div class="fw-semibold"><?php echo e($who); ?></div>
                                            <div class="small text-secondary">
                                                <?php echo e(ucfirst((string) $s['result'])); ?> &middot; <?php echo e(str_replace('_', ' ', (string) $s['method'])); ?>
                                                &middot; <?php echo e(date('g:i A', strtotime((string) $s['scanned_at']))); ?>
                                                <?php if (!empty($s['reason'])): ?><br><?php echo e((string) $s['reason']); ?><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php elseif ($event !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Attendance is closed for this event (status: <strong><?php echo e(ucfirst((string) $event['status'])); ?></strong>).
                    Move it to <strong>Confirmed</strong> or <strong>Ongoing</strong> on the
                    <a href="event.php?id=<?php echo (int) $event['id']; ?>">event page</a> to start scanning.
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($event !== null && $attendanceOpen): ?>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="../../js/qr-attendance.js"></script>
    <script>
        (function () {
            var feed = document.getElementById('scanFeed');
            var feedEmpty = document.getElementById('feedEmpty');
            var btnStart = document.getElementById('btnStart');
            var btnStop = document.getElementById('btnStop');
            var scanResult = document.getElementById('scanResult');

            function pushFeed(data) {
                if (feedEmpty) { feedEmpty.remove(); feedEmpty = null; }
                var cls = data.result === 'marked' ? 'scan-ok' : (data.result === 'duplicate' ? 'scan-dup' : 'scan-err');
                var row = document.createElement('div');
                row.className = 'scan-feed-item ' + cls;
                var dot = document.createElement('span');
                dot.className = 'scan-dot';
                var body = document.createElement('div');
                var title = document.createElement('div');
                title.className = 'fw-semibold';
                title.textContent = data.youth_name || (data.result === 'marked' ? 'Marked' : 'Not accepted');
                var sub = document.createElement('div');
                sub.className = 'small text-secondary';
                sub.textContent = (data.kk_id_no ? data.kk_id_no + ' · ' : '') + new Date().toLocaleTimeString();
                body.appendChild(title);
                body.appendChild(sub);
                row.appendChild(dot);
                row.appendChild(body);
                feed.prepend(row);

                if (data.summary) {
                    document.getElementById('statAttended').textContent = data.summary.attended;
                    document.getElementById('statFinalized').textContent = data.summary.finalized;
                    document.getElementById('statPending').textContent = data.summary.pending_evaluation;
                    document.getElementById('statSelf').textContent = data.summary.self_scan;
                }
            }

            var scanner = new SkedScanner({
                mount: 'reader',
                action: 'officer_scan',
                eventId: <?php echo (int) $eventId; ?>,
                csrf: <?php echo json_encode((string) $_SESSION['csrf_attendance_token']); ?>,
                api: '../api/attendance.php',
                onResult: function (data) {
                    SkedScanner.renderResult(scanResult, data);
                    pushFeed(data);
                },
                onStateChange: function (running) {
                    btnStart.classList.toggle('d-none', running);
                    btnStop.classList.toggle('d-none', !running);
                }
            });

            if (!SkedScanner.cameraAvailable()) {
                var box = document.getElementById('cameraUnavailable');
                document.getElementById('cameraUnavailableMsg').textContent = SkedScanner.unavailableReason();
                box.classList.remove('d-none');
                btnStart.disabled = true;
            }

            btnStart.addEventListener('click', function () { scanner.start(); });
            btnStop.addEventListener('click', function () { scanner.stop(); });

            document.getElementById('manualForm').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var input = document.getElementById('manualCode');
                var val = input.value.trim();
                if (val === '') { return; }
                scanner.submit(val);
                input.value = '';
                input.focus();
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
