<?php
/**
 * ============================================================
 * File     : includes/feedback.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth Feedback / Concerns (P15). A verified youth sends a
 *            private note to their own barangay's SK; the SK marks it
 *            reviewed once addressed. Deliberately simple (no threaded
 *            replies, no categories) — this is a concern-reporting
 *            channel, distinct from Polls (structured, public voting) and
 *            the one-time "KK Suggestions" field in the profiling form.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

/** Feedback inbox alias. Stable per barangay so SK can distinguish senders without seeing identity. */
function sked_feedback_anonymous_alias(int $sequence): string
{
    return 'anonymous' . str_pad((string) max(1, $sequence), 3, '0', STR_PAD_LEFT);
}

/** Submit a feedback note. $submitter = ['id'=>int,'barangay_id'=>?int]. */
function sked_submit_feedback(array $submitter, string $message): array
{
    $barangayId = (int) ($submitter['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        return ['ok' => false, 'errors' => ['Your account is not assigned to a barangay.']];
    }
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'errors' => ['Please write a message before sending.']];
    }
    if (mb_strlen($message) > 2000) {
        $message = mb_substr($message, 0, 2000);
    }

    $userId = (int) ($submitter['id'] ?? 0);
    $stmt = sked_db()->prepare('INSERT INTO youth_feedback (user_id, barangay_id, message) VALUES (:u, :b, :m)');
    $stmt->execute(['u' => $userId, 'b' => $barangayId, 'm' => $message]);
    $feedbackId = (int) sked_db()->lastInsertId();

    sked_notify_role(
        'sk',
        'feedback',
        'New youth feedback received',
        'A youth from your barangay submitted a feedback/concern for review.',
        '../sk/feedback.php',
        $barangayId
    );

    return ['ok' => true, 'errors' => [], 'feedback_id' => $feedbackId];
}

/** A youth's own submitted feedback, newest first. */
function sked_feedback_for_youth(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM youth_feedback WHERE user_id = :u ORDER BY created_at DESC');
    $stmt->execute(['u' => $userId]);
    return $stmt->fetchAll();
}

/** All feedback for a barangay (SK inbox), newest first, with anonymous sender aliases. */
function sked_feedback_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $aliasStmt = sked_db()->prepare(
        'SELECT user_id
           FROM youth_feedback
          WHERE barangay_id = :b
          GROUP BY user_id
          ORDER BY MIN(created_at) ASC, user_id ASC'
    );
    $aliasStmt->execute(['b' => $barangayId]);
    $aliases = [];
    $sequence = 1;
    foreach ($aliasStmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $aliases[(int) $userId] = sked_feedback_anonymous_alias($sequence++);
    }

    $stmt = sked_db()->prepare(
        'SELECT f.*
           FROM youth_feedback f
          WHERE f.barangay_id = :b
          ORDER BY f.status = "reviewed" ASC, f.created_at DESC'
    );
    $stmt->execute(['b' => $barangayId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['anonymous_label'] = $aliases[(int) $row['user_id']] ?? 'anonymous';
    }
    unset($row);
    return $rows;
}

/** Count of still-open feedback for a barangay (for dashboard badges). */
function sked_open_feedback_count(int $barangayId): int
{
    if ($barangayId <= 0) {
        return 0;
    }
    $stmt = sked_db()->prepare("SELECT COUNT(*) FROM youth_feedback WHERE barangay_id = :b AND status = 'open'");
    $stmt->execute(['b' => $barangayId]);
    return (int) $stmt->fetchColumn();
}

/** SK marks a feedback item reviewed. Barangay-scoped. */
function sked_mark_feedback_reviewed(int $feedbackId, int $skUserId, int $skBarangayId): array
{
    $stmt = sked_db()->prepare(
        "UPDATE youth_feedback SET status = 'reviewed', reviewed_by = :r, reviewed_at = NOW()
          WHERE id = :id AND barangay_id = :b AND status = 'open'"
    );
    $stmt->execute(['r' => $skUserId, 'id' => $feedbackId, 'b' => $skBarangayId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Not found, or already reviewed.'];
    }

    $owner = sked_db()->prepare('SELECT user_id FROM youth_feedback WHERE id = :id');
    $owner->execute(['id' => $feedbackId]);
    $youthId = (int) $owner->fetchColumn();
    if ($youthId > 0) {
        sked_notify($youthId, 'feedback', 'Your feedback was reviewed', 'Your Barangay SK has reviewed the concern you submitted. Thank you for speaking up.', null);
    }
    sked_audit($skUserId, 'feedback_reviewed', 'feedback', $feedbackId, '');

    return ['ok' => true];
}
