# SKed — Changelog

> Dated log of what actually shipped. Add a line whenever a [PROGRESS.md](PROGRESS.md)
> item completes. Newest first.

---

## 2026-07-21 (post-roadmap)
- **Real per-role credentials + 30-minute idle auto-logout.** Reworked
  `config/seeds/role_demo_accounts.php` from create-if-missing to a proper
  UPSERT, and gave each of the 4 real persisted accounts (`dilgadmin`,
  `ppskpresident`, `skacevida`, `youthacevida`) its own distinct password
  instead of a shared one. Added a genuine 30-minute idle-session timeout,
  enforced two ways: **server-side** (`SKED_IDLE_TIMEOUT_SECONDS` in
  `includes/auth.php`, checked in `require_roles()` on every request — the
  real security boundary, works even without JS) and **client-side**
  (`js/idle-timeout.js`, a small vanilla script that tracks mouse/keyboard/
  touch/scroll activity and auto-submits the sidebar's logout form after 30
  minutes of none — the fast, proactive UX layer). Background notification
  polling deliberately does NOT count as activity for either layer, so an
  open-but-unused tab still times out correctly. `pages/auth/logout.php`
  now accepts `reason=idle` and redirects to `login.php?timeout=1`, which
  shows "You were signed out after 30 minutes of inactivity." Verified by
  directly backdating a real PHP session file to simulate 33+ minutes idle
  and confirming the server-side redirect, session clear, and message all
  fire correctly, plus confirmed normal sessions and manual logout are
  unaffected (regression-checked across all 4 roles).

## 2026-07-21
- **P10 Notifications & audit complete — the full P0–P10 roadmap is now
  shipped.** Built the read-side UI for two tables that have existed and
  been populated since the very early phases (`notifications` since P2,
  `audit_log` since P0): a real polling-based notification bell (spec 4.5)
  and a DILG audit trail viewer (spec recommendation #8). New
  `pages/api/notifications.php` — a small JSON endpoint (GET for the list,
  POST `mark_one`/`mark_all`, CSRF-protected, 401s when unauthenticated) —
  and `js/notifications.js`, plain vanilla JS polling every 45s and
  rendering the dropdown panel. Extended `includes/notifications.php`
  (`sked_mark_notification_read`, `sked_notification_visual` — per-type
  icon/accent, `sked_relative_time`) and `includes/audit.php`
  (`sked_audit_log_entries` with action/date-range filters,
  `sked_audit_distinct_actions`). New `pages/dilg/audit.php`. Nice
  discovery: the full `.notif-*` panel/badge/list CSS was already sitting
  in `css/dashboard.css`, ported from the source system during the original
  skeleton pass and unused until now — the bell markup in
  `render_sked_notification_bell()` (rewritten in `includes/navigation.php`,
  a function called by all 25 protected pages) was written to match those
  existing classes exactly rather than inventing new ones. Also cleared
  ~40 stray audit_log rows and leftover test notifications that had
  accumulated from earlier phases' testing (all referenced already-deleted
  test accounts) for a genuinely clean baseline. Verified: unauthenticated
  API access rejected, CSRF-rejected POST confirmed, full mark-one/mark-all
  cycle over real HTTP, audit filter correctness, and a full regression
  sweep across all 4 roles' dashboards plus non-role-folder pages
  (account/, manage/) confirming the navigation.php rewrite didn't break
  anything anywhere in the app.
- **P9 Prescriptive analytics complete.** Added `polls.category` (migration
  `2026-07-21_p9_analytics.sql`) so poll engagement can feed the same
  canonical taxonomy (`sked_interest_categories()`) already shared by youth
  profiling and event categories. New `includes/analytics.php`:
  `sked_category_interest_shares()`, `sked_category_poll_shares()`,
  `sked_category_success_scores()` each compute one 0-100 signal per
  category (optionally barangay-scoped, null = municipality-wide), and
  `sked_recommend_categories()` combines them via a weighted average
  (interest 0.5, poll 0.3, success 0.2) that **excludes and renormalizes
  around missing signals** rather than treating absent data as zero — a
  category with no evidence at all is omitted from the ranking entirely.
  `sked_explain_recommendation()` renders the evidence trail behind each
  score. New pages `pages/{sk,ppsk,dilg}/analytics.php` (full ranked list +
  plain-language methodology section) and compact top-3 "Recommended Focus
  Areas" widgets on the SK and PPSK dashboards, linking through to the full
  page. SK's poll-creation form gained an optional topic/category field.
  Verified by hand-computing an exact scenario end to end (4 profiled youth,
  2 category-tagged polls, 1 evaluated event) and matching the engine's
  output to the arithmetic to one decimal place, then confirming the correct
  empty-state at baseline and correct data flow through both dashboard
  widgets and full pages across SK/PPSK/DILG over real HTTP requests.
- **P8 Turnover of power complete.** Added `turnover` to the reports type
  enum + `new_officer_name/email/mobile` columns, plus a new `turnover_roster`
  table (migration `2026-07-21_p8_turnover.sql`). New `includes/turnover.php`
  built around `sked_provision_officer()` — the core primitive shared by every
  provisioning path: it retires any current active officer of that role+scope
  via P7's `sked_retire_official()`, then either **promotes an existing active
  verified youth account matched by email** (the realistic case — SK
  Chairmen/PPSK are themselves KK youth) or creates a brand-new account with a
  generated username/password (returned once, never stored in plaintext).
  `sked_submit_turnover_report()` (outgoing PPSK, with a 20-barangay roster
  form), `sked_activate_new_ppsk()` / `sked_decline_turnover_report()` (DILG),
  `sked_provision_sk_from_roster()` (new PPSK's self-service delegation, spec
  6.1 step 4). New pages `pages/dilg/turnover.php` and `pages/ppsk/turnover.php`
  — each also exposes a direct-designation shortcut (DILG→PPSK bootstrap,
  PPSK→SK for filling a single vacancy, e.g. right after a P7 dismissal) outside
  the full election-roster flow. One-active-per-scope is enforced procedurally
  (retire-then-create) since MySQL/MariaDB has no partial unique index. Verified
  exhaustively: username collision handling, every promote/create/reject branch,
  the full report+roster lifecycle, and — over real HTTP requests — the entire
  chain end to end: PPSK submits → DILG activates with real issued credentials →
  new PPSK logs in with them → provisions an SK → that SK logs in and lands on
  their own dashboard. Also confirmed the former-role badge renders correctly
  on P5's activity page for a freshly-retired officer.
- **P7 Reporting & compliance complete.** Added `reports` (generic
  monthly/interbarangay/minutes/dismissal_recommendation submissions, with a
  `UNIQUE(barangay_id, type, period_month)` constraint that only bites on
  monthly reports thanks to MySQL's NULL-is-distinct unique-index semantics)
  and `sk_strikes` (migration `2026-07-21_p7_reports_compliance.sql`). New
  `includes/role_transitions.php`: `sked_retire_official()` — a shared primitive
  that reverts an active SK/PPSK to Youth with a "Former ..." badge and closes
  their `role_history` term; built now for P7's dismissal flow but written to be
  reused by P8's turnover, same pattern as points/notifications being built for
  their first real consumer. New `includes/reports.php` (submit/list/review) and
  `includes/compliance.php` (`sked_run_compliance_check()` — idempotent, checks
  once past the 10th whether last month's report is missing and strikes + notifies;
  `sked_escalate_to_dilg()`; `sked_process_dismissal()`). Pages: `pages/sk/reports.php`
  (submit + compliance status + strike history), `pages/ppsk/reports.php` (review SK
  reports + submit to DILG), `pages/ppsk/compliance.php` (strike overview + escalate),
  `pages/dilg/reports.php` (federation report review), `pages/dilg/compliance.php`
  (pending dismissals + process action). Cron: `config/cron/compliance_check.php`.
  Nav: SK gained "Monthly Reports"; PPSK's stubbed "Reports" repointed + new
  "SK Compliance"; DILG gained both "Reports" and "Dismissal Review". Verified via
  extensive logic tests (after catching and fixing a self-inflicted test-isolation
  bug) and full HTTP flows across all three roles, including confirming the P5
  activity page correctly renders a freshly-dismissed official's former-role badge.
- **P6 Charters & polls complete.** Added `project_charters`, `polls`,
  `poll_options`, `poll_responses` (migration `2026-07-21_p6_charters_polls.sql`).
  New `includes/charters.php`: `sked_create_charter` (title/dates/info-only
  `budget_amount`/status, optional event link restricted to events the SK
  actually manages), `sked_charters_for_barangay` (status + overlapping
  date-range filters), `sked_charter_success_rate` (derived live from the
  linked event's evaluations via `sked_event_rating()`, not stored — same
  derive-on-read pattern as P5's activity levels), `sked_update_charter`.
  New `includes/polls.php`: `sked_create_poll` (2-6 normalized options),
  `sked_cast_poll_vote` (barangay-scoped, one vote per youth, awards points),
  `sked_poll_results`, `sked_set_poll_status`. New pages: `pages/sk/charters.php`
  (create + GET-based filters + inline status update), `pages/sk/polls.php`
  (create + publish/close + live results bars), `pages/youth/polls.php`
  (vote once, see results after voting). Nav: SK's stubbed "CBYDP / Projects"
  repointed to charters.php; new "Polls" nav item for both SK and youth. Also
  fixed leftover P4 gaps on the SK dashboard (events/registrations figures were
  still hardcoded placeholders) and wired the new project count. Verified: full
  validation matrix, cross-barangay event-link rejection, date-range filter
  correctness, one-vote-per-youth enforcement, and complete HTTP flows.
- **P5 Gamification complete.** Added activity-level derivation to
  `includes/points.php`: `sked_activity_levels()` (5 tiers — Newcomer 0,
  Bronze 10, Silver 30, Gold 60, Platinum 100), `sked_activity_level()`
  (current tier + next + points-to-next + progress %), `sked_points_history()`
  (chronological ledger with event titles joined in), `sked_points_action_label()`.
  New page `pages/youth/activity.php` — fills the "Profile" nav slot that had
  been a stub since the skeleton pass; shows identity, verification status,
  former-role badge (column ready from P0 for the P8 turnover workflow),
  activity level with a progress bar, and full points history. Youth dashboard's
  points card and activity docket row now show the live level and link through.
  Verified: exact tier-boundary math at all 5 thresholds, points history with
  event-title joins, full HTTP flow, graceful demo-account handling.
- **P4 Events & engagement complete.** Added `events`, `event_barangays`,
  `event_participants`, `event_evaluations` (migration `2026-07-20_p4_events.sql`).
  New `includes/events.php`: role-scoped creation (SK barangay-only; PPSK
  municipal/inter-barangay), share-token generation, youth eligibility (scope +
  verified, enforced at the page layer consistent with existing convention),
  join/register with a row-locked capacity check (no overbooking race), cancel,
  manage-authorization (`sked_can_manage_event`), a linear lifecycle state machine
  (`sked_event_next_statuses`/`sked_set_event_status`), attendance marking (awards
  points once), evaluation submission (attendees-only, once, feeds a rating
  average), and `sked_run_event_maintenance()` for cron (auto-cancels
  under-subscribed published events past their registration deadline, sends
  day-before reminders) — wired to a CLI-only `config/cron/event_maintenance.php`.
  New pages: `pages/sk/events.php`, `pages/ppsk/events.php` (with a barangay
  multi-select for inter-barangay events), `pages/manage/event.php` (shared
  roster/lifecycle/attendance page for SK/PPSK/DILG, authorized per role+scope),
  `pages/youth/events.php` (browse/join/register/cancel/evaluate, team-sport team
  name capture), and `pages/public/event.php` (the no-login landing page the
  management-only share link points to). SK/PPSK/youth dashboards wired to live
  event counts. Verified extensively: direct logic tests for every rule (capacity
  full, out-of-scope block, double-join block, cross-barangay manage block, invalid
  lifecycle transition block, attendee-only evaluation, idempotent points,
  auto-cancel vs. met-minimum control case) plus full HTTP request-flow tests
  through real login/CSRF/session cycles for both slices.
- **Mid-phase incident:** MySQL's InnoDB redo log corrupted (unrelated to SKed
  work — shared XAMPP instance also hosting ~15 other project databases; disk was
  at 93% full, the likely trigger). User approved standard recovery (rename
  `ib_logfile0`/`ib_logfile1`, let InnoDB rebuild); user applied it manually after
  a sandbox permission block. Verified after recovery: all `sked_db` tables and
  row counts intact, zero data loss.

## 2026-07-20
- **P3 Youth profiling complete.** Added `youth_profiles` (versioned, v1),
  `youth_interests` (normalized multi-select for analytics), and `points_ledger`
  (migration `2026-07-20_p3_profiling.sql`). New `includes/profiling.php` (field/
  interest definitions + validated get/save with interest replacement) and
  `includes/points.php` (`sked_award_points` idempotent per user+action+ref,
  `sked_total_points`, points scheme). New verified-only page
  `pages/youth/profile.php`; completing a profile awards 10 points once. Youth
  dashboard now shows live points + profile-completion state and gates the profile
  card behind verification. Verified end-to-end over HTTP.
- **P2 Youth verification complete.** Added `notifications` table (migration
  `2026-07-20_p2_verification.sql`) and reusable infra: `includes/audit.php`
  (`sked_audit`, with a NULL-actor fallback for demo accounts), `includes/
  notifications.php` (`sked_notify`/`sked_unread_count`/`sked_recent_notifications`/
  `sked_mark_all_read`), and `includes/verification.php` (barangay-scoped list +
  `sked_verify_youth`/`sked_reject_youth`). New SK page `pages/sk/verify.php`
  (Membership Validation queue with Verify/Reject, CSRF, age badges); nav links and
  SK dashboard counts repointed to live data. Added `sked_require_verified()` gate
  for future youth-only features. Demo SK is now barangay Acevida (id 1); seed
  `config/seeds/demo_pending_youth.php` adds 4 pending youth (password `youth123`).
  Verified end-to-end over HTTP.
- **P1 Auth & accounts hardening complete.** Added `birthdate` to `users`
  (migration `2026-07-20_p1_auth_accounts.sql`). Registration now requires a
  barangay (seeded dropdown) and date of birth, enforcing KK age 15–30; new youth
  are created with `status='pending'`. Login now sets `status`/`barangay_id` in
  session and blocks `rejected` accounts. Added shared self-service
  `pages/account/settings.php` (change password + edit mobile; demo accounts get a
  read-only notice). Made the sidebar location-aware via a `$linkBase` arg so
  sibling-folder pages resolve dashboard/module links. New auth helpers:
  `sked_age_from_birthdate`, `sked_find_user_by_id`, `sked_update_password`,
  `sked_update_own_contact`. Lint-clean; logic + HTTP flows verified.
- **P0 Data foundation complete.** Created `barangays` (seeded with Siniloan's 20
  barangays), `role_history`, and `audit_log` tables; extended `users` with
  `status`, `barangay_id`, `former_role_badge`, `term_start`, `term_end` + barangay FK.
  Shipped `config/migrations/2026-07-20_p0_data_foundation.sql` (idempotent), updated
  `config/schema.sql` for fresh installs, and added `includes/barangays.php` (list /
  name / exists / dropdown-render helpers). Applied and verified on live MariaDB 10.4.32.
- Added project tracking docs: `docs/PROJECT-CONTEXT.md`, `docs/PROGRESS.md`,
  `docs/DECISIONS.md`, `docs/CHANGELOG.md`.
- Logged decision D-001: prescriptive analytics = rule-based weighted scoring.
- Baseline reviewed: skeleton pass (landing page, 4 role dashboards, role-aware nav,
  hardened sessions, demo login, real youth registration/login, `users` table) confirmed complete.
