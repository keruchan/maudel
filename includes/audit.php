<?php
/**
 * ============================================================
 * File     : includes/audit.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Append-only audit trail helper. Records sensitive actions
 *            (verification, dismissal, turnover, provisioning) to the
 *            `audit_log` table (P0) for DILG oversight and dispute
 *            resolution. actor_id NULL = system/cron action. The DILG-facing
 *            viewer (P10) is pages/dilg/audit.php, backed by the query
 *            helpers below.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Write one audit-trail entry. Never throws to the caller — auditing must
 * not break the action it records; failures are logged to PHP's error log.
 */
function sked_audit(?int $actorId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $sql = 'INSERT INTO audit_log (actor_id, action, entity_type, entity_id, details, ip_address)
            VALUES (:actor_id, :action, :entity_type, :entity_id, :details, :ip)';
    $params = [
        'actor_id'    => $actorId,
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'details'     => $details,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    try {
        sked_db()->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        // Demo accounts (ids 1-4) aren't persisted in `users`, so their id
        // fails the actor_id foreign key. Still record the action, but as a
        // system actor (NULL) with the original actor noted in details.
        if ($e->getCode() === '23000' && $actorId !== null) {
            $params['actor_id'] = null;
            $params['details'] = trim('[actor #' . $actorId . ' — demo/unlinked] ' . (string) $details);
            try {
                sked_db()->prepare($sql)->execute($params);
                return;
            } catch (Throwable $inner) {
                error_log('sked_audit retry failed: ' . $inner->getMessage());
                return;
            }
        }
        error_log('sked_audit failed: ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log('sked_audit failed: ' . $e->getMessage());
    }
}

/**
 * Audit log entries for DILG's viewer, newest first, with the actor's
 * current name/role joined in (actor_id NULL or a since-removed user shows
 * as "System"). Optional filters: action (exact match), date_from, date_to
 * (both YYYY-MM-DD, inclusive).
 *
 * @return array<int,array<string,mixed>>
 */
function sked_audit_log_entries(array $filters = [], int $limit = 200): array
{
    $where = [];
    $params = [];

    $action = (string) ($filters['action'] ?? '');
    if ($action !== '') {
        $where[] = 'a.action = :action';
        $params['action'] = $action;
    }
    $dateFrom = (string) ($filters['date_from'] ?? '');
    if ($dateFrom !== '' && DateTime::createFromFormat('!Y-m-d', $dateFrom) !== false) {
        $where[] = 'DATE(a.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    $dateTo = (string) ($filters['date_to'] ?? '');
    if ($dateTo !== '' && DateTime::createFromFormat('!Y-m-d', $dateTo) !== false) {
        $where[] = 'DATE(a.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $limit = max(1, min(500, $limit));
    $sql = 'SELECT a.*, u.name AS actor_name, u.role AS actor_role
              FROM audit_log a
         LEFT JOIN users u ON u.id = a.actor_id'
        . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $limit;

    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Distinct action values seen in the audit log, for a filter dropdown. */
function sked_audit_distinct_actions(): array
{
    $stmt = sked_db()->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
    return array_column($stmt->fetchAll(), 'action');
}
