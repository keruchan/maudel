# Old Reporting Process — CBYDP, ABYIP, and Project Charters (archived 2026-07-29)

> **Why this file exists**: On 2026-07-29 the CBYDP and ABYIP features were rewritten from
> a structured section/line-item data-entry system into a simple file-upload system (see
> "What replaced this" at the bottom). This document is a complete snapshot of the
> **old** structured implementation — schema, backend functions, UI pages, navigation,
> and every cross-reference — captured immediately before the rewrite so the old design
> is fully recoverable from documentation (and from git history) if it's ever needed
> again. Project Charters (`includes/charters.php` / `pages/sk/charters.php`) were **not**
> rewritten — they're documented here too for completeness since they share the
> "Programs" nav grouping and were part of the same original P6/P12 feature family, but
> their code is untouched and still live exactly as described below.

---

## 1. Database Schema (as it stood pre-rewrite)

### Source files
- `config/migrations/2026-07-21_p12_cbydp_abyip.sql` — CBYDP + ABYIP tables (Phase P12)
- `config/migrations/2026-07-21_p6_charters_polls.sql` — `project_charters` table (Phase P6; that file also defines `polls`/`poll_options`/`poll_responses`, a separate feature not covered here)
- `config/schema.sql` has no references to these tables — the canonical schema for them lived only in the two migration files above.

Both migration files note they are idempotent (`CREATE TABLE IF NOT EXISTS`) and that `created_by`/`created_by_role` columns are intentionally **not** foreign-keyed to `users`, because demo SK officials (ids 1-4) aren't real rows in `users`.

### `cbydp_plans`
```sql
CREATE TABLE IF NOT EXISTS cbydp_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    region VARCHAR(60) NOT NULL DEFAULT 'Region IV-A (CALABARZON)',
    province VARCHAR(60) NOT NULL DEFAULT 'Laguna',
    city_municipality VARCHAR(60) NOT NULL DEFAULT 'Siniloan',
    cy_year_start SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    prepared_by_name VARCHAR(150) NULL,
    prepared_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Secretary',
    approved_by_name VARCHAR(150) NULL,
    approved_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Chairperson',
    signed_file_path VARCHAR(255) NULL,
    signed_file_original_name VARCHAR(255) NULL,
    signed_uploaded_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbydp_barangay_cycle (barangay_id, cy_year_start),
    CONSTRAINT fk_cbydp_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
One row per barangay per 3-year cycle (unique on `barangay_id, cy_year_start`).

### `cbydp_sections`
```sql
CREATE TABLE IF NOT EXISTS cbydp_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id INT UNSIGNED NOT NULL,
    center_of_participation VARCHAR(50) NOT NULL,
    agenda_statement VARCHAR(1000) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbydp_section (plan_id, center_of_participation),
    CONSTRAINT fk_cbydp_section_plan FOREIGN KEY (plan_id) REFERENCES cbydp_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
One row per "Center of Participation" per plan (unique — a center can only appear once per plan). `center_of_participation` values come from `sked_interest_categories()`.

### `cbydp_line_items`
```sql
CREATE TABLE IF NOT EXISTS cbydp_line_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    youth_development_concern VARCHAR(255) NOT NULL,
    objective VARCHAR(255) NULL,
    performance_indicator VARCHAR(255) NULL,
    target_year1 VARCHAR(255) NULL,
    target_year2 VARCHAR(255) NULL,
    target_year3 VARCHAR(255) NULL,
    ppas VARCHAR(500) NULL,
    budget DECIMAL(12,2) NULL,
    person_responsible VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cbydp_line_section (section_id),
    CONSTRAINT fk_cbydp_line_section FOREIGN KEY (section_id) REFERENCES cbydp_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `abyip_plans`
```sql
CREATE TABLE IF NOT EXISTS abyip_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    cbydp_plan_id INT UNSIGNED NULL,
    region VARCHAR(60) NOT NULL DEFAULT 'Region IV-A (CALABARZON)',
    province VARCHAR(60) NOT NULL DEFAULT 'Laguna',
    city_municipality VARCHAR(60) NOT NULL DEFAULT 'Siniloan',
    calendar_year SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    prepared_by_name VARCHAR(150) NULL,
    prepared_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Secretary',
    approved_by_name VARCHAR(150) NULL,
    approved_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Chairperson',
    signed_file_path VARCHAR(255) NULL,
    signed_file_original_name VARCHAR(255) NULL,
    signed_uploaded_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_abyip_barangay_year (barangay_id, calendar_year),
    CONSTRAINT fk_abyip_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    CONSTRAINT fk_abyip_cbydp FOREIGN KEY (cbydp_plan_id) REFERENCES cbydp_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
One row per barangay per calendar year (unique). `cbydp_plan_id` nullable — set only when derived from a CBYDP; `ON DELETE SET NULL` if the source CBYDP is deleted.

### `abyip_sections`
```sql
CREATE TABLE IF NOT EXISTS abyip_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id INT UNSIGNED NOT NULL,
    center_of_participation VARCHAR(50) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_abyip_section (plan_id, center_of_participation),
    CONSTRAINT fk_abyip_section_plan FOREIGN KEY (plan_id) REFERENCES abyip_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `abyip_line_items`
```sql
CREATE TABLE IF NOT EXISTS abyip_line_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    source_cbydp_line_item_id INT UNSIGNED NULL,
    reference_code VARCHAR(20) NULL,
    ppa_name VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    expected_result VARCHAR(500) NULL,
    performance_indicator VARCHAR(255) NULL,
    period_of_implementation VARCHAR(120) NULL,
    budget_mooe DECIMAL(12,2) NOT NULL DEFAULT 0,
    budget_co DECIMAL(12,2) NOT NULL DEFAULT 0,
    budget_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    person_responsible VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_abyip_line_section (section_id),
    CONSTRAINT fk_abyip_line_section FOREIGN KEY (section_id) REFERENCES abyip_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_abyip_line_source FOREIGN KEY (source_cbydp_line_item_id) REFERENCES cbydp_line_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
`budget_total` is not a generated column — it was computed and stored server-side in PHP as `mooe + co` on every insert.

### `project_charters` (untouched, still live)
```sql
CREATE TABLE IF NOT EXISTS project_charters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    budget_amount DECIMAL(12,2) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('upcoming','ongoing','completed') NOT NULL DEFAULT 'upcoming',
    event_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    created_by_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_charter_barangay (barangay_id),
    KEY idx_charter_status (status),
    KEY idx_charter_dates (start_date, end_date),
    CONSTRAINT fk_charter_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    CONSTRAINT fk_charter_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
No unique constraint — a barangay can have any number of charters. `budget_amount` is explicitly info-only, no approval workflow (spec 4.3). `event_id` optionally links one event so a "success rate" can be derived live (not stored) from that event's youth evaluations.

---

## 2. Backend Logic Files (as they stood pre-rewrite)

### `includes/cbydp.php`
CBYDP is a 3-year rolling plan, one per barangay per cycle, grouped into sections by Center of Participation, each holding line items. SK-only create/edit; PPSK/DILG view+export.

- `sked_cbydp_create(array $creator, int $cyYearStart, string $preparedByName): array` — Creates a new CBYDP plan shell for the creator's barangay (SK-only). Validates barangay assignment and year range (2020-2100); catches duplicate-cycle unique-constraint violations (23000) and returns a friendly error.
- `sked_cbydp_get(int $planId, ?int $barangayId = null): ?array` — Fetches one plan by id (optionally barangay-scoped for access control), with its sections and each section's line items nested, plus `barangay_name` attached.
- `sked_cbydp_list_for_barangay(int $barangayId): array` — All CBYDP plans for one barangay, newest cycle first.
- `sked_cbydp_list_all(): array` — All CBYDP plans across all barangays (joined with barangay name), for PPSK/DILG oversight lists.
- `sked_cbydp_add_section(int $planId, int $actorBarangayId, string $center, string $agendaStatement): array` — Adds a Center-of-Participation section to a plan; validates the center against `sked_interest_categories()`; barangay-scoped via `sked_cbydp_get`.
- `sked_cbydp_delete_section(int $sectionId, int $actorBarangayId): array` — Deletes a section (cascades to its line items) via a JOIN-scoped DELETE ensuring the section's plan belongs to the actor's barangay.
- `sked_cbydp_add_line_item(int $sectionId, int $actorBarangayId, array $data): array` — Adds a youth-development-concern line item to a section; verifies section ownership; validates required `youth_development_concern` and numeric non-negative `budget`; computes next `sort_order`.
- `sked_cbydp_delete_line_item(int $itemId, int $actorBarangayId): array` — Deletes one line item, barangay-scoped via its section's parent plan.
- `sked_cbydp_set_status(int $planId, int $actorBarangayId, string $status): array` — Toggles plan status between `draft`/`finalized`, barangay-scoped.
- `sked_cbydp_total_budget(array $plan): float` — Sums every line item's `budget` across the whole (already-nested) plan array.

### `includes/abyip.php`
ABYIP is one per barangay per calendar year, normally created FROM a CBYDP (copying sections/line items for one year, then adjusted with reference codes / MOOE-CO split); a blank ABYIP with no CBYDP link was also supported. SK-only create/edit; PPSK/DILG view+export.

- `sked_abyip_create_blank(array $creator, int $calendarYear, string $preparedByName): array` — Creates an ABYIP shell with no CBYDP link, for the creator's barangay; validates barangay + year range; catches duplicate-year violations.
- `sked_abyip_create_from_cbydp(array $creator, int $cbydpPlanId, int $calendarYear, string $preparedByName): array` — Creates an ABYIP by copying a CBYDP's sections/line items as a starting point. Validates the calendar year falls within the CBYDP's `cy_year_start .. +2` window. Runs in a transaction: inserts the `abyip_plans` row (with `cbydp_plan_id` set), then for each CBYDP section inserts a matching `abyip_sections` row, then for each CBYDP line item inserts an `abyip_line_items` row where: `ppa_name` = the CBYDP item's `ppas` field (truncated to 255 chars) or falls back to `youth_development_concern`; `description` = CBYDP's `ppas`; `expected_result` = CBYDP's `objective`; `performance_indicator` copied; `budget_mooe` and `budget_total` both set to the CBYDP item's `budget` (CO left at 0); `source_cbydp_line_item_id` set to the original item's id. Rolls back and returns a generic error on any exception.
- `sked_abyip_get(int $planId, ?int $barangayId = null): ?array` — Same shape/pattern as `sked_cbydp_get`, for ABYIP.
- `sked_abyip_list_for_barangay(int $barangayId): array` — All ABYIP plans for a barangay, newest year first.
- `sked_abyip_list_all(): array` — All ABYIP plans municipality-wide (joined with barangay name) for oversight.
- `sked_abyip_add_section(int $planId, int $actorBarangayId, string $center): array` — Adds a Center-of-Participation section (used for blank/independent ABYIPs); validates against `sked_interest_categories()`.
- `sked_abyip_delete_section(int $sectionId, int $actorBarangayId): array` — Deletes a section (cascades line items), barangay-scoped.
- `sked_abyip_add_line_item(int $sectionId, int $actorBarangayId, array $data): array` — Adds a PPA line item; requires non-empty `ppa_name`; validates `budget_mooe`/`budget_co` are non-negative numbers if provided; always computes `budget_total = round(mooe + co, 2)` server-side.
- `sked_abyip_delete_line_item(int $itemId, int $actorBarangayId): array` — Deletes one line item, barangay-scoped.
- `sked_abyip_set_status(int $planId, int $actorBarangayId, string $status): array` — Toggles `draft`/`finalized`, barangay-scoped.
- `sked_abyip_total_budget(array $plan): array` — Returns `['mooe' => float, 'co' => float, 'total' => float]` summed across all line items in the nested plan array.

### `includes/charters.php` (untouched, still live)
SK Project Charter tracker (P6, spec 4.3) — upcoming/ongoing/completed projects with an info-only budget field (no approval workflow) and an optional event link powering a live-derived success rate.

- `sked_create_charter(array $creator, array $data): array`
- `sked_charters_for_barangay(int $barangayId, array $filters = []): array` — filters: `status`, `date_from`/`date_to` (overlapping-range).
- `sked_get_charter(int $charterId): ?array`
- `sked_charter_success_rate(array $charter): array` — `['count'=>int,'avg'=>?float]`, derived live from the linked event's evaluations.
- `sked_update_charter(int $charterId, int $actorBarangayId, array $data): array` — status-only update.

### `includes/plan_uploads.php` (untouched, still live — used by Katitikan)
Shared signed-copy upload handling originally for CBYDP, ABYIP (P12), and Katitikan (P13). `SKED_PLAN_UPLOAD_TYPES = ['cbydp','abyip','katitikan']`. Still used by the Katitikan feature; left in place. `pages/manage/plan_file.php` (the gated streaming download for these signed copies) is likewise untouched.

---

## 3. UI Pages (as they stood pre-rewrite)

### `pages/sk/cbydp.php` — SK CBYDP list + create
Role gate `require_role('sk')`. GET listed the SK's own barangay's plans (Cycle / Status / Action→Open). POST create form: `cy_year_start` (number, 2020-2100, default current year), `prepared_by_name` (text). No delete.

### `pages/sk/abyip.php` — SK ABYIP list + create
Role gate `require_role('sk')`. GET listed the SK's own plans (Year / Source badge "From CBYDP"/"Blank" / Status / Action). POST create form: `calendar_year` (number), `cbydp_plan_id` (select, blank = "start blank instead"), `prepared_by_name`. No delete.

### `pages/sk/charters.php` — SK Project Charters (untouched, still live)
Browser tab title "SKed | CBYDP / Projects", nav label "Project Charters" (key `projects`) — a DIFFERENT feature from the CBYDP plan despite the overlapping name in the old page title. Create form: `title`, `description`, `start_date`/`end_date`, `budget_amount` (info only), `status` (Upcoming/Current/Completed), `event_id` (optional, powers success rate). Separate `form=status` action for inline status updates. GET filters: `status`, `date_from`, `date_to`.

### `pages/manage/cbydp_plan.php` — CBYDP detail/edit (shared, now DELETED)
Role gate `require_roles(['sk','ppsk','dilg','youth'])`. `$isEditable` = SK + own barangay. Youth needed `status==='finalized'` + own barangay. Actions: `add_section` (center + agenda_statement), `delete_section`, `add_item` (9 line-item fields), `delete_item`, `set_status`, `upload_signed`. Rendered each Center-of-Participation section as its own panel with an editable line-item table + collapsible "Add a line item" form.

### `pages/manage/abyip_plan.php` — ABYIP detail/edit (shared, now DELETED)
Mirror of `cbydp_plan.php` with the same access rules. `add_item` fields: `reference_code`, `ppa_name`, `description`, `expected_result`, `performance_indicator`, `period_of_implementation`, `budget_mooe`, `budget_co`, `person_responsible`. Header showed MOOE/CO/Total budget totals.

### `pages/manage/cbydp_export.php` / `pages/manage/abyip_export.php` (now DELETED)
Standalone print-styled HTML matching the real DILG/NYC Annex template layout, explicitly without barangay/SK seal logos, with signature blocks (Prepared by / Approved by).

### `pages/ppsk/plans.php` / `pages/dilg/plans.php` — oversight lists (rewritten, see below)
Purely view-only aggregators: two panels (CBYDP table, ABYIP table) — Barangay / Cycle-or-Year / Status / Signed Copy badge / Action (View, Export).

---

## 4. Navigation Entries (pre-rewrite)

**SK role**, section "Programs": `projects`→`charters.php` "Project Charters"; `cbydp`→`cbydp.php` "CBYDP"; `abyip`→`abyip.php` "ABYIP" (three separate entries).

**PPSK role**, section "Programs": `plans`→`plans.php` "Youth Development Plans" (one combined entry covering both CBYDP+ABYIP oversight).

**DILG role**, section "Programs": `plans`→`plans.php` "Youth Development Plans" (same pattern).

**Youth role**: no direct nav entry; accessed CBYDP/ABYIP (finalized only) + charters via `full_disclosure.php` ("Full Disclosure Board").

---

## 5. Cross-References (pre-rewrite)

- **`pages/index.php`**: `$programsCompletedCount` = count of finalized CBYDP + finalized ABYIP (`sked_cbydp_list_all()`/`sked_abyip_list_all()` filtered on `status==='finalized'`), shown in the public stats band.
- **`pages/ppsk/dashboard.php`**: `$totalPlansCount` = count(`sked_cbydp_list_all()`) + count(`sked_abyip_list_all()`), a dashboard stat tile.
- **`pages/youth/full_disclosure.php`**: built `$charters`/`$cbydps`/`$abyips` arrays from the barangay's finalized plans, showing budgets and (for charters) live success rate.
- **`config/seeds/populate_demo_data.php`** and **`config/seeds/focus_barangay_reset.php`**: seeded demo CBYDP/ABYIP data via `sked_cbydp_create()`→`add_section`→`add_line_item`→`set_status('finalized')`, then `sked_abyip_create_from_cbydp()`→`set_status('finalized')`.
- **`sked_interest_categories()`** (`includes/profiling.php`) supplied the "Center of Participation" list used throughout CBYDP/ABYIP section dropdowns: Health, Education, Economic Empowerment, Social Inclusion & Equity, Peace Building and Security, Governance, Active Citizenship, Environment, Global Mobility, Agriculture.

---

## What replaced this (2026-07-29)

Per the user's request, CBYDP and ABYIP were rewritten from structured section/line-item
data entry into a simple **file-upload** model — plus two new upload categories:
Annual Budget and Monthly Itemized List of Purchase Request. No more field-by-field
plan authoring; SK just uploads the finished document with a date scope (year, or
year+month for the monthly purchase-request category). Every upload is immediately
public — viewable via a "View Attachment" link by any user, including anonymous
visitors, with no draft/finalized workflow. See `includes/plan_documents.php`,
`pages/sk/plans.php`, `pages/ppsk/plans.php`, `pages/dilg/plans.php`, and
`pages/public/plan_document.php` for the new implementation.

The old tables (`cbydp_plans`/`cbydp_sections`/`cbydp_line_items`/`abyip_plans`/
`abyip_sections`/`abyip_line_items`) were **left in the database, not dropped** —
any already-finalized historical plans remain queryable directly via SQL or through
`includes/cbydp.php`/`includes/abyip.php`, which were also left in place (just no
longer wired into any page). `includes/plan_uploads.php` and
`pages/manage/plan_file.php` were **not** touched — they're still used by Katitikan.

**Update (same day, immediately after the rewrite above): Project Charters retired
too.** The user asked for "no more project charters" right after the CBYDP/ABYIP
rewrite landed. `pages/sk/charters.php` was deleted, the SK nav's "Project Charters"
entry (`includes/navigation.php`) was removed, and the Project Charters
table/stat-card on `pages/youth/full_disclosure.php` was removed (that page now
shows only the Plan Documents disclosure table). `includes/charters.php` and the
`project_charters` table were **left in place, untouched** (same
don't-delete-historical-data convention as the CBYDP/ABYIP tables above) — the
schema/functions in §1/§2 remain accurate for that table, it's just no longer
reachable from any page.
