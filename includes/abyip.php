<?php
/**
 * ============================================================
 * File     : includes/abyip.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Annual Barangay Youth Investment Program (ABYIP, P12) — one
 *            per barangay per calendar year. Normally created FROM a
 *            CBYDP (copies its sections/line items as a starting point for
 *            that one year, with reference codes and MOOE/CO budget split
 *            added afterward), matching real SK practice; a blank ABYIP
 *            with no CBYDP link is also supported for a barangay that
 *            doesn't have one yet. SK-only create/edit; PPSK/DILG view +
 *            export for oversight — same rule as includes/cbydp.php.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/barangays.php';
require_once __DIR__ . '/profiling.php'; // sked_interest_categories()
require_once __DIR__ . '/cbydp.php';
require_once __DIR__ . '/audit.php';

/** Create a blank ABYIP shell (no CBYDP link) for the creator's barangay. */
function sked_abyip_create_blank(array $creator, int $calendarYear, string $preparedByName): array
{
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        return ['ok' => false, 'errors' => ['Your account is not assigned to a barangay.']];
    }
    if ($calendarYear < 2020 || $calendarYear > 2100) {
        return ['ok' => false, 'errors' => ['Please enter a valid calendar year.']];
    }

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO abyip_plans (barangay_id, calendar_year, prepared_by_name, approved_by_name, created_by)
             VALUES (:bgy, :year, :prepared, :approved, :created_by)'
        );
        $stmt->execute([
            'bgy' => $barangayId,
            'year' => $calendarYear,
            'prepared' => trim($preparedByName) !== '' ? trim($preparedByName) : null,
            'approved' => (string) ($creator['name'] ?? '') ?: null,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ["An ABYIP for {$calendarYear} already exists for your barangay."]];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'plan_id' => (int) sked_db()->lastInsertId()];
}

/**
 * Create an ABYIP for one year by copying a CBYDP's sections/line items as
 * a starting point. $calendarYear must fall within that CBYDP's 3-year
 * window (cy_year_start..+2).
 */
function sked_abyip_create_from_cbydp(array $creator, int $cbydpPlanId, int $calendarYear, string $preparedByName): array
{
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        return ['ok' => false, 'errors' => ['Your account is not assigned to a barangay.']];
    }
    $cbydp = sked_cbydp_get($cbydpPlanId, $barangayId);
    if ($cbydp === null) {
        return ['ok' => false, 'errors' => ['CBYDP plan not found.']];
    }
    $windowStart = (int) $cbydp['cy_year_start'];
    if ($calendarYear < $windowStart || $calendarYear > $windowStart + 2) {
        return ['ok' => false, 'errors' => ["That year is outside this CBYDP's {$windowStart}–" . ($windowStart + 2) . ' window.']];
    }

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO abyip_plans (barangay_id, cbydp_plan_id, calendar_year, prepared_by_name, approved_by_name, created_by)
             VALUES (:bgy, :cbydp, :year, :prepared, :approved, :created_by)'
        );
        $stmt->execute([
            'bgy' => $barangayId,
            'cbydp' => $cbydpPlanId,
            'year' => $calendarYear,
            'prepared' => trim($preparedByName) !== '' ? trim($preparedByName) : null,
            'approved' => (string) ($creator['name'] ?? '') ?: null,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
        ]);
        $abyipPlanId = (int) $pdo->lastInsertId();

        $secStmt = $pdo->prepare(
            'INSERT INTO abyip_sections (plan_id, center_of_participation, sort_order) VALUES (:p, :c, :o)'
        );
        $itemStmt = $pdo->prepare(
            'INSERT INTO abyip_line_items
                (section_id, source_cbydp_line_item_id, ppa_name, description, expected_result,
                 performance_indicator, budget_mooe, budget_total, person_responsible, sort_order)
             VALUES (:section, :src, :ppa, :desc, :result, :indicator, :mooe, :total, :person, :order)'
        );

        foreach ($cbydp['sections'] as $sIdx => $section) {
            $secStmt->execute(['p' => $abyipPlanId, 'c' => $section['center_of_participation'], 'o' => $sIdx]);
            $sectionId = (int) $pdo->lastInsertId();

            foreach ($section['items'] as $iIdx => $item) {
                $ppaName = trim((string) ($item['ppas'] ?? '')) !== ''
                    ? mb_substr((string) $item['ppas'], 0, 255)
                    : mb_substr((string) $item['youth_development_concern'], 0, 255);
                $budget = (float) ($item['budget'] ?? 0);
                $itemStmt->execute([
                    'section' => $sectionId,
                    'src' => $item['id'],
                    'ppa' => $ppaName,
                    'desc' => $item['ppas'] ?? null,
                    'result' => $item['objective'] ?? null,
                    'indicator' => $item['performance_indicator'] ?? null,
                    'mooe' => $budget,
                    'total' => $budget,
                    'person' => $item['person_responsible'] ?? null,
                    'order' => $iIdx,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ["An ABYIP for {$calendarYear} already exists for your barangay."]];
        }
        error_log('sked_abyip_create_from_cbydp failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not create the ABYIP. Please try again.']];
    }

    return ['ok' => true, 'errors' => [], 'plan_id' => $abyipPlanId];
}

/** A plan by id, barangay-scoped when $barangayId is given. */
function sked_abyip_get(int $planId, ?int $barangayId = null): ?array
{
    if ($planId <= 0) {
        return null;
    }
    $sql = 'SELECT * FROM abyip_plans WHERE id = :id';
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

    $sStmt = sked_db()->prepare('SELECT * FROM abyip_sections WHERE plan_id = :p ORDER BY sort_order ASC, id ASC');
    $sStmt->execute(['p' => $planId]);
    $sections = $sStmt->fetchAll();

    $iStmt = sked_db()->prepare('SELECT * FROM abyip_line_items WHERE section_id = :s ORDER BY sort_order ASC, id ASC');
    foreach ($sections as &$section) {
        $iStmt->execute(['s' => $section['id']]);
        $section['items'] = $iStmt->fetchAll();
    }
    unset($section);

    $plan['sections'] = $sections;
    $plan['barangay_name'] = sked_barangay_name((int) $plan['barangay_id']);
    return $plan;
}

/** All ABYIP plans for a barangay, newest year first. */
function sked_abyip_list_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM abyip_plans WHERE barangay_id = :bgy ORDER BY calendar_year DESC');
    $stmt->execute(['bgy' => $barangayId]);
    return $stmt->fetchAll();
}

/** All ABYIP plans municipality-wide, for PPSK/DILG oversight. */
function sked_abyip_list_all(): array
{
    $stmt = sked_db()->query(
        'SELECT p.*, b.name AS barangay_name FROM abyip_plans p
           JOIN barangays b ON b.id = p.barangay_id
          ORDER BY b.name ASC, p.calendar_year DESC'
    );
    return $stmt->fetchAll();
}

/** Add a Center-of-Participation section to a plan (for blank/independent ABYIPs). Barangay-scoped. */
function sked_abyip_add_section(int $planId, int $actorBarangayId, string $center): array
{
    $plan = sked_abyip_get($planId, $actorBarangayId);
    if ($plan === null) {
        return ['ok' => false, 'errors' => ['Plan not found.']];
    }
    if (!in_array($center, sked_interest_categories(), true)) {
        return ['ok' => false, 'errors' => ['Please select a valid Center of Participation.']];
    }

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO abyip_sections (plan_id, center_of_participation, sort_order) VALUES (:p, :c, :o)'
        );
        $stmt->execute(['p' => $planId, 'c' => $center, 'o' => count($plan['sections'])]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ['That Center of Participation is already in this plan.']];
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'section_id' => (int) sked_db()->lastInsertId()];
}

/** Remove a section (and its line items). Barangay-scoped. */
function sked_abyip_delete_section(int $sectionId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE aps FROM abyip_sections aps
           JOIN abyip_plans ap ON ap.id = aps.plan_id
          WHERE aps.id = :s AND ap.barangay_id = :bgy'
    );
    $stmt->execute(['s' => $sectionId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Section not found.']];
}

/**
 * Add a PPA line item to a section. Barangay-scoped via the section's
 * parent plan. Total is always computed as MOOE + CO server-side.
 *
 * @param array<string,mixed> $data
 */
function sked_abyip_add_line_item(int $sectionId, int $actorBarangayId, array $data): array
{
    $owns = sked_db()->prepare(
        'SELECT aps.id FROM abyip_sections aps JOIN abyip_plans ap ON ap.id = aps.plan_id
          WHERE aps.id = :s AND ap.barangay_id = :bgy LIMIT 1'
    );
    $owns->execute(['s' => $sectionId, 'bgy' => $actorBarangayId]);
    if ($owns->fetchColumn() === false) {
        return ['ok' => false, 'errors' => ['Section not found.']];
    }

    $ppaName = trim((string) ($data['ppa_name'] ?? ''));
    if ($ppaName === '') {
        return ['ok' => false, 'errors' => ["PPA's name is required."]];
    }

    foreach (['budget_mooe' => 'MOOE', 'budget_co' => 'CO'] as $field => $label) {
        $raw = trim((string) ($data[$field] ?? ''));
        if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) {
            return ['ok' => false, 'errors' => ["$label budget must be a positive number."]];
        }
    }
    $mooe = round((float) trim((string) ($data['budget_mooe'] ?? '0')), 2);
    $co = round((float) trim((string) ($data['budget_co'] ?? '0')), 2);

    $countStmt = sked_db()->prepare('SELECT COUNT(*) FROM abyip_line_items WHERE section_id = :s');
    $countStmt->execute(['s' => $sectionId]);
    $nextOrder = (int) $countStmt->fetchColumn();

    $stmt = sked_db()->prepare(
        'INSERT INTO abyip_line_items
            (section_id, reference_code, ppa_name, description, expected_result, performance_indicator,
             period_of_implementation, budget_mooe, budget_co, budget_total, person_responsible, sort_order)
         VALUES (:s, :ref, :ppa, :desc, :result, :indicator, :period, :mooe, :co, :total, :person, :order)'
    );
    $stmt->execute([
        's' => $sectionId,
        'ref' => trim((string) ($data['reference_code'] ?? '')) ?: null,
        'ppa' => $ppaName,
        'desc' => trim((string) ($data['description'] ?? '')) ?: null,
        'result' => trim((string) ($data['expected_result'] ?? '')) ?: null,
        'indicator' => trim((string) ($data['performance_indicator'] ?? '')) ?: null,
        'period' => trim((string) ($data['period_of_implementation'] ?? '')) ?: null,
        'mooe' => $mooe,
        'co' => $co,
        'total' => round($mooe + $co, 2),
        'person' => trim((string) ($data['person_responsible'] ?? '')) ?: null,
        'order' => $nextOrder,
    ]);

    return ['ok' => true, 'errors' => [], 'item_id' => (int) sked_db()->lastInsertId()];
}

/** Remove a line item. Barangay-scoped via its section's parent plan. */
function sked_abyip_delete_line_item(int $itemId, int $actorBarangayId): array
{
    $stmt = sked_db()->prepare(
        'DELETE ali FROM abyip_line_items ali
           JOIN abyip_sections aps ON aps.id = ali.section_id
           JOIN abyip_plans ap ON ap.id = aps.plan_id
          WHERE ali.id = :i AND ap.barangay_id = :bgy'
    );
    $stmt->execute(['i' => $itemId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Line item not found.']];
}

/** Toggle draft/finalized. Barangay-scoped. */
function sked_abyip_set_status(int $planId, int $actorBarangayId, string $status): array
{
    if (!in_array($status, ['draft', 'finalized'], true)) {
        return ['ok' => false, 'errors' => ['Invalid status.']];
    }
    $stmt = sked_db()->prepare('UPDATE abyip_plans SET status = :s WHERE id = :id AND barangay_id = :bgy');
    $stmt->execute(['s' => $status, 'id' => $planId, 'bgy' => $actorBarangayId]);
    return $stmt->rowCount() > 0
        ? ['ok' => true, 'errors' => []]
        : ['ok' => false, 'errors' => ['Plan not found.']];
}

/** Sum of every line item's MOOE/CO/Total across the whole plan. */
function sked_abyip_total_budget(array $plan): array
{
    $totals = ['mooe' => 0.0, 'co' => 0.0, 'total' => 0.0];
    foreach ($plan['sections'] as $section) {
        foreach ($section['items'] as $item) {
            $totals['mooe'] += (float) ($item['budget_mooe'] ?? 0);
            $totals['co'] += (float) ($item['budget_co'] ?? 0);
            $totals['total'] += (float) ($item['budget_total'] ?? 0);
        }
    }
    return $totals;
}
