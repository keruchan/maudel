<?php
/**
 * ============================================================
 * File     : includes/katitikan.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Katitikan (Minutes of the Meeting) tracker (P13), matching
 *            the real Brgy. J.P. Rizal SK regular-session minutes sample —
 *            attendance roll (present/absent), the fixed roman-numeral
 *            sections (Call to Order, Invocation, Roll Call, Reading &
 *            Approval, Privilege Hour, Calendar of Business [Unfinished/
 *            Agenda/New Business], Committee Reports, Announcements,
 *            Adjournment), and a Secretary's Certification + Approved-By
 *            signature block.
 *
 * SK-only create/edit (own barangay), matching every other SK-authored
 * planning document in this app (charters/CBYDP/ABYIP). Once finalized,
 * the SK may submit it to DILG — this creates a linked row in the existing
 * `reports` table (type='minutes', already defined in P7) so it appears in
 * DILG's existing Reports queue, with a link back to the full structured
 * record here.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/barangays.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/sk_members.php';

/** Formats a TIME string ("20:40:00") the way Filipino formal minutes phrase it: "8:40 o'clock in the evening". */
function sked_format_time_filipino(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('H:i:s', strlen($time) === 5 ? $time . ':00' : $time);
    if ($dt === false) {
        return '';
    }
    $hour24 = (int) $dt->format('G');
    $period = $hour24 < 12 ? 'in the morning' : ($hour24 < 18 ? 'in the afternoon' : 'in the evening');
    return $dt->format('g:i') . " o'clock " . $period;
}

/** Create a new Katitikan shell for the creator's barangay. SK-only. */
function sked_katitikan_create(array $creator, array $data): array
{
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    $errors = [];
    if ($barangayId <= 0) {
        $errors[] = 'Your account is not assigned to a barangay.';
    }
    $sessionNo = trim((string) ($data['session_no'] ?? ''));
    if ($sessionNo === '') {
        $errors[] = 'Session number is required.';
    }
    $seriesYear = (int) ($data['series_year'] ?? 0);
    if ($seriesYear < 2020 || $seriesYear > 2100) {
        $errors[] = 'Please enter a valid series year.';
    }
    $meetingDate = trim((string) ($data['meeting_date'] ?? ''));
    if ($meetingDate === '' || DateTime::createFromFormat('!Y-m-d', $meetingDate) === false) {
        $errors[] = 'A valid meeting date is required.';
    }
    $meetingTime = trim((string) ($data['meeting_time'] ?? ''));
    if ($meetingTime === '' || DateTime::createFromFormat('H:i', $meetingTime) === false) {
        $errors[] = 'A valid meeting time is required.';
    }
    $venue = trim((string) ($data['venue'] ?? '')) ?: 'Barangay Session Hall';

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO katitikan
                (barangay_id, session_no, series_year, meeting_date, meeting_time, venue,
                 presiding_officer, prepared_by_name, approved_by_name, created_by)
             VALUES
                (:bgy, :session_no, :series_year, :meeting_date, :meeting_time, :venue,
                 :presiding, :prepared, :approved, :created_by)'
        );
        $stmt->execute([
            'bgy' => $barangayId,
            'session_no' => $sessionNo,
            'series_year' => $seriesYear,
            'meeting_date' => $meetingDate,
            'meeting_time' => $meetingTime . ':00',
            'venue' => $venue,
            'presiding' => trim((string) ($data['presiding_officer'] ?? '')) ?: ((string) ($creator['name'] ?? '') ?: null),
            'prepared' => trim((string) ($data['prepared_by_name'] ?? '')) ?: null,
            'approved' => (string) ($creator['name'] ?? '') ?: null,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ["Session No. {$sessionNo}, Series of {$seriesYear} already exists for your barangay."]];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'katitikan_id' => (int) sked_db()->lastInsertId()];
}

/** A record by id, barangay-scoped when $barangayId is given. */
function sked_katitikan_get(int $id, ?int $barangayId = null): ?array
{
    if ($id <= 0) {
        return null;
    }
    $sql = 'SELECT * FROM katitikan WHERE id = :id';
    $params = ['id' => $id];
    if ($barangayId !== null) {
        $sql .= ' AND barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }
    $stmt = sked_db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    $aStmt = sked_db()->prepare(
        'SELECT ka.*, so.position AS roster_position, so.committee AS roster_committee, so.status AS roster_status
           FROM katitikan_attendees ka
      LEFT JOIN sk_officials so ON so.id = ka.sk_official_id
          WHERE ka.katitikan_id = :k
          ORDER BY ka.sort_order ASC, ka.id ASC'
    );
    $aStmt->execute(['k' => $id]);
    $row['attendees'] = $aStmt->fetchAll();

    $pStmt = sked_db()->prepare('SELECT * FROM katitikan_privilege_items WHERE katitikan_id = :k ORDER BY sort_order ASC, id ASC');
    $pStmt->execute(['k' => $id]);
    $row['privilege_items'] = $pStmt->fetchAll();

    $agStmt = sked_db()->prepare('SELECT * FROM katitikan_agenda_items WHERE katitikan_id = :k ORDER BY sort_order ASC, id ASC');
    $agStmt->execute(['k' => $id]);
    $agendaItems = $agStmt->fetchAll();
    $row['agenda_unfinished'] = array_values(array_filter($agendaItems, static fn($r) => $r['category'] === 'unfinished'));
    $row['agenda_agenda'] = array_values(array_filter($agendaItems, static fn($r) => $r['category'] === 'agenda'));
    $row['agenda_new'] = array_values(array_filter($agendaItems, static fn($r) => $r['category'] === 'new'));

    $row['barangay_name'] = sked_barangay_name((int) $row['barangay_id']);
    return $row;
}

/** All Katitikan for a barangay, newest meeting first. */
function sked_katitikan_list_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM katitikan WHERE barangay_id = :bgy ORDER BY meeting_date DESC, id DESC');
    $stmt->execute(['bgy' => $barangayId]);
    return $stmt->fetchAll();
}

/** All Katitikan municipality-wide, for DILG oversight. */
function sked_katitikan_list_all(): array
{
    $stmt = sked_db()->query(
        'SELECT k.*, b.name AS barangay_name FROM katitikan k
           JOIN barangays b ON b.id = k.barangay_id
          ORDER BY k.meeting_date DESC, k.id DESC'
    );
    return $stmt->fetchAll();
}

/**
 * Update the fixed narrative fields (everything except attendees/privilege/
 * agenda items, which have their own add/delete functions below).
 * Barangay-scoped.
 */
function sked_katitikan_update_fields(int $id, int $actorBarangayId, array $data): array
{
    $k = sked_katitikan_get($id, $actorBarangayId);
    if ($k === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }

    $adjournmentTime = trim((string) ($data['adjournment_time'] ?? ''));
    if ($adjournmentTime !== '' && DateTime::createFromFormat('H:i', $adjournmentTime) === false) {
        return ['ok' => false, 'errors' => ['Invalid adjournment time.']];
    }

    $stmt = sked_db()->prepare(
        'UPDATE katitikan SET
            presiding_officer = :presiding, invocation_by = :invocation,
            roll_call_notes = :roll_call, minutes_reading_notes = :minutes_reading,
            committee_reports = :committee, announcements = :announcements,
            adjournment_time = :adjournment_time, adjourned_by = :adjourned_by,
            prepared_by_name = :prepared_by_name
          WHERE id = :id AND barangay_id = :bgy'
    );
    $stmt->execute([
        'presiding' => trim((string) ($data['presiding_officer'] ?? '')) ?: null,
        'invocation' => trim((string) ($data['invocation_by'] ?? '')) ?: null,
        'roll_call' => trim((string) ($data['roll_call_notes'] ?? '')) ?: null,
        'minutes_reading' => trim((string) ($data['minutes_reading_notes'] ?? '')) ?: null,
        'committee' => trim((string) ($data['committee_reports'] ?? '')) ?: null,
        'announcements' => trim((string) ($data['announcements'] ?? '')) ?: null,
        'adjournment_time' => $adjournmentTime !== '' ? $adjournmentTime . ':00' : null,
        'adjourned_by' => trim((string) ($data['adjourned_by'] ?? '')) ?: null,
        'prepared_by_name' => trim((string) ($data['prepared_by_name'] ?? '')) ?: null,
        'id' => $id,
        'bgy' => $actorBarangayId,
    ]);

    return ['ok' => true, 'errors' => []];
}

/** Add an attendee (present or absent). Barangay-scoped. */
function sked_katitikan_add_attendee(int $id, int $actorBarangayId, string $name, string $designation, string $status, ?int $officialId = null): array
{
    if (sked_katitikan_get($id, $actorBarangayId) === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }
    if ($officialId !== null && $officialId > 0) {
        $official = sked_sk_official_get($officialId, $actorBarangayId);
        if ($official === null || (string) $official['status'] !== 'active') {
            return ['ok' => false, 'errors' => ['Selected SK official is not active in your barangay roster.']];
        }
        $name = (string) $official['full_name'];
        $designation = (string) $official['position'];
    }
    $name = trim($name);
    $designation = trim($designation);
    if ($name === '' || $designation === '') {
        return ['ok' => false, 'errors' => ['Name and designation are required.']];
    }
    if (!in_array($status, ['present', 'absent'], true)) {
        return ['ok' => false, 'errors' => ['Invalid attendance status.']];
    }

    $countStmt = sked_db()->prepare('SELECT COUNT(*) FROM katitikan_attendees WHERE katitikan_id = :k');
    $countStmt->execute(['k' => $id]);
    $nextOrder = (int) $countStmt->fetchColumn();

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO katitikan_attendees (katitikan_id, sk_official_id, name, designation, attendance_status, sort_order)
             VALUES (:k, :official_id, :name, :designation, :status, :order)'
        );
        $stmt->execute([
            'k' => $id,
            'official_id' => $officialId !== null && $officialId > 0 ? $officialId : null,
            'name' => $name,
            'designation' => $designation,
            'status' => $status,
            'order' => $nextOrder,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ['That SK official is already in this attendance roll.']];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => []];
}

/** Add selected active SK roster members to a Katitikan attendance roll. */
function sked_katitikan_add_official_attendees(int $id, int $actorBarangayId, array $officialIds, string $status): array
{
    if (!in_array($status, ['present', 'absent'], true)) {
        return ['ok' => false, 'errors' => ['Invalid attendance status.']];
    }
    if (sked_katitikan_get($id, $actorBarangayId) === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }

    $officialIds = array_values(array_unique(array_filter(array_map('intval', $officialIds), static fn($v) => $v > 0)));
    if (empty($officialIds)) {
        return ['ok' => false, 'errors' => ['Select at least one SK official.']];
    }

    $added = 0;
    $skipped = 0;
    foreach ($officialIds as $officialId) {
        $r = sked_katitikan_add_attendee($id, $actorBarangayId, '', '', $status, $officialId);
        if ($r['ok']) {
            $added++;
        } else {
            $skipped++;
        }
    }

    if ($added === 0 && $skipped > 0) {
        return ['ok' => false, 'errors' => ['Selected officials are already in the attendance roll.']];
    }

    return ['ok' => true, 'errors' => [], 'added' => $added, 'skipped' => $skipped];
}

/** Remove an attendee row. Barangay-scoped. */
function sked_katitikan_delete_attendee(int $attendeeId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE ka FROM katitikan_attendees ka
           JOIN katitikan k ON k.id = ka.katitikan_id
          WHERE ka.id = :a AND k.barangay_id = :bgy'
    );
    $stmt->execute(['a' => $attendeeId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0 ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['Attendee not found.']];
}

/** Add a Privilege Hour proposal. Barangay-scoped. */
function sked_katitikan_add_privilege_item(int $id, int $actorBarangayId, string $speakerName, string $proposal): array
{
    if (sked_katitikan_get($id, $actorBarangayId) === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }
    $proposal = trim($proposal);
    if ($proposal === '') {
        return ['ok' => false, 'errors' => ['Proposal text is required.']];
    }

    $countStmt = sked_db()->prepare('SELECT COUNT(*) FROM katitikan_privilege_items WHERE katitikan_id = :k');
    $countStmt->execute(['k' => $id]);
    $nextOrder = (int) $countStmt->fetchColumn();

    $stmt = sked_db()->prepare(
        'INSERT INTO katitikan_privilege_items (katitikan_id, speaker_name, proposal, sort_order) VALUES (:k, :speaker, :proposal, :order)'
    );
    $stmt->execute(['k' => $id, 'speaker' => trim($speakerName) ?: null, 'proposal' => $proposal, 'order' => $nextOrder]);

    return ['ok' => true, 'errors' => []];
}

/** Remove a Privilege Hour item. Barangay-scoped. */
function sked_katitikan_delete_privilege_item(int $itemId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE kp FROM katitikan_privilege_items kp
           JOIN katitikan k ON k.id = kp.katitikan_id
          WHERE kp.id = :i AND k.barangay_id = :bgy'
    );
    $stmt->execute(['i' => $itemId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0 ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['Item not found.']];
}

/** Add a Calendar of Business item (unfinished / agenda / new business). Barangay-scoped. */
function sked_katitikan_add_agenda_item(int $id, int $actorBarangayId, string $category, string $description): array
{
    if (sked_katitikan_get($id, $actorBarangayId) === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }
    if (!in_array($category, ['unfinished', 'agenda', 'new'], true)) {
        return ['ok' => false, 'errors' => ['Invalid category.']];
    }
    $description = trim($description);
    if ($description === '') {
        return ['ok' => false, 'errors' => ['Description is required.']];
    }

    $countStmt = sked_db()->prepare('SELECT COUNT(*) FROM katitikan_agenda_items WHERE katitikan_id = :k AND category = :c');
    $countStmt->execute(['k' => $id, 'c' => $category]);
    $nextOrder = (int) $countStmt->fetchColumn();

    $stmt = sked_db()->prepare(
        'INSERT INTO katitikan_agenda_items (katitikan_id, category, description, sort_order) VALUES (:k, :c, :d, :order)'
    );
    $stmt->execute(['k' => $id, 'c' => $category, 'd' => $description, 'order' => $nextOrder]);

    return ['ok' => true, 'errors' => []];
}

/** Remove a Calendar of Business item. Barangay-scoped. */
function sked_katitikan_delete_agenda_item(int $itemId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE ka FROM katitikan_agenda_items ka
           JOIN katitikan k ON k.id = ka.katitikan_id
          WHERE ka.id = :i AND k.barangay_id = :bgy'
    );
    $stmt->execute(['i' => $itemId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0 ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['Item not found.']];
}

/** Toggle draft/finalized. Barangay-scoped. */
function sked_katitikan_set_status(int $id, int $actorBarangayId, string $status): array
{
    if (!in_array($status, ['draft', 'finalized'], true)) {
        return ['ok' => false, 'errors' => ['Invalid status.']];
    }
    $stmt = sked_db()->prepare('UPDATE katitikan SET status = :s WHERE id = :id AND barangay_id = :bgy');
    $stmt->execute(['s' => $status, 'id' => $id, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0 ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['Record not found.']];
}

/**
 * Submit a finalized Katitikan to DILG: creates a linked row in the
 * existing `reports` table (type='minutes') and stamps katitikan.report_id.
 * Barangay-scoped; a record can only be submitted once.
 */
function sked_katitikan_submit_to_dilg(array $submitter, int $id): array
{
    $barangayId = (int) ($submitter['barangay_id'] ?? 0);
    $k = sked_katitikan_get($id, $barangayId);
    if ($k === null) {
        return ['ok' => false, 'errors' => ['Record not found.']];
    }
    if (!empty($k['report_id'])) {
        return ['ok' => false, 'errors' => ['This record has already been submitted to DILG.']];
    }
    if ($k['status'] !== 'finalized') {
        return ['ok' => false, 'errors' => ['Finalize the minutes before submitting.']];
    }

    $title = 'Minutes — Session No. ' . $k['session_no'] . ', Series of ' . $k['series_year'] . ' (' . $k['barangay_name'] . ')';
    $content = 'Regular session held ' . date('F j, Y', strtotime((string) $k['meeting_date']))
        . ' at ' . sked_format_time_filipino((string) $k['meeting_time']) . ', ' . $k['venue'] . '.';

    $r = sked_submit_report($submitter, 'minutes', $title, $content);
    if (!$r['ok']) {
        return $r;
    }

    sked_db()->prepare('UPDATE katitikan SET report_id = :r WHERE id = :id')
        ->execute(['r' => $r['report_id'], 'id' => $id]);
    sked_db()->prepare('UPDATE reports SET katitikan_id = :k WHERE id = :r')
        ->execute(['k' => $id, 'r' => $r['report_id']]);

    return ['ok' => true, 'errors' => [], 'report_id' => $r['report_id']];
}
