<?php
/**
 * ============================================================
 * File     : includes/compliance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK monthly-report compliance & dismissal (P7, spec 6.4).
 *              1. Monthly report due the 10th.
 *              2. Missed deadline -> strike +1, notify SK + PPSK.
 *              3. On 3rd strike -> PPSK formally reports to DILG (a
 *                 'dismissal_recommendation' report) -> DILG processes the
 *                 dismissal (sked_retire_official(), shared with P8).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/role_transitions.php';

const SKED_DISMISSAL_STRIKE_THRESHOLD = 3;

/** Strike count for an SK. */
function sked_strike_count(int $skUserId): int
{
    if ($skUserId <= 0) {
        return 0;
    }
    $stmt = sked_db()->prepare('SELECT COUNT(*) FROM sk_strikes WHERE sk_user_id = :id');
    $stmt->execute(['id' => $skUserId]);
    return (int) $stmt->fetchColumn();
}

/** An SK's strike history, newest first. */
function sked_strikes_for_sk(int $skUserId): array
{
    if ($skUserId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM sk_strikes WHERE sk_user_id = :id ORDER BY period_month DESC');
    $stmt->execute(['id' => $skUserId]);
    return $stmt->fetchAll();
}

/**
 * Every active SK, their barangay, and current strike count — for PPSK's
 * compliance overview.
 */
function sked_compliance_overview(): array
{
    $stmt = sked_db()->query(
        "SELECT u.id AS sk_user_id, u.name, u.barangay_id, b.name AS barangay_name,
                (SELECT COUNT(*) FROM sk_strikes s WHERE s.sk_user_id = u.id) AS strikes
           FROM users u
      LEFT JOIN barangays b ON b.id = u.barangay_id
          WHERE u.role = 'sk' AND u.status = 'active'
          ORDER BY strikes DESC, b.name ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Add a strike for a missed monthly report (idempotent per SK+period via the
 * sk_strikes unique key). Notifies the SK and any active PPSK.
 */
function sked_add_strike(int $skUserId, int $barangayId, string $periodMonth, string $reason = ''): bool
{
    $reason = $reason !== '' ? $reason : 'Missed monthly report deadline (10th) for ' . $periodMonth;
    try {
        $stmt = sked_db()->prepare(
            'INSERT IGNORE INTO sk_strikes (sk_user_id, barangay_id, period_month, reason)
             VALUES (:sk, :bgy, :period, :reason)'
        );
        $stmt->execute(['sk' => $skUserId, 'bgy' => $barangayId, 'period' => $periodMonth, 'reason' => $reason]);
        if ($stmt->rowCount() === 0) {
            return false; // already recorded for this period
        }
    } catch (Throwable $e) {
        error_log('sked_add_strike failed: ' . $e->getMessage());
        return false;
    }

    $count = sked_strike_count($skUserId);
    sked_audit(null, 'sk_strike_added', 'user', $skUserId, $reason . ' (strike ' . $count . ')');

    sked_notify(
        $skUserId,
        'compliance',
        'Compliance strike recorded',
        'Your barangay did not submit its monthly report by the 10th (' . $periodMonth . '). '
            . 'This is strike ' . $count . ' of ' . SKED_DISMISSAL_STRIKE_THRESHOLD . '. '
            . ($count >= SKED_DISMISSAL_STRIKE_THRESHOLD
                ? 'Having reached the strike limit, your PPSK may now report this to DILG.'
                : 'Please submit monthly reports on time to avoid further strikes.'),
        '../sk/reports.php'
    );

    $ppsk = sked_db()->query("SELECT id FROM users WHERE role = 'ppsk' AND status = 'active'")->fetchAll();
    foreach ($ppsk as $p) {
        sked_notify(
            (int) $p['id'],
            'compliance',
            'SK compliance strike',
            'A barangay SK missed their monthly report deadline for ' . $periodMonth . ' (strike ' . $count . ').',
            '../ppsk/compliance.php'
        );
    }

    return true;
}

/**
 * Scheduled compliance check (cron): once past the 10th of the current
 * month, verify every active SK barangay submitted last month's report; add
 * a strike for any that didn't. Idempotent — safe to run daily.
 *
 * @return array{checked:int,strikes_added:int}
 */
function sked_run_compliance_check(): array
{
    $today = new DateTime('today');
    if ((int) $today->format('d') <= 10) {
        return ['checked' => 0, 'strikes_added' => 0]; // deadline hasn't passed yet this cycle
    }

    $periodMonth = (clone $today)->modify('first day of last month')->format('Y-m');
    $sks = sked_db()->query("SELECT id, barangay_id FROM users WHERE role = 'sk' AND status = 'active' AND barangay_id IS NOT NULL")->fetchAll();

    $checked = 0;
    $added = 0;
    foreach ($sks as $sk) {
        $checked++;
        $barangayId = (int) $sk['barangay_id'];
        if (!sked_has_submitted_monthly_report($barangayId, $periodMonth)) {
            if (sked_add_strike((int) $sk['id'], $barangayId, $periodMonth)) {
                $added++;
            }
        }
    }

    return ['checked' => $checked, 'strikes_added' => $added];
}

/**
 * PPSK escalates a repeat-offender SK to DILG (spec 6.4 step 3). Requires
 * the SK to have reached the strike threshold and not already have a
 * pending recommendation.
 *
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_escalate_to_dilg(array $ppsk, int $skUserId): array
{
    $count = sked_strike_count($skUserId);
    if ($count < SKED_DISMISSAL_STRIKE_THRESHOLD) {
        return ['ok' => false, 'errors' => ['This SK has not yet reached the ' . SKED_DISMISSAL_STRIKE_THRESHOLD . '-strike threshold.']];
    }

    $stmt = sked_db()->prepare("SELECT name, barangay_id FROM users WHERE id = :id AND role = 'sk' LIMIT 1");
    $stmt->execute(['id' => $skUserId]);
    $sk = $stmt->fetch();
    if ($sk === false) {
        return ['ok' => false, 'errors' => ['SK account not found.']];
    }

    $existing = sked_db()->prepare(
        "SELECT 1 FROM reports WHERE type = 'dismissal_recommendation' AND ref_user_id = :id AND status = 'submitted' LIMIT 1"
    );
    $existing->execute(['id' => $skUserId]);
    if ($existing->fetchColumn() !== false) {
        return ['ok' => false, 'errors' => ['A dismissal recommendation for this SK is already pending with DILG.']];
    }

    $title = 'Dismissal recommendation: ' . $sk['name'];
    $content = $sk['name'] . ' has accumulated ' . $count . ' compliance strikes for repeated missed monthly report deadlines. '
        . 'Recommending DILG process dismissal per SK compliance policy.';
    $r = sked_submit_report($ppsk, 'dismissal_recommendation', $title, $content, null, $skUserId);

    if ($r['ok']) {
        sked_audit((int) ($ppsk['id'] ?? 0), 'sk_escalated_to_dilg', 'user', $skUserId, $count . ' strikes');
        $dilg = sked_db()->query("SELECT id FROM users WHERE role = 'dilg' AND status = 'active'")->fetchAll();
        foreach ($dilg as $d) {
            sked_notify((int) $d['id'], 'compliance', 'Dismissal recommendation received',
                $title . ' — reported by ' . (string) ($ppsk['name'] ?? 'PPSK') . '.', '../dilg/compliance.php');
        }
    }

    return ['ok' => $r['ok'], 'errors' => $r['errors'] ?? []];
}

/**
 * DILG processes a pending dismissal recommendation: reverts the SK to
 * Youth (sked_retire_official) and marks the recommendation reviewed.
 *
 * @return array{ok:bool,error?:string,badge?:string}
 */
function sked_process_dismissal(int $dilgUserId, int $reportId): array
{
    $report = sked_get_report($reportId);
    if ($report === null || $report['type'] !== 'dismissal_recommendation' || $report['status'] !== 'submitted') {
        return ['ok' => false, 'error' => 'This recommendation is no longer pending.'];
    }

    $skUserId = (int) $report['ref_user_id'];
    $result = sked_retire_official(
        $dilgUserId,
        $skUserId,
        'dismissal_processed',
        'Dismissed by DILG following PPSK escalation (report #' . $reportId . ')'
    );

    if ($result['ok']) {
        sked_mark_report_reviewed($reportId, $dilgUserId);
    }

    return $result;
}
