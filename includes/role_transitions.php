<?php
/**
 * ============================================================
 * File     : includes/role_transitions.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared primitive for converting an SK Chairman or PPSK
 *            President back to a regular Youth account while retaining a
 *            "Former ..." recognition badge (spec 4.1: retiring officials
 *            "convert to regular Youth accounts" and keep this badge).
 *
 * Two callers need this exact mechanic:
 *   - P7 compliance: DILG processes a 3-strike dismissal.
 *   - P8 turnover: an election replaces an outgoing officer.
 * Building it once here (like points.php/notifications.php were built for
 * their first real consumer) avoids duplicating it when P8 lands.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/barangays.php';

/**
 * Revert an active SK or PPSK to a Youth account, stamping a former-role
 * badge and closing out their role_history term. The account stays
 * 'active' — spec is explicit that they continue as a regular Youth user,
 * not a deactivated one.
 *
 * @return array{ok:bool,error?:string,badge?:string}
 */
function sked_retire_official(int $actorId, int $userId, string $auditAction, string $auditNote = ''): array
{
    $stmt = sked_db()->prepare('SELECT id, role, status, barangay_id, term_start FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if ($user === false || !in_array($user['role'], ['sk', 'ppsk'], true) || $user['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Account is not an active SK or PPSK official.'];
    }

    $role = (string) $user['role'];
    $barangayId = $user['barangay_id'] !== null ? (int) $user['barangay_id'] : null;
    $roleLabel = $role === 'sk' ? 'SK Chairman' : 'PPSK President';
    $scopeLabel = ($role === 'sk' && $barangayId !== null) ? sked_barangay_name($barangayId) . ' ' : '';
    $termLabel = !empty($user['term_start'])
        ? '(' . date('Y', strtotime((string) $user['term_start'])) . '–' . date('Y') . ')'
        : '(until ' . date('Y') . ')';
    $badge = 'Former ' . $roleLabel . ' — ' . trim($scopeLabel . $termLabel);

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE users SET role = \'youth\', former_role_badge = :badge, term_end = CURDATE() WHERE id = :id'
        )->execute(['badge' => $badge, 'id' => $userId]);

        $pdo->prepare(
            'INSERT INTO role_history (user_id, role, barangay_id, term_start, term_end, note)
             VALUES (:user_id, :role, :barangay_id, :term_start, CURDATE(), :note)'
        )->execute([
            'user_id' => $userId,
            'role' => $role,
            'barangay_id' => $barangayId,
            'term_start' => $user['term_start'] ?: null,
            'note' => $auditNote !== '' ? $auditNote : null,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_retire_official failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not process this transition. Please try again.'];
    }

    sked_audit($actorId, $auditAction, 'user', $userId, $auditNote !== '' ? $auditNote : $badge);
    sked_notify(
        $userId,
        'role_change',
        'Your role has changed',
        'You are no longer serving as ' . $roleLabel . '. Your account is now a regular Youth account. ' .
            'Thank you for your service — your "' . $badge . '" recognition badge is now on your profile.',
        '../youth/dashboard.php'
    );

    return ['ok' => true, 'badge' => $badge];
}
