<?php
/**
 * ============================================================
 * File     : includes/charters.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK Project Charter tracker (P6, spec 4.3). Projects are
 *            upcoming/ongoing/completed, with an info-only budget field
 *            (no approval workflow) and an optional link to one event so a
 *            "success rate" can be derived live from that event's youth
 *            evaluations (sked_event_rating(), P4) — not stored.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/events.php';

/**
 * Create a project charter. Barangay is taken from the SK creator (SK is the
 * only role that creates charters per spec 4.3).
 *
 * @param array{id:int,role:string,name:string,barangay_id:?int} $creator
 * @return array{ok:bool,errors:array<int,string>,charter_id?:int}
 */
function sked_create_charter(array $creator, array $data): array
{
    $errors = [];
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        $errors[] = 'Your account is not assigned to a barangay.';
    }

    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $status = (string) ($data['status'] ?? 'upcoming');
    $startDate = trim((string) ($data['start_date'] ?? ''));
    $endDate = trim((string) ($data['end_date'] ?? ''));
    $budgetRaw = trim((string) ($data['budget_amount'] ?? ''));
    $eventId = (int) ($data['event_id'] ?? 0);

    if ($title === '') {
        $errors[] = 'Project title is required.';
    }
    if (!in_array($status, ['upcoming', 'ongoing', 'completed'], true)) {
        $errors[] = 'Invalid status.';
    }
    if ($startDate !== '' && DateTime::createFromFormat('!Y-m-d', $startDate) === false) {
        $errors[] = 'Invalid start date.';
    }
    if ($endDate !== '' && DateTime::createFromFormat('!Y-m-d', $endDate) === false) {
        $errors[] = 'Invalid end date.';
    }
    if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
        $errors[] = 'End date cannot be before the start date.';
    }

    $budgetAmount = null;
    if ($budgetRaw !== '') {
        if (!is_numeric($budgetRaw) || (float) $budgetRaw < 0) {
            $errors[] = 'Budget must be a positive number.';
        } else {
            $budgetAmount = round((float) $budgetRaw, 2);
        }
    }

    // Optional event link: must be one this SK actually manages (own barangay).
    if ($eventId > 0) {
        $event = sked_get_event($eventId);
        if ($event === null || !sked_can_manage_event((string) $creator['role'], $barangayId, $event)) {
            $errors[] = 'You can only link an event you manage.';
            $eventId = 0;
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = sked_db()->prepare(
        'INSERT INTO project_charters
            (barangay_id, title, description, budget_amount, start_date, end_date, status, event_id,
             created_by, created_by_role, created_by_name)
         VALUES
            (:barangay_id, :title, :description, :budget, :start_date, :end_date, :status, :event_id,
             :created_by, :created_by_role, :created_by_name)'
    );
    $stmt->execute([
        'barangay_id' => $barangayId,
        'title' => $title,
        'description' => $description !== '' ? $description : null,
        'budget' => $budgetAmount,
        'start_date' => $startDate !== '' ? $startDate : null,
        'end_date' => $endDate !== '' ? $endDate : null,
        'status' => $status,
        'event_id' => $eventId > 0 ? $eventId : null,
        'created_by' => (int) ($creator['id'] ?? 0) ?: null,
        'created_by_role' => (string) ($creator['role'] ?? '') ?: null,
        'created_by_name' => (string) ($creator['name'] ?? '') ?: null,
    ]);

    return ['ok' => true, 'errors' => [], 'charter_id' => (int) sked_db()->lastInsertId()];
}

/**
 * Charters for a barangay, newest first, with optional filters:
 * status (string), date_from / date_to (overlapping range against
 * start_date/end_date, per spec 4.3 "filterable, especially by date range").
 */
function sked_charters_for_barangay(int $barangayId, array $filters = []): array
{
    if ($barangayId <= 0) {
        return [];
    }

    $where = ['barangay_id = :bgy'];
    $params = ['bgy' => $barangayId];

    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, ['upcoming', 'ongoing', 'completed'], true)) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $dateFrom = (string) ($filters['date_from'] ?? '');
    if ($dateFrom !== '' && DateTime::createFromFormat('!Y-m-d', $dateFrom) !== false) {
        // Overlaps the filter range if the charter has no end_date (open-ended) or ends on/after date_from.
        $where[] = '(end_date IS NULL OR end_date >= :date_from)';
        $params['date_from'] = $dateFrom;
    }

    $dateTo = (string) ($filters['date_to'] ?? '');
    if ($dateTo !== '' && DateTime::createFromFormat('!Y-m-d', $dateTo) !== false) {
        $where[] = '(start_date IS NULL OR start_date <= :date_to)';
        $params['date_to'] = $dateTo;
    }

    $sql = 'SELECT * FROM project_charters WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** A single charter by id, or null. */
function sked_get_charter(int $charterId): ?array
{
    if ($charterId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM project_charters WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $charterId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Success rate for a charter, derived live from its linked event's
 * evaluations (not stored). Returns null avg if unlinked or unevaluated.
 *
 * @return array{count:int,avg:?float}
 */
function sked_charter_success_rate(array $charter): array
{
    if (empty($charter['event_id'])) {
        return ['count' => 0, 'avg' => null];
    }
    return sked_event_rating((int) $charter['event_id']);
}

/**
 * Update an existing charter's editable fields. Barangay-scoped: only the
 * owning barangay's SK (or DILG/PPSK acting on any) may update.
 *
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_update_charter(int $charterId, int $actorBarangayId, array $data): array
{
    $charter = sked_get_charter($charterId);
    if ($charter === null || (int) $charter['barangay_id'] !== $actorBarangayId) {
        return ['ok' => false, 'errors' => ['Project not found.']];
    }

    $status = (string) ($data['status'] ?? $charter['status']);
    if (!in_array($status, ['upcoming', 'ongoing', 'completed'], true)) {
        return ['ok' => false, 'errors' => ['Invalid status.']];
    }

    $stmt = sked_db()->prepare('UPDATE project_charters SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $charterId]);

    return ['ok' => true, 'errors' => []];
}
