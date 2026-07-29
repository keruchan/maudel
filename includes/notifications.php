<?php
/**
 * ============================================================
 * File     : includes/notifications.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Per-user notification center helpers (P2). Used first for
 *            youth verification outcomes; reused across events, reports,
 *            strikes, turnover, etc. The interactive bell/center UI (P10)
 *            is backed by these helpers via pages/api/notifications.php.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Create a notification for a user. Never throws to the caller — a failed
 * notification must not break the action that triggered it.
 */
function sked_notify(int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link)
             VALUES (:user_id, :type, :title, :message, :link)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 500),
            'link'    => $link,
        ]);
    } catch (Throwable $e) {
        error_log('sked_notify failed: ' . $e->getMessage());
    }
}

/**
 * Active users who should receive a role-level notification.
 *
 * @return array<int,int>
 */
function sked_notification_recipients(string $role, ?int $barangayId = null, ?int $exceptUserId = null): array
{
    $allowed = ['dilg', 'ppsk', 'sk', 'youth'];
    if (!in_array($role, $allowed, true)) {
        return [];
    }

    try {
        $where = ["role = :role", "status = 'active'"];
        $params = ['role' => $role];
        if ($barangayId !== null) {
            $where[] = 'barangay_id = :barangay_id';
            $params['barangay_id'] = $barangayId;
        }
        if ($exceptUserId !== null && $exceptUserId > 0) {
            $where[] = 'id <> :except_id';
            $params['except_id'] = $exceptUserId;
        }

        $stmt = sked_db()->prepare('SELECT id FROM users WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        error_log('sked_notification_recipients failed: ' . $e->getMessage());
        return [];
    }
}

/** Create the same notification for all active recipients of a role/scope. */
function sked_notify_role(string $role, string $type, string $title, string $message, ?string $link = null, ?int $barangayId = null, ?int $exceptUserId = null): int
{
    $sent = 0;
    foreach (sked_notification_recipients($role, $barangayId, $exceptUserId) as $userId) {
        sked_notify($userId, $type, $title, $message, $link);
        $sent++;
    }
    return $sent;
}

/**
 * Demo shortcut logins use ids 1-4 outside the users table. For the bell,
 * mirror them to the seeded real role account when one exists.
 */
function sked_notification_user_id_for_session(int $sessionUserId, string $role = '', ?int $barangayId = null): int
{
    if ($sessionUserId <= 0 || $sessionUserId >= 1000) {
        return $sessionUserId;
    }

    try {
        $where = ["role = :role", "status = 'active'"];
        $params = ['role' => $role];
        if ($role === 'sk' || $role === 'youth') {
            $where[] = 'barangay_id = :barangay_id';
            $params['barangay_id'] = (int) ($barangayId ?? 0);
        }
        $stmt = sked_db()->prepare('SELECT id FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 1');
        $stmt->execute($params);
        $resolved = $stmt->fetchColumn();
        return $resolved !== false ? (int) $resolved : $sessionUserId;
    } catch (Throwable $e) {
        return $sessionUserId;
    }
}

/** Count of unread notifications for a user (0 for demo accounts / no rows). */
function sked_unread_count(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $stmt = sked_db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Most recent notifications for a user, newest first.
 *
 * @return array<int,array{id:int,type:string,title:string,message:string,link:?string,is_read:int,created_at:string}>
 */
function sked_recent_notifications(int $userId, int $limit = 10): array
{
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $stmt = sked_db()->prepare(
            'SELECT id, type, title, message, link, is_read, created_at
               FROM notifications WHERE user_id = :id
              ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark all of a user's notifications read. Returns the number affected. */
function sked_mark_all_read(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $stmt = sked_db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :id AND is_read = 0');
        $stmt->execute(['id' => $userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Mark a single notification read. Scoped to $userId so one user can't mark another's. */
function sked_mark_notification_read(int $userId, int $notifId): bool
{
    if ($userId <= 0 || $notifId <= 0) {
        return false;
    }
    try {
        $stmt = sked_db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $notifId, 'u' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Icon + accent color for a notification type, for the bell panel. */
function sked_notification_visual(string $type): array
{
    return match ($type) {
        'verification' => ['icon' => 'bi-patch-check-fill', 'accent' => 'accent-fern'],
        'event' => ['icon' => 'bi-calendar-event', 'accent' => 'accent-teal'],
        'reminder' => ['icon' => 'bi-alarm-fill', 'accent' => 'accent-amber'],
        'compliance' => ['icon' => 'bi-exclamation-triangle-fill', 'accent' => 'accent-rust'],
        'role_change' => ['icon' => 'bi-arrow-left-right', 'accent' => 'accent-fern'],
        'feedback' => ['icon' => 'bi-chat-left-text-fill', 'accent' => 'accent-teal'],
        'report' => ['icon' => 'bi-clipboard-check-fill', 'accent' => 'accent-teal'],
        default => ['icon' => 'bi-bell-fill', 'accent' => 'accent-teal'],
    };
}

/** Compact relative-time label ("5m ago", "3h ago", "2d ago", or a date beyond a week). */
function sked_relative_time(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . 'd ago';
    }
    return date('M j', strtotime($datetime));
}
