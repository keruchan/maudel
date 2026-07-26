<?php
/**
 * ============================================================
 * File     : includes/verification.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth membership verification (P2). The SK Chairman of a
 *            barangay reviews youth who registered under that barangay
 *            (residency + age 15-30) and either verifies or rejects them.
 *
 * Rules enforced here (not just in the UI):
 *   - An SK can only act on youth in their OWN barangay.
 *   - Only 'pending' youth can be verified/rejected.
 *   - Verify  -> status 'active',  verified 1
 *     Reject  -> status 'rejected', verified 0
 *   - Every action is written to audit_log and notifies the youth.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

/**
 * Youth awaiting verification in a barangay, oldest registration first.
 *
 * @return array<int,array<string,mixed>>
 */
function sked_pending_youth_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare(
        "SELECT id, name, email, mobile, birthdate, username, purok, created_at
           FROM users
          WHERE role = 'youth' AND status = 'pending' AND barangay_id = :bgy
          ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute(['bgy' => $barangayId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['age'] = !empty($row['birthdate']) ? sked_age_from_birthdate((string) $row['birthdate']) : null;
    }
    return $rows;
}

/**
 * All youth (any status except rejected) registered under a barangay, for
 * the SK's "KK Profiling" roster — SK-assisted profiling entry can happen
 * before or after verification (e.g. during a face-to-face profiling
 * drive), so this is intentionally broader than sked_pending_youth_for_barangay().
 *
 * @return array<int,array<string,mixed>>
 */
function sked_youths_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare(
        "SELECT u.id, u.name, u.status, u.email, u.mobile, u.birthdate, u.username, u.purok, u.created_at,
                (yp.user_id IS NOT NULL) AS has_profile
           FROM users u
      LEFT JOIN youth_profiles yp ON yp.user_id = u.id
          WHERE u.role = 'youth' AND u.status IN ('pending','active') AND u.barangay_id = :bgy
          ORDER BY u.name ASC"
    );
    $stmt->execute(['bgy' => $barangayId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['has_profile'] = (bool) $row['has_profile'];
        $row['age'] = !empty($row['birthdate']) ? sked_age_from_birthdate((string) $row['birthdate']) : null;
    }
    return $rows;
}

/**
 * Load a youth (pending or active) that belongs to the given barangay, or
 * null. Guards SK-assisted KK profiling entry to the SK's own barangay.
 */
function sked_youth_in_barangay(int $youthId, int $barangayId): ?array
{
    if ($youthId <= 0 || $barangayId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare(
        "SELECT id, name, status, barangay_id FROM users
          WHERE id = :id AND role = 'youth' AND status IN ('pending','active') AND barangay_id = :bgy
          LIMIT 1"
    );
    $stmt->execute(['id' => $youthId, 'bgy' => $barangayId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * The full KK-member registry for a barangay: every registered youth of ANY
 * status (pending, active/verified, rejected), with profile-completion flag,
 * age, and total participation points. Broader than
 * sked_youths_for_barangay() (which drops rejected) — this is the SK's
 * complete "who is registered in my barangay" directory. Optional status
 * filter for the page's tabs/dropdown.
 *
 * @return array<int,array<string,mixed>>
 */
function sked_kk_members_for_barangay(int $barangayId, string $statusFilter = ''): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $where = "u.role = 'youth' AND u.barangay_id = :bgy";
    $params = ['bgy' => $barangayId];
    if (in_array($statusFilter, ['pending', 'active', 'rejected'], true)) {
        $where .= ' AND u.status = :status';
        $params['status'] = $statusFilter;
    }

    $stmt = sked_db()->prepare(
        "SELECT u.id, u.name, u.status, u.email, u.mobile, u.birthdate, u.sex_assigned_at_birth,
                u.username, u.purok, u.created_at,
                (yp.user_id IS NOT NULL) AS has_profile,
                COALESCE(pts.total, 0) AS points
           FROM users u
      LEFT JOIN youth_profiles yp ON yp.user_id = u.id
      LEFT JOIN (SELECT user_id, SUM(points) AS total FROM points_ledger GROUP BY user_id) pts
             ON pts.user_id = u.id
          WHERE $where
          ORDER BY FIELD(u.status, 'pending', 'active', 'rejected'), u.name ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['has_profile'] = (bool) $row['has_profile'];
        $row['points'] = (int) $row['points'];
        $row['age'] = !empty($row['birthdate']) ? sked_age_from_birthdate((string) $row['birthdate']) : null;
    }
    return $rows;
}

/** Verification tallies for a barangay (for dashboard cards). */
function sked_verification_counts_for_barangay(int $barangayId): array
{
    $out = ['pending' => 0, 'active' => 0, 'rejected' => 0];
    if ($barangayId <= 0) {
        return $out;
    }
    $stmt = sked_db()->prepare(
        "SELECT status, COUNT(*) AS n FROM users
          WHERE role = 'youth' AND barangay_id = :bgy GROUP BY status"
    );
    $stmt->execute(['bgy' => $barangayId]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($out[$r['status']])) {
            $out[$r['status']] = (int) $r['n'];
        }
    }
    return $out;
}

/**
 * Load a pending youth that belongs to the SK's barangay, or null. Shared
 * guard so verify/reject can't touch youth outside the SK's scope.
 */
function sked_pending_youth_in_scope(int $youthId, int $skBarangayId): ?array
{
    if ($youthId <= 0 || $skBarangayId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare(
        "SELECT id, name, status, barangay_id FROM users
          WHERE id = :id AND role = 'youth' AND status = 'pending' AND barangay_id = :bgy
          LIMIT 1"
    );
    $stmt->execute(['id' => $youthId, 'bgy' => $skBarangayId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Verify a youth: status -> active, verified -> 1. Barangay-scoped.
 *
 * @return array{ok:bool,error?:string,name?:string}
 */
function sked_verify_youth(int $skUserId, int $youthId, int $skBarangayId): array
{
    $youth = sked_pending_youth_in_scope($youthId, $skBarangayId);
    if ($youth === null) {
        return ['ok' => false, 'error' => 'That youth is not a pending member of your barangay.'];
    }

    $stmt = sked_db()->prepare(
        "UPDATE users SET status = 'active', verified = 1
          WHERE id = :id AND role = 'youth' AND status = 'pending' AND barangay_id = :bgy"
    );
    $stmt->execute(['id' => $youthId, 'bgy' => $skBarangayId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'No change was made — the account may have been updated already.'];
    }

    sked_audit($skUserId, 'youth_verified', 'user', $youthId, 'Barangay ' . $skBarangayId);
    sked_notify(
        $youthId,
        'verification',
        'Your KK profile is verified',
        'Your Barangay SK has verified your Katipunan ng Kabataan membership. You can now join events and earn participation points.',
        '../youth/dashboard.php'
    );

    return ['ok' => true, 'name' => (string) $youth['name']];
}

/**
 * Reject a youth: status -> rejected, verified -> 0. Barangay-scoped.
 *
 * @return array{ok:bool,error?:string,name?:string}
 */
function sked_reject_youth(int $skUserId, int $youthId, int $skBarangayId, string $reason = ''): array
{
    $youth = sked_pending_youth_in_scope($youthId, $skBarangayId);
    if ($youth === null) {
        return ['ok' => false, 'error' => 'That youth is not a pending member of your barangay.'];
    }

    $reason = trim($reason);
    $stmt = sked_db()->prepare(
        "UPDATE users SET status = 'rejected', verified = 0
          WHERE id = :id AND role = 'youth' AND status = 'pending' AND barangay_id = :bgy"
    );
    $stmt->execute(['id' => $youthId, 'bgy' => $skBarangayId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'No change was made — the account may have been updated already.'];
    }

    sked_audit($skUserId, 'youth_rejected', 'user', $youthId, $reason !== '' ? 'Reason: ' . $reason : 'Barangay ' . $skBarangayId);
    $reasonLine = $reason !== '' ? ' Reason given: ' . $reason : '';
    sked_notify(
        $youthId,
        'verification',
        'Your KK verification was not approved',
        'Your Barangay SK could not verify your membership at this time.' . $reasonLine . ' Please contact your SK Council for assistance.',
        null
    );

    return ['ok' => true, 'name' => (string) $youth['name']];
}
