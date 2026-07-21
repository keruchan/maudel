<?php
/**
 * ============================================================
 * File     : includes/cbydp.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Comprehensive Barangay Youth Development Plan (CBYDP, P12) —
 *            a 3-year rolling plan, one per barangay per cycle
 *            (barangay_id, cy_year_start), grouped into sections by
 *            "Center of Participation" (sked_interest_categories()), each
 *            holding one or more youth-development-concern line items.
 *            SK-only create/edit (spec, matching Project Charters/P6);
 *            PPSK/DILG view + export for oversight.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/barangays.php';
require_once __DIR__ . '/profiling.php'; // sked_interest_categories()
require_once __DIR__ . '/audit.php';

/** Create a new CBYDP plan shell for the creator's barangay. SK-only. */
function sked_cbydp_create(array $creator, int $cyYearStart, string $preparedByName): array
{
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    $errors = [];
    if ($barangayId <= 0) {
        $errors[] = 'Your account is not assigned to a barangay.';
    }
    if ($cyYearStart < 2020 || $cyYearStart > 2100) {
        $errors[] = 'Please enter a valid starting year.';
    }
    $preparedByName = trim($preparedByName);
    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO cbydp_plans (barangay_id, cy_year_start, prepared_by_name, approved_by_name, created_by)
             VALUES (:bgy, :year, :prepared, :approved, :created_by)'
        );
        $stmt->execute([
            'bgy' => $barangayId,
            'year' => $cyYearStart,
            'prepared' => $preparedByName !== '' ? $preparedByName : null,
            'approved' => (string) ($creator['name'] ?? '') ?: null,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ["A CBYDP starting {$cyYearStart} already exists for your barangay."]];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'plan_id' => (int) sked_db()->lastInsertId()];
}

/** A plan by id, barangay-scoped when $barangayId is given (pass null for cross-barangay/oversight reads). */
function sked_cbydp_get(int $planId, ?int $barangayId = null): ?array
{
    if ($planId <= 0) {
        return null;
    }
    $sql = 'SELECT * FROM cbydp_plans WHERE id = :id';
    $params = ['id' => $planId];
    if ($barangayId !== null) {
        $sql .= ' AND barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }
    $stmt = sked_db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $plan = $stmt->fetch();
    if ($plan === false) {
        return null;
    }

    $sStmt = sked_db()->prepare('SELECT * FROM cbydp_sections WHERE plan_id = :p ORDER BY sort_order ASC, id ASC');
    $sStmt->execute(['p' => $planId]);
    $sections = $sStmt->fetchAll();

    $iStmt = sked_db()->prepare('SELECT * FROM cbydp_line_items WHERE section_id = :s ORDER BY sort_order ASC, id ASC');
    foreach ($sections as &$section) {
        $iStmt->execute(['s' => $section['id']]);
        $section['items'] = $iStmt->fetchAll();
    }
    unset($section);

    $plan['sections'] = $sections;
    $plan['barangay_name'] = sked_barangay_name((int) $plan['barangay_id']);
    return $plan;
}

/** All CBYDP plans for a barangay, newest cycle first. */
function sked_cbydp_list_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM cbydp_plans WHERE barangay_id = :bgy ORDER BY cy_year_start DESC');
    $stmt->execute(['bgy' => $barangayId]);
    return $stmt->fetchAll();
}

/** All CBYDP plans municipality-wide, for PPSK/DILG oversight. */
function sked_cbydp_list_all(): array
{
    $stmt = sked_db()->query(
        'SELECT p.*, b.name AS barangay_name FROM cbydp_plans p
           JOIN barangays b ON b.id = p.barangay_id
          ORDER BY b.name ASC, p.cy_year_start DESC'
    );
    return $stmt->fetchAll();
}

/** Add a Center-of-Participation section to a plan. Barangay-scoped. */
function sked_cbydp_add_section(int $planId, int $actorBarangayId, string $center, string $agendaStatement): array
{
    $plan = sked_cbydp_get($planId, $actorBarangayId);
    if ($plan === null) {
        return ['ok' => false, 'errors' => ['Plan not found.']];
    }
    if (!in_array($center, sked_interest_categories(), true)) {
        return ['ok' => false, 'errors' => ['Please select a valid Center of Participation.']];
    }
    $agendaStatement = trim($agendaStatement);

    try {
        $nextOrder = count($plan['sections']);
        $stmt = sked_db()->prepare(
            'INSERT INTO cbydp_sections (plan_id, center_of_participation, agenda_statement, sort_order)
             VALUES (:p, :c, :a, :o)'
        );
        $stmt->execute([
            'p' => $planId, 'c' => $center,
            'a' => $agendaStatement !== '' ? $agendaStatement : null,
            'o' => $nextOrder,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ['That Center of Participation is already in this plan.']];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'section_id' => (int) sked_db()->lastInsertId()];
}

/** Remove a section (and its line items) from a plan. Barangay-scoped. */
function sked_cbydp_delete_section(int $sectionId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE cs FROM cbydp_sections cs
           JOIN cbydp_plans cp ON cp.id = cs.plan_id
          WHERE cs.id = :s AND cp.barangay_id = :bgy'
    );
    $stmt->execute(['s' => $sectionId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Section not found.']];
}

/**
 * Add a youth-development-concern line item to a section. Barangay-scoped
 * via the section's parent plan.
 *
 * @param array<string,mixed> $data
 */
function sked_cbydp_add_line_item(int $sectionId, int $actorBarangayId, array $data): array
{
    $owns = sked_db()->prepare(
        'SELECT cs.id FROM cbydp_sections cs JOIN cbydp_plans cp ON cp.id = cs.plan_id
          WHERE cs.id = :s AND cp.barangay_id = :bgy LIMIT 1'
    );
    $owns->execute(['s' => $sectionId, 'bgy' => $actorBarangayId]);
    if ($owns->fetchColumn() === false) {
        return ['ok' => false, 'errors' => ['Section not found.']];
    }

    $concern = trim((string) ($data['youth_development_concern'] ?? ''));
    if ($concern === '') {
        return ['ok' => false, 'errors' => ['Youth Development Concern is required.']];
    }
    $budgetRaw = trim((string) ($data['budget'] ?? ''));
    $budget = null;
    if ($budgetRaw !== '') {
        if (!is_numeric($budgetRaw) || (float) $budgetRaw < 0) {
            return ['ok' => false, 'errors' => ['Budget must be a positive number.']];
        }
        $budget = round((float) $budgetRaw, 2);
    }

    $countStmt = sked_db()->prepare('SELECT COUNT(*) FROM cbydp_line_items WHERE section_id = :s');
    $countStmt->execute(['s' => $sectionId]);
    $nextOrder = (int) $countStmt->fetchColumn();

    $stmt = sked_db()->prepare(
        'INSERT INTO cbydp_line_items
            (section_id, youth_development_concern, objective, performance_indicator,
             target_year1, target_year2, target_year3, ppas, budget, person_responsible, sort_order)
         VALUES (:s, :concern, :obj, :indicator, :t1, :t2, :t3, :ppas, :budget, :person, :order)'
    );
    $stmt->execute([
        's' => $sectionId,
        'concern' => $concern,
        'obj' => trim((string) ($data['objective'] ?? '')) ?: null,
        'indicator' => trim((string) ($data['performance_indicator'] ?? '')) ?: null,
        't1' => trim((string) ($data['target_year1'] ?? '')) ?: null,
        't2' => trim((string) ($data['target_year2'] ?? '')) ?: null,
        't3' => trim((string) ($data['target_year3'] ?? '')) ?: null,
        'ppas' => trim((string) ($data['ppas'] ?? '')) ?: null,
        'budget' => $budget,
        'person' => trim((string) ($data['person_responsible'] ?? '')) ?: null,
        'order' => $nextOrder,
    ]);

    return ['ok' => true, 'errors' => [], 'item_id' => (int) sked_db()->lastInsertId()];
}

/** Remove a line item. Barangay-scoped via its section's parent plan. */
function sked_cbydp_delete_line_item(int $itemId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE cli FROM cbydp_line_items cli
           JOIN cbydp_sections cs ON cs.id = cli.section_id
           JOIN cbydp_plans cp ON cp.id = cs.plan_id
          WHERE cli.id = :i AND cp.barangay_id = :bgy'
    );
    $stmt->execute(['i' => $itemId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Line item not found.']];
}

/** Toggle draft/finalized. Barangay-scoped. */
function sked_cbydp_set_status(int $planId, int $actorBarangayId, string $status): array
{
    if (!in_array($status, ['draft', 'finalized'], true)) {
        return ['ok' => false, 'errors' => ['Invalid status.']];
    }
    $stmt = sked_db()->prepare('UPDATE cbydp_plans SET status = :s WHERE id = :id AND barangay_id = :bgy');
    $stmt->execute(['s' => $status, 'id' => $planId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Plan not found.']];
}

/** Sum of every line item's budget across the whole plan. */
function sked_cbydp_total_budget(array $plan): float
{
    $total = 0.0;
    foreach ($plan['sections'] as $section) {
        foreach ($section['items'] as $item) {
            $total += (float) ($item['budget'] ?? 0);
        }
    }
    return $total;
}
