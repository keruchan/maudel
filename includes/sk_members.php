<?php
/**
 * Barangay SK officials roster.
 *
 * Officials are recognition records layered on top of verified youth accounts.
 * It does not change that user's login role; the same account continues using
 * the Youth portal and receives an SK position tag.
 */

require_once __DIR__ . '/../config/database.php';

function sked_sk_positions(): array
{
    return [
        'SK Chairperson',
        'SK Secretary',
        'SK Treasurer',
        'SK Kagawad',
        'SK Auditor',
        'Committee Chairperson',
        'Committee Member',
    ];
}

function sked_sk_position_options(): array
{
    return array_combine(sked_sk_positions(), sked_sk_positions());
}

function sked_sk_official_sort_sql(): string
{
    return "FIELD(position, 'SK Chairperson', 'SK Secretary', 'SK Treasurer', 'SK Auditor', 'SK Kagawad', 'Committee Chairperson', 'Committee Member'), full_name";
}

function sked_sk_official_candidates(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }

    $stmt = sked_db()->prepare(
        "SELECT id, name, username, status, verified
           FROM users
          WHERE barangay_id = :bgy
            AND role = 'youth'
            AND status = 'active'
            AND verified = 1
          ORDER BY name ASC"
    );
    $stmt->execute(['bgy' => $barangayId]);
    return $stmt->fetchAll();
}

/** Municipality-wide count of active SK officials (PPSK/DILG dashboard cards). */
function sked_sk_officials_count(): int
{
    return (int) sked_db()->query("SELECT COUNT(*) FROM sk_officials WHERE status = 'active'")->fetchColumn();
}

function sked_sk_officials_for_barangay(int $barangayId, bool $includeInactive = false): array
{
    if ($barangayId <= 0) {
        return [];
    }

    $whereStatus = $includeInactive ? '' : " AND so.status = 'active'";
    $stmt = sked_db()->prepare(
        "SELECT so.*, u.username, u.role AS account_role, u.status AS account_status, u.verified,
                u.name AS account_name, u.mobile AS account_mobile,
                COALESCE(a.total_meetings, 0) AS total_meetings,
                COALESCE(a.present_count, 0) AS present_count,
                COALESCE(a.absent_count, 0) AS absent_count,
                a.last_attended_at
           FROM sk_officials so
      LEFT JOIN users u ON u.id = so.user_id
      LEFT JOIN (
                SELECT ka.sk_official_id,
                       COUNT(*) AS total_meetings,
                       SUM(CASE WHEN ka.attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
                       SUM(CASE WHEN ka.attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                       MAX(k.meeting_date) AS last_attended_at
                  FROM katitikan_attendees ka
                  JOIN katitikan k ON k.id = ka.katitikan_id
                 WHERE ka.sk_official_id IS NOT NULL
              GROUP BY ka.sk_official_id
            ) a ON a.sk_official_id = so.id
          WHERE so.barangay_id = :bgy{$whereStatus}
          ORDER BY " . sked_sk_official_sort_sql()
    );
    $stmt->execute(['bgy' => $barangayId]);
    return $stmt->fetchAll();
}

function sked_sk_official_get(int $officialId, int $barangayId): ?array
{
    if ($officialId <= 0 || $barangayId <= 0) {
        return null;
    }

    $stmt = sked_db()->prepare('SELECT * FROM sk_officials WHERE id = :id AND barangay_id = :bgy LIMIT 1');
    $stmt->execute(['id' => $officialId, 'bgy' => $barangayId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function sked_sk_official_user_in_scope(int $userId, int $barangayId): ?array
{
    if ($userId <= 0 || $barangayId <= 0) {
        return null;
    }

    $stmt = sked_db()->prepare(
        "SELECT id, name, mobile
           FROM users
          WHERE id = :id
            AND barangay_id = :bgy
            AND role = 'youth'
            AND status = 'active'
            AND verified = 1
          LIMIT 1"
    );
    $stmt->execute(['id' => $userId, 'bgy' => $barangayId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function sked_sk_official_save(int $barangayId, int $actorId, array $data): array
{
    if ($barangayId <= 0) {
        return ['ok' => false, 'errors' => ['Your account is not assigned to a barangay.']];
    }

    $officialId = (int) ($data['official_id'] ?? 0);
    $userId = (int) ($data['user_id'] ?? 0);
    $position = trim((string) ($data['position'] ?? ''));
    $fullName = '';
    $committee = trim((string) ($data['committee'] ?? ''));
    $contactNo = trim((string) ($data['contact_no'] ?? ''));
    $status = trim((string) ($data['status'] ?? 'active'));
    $termStart = trim((string) ($data['term_start'] ?? ''));
    $termEnd = trim((string) ($data['term_end'] ?? ''));
    $errors = [];

    if (!in_array($position, sked_sk_positions(), true)) {
        $errors[] = 'Please select a valid SK position.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid official status.';
    }
    if ($userId <= 0) {
        $errors[] = 'Select the verified youth account for this SK member.';
    }
    if ($termStart !== '' && DateTime::createFromFormat('!Y-m-d', $termStart) === false) {
        $errors[] = 'Invalid term start date.';
    }
    if ($termEnd !== '' && DateTime::createFromFormat('!Y-m-d', $termEnd) === false) {
        $errors[] = 'Invalid term end date.';
    }

    $user = $userId > 0 ? sked_sk_official_user_in_scope($userId, $barangayId) : null;
    if ($userId > 0 && $user === null) {
        $errors[] = 'Selected account must be an active, verified youth account in your barangay.';
    } elseif ($user !== null) {
        $fullName = (string) $user['name'];
        $contactNo = $contactNo !== '' ? $contactNo : (string) ($user['mobile'] ?? '');
    }
    if ($contactNo !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $contactNo)) {
        $errors[] = 'Please enter a valid contact number.';
    }

    if ($officialId > 0 && sked_sk_official_get($officialId, $barangayId) === null) {
        $errors[] = 'Official record not found.';
    }
    if ($userId > 0) {
        $duplicateStmt = sked_db()->prepare(
            'SELECT COUNT(*)
               FROM sk_officials
              WHERE barangay_id = :bgy
                AND user_id = :user_id
                AND id <> :id'
        );
        $duplicateStmt->execute(['bgy' => $barangayId, 'user_id' => $userId, 'id' => $officialId]);
        if ((int) $duplicateStmt->fetchColumn() > 0) {
            $errors[] = 'That verified youth account is already declared as an SK member.';
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    if ($officialId > 0) {
        $stmt = sked_db()->prepare(
            'UPDATE sk_officials
                SET user_id = :user_id, full_name = :full_name, position = :position,
                    committee = :committee, contact_no = :contact_no, status = :status,
                    term_start = :term_start, term_end = :term_end
              WHERE id = :id AND barangay_id = :bgy'
        );
        $stmt->execute([
            'user_id' => $userId > 0 ? $userId : null,
            'full_name' => $fullName,
            'position' => $position,
            'committee' => $committee !== '' ? $committee : null,
            'contact_no' => $contactNo !== '' ? $contactNo : null,
            'status' => $status,
            'term_start' => $termStart !== '' ? $termStart : null,
            'term_end' => $termEnd !== '' ? $termEnd : null,
            'id' => $officialId,
            'bgy' => $barangayId,
        ]);
        return ['ok' => true, 'errors' => [], 'official_id' => $officialId];
    }

    $stmt = sked_db()->prepare(
        'INSERT INTO sk_officials
            (barangay_id, user_id, full_name, position, committee, contact_no, status, term_start, term_end, created_by)
         VALUES
            (:bgy, :user_id, :full_name, :position, :committee, :contact_no, :status, :term_start, :term_end, :created_by)'
    );
    $stmt->execute([
        'bgy' => $barangayId,
        'user_id' => $userId > 0 ? $userId : null,
        'full_name' => $fullName,
        'position' => $position,
        'committee' => $committee !== '' ? $committee : null,
        'contact_no' => $contactNo !== '' ? $contactNo : null,
        'status' => $status,
        'term_start' => $termStart !== '' ? $termStart : null,
        'term_end' => $termEnd !== '' ? $termEnd : null,
        'created_by' => $actorId > 0 ? $actorId : null,
    ]);

    return ['ok' => true, 'errors' => [], 'official_id' => (int) sked_db()->lastInsertId()];
}

function sked_sk_official_set_status(int $officialId, int $barangayId, string $status): array
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'errors' => ['Invalid official status.']];
    }
    if ($status === 'active') {
        $checkStmt = sked_db()->prepare(
            "SELECT so.id
               FROM sk_officials so
               JOIN users u ON u.id = so.user_id
              WHERE so.id = :id
                AND so.barangay_id = :bgy
                AND u.role = 'youth'
                AND u.status = 'active'
                AND u.verified = 1
              LIMIT 1"
        );
        $checkStmt->execute(['id' => $officialId, 'bgy' => $barangayId]);
        if ($checkStmt->fetch() === false) {
            return ['ok' => false, 'errors' => ['This SK member needs a fully verified youth account before being activated.']];
        }
    }

    $stmt = sked_db()->prepare('UPDATE sk_officials SET status = :status WHERE id = :id AND barangay_id = :bgy');
    $stmt->execute(['status' => $status, 'id' => $officialId, 'bgy' => $barangayId]);
    return $stmt->rowCount() > 0 ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['Official record not found.']];
}

function sked_sk_officials_for_user(int $userId): array
{
    if ($userId <= 0 || $userId < 1000) {
        return [];
    }

    $stmt = sked_db()->prepare(
        "SELECT so.*, b.name AS barangay_name
           FROM sk_officials so
           JOIN barangays b ON b.id = so.barangay_id
          WHERE so.user_id = :user_id AND so.status = 'active'
          ORDER BY so.updated_at DESC"
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function sked_sk_official_badge_for_user(int $userId): ?array
{
    $rows = sked_sk_officials_for_user($userId);
    return $rows[0] ?? null;
}

function sked_sk_official_attendance_rate(array $official): ?int
{
    $total = (int) ($official['total_meetings'] ?? 0);
    if ($total <= 0) {
        return null;
    }
    return (int) round(((int) ($official['present_count'] ?? 0) / $total) * 100);
}
