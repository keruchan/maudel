<?php
/**
 * ============================================================
 * File     : includes/turnover.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Turnover of power (P8, spec 6.1). Two flows share the same
 *            underlying primitive (sked_provision_officer):
 *              1. Election turnover: outgoing PPSK submits a 'turnover'
 *                 report (new PPSK identity + incoming SK roster) -> DILG
 *                 reviews & activates the new PPSK -> new PPSK generates
 *                 credentials for each incoming SK from that same roster.
 *              2. Direct designation: DILG designates a PPSK, or PPSK
 *                 designates a single SK, without a roster/election event
 *                 (e.g. filling a vacancy left by a P7 dismissal).
 *
 * Provisioning an officer either PROMOTES an existing active, verified
 * youth account matched by email (the realistic case — SK Chairmen are
 * themselves KK youth), or creates a brand-new account with a generated
 * username/password if no such account exists. Either way, any current
 * active officer of that role+scope is first retired via the shared
 * sked_retire_official() primitive built in P7 — enforcing "one active SK
 * per barangay" / "one active PPSK per municipality" at the point of
 * provisioning (spec 6.1 step 6, recommendation #4).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/barangays.php';
require_once __DIR__ . '/role_transitions.php';
require_once __DIR__ . '/reports.php';

/** The currently active officer's user id for a role+scope, or null. */
function sked_find_active_officer(string $role, ?int $barangayId): ?int
{
    if ($role === 'sk') {
        $stmt = sked_db()->prepare("SELECT id FROM users WHERE role = 'sk' AND status = 'active' AND barangay_id = :b LIMIT 1");
        $stmt->execute(['b' => $barangayId]);
    } else {
        $stmt = sked_db()->prepare("SELECT id FROM users WHERE role = 'ppsk' AND status = 'active' LIMIT 1");
        $stmt->execute();
    }
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Generate a unique username from a display name, avoiding demo + existing collisions. */
function sked_generate_username(string $name): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $base = $base !== '' ? substr($base, 0, 14) : 'officer';
    $candidate = $base;
    $suffix = 0;
    while (array_key_exists(strtolower($candidate), sked_demo_users()) || sked_find_registered_user($candidate) !== null) {
        $suffix++;
        $candidate = $base . $suffix;
    }
    return $candidate;
}

/** A random one-time temp password (10 hex chars) for a newly provisioned account. */
function sked_generate_temp_password(): string
{
    return bin2hex(random_bytes(5));
}

/**
 * Core provisioning primitive: retire the current active officer of this
 * role+scope (if any), then either promote an existing verified youth
 * account matched by email or create a brand-new one.
 *
 * @return array{ok:bool,error?:string,user_id?:int,promoted?:bool,credentials?:?array{username:string,password:string},retired_badge?:?string}
 */
function sked_provision_officer(int $actorId, string $role, ?int $barangayId, string $name, string $email, string $mobile): array
{
    $name = trim($name);
    $email = trim($email);
    $mobile = trim($mobile);

    if (!in_array($role, ['sk', 'ppsk'], true)) {
        return ['ok' => false, 'error' => 'Invalid role.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required.'];
    }
    if ($role === 'sk' && ($barangayId === null || $barangayId <= 0 || !sked_barangay_exists($barangayId))) {
        return ['ok' => false, 'error' => 'A valid barangay is required.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.'];
    }

    // Retire the outgoing officer first (own transaction — see D-019 for why
    // this isn't nested inside the create step's transaction).
    $retiredBadge = null;
    $existingActiveId = sked_find_active_officer($role, $barangayId);
    if ($existingActiveId !== null) {
        $reason = $role === 'ppsk' ? 'Replaced during PPSK turnover' : 'Replaced during SK turnover';
        $ret = sked_retire_official($actorId, $existingActiveId, $role . '_turnover_replaced', $reason);
        if (!$ret['ok']) {
            return ['ok' => false, 'error' => $ret['error']];
        }
        $retiredBadge = $ret['badge'];
    }

    $roleLabel = $role === 'sk' ? 'SK Chairman' : 'PPSK President';
    $existingUser = $email !== '' ? sked_find_registered_user($email) : null;

    $pdo = sked_db();

    if ($existingUser !== null) {
        if ($existingUser['role'] !== 'youth' || $existingUser['status'] !== 'active') {
            return ['ok' => false, 'error' => 'An account already exists for that email, but it is not an active, verified youth account, so it cannot be promoted to ' . $roleLabel . '.'];
        }
        $pdo->prepare(
            'UPDATE users SET role = :role, barangay_id = :barangay_id, term_start = CURDATE(), term_end = NULL, former_role_badge = NULL WHERE id = :id'
        )->execute(['role' => $role, 'barangay_id' => $barangayId, 'id' => $existingUser['id']]);

        $userId = (int) $existingUser['id'];
        $credentials = null;
        $promoted = true;
    } else {
        $username = sked_generate_username($name);
        $password = sked_generate_temp_password();

        $pdo->prepare(
            'INSERT INTO users (role, status, barangay_id, name, email, mobile, username, password_hash, verified, term_start)
             VALUES (:role, \'active\', :barangay_id, :name, :email, :mobile, :username, :hash, 1, CURDATE())'
        )->execute([
            'role' => $role,
            'barangay_id' => $barangayId,
            'name' => $name,
            'email' => $email !== '' ? $email : $username . '@sked.local',
            'mobile' => $mobile !== '' ? $mobile : null,
            'username' => $username,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();
        $credentials = ['username' => $username, 'password' => $password];
        $promoted = false;
    }

    sked_audit($actorId, $role . '_provisioned', 'user', $userId, $promoted ? 'Promoted existing account' : 'New account created');
    sked_notify($userId, 'role_change', 'You have been designated ' . $roleLabel,
        'Congratulations — you are now serving as ' . $roleLabel . ($barangayId !== null ? ' for ' . sked_barangay_name($barangayId) : '') . '.',
        '../' . $role . '/dashboard.php');

    return ['ok' => true, 'user_id' => $userId, 'promoted' => $promoted, 'credentials' => $credentials, 'retired_badge' => $retiredBadge];
}

/* ============================================================
 * Election-turnover flow (report + roster driven)
 * ============================================================ */

/**
 * Outgoing PPSK submits the turnover report: new PPSK identity + incoming
 * SK roster (one row per barangay they have a nominee for).
 *
 * @param array{id:int,role:string,name:string} $outgoingPpsk
 * @param array<int,array{barangay_id:int,name:string,email?:string,mobile?:string}> $roster
 * @return array{ok:bool,errors:array<int,string>,report_id?:int}
 */
function sked_submit_turnover_report(array $outgoingPpsk, string $newName, string $newEmail, string $newMobile, array $roster): array
{
    $errors = [];
    $newName = trim($newName);
    $newEmail = trim($newEmail);
    if ($newName === '') {
        $errors[] = 'The incoming PPSK\'s name is required.';
    }
    if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email for the incoming PPSK.';
    }

    $clean = [];
    foreach ($roster as $row) {
        $bgy = (int) ($row['barangay_id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($bgy > 0 && $name !== '' && sked_barangay_exists($bgy)) {
            $clean[$bgy] = ['barangay_id' => $bgy, 'name' => $name, 'email' => trim((string) ($row['email'] ?? '')), 'mobile' => trim((string) ($row['mobile'] ?? ''))];
        }
    }
    if (empty($clean)) {
        $errors[] = 'Include at least one incoming SK Chairman in the roster.';
    }

    $existing = sked_db()->prepare("SELECT 1 FROM reports WHERE type = 'turnover' AND status = 'submitted' LIMIT 1");
    $existing->execute();
    if ($existing->fetchColumn() !== false) {
        $errors[] = 'A turnover report is already pending DILG review.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO reports (type, submitted_by, submitted_by_role, submitted_by_name, target_role, title, content, new_officer_name, new_officer_email, new_officer_mobile)
             VALUES (\'turnover\', :sid, :srole, :sname, \'dilg\', :title, :content, :nname, :nemail, :nmobile)'
        );
        $stmt->execute([
            'sid' => (int) ($outgoingPpsk['id'] ?? 0) ?: null,
            'srole' => (string) ($outgoingPpsk['role'] ?? '') ?: null,
            'sname' => (string) ($outgoingPpsk['name'] ?? '') ?: null,
            'title' => 'Turnover of Power: incoming PPSK ' . $newName,
            'content' => 'Incoming PPSK: ' . $newName . '. Incoming SK roster: ' . count($clean) . ' barangay(s).',
            'nname' => $newName,
            'nemail' => $newEmail !== '' ? $newEmail : null,
            'nmobile' => $newMobile !== '' ? $newMobile : null,
        ]);
        $reportId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO turnover_roster (report_id, barangay_id, name, email, mobile) VALUES (:r, :b, :n, :e, :m)');
        foreach ($clean as $row) {
            $ins->execute([
                'r' => $reportId,
                'b' => $row['barangay_id'],
                'n' => $row['name'],
                'e' => $row['email'] !== '' ? $row['email'] : null,
                'm' => $row['mobile'] !== '' ? $row['mobile'] : null,
            ]);
        }

        $pdo->commit();
        sked_notify_role(
            'dilg',
            'role_change',
            'Turnover report submitted',
            (string) ($outgoingPpsk['name'] ?? 'Outgoing PPSK') . ' submitted a turnover report for DILG review.',
            '../dilg/turnover.php'
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_submit_turnover_report failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not submit the turnover report. Please try again.']];
    }

    return ['ok' => true, 'errors' => [], 'report_id' => $reportId];
}

/** Pending turnover reports for DILG review. */
function sked_pending_turnover_reports(): array
{
    return sked_reports_for_role('dilg', ['type' => 'turnover', 'status' => 'submitted']);
}

/** The incoming SK roster attached to a turnover report. */
function sked_turnover_roster_for_report(int $reportId): array
{
    if ($reportId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare(
        'SELECT tr.*, b.name AS barangay_name FROM turnover_roster tr
           JOIN barangays b ON b.id = tr.barangay_id
          WHERE tr.report_id = :r ORDER BY b.name'
    );
    $stmt->execute(['r' => $reportId]);
    return $stmt->fetchAll();
}

/**
 * DILG activates the incoming PPSK from a pending turnover report: retires
 * the outgoing PPSK, provisions the new one, marks the report reviewed. The
 * SK roster stays attached for the new PPSK to provision afterward.
 *
 * @return array{ok:bool,error?:string,user_id?:int,promoted?:bool,credentials?:?array}
 */
function sked_activate_new_ppsk(int $dilgUserId, int $reportId): array
{
    $report = sked_get_report($reportId);
    if ($report === null || $report['type'] !== 'turnover' || $report['status'] !== 'submitted') {
        return ['ok' => false, 'error' => 'This turnover report is no longer pending.'];
    }

    $result = sked_provision_officer(
        $dilgUserId, 'ppsk', null,
        (string) $report['new_officer_name'], (string) $report['new_officer_email'], (string) $report['new_officer_mobile']
    );
    if (!$result['ok']) {
        return $result;
    }

    sked_mark_report_reviewed($reportId, $dilgUserId);

    return $result;
}

/** DILG declines a pending turnover report without provisioning anything. */
function sked_decline_turnover_report(int $dilgUserId, int $reportId): bool
{
    $ok = sked_mark_report_reviewed($reportId, $dilgUserId);
    if ($ok) {
        sked_audit($dilgUserId, 'turnover_declined', 'report', $reportId);
    }
    return $ok;
}

/** Unprovisioned SK roster rows across all processed turnover reports, for the current PPSK to act on. */
function sked_pending_roster_rows(): array
{
    $stmt = sked_db()->query(
        "SELECT tr.*, b.name AS barangay_name FROM turnover_roster tr
           JOIN barangays b ON b.id = tr.barangay_id
           JOIN reports r ON r.id = tr.report_id
          WHERE tr.status = 'pending' AND r.status = 'reviewed'
          ORDER BY b.name"
    );
    return $stmt->fetchAll();
}

/**
 * New PPSK provisions one incoming SK Chairman from the roster: retires the
 * outgoing SK for that barangay, provisions the new one, marks the roster
 * row provisioned.
 *
 * @return array{ok:bool,error?:string,user_id?:int,promoted?:bool,credentials?:?array}
 */
function sked_provision_sk_from_roster(int $ppskUserId, int $rosterId): array
{
    $stmt = sked_db()->prepare("SELECT * FROM turnover_roster WHERE id = :id AND status = 'pending' LIMIT 1");
    $stmt->execute(['id' => $rosterId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'This roster entry is no longer pending.'];
    }

    $result = sked_provision_officer($ppskUserId, 'sk', (int) $row['barangay_id'], (string) $row['name'], (string) $row['email'], (string) $row['mobile']);
    if (!$result['ok']) {
        return $result;
    }

    sked_db()->prepare('UPDATE turnover_roster SET status = \'provisioned\', provisioned_user_id = :uid WHERE id = :id')
        ->execute(['uid' => $result['user_id'], 'id' => $rosterId]);

    return $result;
}
