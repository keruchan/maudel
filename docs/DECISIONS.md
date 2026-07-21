# SKed — Decision Log

> Running record of design/technical decisions, especially where the spec left a
> choice open. When the user asks "what did you use for X?", the answer lives here.
> Context: [PROJECT-CONTEXT.md](PROJECT-CONTEXT.md) · Progress: [PROGRESS.md](PROGRESS.md)

_Format: **ID** · date · decision · rationale · status_

---

## D-001 · 2026-07-20 · Prescriptive analytics = rule-based weighted scoring
**Decision:** Implement the recommender as a transparent **rule-based weighted-scoring**
engine — rank interest categories from youth profiling + poll results + past event
success rates, surface the top N as suggestions on SK/PPSK dashboards. **Not** an ML
model or separate service.
**Rationale:** Fits the vanilla-PHP stack; explainable/auditable to DILG and PPSK;
no external service or training data needed. Upgradeable later if data volume justifies.
**Status:** Accepted (chosen by Claude at user's request; revisit at Phase P9).

## D-002 · 2026-07-20 · Single `users` table for all roles
**Decision:** Keep all roles in one `users` table keyed by `role` + `status`, with a
separate `role_history` audit table, rather than a table per role.
**Rationale:** Makes turnover-of-power (role reverts to Youth, "Former …" badge retained)
and one-active-per-scope enforcement far cleaner to query. Matches existing schema.
**Status:** Accepted.

## D-003 · 2026-07-20 · Barangays seeded as province→municipality→barangay
**Decision:** Store barangays in a seeded `barangays` table structured for
province → municipality → barangay, not hardcoded in PHP.
**Rationale:** Lets DILG expand beyond Siniloan later with no code change/migration.
**Status:** Accepted.

## D-026 · 2026-07-21 · Idle timeout enforced both server-side and client-side, not one or the other
**Decision:** 30-minute idle logout is implemented twice: authoritatively in
`require_roles()` (server, checked on every request) and proactively in
`js/idle-timeout.js` (client, auto-submits logout after 30 min of no
mouse/keyboard/touch/scroll activity).
**Rationale:** Server-side alone only fires reactively on the user's NEXT click —
someone idle past 30 minutes wouldn't actually get logged out until they tried to do
something, which is a weak "auto" logout. Client-side alone is trivially bypassable
(disable JS) and isn't a real security boundary. Both together give a correct,
enforced timeout with a fast, friendly UX.
**Status:** Accepted.

## D-027 · 2026-07-21 · Background polling doesn't count as "activity" for idle timeout
**Decision:** The notification bell's 45-second background poll does not update
`$_SESSION['last_activity']` and is not among the events the client-side timer
listens for.
**Rationale:** If polling counted as activity, a tab left open and forgotten would
poll every 45s forever and the session would never time out — defeating the entire
point of an idle timeout. Only genuine navigation/form submission (server-side) and
real mouse/keyboard/touch/scroll input (client-side) reset the clock.
**Status:** Accepted.

## D-028 · 2026-07-21 · Real accounts get unique per-role passwords, not one shared password
**Decision:** `config/seeds/role_demo_accounts.php` was reworked from
create-if-missing to an UPSERT that assigns each of the 4 real accounts its own
distinct password.
**Rationale:** User explicitly asked for "true user credentials" rather than a single
shared demo password — distinct credentials per role better reflect how real accounts
would work and avoid implying one password unlocks every role.
**Status:** Accepted.

## D-024 · 2026-07-21 · Notification bell markup matches pre-existing ported CSS
**Decision:** Build the notification bell/panel markup to exactly match the `.notif-*`
component classes already present in `css/dashboard.css`, rather than designing new
markup/classes from scratch.
**Rationale:** That CSS was ported from the source CENRO/CERTREEFY system during the
original skeleton pass (visible in its class naming and structure) but was never wired
up — a complete, already-themed notification panel design was sitting unused. Using it
as-is honors the project's "reuse the existing design system, don't invent a new
language" rule and saved significant design effort.
**Status:** Accepted.

## D-025 · 2026-07-21 · Notifications API is a small dedicated JSON endpoint, not reused pages
**Decision:** `pages/api/notifications.php` is a new, minimal JSON-only endpoint (GET
list, POST mark_one/mark_all) rather than adding AJAX branches to existing pages.
**Rationale:** The bell is polled from every one of 25 protected pages regardless of
which page the user is on; a dedicated endpoint keeps that polling logic independent of
whatever page happens to be loaded, and keeps the concern (read/update notification
state) cleanly separated from page rendering. Matches the `pages/public/` precedent of
giving a distinct concern its own small directory.
**Status:** Accepted.

## D-022 · 2026-07-21 · Polls gain an optional category to feed the recommender
**Decision:** Add a nullable `category` column to `polls` (drawn from the same
`sked_interest_categories()` list events already use) rather than trying to infer a
category from poll question/option free text.
**Rationale:** Poll options are arbitrary SK-authored strings ("Basketball", "Job Fair")
— fuzzy-matching them to categories would be fragile and opaque, undermining the
"explainable" goal of D-001. A one-time optional tag at poll-creation time is nearly
free to add and keeps every signal in the recommender traceable to explicit data, not
inferred guesses.
**Status:** Accepted.

## D-023 · 2026-07-21 · Recommender weights: interest 0.5 / poll 0.3 / success 0.2, missing signals excluded
**Decision:** Score = weighted average of whichever signals have data, weights
renormalized among only the present signals; a category with zero signals is omitted
from the ranking rather than scored 0.
**Rationale:** Profiling interest is the most direct, deliberate expression of youth
preference (highest weight). Poll engagement is a more specific, SK-solicited signal.
Past event success is useful confirmation but shouldn't penalize a category nobody has
tried yet — treating "no events run" as a success score of 0 would unfairly bury
untested-but-popular categories. Excluding absent signals (rather than defaulting them
to 0 or an assumed neutral value) keeps every score honestly grounded in real evidence,
which is what D-001 asked for.
**Status:** Accepted.

## D-019 · 2026-07-21 · Provisioning promotes an existing account by email, else creates new
**Decision:** `sked_provision_officer()` first checks whether an active, verified youth
account already exists with the incoming officer's email. If so, it promotes that
account in place (role change only — they keep their existing username/password). Only
if no such account exists does it create a brand-new one with a generated
username/password.
**Rationale:** SK Chairmen and PPSK Presidents are themselves KK youth (RA 10742) — in
the realistic case they're already registered, verified SKed users. Promoting avoids
duplicate accounts and lets them keep using credentials they already know. Falls back
to account creation for genuinely new users (e.g. an appointee who never registered).
Rejects promotion if the matched account isn't an active verified youth (e.g. still
pending, or already another official) — surfaced as a clear error rather than silently
overwriting.
**Status:** Accepted.

## D-020 · 2026-07-21 · One-active-per-scope enforced procedurally, not via partial unique index
**Decision:** "One active SK per barangay" / "one active PPSK per municipality" (spec 6.1
step 6) is enforced by retiring the outgoing officer *before* creating/promoting the new
one — not via a database partial/filtered unique index.
**Rationale:** MySQL/MariaDB has no partial unique index support (unlike PostgreSQL). A
plain unique index on `(role, barangay_id)` would incorrectly block multiple youths
sharing a barangay. The retire-then-create sequence, run as two sequential transactions
(not nested — PDO doesn't support true nested transactions), gives practical correctness
for this human-paced, low-concurrency workflow. True race-condition-proof atomicity
(like the event-registration capacity lock, D-011) isn't needed here since turnover
actions aren't performed by many concurrent users.
**Status:** Accepted.

## D-021 · 2026-07-21 · Direct officer designation alongside the full election-roster flow
**Decision:** Both DILG (designate PPSK) and PPSK (designate SK) can provision a single
officer directly, without a turnover report/roster — reusing the same
`sked_provision_officer()` primitive.
**Rationale:** Spec 4.1 separately lists "Designate the PPSK President" as a DILG
capability distinct from the roster-driven turnover flow (needed for initial bootstrap,
when there's no outgoing PPSK yet). Symmetrically, PPSK needs a way to fill a single
barangay vacancy outside a full election — most notably right after a P7 dismissal,
which leaves a barangay without an SK. Reusing the shared primitive means this costs
almost nothing extra to support.
**Status:** Accepted.

## D-016 · 2026-07-21 · sked_retire_official() built now, shared with P8
**Decision:** Build the "revert an official to Youth + badge + role_history" primitive
(`sked_retire_official()`) as part of P7's dismissal flow, in its own file
(`includes/role_transitions.php`) rather than inlined in compliance.php.
**Rationale:** Spec 6.4's dismissal flow explicitly ends the same way P8's election
turnover will ("SK Chairman reverts to Youth" + badge) — both are real consumers of
the identical mechanic. Building it once, generically (takes role/user, not
dismissal-specific), avoids duplicating this logic when P8 lands. Matches the
established pattern of building shared infra for its first real consumer (points.php
in P3, notifications.php in P2).
**Status:** Accepted.

## D-017 · 2026-07-21 · Dismissed officials stay `status='active'`, not `retired`
**Decision:** `sked_retire_official()` sets `role='youth'` but leaves `status='active'`
(their pre-existing verified state) — the `retired` value in the `status` enum (added
in P0) is left unused by this transition.
**Rationale:** Spec 4.1 is explicit that outgoing officials "convert to regular Youth
accounts" — implying full continued use of SKed (joining events, etc.), not a
deactivated state. `retired` remains reserved in the enum for a possible future
distinct use (e.g. an explicitly deactivated account) rather than repurposed here.
**Status:** Accepted.

## D-018 · 2026-07-21 · Turnover-report submission type deferred to P8
**Decision:** The `reports` schema already supports a conceptual "turnover" report
type in principle, but P7 does not expose UI for PPSK to submit one — spec 4.2's
turnover-of-power report (outgoing + incoming PPSK + full incoming SK roster) is
tightly coupled to the actual election/roster-intake mechanics, which are P8's job.
**Rationale:** Building the submission form without the roster-intake workflow behind
it would be a half-feature; better to build both together in P8.
**Status:** Accepted (revisit scope when P8 starts).

## D-014 · 2026-07-21 · Project charter links to at most one event
**Decision:** `project_charters.event_id` is a single nullable FK, not a many-to-many
join table, for linking a charter to the event whose evaluations drive its success rate.
**Rationale:** The spec's own suggested schema shows `success_rate` as a plain field
with no join table; a single optional link is the simplest thing that satisfies "success
rate computed from post-event evaluations" without premature M:N complexity. Can grow to
M:N later if a real need for multi-event projects emerges.
**Status:** Accepted.

## D-015 · 2026-07-21 · Charter status is direct-set, not a workflow like events
**Decision:** SK edits a charter's status (upcoming/ongoing/completed) via a plain
dropdown + save, not a `sked_event_next_statuses()`-style transition state machine.
**Rationale:** Spec 4.3 describes charters as simple upcoming/current/past tracking, not
a governance-sensitive workflow like event lifecycle or youth verification — a direct
edit is proportionate and avoids over-engineering a feature the spec doesn't ask to be
gated.
**Status:** Accepted.

## D-013 · 2026-07-21 · Activity levels: 5 fixed tiers at 0/10/30/60/100 points
**Decision:** Five named tiers (Newcomer, Bronze, Silver, Gold, Platinum) at fixed
point thresholds, derived on the fly from `SUM(points_ledger)` rather than stored
denormalized on `users`.
**Rationale:** Matches the existing points scheme naturally (profiling=10 lands a
youth at Bronze immediately, encouraging completion); deriving on read means the
tier is always consistent with the ledger with no sync/migration risk if the scheme
changes later. Thresholds are a single array in `sked_activity_levels()` — easy to retune.
**Status:** Accepted.

## D-009 · 2026-07-21 · Event lifecycle is a fixed linear state machine
**Decision:** Model event status as a fixed sequence (draft → published → confirmed →
ongoing → completed → evaluation → closed, with cancel available from most states)
via `sked_event_next_statuses()`, rather than a configurable workflow engine.
**Rationale:** Matches the spec's lifecycle exactly (Section 6.3); a fixed table of
allowed transitions is simple to reason about and audit. No two SK/PPSK workflows need
to diverge in v1.
**Status:** Accepted.

## D-010 · 2026-07-21 · Team-sport eligibility enforced per-registrant, not per-team
**Decision:** For team-sport events, each individual who registers must independently
pass the verified + in-scope check (barangay for barangay events, target barangays for
inter-barangay, any for municipal); the team is just a `team_name` label on each
registrant's participation row, not a separate roster entity.
**Rationale:** Satisfies spec 4.5's team-sport eligibility rule (every team member must
be an active/verified user in the correct scope) without a separate teams table —
each registrant already goes through the same `sked_join_event` scope/verified checks.
**Status:** Accepted.

## D-011 · 2026-07-21 · Capacity checks use a row lock, not application-level counting
**Decision:** `sked_join_event()` wraps the capacity check + insert in a transaction
with `SELECT ... FOR UPDATE` on the participant count, rather than trusting a
count-then-insert without locking.
**Rationale:** Prevents a race condition where two youths registering for the last slot
at nearly the same moment could both succeed, overbooking a capped event.
**Status:** Accepted.

## D-012 · 2026-07-21 · MySQL InnoDB corruption recovered via standard redo-log rebuild
**Decision:** When MySQL's InnoDB engine failed to start ("Missing MLOG_CHECKPOINT"),
recovered by renaming (not deleting) `ib_logfile0`/`ib_logfile1` and letting InnoDB
rebuild them fresh, rather than restoring from a backup or reinstalling.
**Rationale:** This is the standard, well-documented fix for this specific error and
does not touch `ibdata1` (the actual data file). Flagged to the user first because the
same MySQL instance hosts ~15 other unrelated project databases and the fix acts on the
whole shared instance — not something to do unilaterally. Verified zero data loss in
`sked_db` afterward (all tables + expected row counts intact).
**Status:** Resolved. Follow-up: disk was at 93% full (8.5GB free of 112GB) — a likely
contributing cause and worth freeing space to avoid recurrence.

## D-007 · 2026-07-20 · points_ledger built in P3 (activity-level UI deferred to P5)
**Decision:** Create `points_ledger` + `sked_award_points()`/`sked_total_points()` in P3
because profiling completion awards points, but defer the activity-level/badge UI and
the full point-earning wiring (events, polls, evaluations) to P5.
**Rationale:** Same pattern as notifications — stand up the ledger when the first earner
lands; do the derived UI once. `UNIQUE(user_id, action_type, ref_type, ref_id)` makes
one-time awards idempotent (ref_type/ref_id default to ''/0, never NULL, so uniqueness holds).
**Status:** Accepted.

## D-008 · 2026-07-20 · Profiling questionnaire is fixed v1 + versioned, interests normalized
**Decision:** Ship a fixed v1 KK questionnaire (single-choice demographics + yes/no civic
questions + multi-select interests + remarks) with `questionnaire_version` stamped per
profile; store multi-select interests in a normalized `youth_interests` table, not JSON.
**Rationale:** A dynamic form-builder is overkill for v1; the version stamp keeps future
question changes analytics-comparable. Normalized interests let P9 simply `GROUP BY
category` per barangay. One profile row per youth (updated in place), not full history.
**Status:** Accepted (revisit history-retention if analytics needs it).

## D-005 · 2026-07-20 · Notifications table built in P2 (bell UI deferred to P10)
**Decision:** Create the `notifications` table and `sked_notify()` helper during P2
so verification outcomes are recorded per-user, but defer the interactive
bell/notification-center UI to P10.
**Rationale:** Verification is the first real notification source; the data layer is
needed now, while the polling bell UI is a cross-cutting concern better done once.
**Status:** Accepted.

## D-006 · 2026-07-20 · Audit logs demo actors as NULL actor
**Decision:** `sked_audit()` retries with `actor_id = NULL` (noting the original id in
details) when the actor id fails the `audit_log.actor_id` FK — which happens for demo
accounts (ids 1–4) that aren't persisted in `users`.
**Rationale:** Keeps the audit trail complete even in demo mode without weakening the FK
for real accounts (ids ≥ 1000), which attribute correctly.
**Status:** Accepted (demo-mode accommodation).

## D-004 · 2026-07-20 · Demo logins retained as dev-only
**Decision:** Keep the no-password demo logins (`1`–`4`) as a development shortcut
alongside real password auth, rather than removing them during the build.
**Rationale:** Keeps every role dashboard reachable for review while functional auth is
built out incrementally. To be gated/removed before any shared deployment.
**Status:** Accepted (temporary).
