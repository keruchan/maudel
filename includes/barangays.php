<?php
/**
 * ============================================================
 * File     : includes/barangays.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared geographic helpers for Region IV-A / Laguna /
 *            Siniloan address dropdowns and barangay scoping.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';

const SKED_REGION_CODE = '0400000000';
const SKED_REGION_NAME = 'Region IV-A (CALABARZON)';
const SKED_PROVINCE_NAME = 'Laguna';
const SKED_DEFAULT_MUNICIPALITY = 'Siniloan';

/**
 * Laguna cities/municipalities from the PSA PSGC listing for the province.
 *
 * @return array<int,string>
 */
function sked_laguna_municipalities(): array
{
    return [
        'Alaminos',
        'Bay',
        'City of Biñan',
        'City of Cabuyao',
        'City of Calamba',
        'Calauan',
        'Cavinti',
        'Famy',
        'Kalayaan',
        'Liliw',
        'Los Baños',
        'Luisiana',
        'Lumban',
        'Mabitac',
        'Magdalena',
        'Majayjay',
        'Nagcarlan',
        'Paete',
        'Pagsanjan',
        'Pakil',
        'Pangil',
        'Pila',
        'Rizal',
        'City of San Pablo',
        'City of San Pedro',
        'Santa Cruz',
        'Santa Maria',
        'City of Santa Rosa',
        'Siniloan',
        'Victoria',
    ];
}

/** Local purok choices used by SKed address forms. */
function sked_purok_options(): array
{
    return [
        'Purok 1',
        'Purok 2',
        'Purok 3',
        'Purok 4',
        'Purok 5',
        'Purok 6',
        'Purok 7',
        'Purok 8',
        'Purok 9',
        'Purok 10',
    ];
}

/**
 * All active barangays for a municipality, alphabetical by name.
 * Result is cached per-request so repeated dropdowns do not re-query.
 *
 * @return array<int,array{id:int,name:string,municipality:string,province:string}>
 */
function sked_barangays(string $municipality = SKED_DEFAULT_MUNICIPALITY, string $province = SKED_PROVINCE_NAME): array
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

/** True if a Laguna municipality/city name is in the PSGC-backed list. */
function sked_laguna_municipality_exists(string $municipality): bool
{
    return in_array($municipality, sked_laguna_municipalities(), true);
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
    $stmt = sked_db()->prepare('SELECT name FROM barangays WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $barangayId]);
    $name = $stmt->fetchColumn();
    return $name === false ? '' : (string) $name;
}

/** True if the id refers to an existing, active barangay in Siniloan. */
function sked_barangay_exists(int $barangayId): bool
{
    return sked_barangay_in_scope($barangayId);
}

/** True when a barangay id belongs to a specific municipality/province. */
function sked_barangay_in_scope(int $barangayId, string $municipality = SKED_DEFAULT_MUNICIPALITY, string $province = SKED_PROVINCE_NAME): bool
{
    foreach (sked_barangays($municipality, $province) as $b) {
        if ($b['id'] === $barangayId) {
            return true;
        }
    }
    return false;
}

/**
 * Render the <option> list for a barangay <select>.
 *
 * @param int|null $selectedId  Pre-selected barangay id, if any.
 * @param string   $placeholder First, value-less option label.
 */
function sked_render_barangay_options(?int $selectedId = null, string $placeholder = 'Select barangay...', string $municipality = SKED_DEFAULT_MUNICIPALITY): void
{
    echo '<option value="">' . e($placeholder) . '</option>';
    foreach (sked_barangays($municipality) as $b) {
        $sel = ($selectedId !== null && $b['id'] === $selectedId) ? ' selected' : '';
        echo '<option value="' . e((string) $b['id']) . '"' . $sel . '>' . e($b['name']) . '</option>';
    }
}

/** Render Laguna municipality options, selecting Siniloan by default. */
function sked_render_municipality_options(string $selected = SKED_DEFAULT_MUNICIPALITY): void
{
    foreach (sked_laguna_municipalities() as $municipality) {
        $sel = $municipality === $selected ? ' selected' : '';
        echo '<option value="' . e($municipality) . '"' . $sel . '>' . e($municipality) . '</option>';
    }
}

/** Render local purok options. */
function sked_render_purok_options(string $selected = '', string $placeholder = 'Select purok...'): void
{
    echo '<option value="">' . e($placeholder) . '</option>';
    foreach (sked_purok_options() as $purok) {
        $sel = $purok === $selected ? ' selected' : '';
        echo '<option value="' . e($purok) . '"' . $sel . '>' . e($purok) . '</option>';
    }
}
