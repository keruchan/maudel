# SKed — Project Context

> Living reference for what SKed is, who it serves, and how it's built.
> Companion files: [PROGRESS.md](PROGRESS.md) · [DECISIONS.md](DECISIONS.md) · [CHANGELOG.md](CHANGELOG.md)
> Full functional spec: [`../SKed-System-Spec.md`](../SKed-System-Spec.md)

_Last updated: 2026-07-20_

---

## 1. What SKed is

**SKed** — a youth profiling, events, and engagement management platform for the
**Sangguniang Kabataan (SK)** structure. "SKed" is the system's name; it is **not
an acronym** and does not expand to anything.

**Phase-1 scope:** Municipality of **Siniloan, 4th District of Laguna** — its 20
official barangays, seeded as `province → municipality → barangay` so the DILG can
later expand to the rest of the district without a migration.

**Three pillars**
1. **Youth Profiling** → feeds a prescriptive-analytics recommender.
2. **Events & Engagement** → post / join / register / attend / evaluate, at
   barangay and inter-barangay/municipal level.
3. **Gamified Recognition** → activity points from participation drive an
   activity level shown on each youth's profile.

## 2. Roles

| Role      | Scope        | Summary |
|-----------|--------------|---------|
| **DILG**  | Superadmin   | Oversight, user provisioning, report consolidation |
| **PPSK**  | Municipal    | Inter-barangay events, SK oversight, reporting to DILG, SK account mgmt |
| **SK**    | Barangay     | Barangay events, project charters, polls, monthly reports to PPSK |
| **Youth** | Barangay     | Registration, profiling, event participation, points |

One active **SK Chairman per barangay** and one active **PPSK per municipality** at
any time. Youth eligible age: **15–30** (RA 10742).

## 3. Tech stack & conventions

- **Vanilla PHP** (no framework) + **MySQL/MariaDB** via PDO + HTML/CSS/JS.
- Runs on **XAMPP** at `c:\xampp\htdocs\SKed` → `http://localhost/SKed/`.
- Front-end: **Bootstrap 5.3.3** + Bootstrap Icons 1.11.3 (CDN); fonts **Sora**
  (display) + **Plus Jakarta Sans** (body); theme = civic indigo (`#4338ca` /
  `#818cf8`). Shared design system in `css/dashboard.css`.
- Design mirrors a source CENRO/CERTREEFY system — **reuse existing component
  classes** (`ledger-card`, `registry-card`, `docket-panel`, `page-header`,
  `section-heading`); do not introduce a new design language.

**Key files**
- `config/config.php` — hardened session + timezone (Asia/Manila) + DB include; put at top of every page.
- `config/database.php` — `sked_db(): PDO` lazy singleton.
- `config/schema.sql` — DDL. Run: `"C:\xampp\mysql\bin\mysql.exe" -u root < config/schema.sql`.
- `includes/auth.php` — `require_role()`, login/registration helpers, `sked_is_verified()`.
- `includes/navigation.php` — `render_sked_navigation($role,$activePage)` + notification bell.
- `includes/view.php` — `e()` escape helper (use on all dynamic output).
- `pages/{auth,dilg,ppsk,sk,youth}/…` — pages sit one dir under `pages/`, routes use `../`.

**Working rules (from the spec/user)**
- Apply schema changes directly (ship CREATE/ALTER **with** the PHP).
- Don't break existing features; don't discard `index.php` or the dashboards.
- Reuse the existing layout/theme for every new page.
- Flag genuinely unspecified decisions; otherwise pick the sensible default and log it in [DECISIONS.md](DECISIONS.md).

## 4. Core data entities (target)

`users`, `barangays`, `role_history`, `audit_log`, `youth_profiles`, `events`,
`event_participants`, `event_evaluations`, `project_charters`, `polls`,
`poll_responses`, `reports`, `sk_strikes`, `points_ledger`, `notifications`.

See [PROGRESS.md](PROGRESS.md) for which of these exist yet.
