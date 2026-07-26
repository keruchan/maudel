<?php
/**
 * ============================================================
 * File     : includes/attendance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : QR-based event attendance (P19).
 *
 * Two capture directions, deliberately not equal:
 *
 *   1. OFFICER SCAN (primary, always available) — the SK/PPSK official
 *      scans the youth's personal QR (their KK ID card). The official is
 *      physically present and authenticated, so this is the trustworthy
 *      path and needs no per-event opt-in.
 *
 *   2. SELF SCAN (fallback, opt-in per event) — the official displays or
 *      prints the event's QR and youth scan it themselves. Anyone who can
 *      see the poster can scan it, so this is weaker: it is OFF by default
 *      and an official must switch it on for a specific event (spec: "use
 *      the 2nd way only if the 1st way fails"). Every self-scan is logged
 *      with its method so a roster can be audited afterwards.
 *
 * Walk-ins: an event of type 'interested' (a seminar/assembly with no
 * limited slots) admits youth who never signed up — scanning them in also
 * enrols them. An event of type 'register' has finite capacity, so an
 * unregistered scan is rejected rather than silently over-filling it.
 *
 * Finalization: attendance is recorded immediately, but is only "final"
 * once the youth submits the event evaluation. That state is DERIVED from
 * event_evaluations rather than stored (same rule as P5 engagement levels
 * and P6 charter success rates — no duplicated truth).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

/** QR payload prefixes — let one scanner tell the two token families apart. */
const SKED_QR_PREFIX_YOUTH = 'SKEDY:';
const SKED_QR_PREFIX_EVENT = 'SKEDE:';

/** Event statuses during which attendance may be captured. */
const SKED_ATTENDANCE_OPEN_STATUSES = ['confirmed', 'ongoing', 'completed', 'evaluation'];

/* ============================================================
 * Token + ID issuance
 * ============================================================ */

/**
 * Ensure a youth has a KK ID number and QR token, issuing them on first
 * use. Returns null for demo accounts (ids < 1000 are a no-DB shortcut).
 *
 * @return array{kk_id_no:string,qr_token:string}|null
 */
function sked_ensure_youth_qr(int $userId): ?array
{
    if ($userId < 1000) {
        return null;
    }

    $stmt = sked_db()->prepare("SELECT id, kk_id_no, qr_token, created_at FROM users WHERE id = :id AND role = 'youth' LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    $kkId = (string) ($row['kk_id_no'] ?? '');
    $token = (string) ($row['qr_token'] ?? '');
    if ($kkId !== '' && $token !== '') {
        return ['kk_id_no' => $kkId, 'qr_token' => $token];
    }

    // Deterministic, human-readable, and unique because users.id is.
    if ($kkId === '') {
        $year = !empty($row['created_at']) ? date('Y', strtotime((string) $row['created_at'])) : date('Y');
        $kkId = 'KK-' . $year . '-' . str_pad((string) $userId, 6, '0', STR_PAD_LEFT);
    }
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
    }

    $upd = sked_db()->prepare(
        'UPDATE users SET kk_id_no = :kk, qr_token = :tok, qr_issued_at = NOW() WHERE id = :id'
    );
    $upd->execute(['kk' => $kkId, 'tok' => $token, 'id' => $userId]);

    return ['kk_id_no' => $kkId, 'qr_token' => $token];
}

/** Ensure an event has an attendance token (issued lazily on first display). */
function sked_ensure_event_attendance_token(int $eventId): ?string
{
    if ($eventId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT attendance_token FROM events WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $eventId]);
    $existing = $stmt->fetchColumn();
    if ($existing === false) {
        return null;
    }
    if (!empty($existing)) {
        return (string) $existing;
    }

    $token = bin2hex(random_bytes(16));
    sked_db()->prepare('UPDATE events SET attendance_token = :t WHERE id = :id')
        ->execute(['t' => $token, 'id' => $eventId]);
    return $token;
}

/** Turn the self-scan fallback on/off for one event. Officials only (caller authorizes). */
function sked_set_event_self_scan(int $eventId, bool $enabled, int $actorId): bool
{
    if ($enabled) {
        sked_ensure_event_attendance_token($eventId);
    }
    $stmt = sked_db()->prepare('UPDATE events SET self_scan_enabled = :e WHERE id = :id');
    $stmt->execute(['e' => $enabled ? 1 : 0, 'id' => $eventId]);
    sked_audit($actorId, $enabled ? 'event_self_scan_enabled' : 'event_self_scan_disabled', 'event', $eventId, '');
    return true;
}

/** The full string encoded into a youth's QR image. */
function sked_qr_payload_youth(string $qrToken): string
{
    return SKED_QR_PREFIX_YOUTH . $qrToken;
}

/** The full string encoded into an event's QR image. */
function sked_qr_payload_event(string $attendanceToken): string
{
    return SKED_QR_PREFIX_EVENT . $attendanceToken;
}

/**
 * Normalize whatever the scanner (or a typing official) produced.
 *
 * Accepts the prefixed payloads, a bare 32-hex token, or a typed KK ID
 * number — manual entry has to work when a camera is unavailable.
 *
 * @return array{kind:string,value:string} kind = youth|event|kk_id|unknown
 */
function sked_parse_qr_payload(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['kind' => 'unknown', 'value' => ''];
    }

    if (stripos($raw, SKED_QR_PREFIX_YOUTH) === 0) {
        return ['kind' => 'youth', 'value' => substr($raw, strlen(SKED_QR_PREFIX_YOUTH))];
    }
    if (stripos($raw, SKED_QR_PREFIX_EVENT) === 0) {
        return ['kind' => 'event', 'value' => substr($raw, strlen(SKED_QR_PREFIX_EVENT))];
    }
    if (preg_match('/^KK-\d{4}-\d{4,8}$/i', $raw)) {
        return ['kind' => 'kk_id', 'value' => strtoupper($raw)];
    }
    if (preg_match('/^[a-f0-9]{32}$/i', $raw)) {
        return ['kind' => 'youth', 'value' => strtolower($raw)];
    }

    return ['kind' => 'unknown', 'value' => $raw];
}

/** Look up a youth by QR token. */
function sked_youth_by_qr_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
        return null;
    }
    $stmt = sked_db()->prepare(
        "SELECT id, name, username, status, barangay_id, kk_id_no FROM users
          WHERE qr_token = :t AND role = 'youth' LIMIT 1"
    );
    $stmt->execute(['t' => strtolower($token)]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Look up a youth by their printed KK ID number (manual-entry fallback). */
function sked_youth_by_kk_id(string $kkId): ?array
{
    $stmt = sked_db()->prepare(
        "SELECT id, name, username, status, barangay_id, kk_id_no FROM users
          WHERE kk_id_no = :k AND role = 'youth' LIMIT 1"
    );
    $stmt->execute(['k' => strtoupper(trim($kkId))]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Look up an event by its attendance token. */
function sked_event_by_attendance_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT id FROM events WHERE attendance_token = :t LIMIT 1');
    $stmt->execute(['t' => strtolower($token)]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : sked_get_event((int) $id);
}

/* ============================================================
 * Scan audit log
 * ============================================================ */

/** Record every scan attempt — including rejects, so a roster can be audited. */
function sked_log_attendance_scan(int $eventId, ?int $userId, string $method, string $result, string $reason, ?int $scannedBy, ?string $scannedByRole): void
{
    $stmt = sked_db()->prepare(
        'INSERT INTO attendance_scans (event_id, user_id, method, result, reason, scanned_by, scanned_by_role)
         VALUES (:e, :u, :m, :r, :reason, :by, :role)'
    );
    $stmt->execute([
        'e' => $eventId,
        'u' => $userId !== null && $userId > 0 ? $userId : null,
        'm' => $method,
        'r' => $result,
        'reason' => $reason !== '' ? mb_substr($reason, 0, 160) : null,
        'by' => $scannedBy !== null && $scannedBy > 0 ? $scannedBy : null,
        'role' => $scannedByRole,
    ]);
}

/** Recent scan attempts for an event, newest first. */
function sked_recent_attendance_scans(int $eventId, int $limit = 25): array
{
    $limit = max(1, min(200, $limit));
    $stmt = sked_db()->prepare(
        "SELECT s.*, u.name, u.kk_id_no
           FROM attendance_scans s
      LEFT JOIN users u ON u.id = s.user_id
          WHERE s.event_id = :e
          ORDER BY s.scanned_at DESC, s.id DESC
          LIMIT $limit"
    );
    $stmt->execute(['e' => $eventId]);
    return $stmt->fetchAll();
}

/* ============================================================
 * Marking attendance
 * ============================================================ */

/**
 * Shared core for both scan directions: validate the youth against the
 * event, enrol walk-ins where the event allows it, then mark them present.
 *
 * @return array{ok:bool,result:string,message:string,youth_name?:string,kk_id_no?:string}
 */
function sked_attendance_mark(array $event, array $youth, string $method, ?int $actorId, ?string $actorRole): array
{
    $eventId = (int) $event['id'];
    $youthId = (int) $youth['id'];
    $youthName = (string) $youth['name'];

    $fail = static function (string $reason) use ($eventId, $youthId, $method, $actorId, $actorRole): array {
        sked_log_attendance_scan($eventId, $youthId, $method, 'rejected', $reason, $actorId, $actorRole);
        return ['ok' => false, 'result' => 'rejected', 'message' => $reason];
    };

    if (!in_array((string) $event['status'], SKED_ATTENDANCE_OPEN_STATUSES, true)) {
        return $fail('Attendance is not open for this event (status: ' . $event['status'] . ').');
    }
    if ((string) $youth['status'] !== 'active') {
        return $fail($youthName . ' is not a verified KK member yet.');
    }

    $youthBarangayId = (int) ($youth['barangay_id'] ?? 0);
    if ($youthBarangayId <= 0 || !sked_event_in_scope_for_barangay($event, $youthBarangayId)) {
        return $fail($youthName . ' is not covered by this event\'s scope.');
    }

    // Current participation, if any.
    $partStmt = sked_db()->prepare('SELECT status FROM event_participants WHERE event_id = :e AND user_id = :u LIMIT 1');
    $partStmt->execute(['e' => $eventId, 'u' => $youthId]);
    $current = $partStmt->fetchColumn();

    if ($current === 'attended') {
        sked_log_attendance_scan($eventId, $youthId, $method, 'duplicate', 'Already marked present', $actorId, $actorRole);
        return [
            'ok' => true,
            'result' => 'duplicate',
            'message' => $youthName . ' is already marked present.',
            'youth_name' => $youthName,
            'kk_id_no' => (string) ($youth['kk_id_no'] ?? ''),
        ];
    }

    $isEnrolled = $current !== false && $current !== 'cancelled';
    if (!$isEnrolled) {
        // Walk-in: only open-join events (seminars/assemblies) admit them.
        if ((string) $event['type'] === 'register') {
            return $fail($youthName . ' is not registered for this limited-slot event.');
        }
        if ($current === 'cancelled') {
            sked_db()->prepare("UPDATE event_participants SET status = 'interested', joined_at = NOW() WHERE event_id = :e AND user_id = :u")
                ->execute(['e' => $eventId, 'u' => $youthId]);
        } else {
            sked_db()->prepare("INSERT INTO event_participants (event_id, user_id, status) VALUES (:e, :u, 'interested')")
                ->execute(['e' => $eventId, 'u' => $youthId]);
        }
        sked_award_points($youthId, 'event_joined', 'event', $eventId);
    }

    sked_db()->prepare(
        "UPDATE event_participants
            SET status = 'attended', attended_at = NOW(), attendance_method = :m
          WHERE event_id = :e AND user_id = :u"
    )->execute(['m' => $method, 'e' => $eventId, 'u' => $youthId]);

    sked_award_points($youthId, 'event_attended', 'event', $eventId);
    sked_log_attendance_scan($eventId, $youthId, $method, 'marked', $isEnrolled ? '' : 'Walk-in enrolled on scan', $actorId, $actorRole);
    if ($actorId !== null && $actorId > 0 && $actorRole !== 'youth') {
        sked_audit($actorId, 'attendance_scanned', 'event', $eventId, $youthName . ' (' . $method . ')');
    }

    // Nudge the evaluation that finalizes this attendance.
    sked_notify(
        $youthId,
        'event',
        'Attendance recorded: ' . $event['title'],
        'You were marked present at "' . $event['title'] . '". Submit your evaluation to finalize your attendance and earn the evaluation points.',
        '../youth/events.php'
    );

    return [
        'ok' => true,
        'result' => 'marked',
        'message' => $youthName . ' marked present.' . ($isEnrolled ? '' : ' (walk-in enrolled)'),
        'youth_name' => $youthName,
        'kk_id_no' => (string) ($youth['kk_id_no'] ?? ''),
    ];
}

/**
 * PRIMARY FLOW — an official scans a youth's QR (or types their KK ID).
 *
 * @param array{id:int,role:string,barangay_id:?int} $actor
 * @return array{ok:bool,result:string,message:string,...}
 */
function sked_attendance_officer_scan(array $actor, int $eventId, string $rawPayload): array
{
    $role = (string) ($actor['role'] ?? '');
    $actorId = (int) ($actor['id'] ?? 0);
    $actorBarangayId = isset($actor['barangay_id']) ? (int) $actor['barangay_id'] : null;

    $event = sked_get_event($eventId);
    if ($event === null) {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Event not found.'];
    }
    if (!sked_can_manage_event($role, $actorBarangayId, $event)) {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'You are not authorized to take attendance for this event.'];
    }

    $parsed = sked_parse_qr_payload($rawPayload);
    $youth = null;
    if ($parsed['kind'] === 'youth') {
        $youth = sked_youth_by_qr_token($parsed['value']);
    } elseif ($parsed['kind'] === 'kk_id') {
        $youth = sked_youth_by_kk_id($parsed['value']);
    } elseif ($parsed['kind'] === 'event') {
        sked_log_attendance_scan($eventId, null, 'officer_scan', 'rejected', 'Scanned an event QR instead of a youth ID', $actorId, $role);
        return ['ok' => false, 'result' => 'rejected', 'message' => 'That is an event QR, not a youth ID card.'];
    }

    if ($youth === null) {
        sked_log_attendance_scan($eventId, null, 'officer_scan', 'rejected', 'Unrecognized code', $actorId, $role);
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Unrecognized code — no KK member matches it.'];
    }

    $method = $parsed['kind'] === 'kk_id' ? 'manual' : 'officer_scan';
    return sked_attendance_mark($event, $youth, $method, $actorId, $role);
}

/**
 * FALLBACK FLOW — a youth scans the event's QR themselves. Requires the
 * official to have switched self-scan on for that event.
 *
 * @param array{id:int,barangay_id:?int} $youthSession
 */
function sked_attendance_self_scan(array $youthSession, string $rawPayload): array
{
    $youthId = (int) ($youthSession['id'] ?? 0);
    if ($youthId < 1000) {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Demo accounts cannot record real attendance.'];
    }

    $parsed = sked_parse_qr_payload($rawPayload);
    if ($parsed['kind'] === 'youth') {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'That is your own ID card. Scan the event QR shown by your SK.'];
    }
    if ($parsed['kind'] !== 'event') {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Unrecognized code — that is not a SKed event QR.'];
    }

    $event = sked_event_by_attendance_token($parsed['value']);
    if ($event === null) {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Unrecognized code — that is not a SKed event QR.'];
    }

    $eventId = (int) $event['id'];
    if ((int) ($event['self_scan_enabled'] ?? 0) !== 1) {
        sked_log_attendance_scan($eventId, $youthId, 'self_scan', 'rejected', 'Self-scan not enabled for this event', $youthId, 'youth');
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Self check-in is switched off for this event. Please have your SK scan your ID card instead.'];
    }

    $stmt = sked_db()->prepare("SELECT id, name, username, status, barangay_id, kk_id_no FROM users WHERE id = :id AND role = 'youth' LIMIT 1");
    $stmt->execute(['id' => $youthId]);
    $youth = $stmt->fetch();
    if ($youth === false) {
        return ['ok' => false, 'result' => 'rejected', 'message' => 'Your account could not be loaded.'];
    }

    $res = sked_attendance_mark($event, $youth, 'self_scan', $youthId, 'youth');
    $res['event_id'] = $eventId;
    $res['event_title'] = (string) $event['title'];
    return $res;
}

/* ============================================================
 * Finalization (attendance is final once evaluated)
 * ============================================================ */

/** True once the youth has submitted this event's evaluation. */
function sked_attendance_is_finalized(int $eventId, int $userId): bool
{
    $stmt = sked_db()->prepare('SELECT 1 FROM event_evaluations WHERE event_id = :e AND user_id = :u LIMIT 1');
    $stmt->execute(['e' => $eventId, 'u' => $userId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Attended events of a youth that still need an evaluation to be finalized.
 *
 * Restricted to statuses sked_submit_evaluation() will actually accept, so
 * the UI never offers a rating form that the server would then refuse. An
 * event the youth was scanned into while it is still 'ongoing' qualifies —
 * that is the normal QR-attendance case.
 */
function sked_pending_evaluations_for_youth(int $userId): array
{
    if ($userId < 1000) {
        return [];
    }
    $statuses = sked_evaluation_open_statuses_sql();
    $stmt = sked_db()->prepare(
        "SELECT e.id, e.title, e.event_date, e.status, p.attended_at
           FROM event_participants p
           JOIN events e ON e.id = p.event_id
      LEFT JOIN event_evaluations ev ON ev.event_id = e.id AND ev.user_id = p.user_id
          WHERE p.user_id = :u AND p.status = 'attended' AND ev.id IS NULL
            AND e.status IN ($statuses)
          ORDER BY p.attended_at DESC"
    );
    $stmt->execute(['u' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Attendance tallies for one event: how many present, how many of those
 * have finalized via evaluation, and the capture-method split (which shows
 * whether the fallback self-scan path was leaned on).
 */
function sked_attendance_summary(int $eventId): array
{
    $out = [
        'attended' => 0, 'finalized' => 0, 'pending_evaluation' => 0,
        'officer_scan' => 0, 'self_scan' => 0, 'manual' => 0, 'unscanned' => 0,
    ];
    if ($eventId <= 0) {
        return $out;
    }

    $stmt = sked_db()->prepare(
        "SELECT p.attendance_method, (ev.id IS NOT NULL) AS finalized
           FROM event_participants p
      LEFT JOIN event_evaluations ev ON ev.event_id = p.event_id AND ev.user_id = p.user_id
          WHERE p.event_id = :e AND p.status = 'attended'"
    );
    $stmt->execute(['e' => $eventId]);

    foreach ($stmt->fetchAll() as $row) {
        $out['attended']++;
        if ((int) $row['finalized'] === 1) {
            $out['finalized']++;
        }
        $method = (string) ($row['attendance_method'] ?? '');
        if (isset($out[$method])) {
            $out[$method]++;
        } else {
            $out['unscanned']++; // marked by hand on the roster page, pre-QR
        }
    }
    $out['pending_evaluation'] = $out['attended'] - $out['finalized'];

    return $out;
}
