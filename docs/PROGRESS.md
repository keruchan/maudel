# SKed — Progress Tracker

> Phase-by-phase build status. Update the status column as work lands, and add a
> dated line to [CHANGELOG.md](CHANGELOG.md) when a phase item completes.
> Context: [PROJECT-CONTEXT.md](PROJECT-CONTEXT.md) · Decisions: [DECISIONS.md](DECISIONS.md)

_Last updated: 2026-07-21 · **All phases (P0–P10) complete.** Future work is polish/hardening, not roadmap phases — see the note at the bottom of this file._

**Legend:** ✅ done · 🚧 in progress · ⬜ not started

---

## Baseline — Skeleton pass (design/layout/theme) — ✅ done

| Item | Status |
|------|:--:|
| Public landing page (`pages/index.php`, anchored sections) | ✅ |
| 4 role dashboards — layout only, static placeholder figures | ✅ |
| Role-aware sidebar + mobile nav (`render_sked_navigation`) | ✅ |
| Hardened sessions, CSRF-protected logout, `e()` escaping | ✅ |
| Demo login (usernames `1`–`4`, no password) | ✅ |
| Real youth registration + login persisted in `users` (MySQL) | ✅ |
| `users` table (id, role, name, email, mobile, username, password_hash, verified) | ✅ |

## P0 — Data foundation — ✅ done

| Item | Status |
|------|:--:|
| `barangays` table (province→municipality→barangay) + seed 20 Siniloan barangays | ✅ |
| Extend `users`: `status`, `barangay_id`, `former_role_badge`, term dates | ✅ |
| `role_history` audit table | ✅ |
| `audit_log` table | ✅ |
| PHP companion: `includes/barangays.php` (list / name / exists / dropdown helpers) | ✅ |

_Applied via `config/migrations/2026-07-20_p0_data_foundation.sql` (idempotent); `config/schema.sql` updated for fresh installs. Verified against live MariaDB 10.4.32._

## P1 — Auth & accounts hardening — ✅ done

| Item | Status |
|------|:--:|
| Real password login for all roles (demo stays dev-only); login respects `status` (rejected blocked) | ✅ |
| Registration: barangay dropdown + DOB with age 15–30 validation; new youth → `status='pending'` | ✅ |
| Account Settings page (shared, self-service, every role): change password + edit mobile | ✅ |
| Location-aware nav (`$linkBase`) so sibling-folder pages resolve dashboard links | ✅ |
| `birthdate` column added to `users` (migration `2026-07-20_p1_auth_accounts.sql`) | ✅ |

_New helpers in `includes/auth.php`: `sked_age_from_birthdate`, `sked_find_user_by_id`, `sked_update_password`, `sked_update_own_contact`. New page `pages/account/settings.php`. Verified: age gate, barangay validation, register→login, password change, rejected-login block, and authenticated HTTP render._

## P2 — Youth verification — ✅ done

| Item | Status |
|------|:--:|
| SK reviews pending youth in own barangay → verify / reject (barangay-scoped, CSRF, audited) | ✅ |
| Gate profiling / events / points behind `verified` (`sked_require_verified()` guard + youth dashboard UX) | ✅ |
| Verification-status notifications (`notifications` table + `sked_notify`) | ✅ |
| SK dashboard wired to live member/pending counts | ✅ |
| Infra: `notifications` table, `sked_audit()`, notification helpers, demo SK barangay + seed youth | ✅ |

_New: `pages/sk/verify.php` (queue), `includes/verification.php`, `includes/notifications.php`, `includes/audit.php`, migration `2026-07-20_p2_verification.sql`, seed `config/seeds/demo_pending_youth.php`. Demo SK is barangay Acevida (id 1) with 4 seeded pending youth (password `youth123`). Verified end-to-end over HTTP incl. CSRF, barangay-scope, and double-action guards._

## P3 — Youth profiling — ✅ done

| Item | Status |
|------|:--:|
| `youth_profiles` (versioned questionnaire, v1) + normalized `youth_interests` | ✅ |
| Profiling form (verified-only, optional but recommended) at `pages/youth/profile.php` | ✅ |
| Points award on completion (idempotent) via `points_ledger` + `sked_award_points` | ✅ |
| Youth dashboard wired to live points + profile-completion state | ✅ |

_New: `includes/profiling.php` (fields/interests/get/save), `includes/points.php` (P5 infra: ledger + award + total), migration `2026-07-20_p3_profiling.sql`. Interests are normalized (feeds P9 analytics). Gated by `sked_require_verified()`. Verified end-to-end over HTTP incl. the verified-gate redirect, invalid-option rejection, and no double-award on re-save._

## P4 — Events & engagement — ✅ done

| Item | Status |
|------|:--:|
| `events` + SK barangay / PPSK municipal + inter-barangay creation | ✅ |
| Event types: interested/join vs. register (capacity, race-safe via row lock) | ✅ |
| `event_participants`, join/register/cancel/attendance | ✅ |
| `event_evaluations` + points (attendees only, once) | ✅ |
| Lifecycle states + min-participant auto-cancel (cron) | ✅ |
| Mgmt-only shareable public link (`pages/public/event.php`) | ✅ |
| Team-sport scope eligibility check (per-registrant verified + in-scope + team name) | ✅ |
| Event reminders (cron, day-before) | ✅ |

_New: `includes/events.php` (creation, eligibility, join/register/cancel, manage authz, lifecycle, attendance, evaluations, cron maintenance), migration `2026-07-20_p4_events.sql` (events, event_barangays, event_participants, event_evaluations). Pages: `pages/sk/events.php` (create+list, barangay-scoped), `pages/ppsk/events.php` (create+list, municipal/inter-barangay), `pages/manage/event.php` (shared roster/lifecycle/attendance detail, role+scope authorized), `pages/youth/events.php` (browse/join/register/cancel/evaluate), `pages/public/event.php` (no-login share-link landing page). Cron: `config/cron/event_maintenance.php` (CLI-only, auto-cancel + reminders). Built and verified as two slices (A: creation/browse/join; B: management/attendance/evaluation/lifecycle/cron) — both fully tested via direct logic tests and real HTTP request flows, including CSRF, cross-barangay authorization boundaries, capacity race safety, and idempotent points._

## P5 — Gamification — ✅ done

| Item | Status |
|------|:--:|
| `points_ledger` writes on each point-earning action (built in P3, fed by P3+P4) | ✅ |
| Activity-level derivation (5 tiers: Newcomer/Bronze/Silver/Gold/Platinum) | ✅ |
| Badge/level + progress bar + full points history on youth profile page | ✅ |
| Dashboard points card + activity docket row upgraded to show live level | ✅ |

_New: `sked_activity_levels()`/`sked_activity_level()`/`sked_points_history()`/`sked_points_action_label()` in `includes/points.php`. New page `pages/youth/activity.php` (fills the previously-stubbed nav "Profile" slot) — identity card, verification status, former-role badge (column exists from P0, ready for P8 turnover), activity level with progress bar, full chronological points history with event titles joined in. Verified: all 5 tier boundaries exact (0/10/30/60/100), points history + event-title join correct, HTTP flow correct, demo accounts handled gracefully._

## P6 — Charters & polls — ✅ done

| Item | Status |
|------|:--:|
| `project_charters` create + list + status update (budget info-only, no approval workflow) | ✅ |
| Filterable by status and date range | ✅ |
| Success rate derived live from a linked event's evaluations | ✅ |
| `polls` / `poll_options` / `poll_responses` — SK create/publish/close, youth vote once | ✅ |
| Voting awards points; results shown post-vote | ✅ |
| SK dashboard project/event figures wired to live data (also fixed leftover P4 stubs) | ✅ |

_New: `includes/charters.php`, `includes/polls.php`, migration `2026-07-21_p6_charters_polls.sql` (project_charters, polls, poll_options, poll_responses). Pages: `pages/sk/charters.php` (create+filter+status), `pages/sk/polls.php` (create+publish/close+results), `pages/youth/polls.php` (vote/results). Nav: SK "CBYDP / Projects" repointed from stub, new "Polls" item for SK and youth. Verified via direct logic tests (validation, barangay-scoped event linking, date-range filtering, one-vote-per-youth, points award) and full HTTP flows._

## P7 — Reporting & compliance — ✅ done

| Item | Status |
|------|:--:|
| SK monthly report (due 10th) submission, one per barangay per month | ✅ |
| PPSK reviews SK monthly reports; submits inter-barangay event reports + meeting minutes to DILG | ✅ |
| DILG consolidation view of federation reports | ✅ |
| `sk_strikes` + compliance cron (idempotent, notifies SK + PPSK) | ✅ |
| 3-strike PPSK→DILG escalation (`dismissal_recommendation`) | ✅ |
| DILG processes dismissal → shared `sked_retire_official()` reverts SK to Youth + badge | ✅ |

_New: `includes/reports.php`, `includes/compliance.php`, `includes/role_transitions.php` (shared primitive — also needed by P8 turnover), migration `2026-07-21_p7_reports_compliance.sql` (reports, sk_strikes). Pages: `pages/sk/reports.php` (submit + compliance status + history), `pages/ppsk/reports.php` + `pages/ppsk/compliance.php`, `pages/dilg/reports.php` + `pages/dilg/compliance.php`. Cron: `config/cron/compliance_check.php`. Turnover-report submission type intentionally deferred — the actual election/roster workflow is P8; the report type exists in the schema but isn't exposed in UI yet. Verified extensively: duplicate-period rejection, strike idempotency, threshold enforcement, double-escalation block, full dismissal reversion (role/badge/role_history/notification), and cross-phase integration (P5's activity page correctly shows the former-role badge). Full HTTP flows confirmed for all three roles._

## P8 — Turnover of power — ✅ done

| Item | Status |
|------|:--:|
| Outgoing PPSK submits turnover report (new PPSK identity + incoming SK roster) | ✅ |
| DILG reviews, activates new PPSK (or declines) | ✅ |
| New PPSK self-serves credentials for each incoming SK from the roster | ✅ |
| One active SK/barangay + one active PPSK/municipality enforced at provisioning | ✅ |
| Outgoing officer reverts to Youth + "Former …" badge on every replacement | ✅ |
| Direct designation (DILG→PPSK, PPSK→SK) for bootstrap / filling a single vacancy | ✅ |
| Promotes an existing verified youth account by email instead of duplicating | ✅ |

_New: `includes/turnover.php` (`sked_provision_officer` — the core primitive; `sked_submit_turnover_report`, `sked_activate_new_ppsk`, `sked_decline_turnover_report`, `sked_provision_sk_from_roster`, `sked_pending_roster_rows`), migration `2026-07-21_p8_turnover.sql` (`turnover_roster` table + `turnover` report type + new_officer_* columns). Pages: `pages/dilg/turnover.php`, `pages/ppsk/turnover.php`. One-active-per-scope is enforced procedurally (retire-then-create, not a DB partial unique index — MySQL/MariaDB has no partial unique index support) via the shared `sked_retire_official()` primitive built in P7. Verified extensively: username collision avoidance, promote-vs-create branching (including rejecting promotion of non-verified accounts), full report/roster lifecycle, and the complete HTTP chain — PPSK submits → DILG activates → new PPSK logs in with real generated credentials → provisions an SK → that SK logs in and lands correctly on their dashboard._

## P9 — Prescriptive analytics — ✅ done

| Item | Status |
|------|:--:|
| Rule-based weighted-scoring recommender (see [DECISIONS.md](DECISIONS.md) D-001) | ✅ |
| Top-N suggestions surfaced on SK/PPSK dashboards | ✅ |
| Full ranked breakdown page (SK, PPSK, DILG) with per-category evidence | ✅ |
| Polls gained an optional category tag so poll engagement feeds the same taxonomy as profiling interests and event categories | ✅ |

_New: `includes/analytics.php` — `sked_recommend_categories()` blends three signals (youth-interest share, poll-vote share, event-success rate), weighted 0.5/0.3/0.2, with missing signals excluded and remaining weights renormalized rather than counted as zero (a category with zero signals is omitted entirely, not scored 0). `sked_explain_recommendation()` gives a one-line evidence trail per category — every score is traceable, matching D-001's "transparent, explainable to DILG/PPSK" goal. Migration `2026-07-21_p9_analytics.sql` adds `polls.category`. Pages: `pages/{sk,ppsk,dilg}/analytics.php` (full ranked list + methodology explainer) plus compact top-3 widgets embedded on the SK and PPSK dashboards. Verified by hand-calculating an exact test scenario (4 profiled youth, 2 tagged polls, 1 evaluated event) and confirming the engine's output matched the arithmetic to one decimal place, then confirming the same data flowed correctly through both dashboard widgets and full pages across all three roles over HTTP._

## P10 — Notifications & audit — ✅ done

| Item | Status |
|------|:--:|
| Real polling-based notification bell + dropdown panel (all roles) | ✅ |
| Mark one / mark all read, relative timestamps, per-type icon+accent | ✅ |
| DILG audit trail viewer, filterable by action + date range | ✅ |

_New: `pages/api/notifications.php` (JSON endpoint: GET list, POST mark_one/mark_all, CSRF-protected), `js/notifications.js` (vanilla JS, 45s polling), `pages/dilg/audit.php`. Extended `includes/notifications.php` (`sked_mark_notification_read`, `sked_notification_visual`, `sked_relative_time`) and `includes/audit.php` (`sked_audit_log_entries`, `sked_audit_distinct_actions`). The `.notif-*` component CSS turned out to already be fully built in `css/dashboard.css` (ported from the source system in the original skeleton pass, unused until now) — the markup in `render_sked_notification_bell()` was written to match it exactly rather than inventing new classes. `notifications`/`audit_log` tables and their write-side (`sked_notify`/`sked_audit`) have existed since P2/P0 and were already being populated by every phase since — P10 was purely the read-side UI. Verified: unauthenticated API access rejected, CSRF-rejected POST confirmed, full mark-one/mark-all cycle, audit filter correctness (action + actor-name-vs-System rendering), and a full regression sweep confirming all 4 roles' dashboards plus non-role-folder pages (account/, manage/) render the bell cleanly with no errors — this change touched a function called on all 25 protected pages._

---

## 🎉 Roadmap complete — P0 through P10 all shipped

Every phase in the original roadmap is done. SKed now implements the full functional spec: youth profiling, events & engagement, gamification, charters & polls, reporting & compliance, turnover of power, prescriptive analytics, and notifications & audit — on top of the auth/verification/data foundation. See [CHANGELOG.md](CHANGELOG.md) for the complete phase-by-phase build history and [DECISIONS.md](DECISIONS.md) for every design call made along the way.
