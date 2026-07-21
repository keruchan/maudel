<?php
/**
 * ============================================================
 * File     : includes/barangays.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Read helpers for the seeded `barangays` table (P0 data
 *            foundation). Keeps the barangay list out of PHP source so
 *            future districts/municipalities can be added by data alone.
 *
 * Requires config/database.php (sked_db()) to be loaded first — it is,
 * via config/config.php, on every protected page.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';

/**
 * All active barangays for a municipality, alphabetical by name.
 * Result is cached per-request so repeated dropdowns don't re-query.
 *
 * @return array<int,array{id:int,name:string,municipality:string,province:string}>
 */
function sked_barangays(string $municipality = 'Siniloan', string $province = 'Laguna'): array
{
    static $cache = [];
    $key = strtolower($province . '|' . $municipality);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = sked_db()->prepare(
        'SELECT id, name, municipality, province
           FROM barangays
          WHERE is_active = 1 AND municipality = :m AND province = :p
          ORDER BY name ASC'
    );
    $stmt->execute(['m' => $municipality, 'p' => $province]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['id'] = (int) $row['id'];
        $rows[] = $row;
    }

    return $cache[$key] = $rows;
}

/** Display name for a barangay id, or '' if unknown/null. */
function sked_barangay_name(?int $barangayId): string
{
    if ($barangayId === null) {
        return '';
    }
    foreach (sked_barangays() as $b) {
        if ($b['id'] === $barangayId) {
            return $b['name'];
        }
    }
    // Fall back to a direct lookup for barangays outside the default scope.
    $stmt = sked_db()->prepare('SELECT name FROM barangays WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $barangayId]);
    $name = $stmt->fetchColumn();
    return $name === false ? '' : (string) $name;
}

/** True if the id refers to an existing, active barangay. */
function sked_barangay_exists(int $barangayId): bool
{
    foreach (sked_barangays() as $b) {
        if ($b['id'] === $barangayId) {
            return true;
        }
    }
    return false;
}

/**
 * Render the <option> list for a barangay <select>.
 * Requires includes/view.php (e()) for escaping — loaded on protected pages.
 *
 * @param int|null $selectedId  Pre-selected barangay id, if any.
 * @param string   $placeholder First, value-less option label.
 */
function sked_render_barangay_options(?int $selectedId = null, string $placeholder = 'Select barangay…'): void
{
    echo '<option value="">' . e($placeholder) . '</option>';
    foreach (sked_barangays() as $b) {
        $sel = ($selectedId !== null && $b['id'] === $selectedId) ? ' selected' : '';
        echo '<option value="' . e((string) $b['id']) . '"' . $sel . '>' . e($b['name']) . '</option>';
    }
}
