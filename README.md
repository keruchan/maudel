# SKed — A Web-Based Youth Profiling System for Event Management

Skeleton build for the Sangguniang Kabataan (SK) youth council system. This
pass delivers the **system design only**: file structure, layout, navigation,
per-role dashboards, and the visual theme. No database, business logic, real
auth, or role permissions yet — those follow in a later functional pass.

The structure, layouts, animations, and component patterns mirror the source
CENRO/CERTREEFY system; only the **branding, content, fonts, and colors** are
re-themed for the SK.

## Visual identity

- **Fonts:** Sora (display / headings) + Plus Jakarta Sans (body). Font *sizes*
  are unchanged from the source system.
- **Palette:** civic indigo (`#4338ca`) + iris (`#818cf8`) primary, deep
  midnight-indigo (`#1e1b4b`) dark surfaces, cool canvas (`#f5f5fb`) backgrounds,
  with gold / sky / coral accents for status coding.

## Roles & credentials

**Quick demo logins** (no password, no DB row — session-only shortcut, useful for fast role-switching but can't do things tied to a real account like changing password or being verified/dismissed/promoted):

| Username | Role         | Description                                    |
|:--------:|--------------|-------------------------------------------------|
| `1`      | DILG         | Superadmin — system-wide oversight              |
| `2`      | PPSK         | Pederasyon President — all SK across barangays  |
| `3`      | SK Chairman  | Barangay-level SK head (acts as Acevida's SK)   |
| `4`      | Youth        | General end user / community member (Acevida)   |

**Real persisted accounts** (one per role, each with its own distinct password — seeded/reset via `config/seeds/role_demo_accounts.php`, an idempotent UPSERT) — use these for anything that needs a real database row: account settings, being a verification/dismissal/turnover target, actually joining events/polls, etc.:

| Username         | Password             | Role  | Barangay |
|-------------------|----------------------|-------|----------|
| `dilgadmin`       | `DilgSked#2026!`     | DILG  | —        |
| `ppskpresident`   | `PpskSked#2026!`     | PPSK  | —        |
| `skacevida`       | `SkChairSked#2026!`  | SK    | Acevida  |
| `youthacevida`    | `YouthSked#2026!`    | Youth | Acevida  |

## Session policy

Every account is **automatically signed out after 30 minutes of inactivity**, shown
a message on the next login screen. Enforced server-side on every request
(`SKED_IDLE_TIMEOUT_SECONDS` in `includes/auth.php`) and proactively client-side
(`js/idle-timeout.js`, auto-logs-out the moment 30 idle minutes pass, no click
needed). Background notification polling does not count as activity.

## Run it

Served from XAMPP `htdocs`:

- Landing page: `http://localhost/SKed/`
- Login: `http://localhost/SKed/pages/auth/login.php`

## File structure

```
SKed/
├── index.php                     # Root redirect -> pages/index.php
├── README.md
├── config/
│   └── config.php                # Session bootstrap (NO DB in this pass)
├── includes/
│   ├── auth.php                  # Demo login + role routing (no DB)
│   ├── navigation.php            # Role-aware sidebar / mobile nav
│   └── view.php                  # e() HTML-escape helper
├── css/
│   └── dashboard.css             # Shared design system (re-themed)
└── pages/
    ├── index.php                 # Public landing page (pre-login)
    ├── auth/
    │   ├── auth.css              # Login styling
    │   ├── login.php             # Demo login (usernames 1–4)
    │   └── logout.php            # CSRF-protected logout
    ├── dilg/
    │   └── dashboard.php         # DILG (Superadmin) dashboard
    ├── ppsk/
    │   └── dashboard.php         # PPSK (Federation President) dashboard
    ├── sk/
    │   └── dashboard.php         # SK Chairman (barangay) dashboard
    └── youth/
        └── dashboard.php         # Youth / Community dashboard
```

## Static pages

The source system exposes its "static" content (About, Services, Process, FAQ,
Contact) as **anchored sections within the single public landing page** rather
than separate files. SKed mirrors that exactly — see the corresponding sections
in `pages/index.php` (`#about`, `#services`, `#process`, `#faq`, `#contact`).

## Notes for the next pass

- Feature links in the sidebars currently point back to each role's
  `dashboard.php` so nothing 404s; repoint them to real pages as modules are
  built.
- `config/config.php` intentionally omits the PDO connection. Wiring in a real
  `$pdo` + schema is a drop-in change that mirrors the source layout.
- `includes/auth.php` holds the demo credential map (`sked_demo_users()`) and
  session-only guards; replace with real credential verification, account
  status, throttling, and audit logging later.
- The notification bell in dashboard headers is a static placeholder in this
  pass (no panel/feed wired yet).
