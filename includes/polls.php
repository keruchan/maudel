<?php
/**
 * ============================================================
 * File     : includes/polls.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK community polls (P6, spec 4.3). SK creates a poll with
 *            normalized options; verified youth in the same barangay each
 *            get one vote; results feed insights/prescriptive analytics.
 *            Voting awards participation points (once per poll).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/points.php';

/**
 * Create a poll with its options. Barangay is taken from the SK creator.
 * $category is optional (from sked_interest_categories()) — tagging a poll
 * with a category lets its engagement feed the P9 recommender alongside
 * profiling interests and event success rates.
 *
 * @param array{id:int,role:string,name:string,barangay_id:?int} $creator
 * @param string[] $options Raw option text list; blanks are dropped, duplicates collapsed.
 * @return array{ok:bool,errors:array<int,string>,poll_id?:int}
 */
function sked_create_poll(array $creator, string $question, array $options, bool $publish = false, string $category = ''): array
{
    $errors = [];
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        $errors[] = 'Your account is not assigned to a barangay.';
    }

    $question = trim($question);
    if ($question === '') {
        $errors[] = 'Poll question is required.';
    }

    $category = trim($category);

    $clean = [];
    foreach ($options as $opt) {
        $opt = trim((string) $opt);
        if ($opt !== '' && !in_array($opt, $clean, true)) {
            $clean[] = $opt;
        }
    }
    if (count($clean) < 2) {
        $errors[] = 'Provide at least two distinct answer options.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO polls (barangay_id, question, category, status, created_by, created_by_role, created_by_name)
             VALUES (:barangay_id, :question, :category, :status, :created_by, :created_by_role, :created_by_name)'
        );
        $stmt->execute([
            'barangay_id' => $barangayId,
            'question' => $question,
            'category' => $category !== '' ? $category : null,
            'status' => $publish ? 'open' : 'draft',
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
            'created_by_role' => (string) ($creator['role'] ?? '') ?: null,
            'created_by_name' => (string) ($creator['name'] ?? '') ?: null,
        ]);
        $pollId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (:p, :t, :o)');
        foreach ($clean as $i => $opt) {
            $ins->execute(['p' => $pollId, 't' => $opt, 'o' => $i]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_create_poll failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not create the poll. Please try again.']];
    }

    return ['ok' => true, 'errors' => [], 'poll_id' => $pollId];
}

/** All polls an SK manages for their barangay, newest first. */
function sked_polls_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare('SELECT * FROM polls WHERE barangay_id = :b ORDER BY created_at DESC');
    $stmt->execute(['b' => $barangayId]);
    return $stmt->fetchAll();
}

/** Open polls a youth in $barangayId can currently vote on. */
function sked_open_polls_for_youth(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare("SELECT * FROM polls WHERE barangay_id = :b AND status = 'open' ORDER BY created_at DESC");
    $stmt->execute(['b' => $barangayId]);
    return $stmt->fetchAll();
}

/** A single poll by id, or null. */
function sked_get_poll(int $pollId): ?array
{
    if ($pollId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM polls WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $pollId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Options for a poll with live vote counts, in display order, plus the
 * poll's total vote count.
 *
 * @return array{options:array<int,array{id:int,option_text:string,votes:int}>,total:int}
 */
function sked_poll_results(int $pollId): array
{
    if ($pollId <= 0) {
        return ['options' => [], 'total' => 0];
    }
    $stmt = sked_db()->prepare(
        'SELECT o.id, o.option_text, COUNT(r.id) AS votes
           FROM poll_options o
      LEFT JOIN poll_responses r ON r.option_id = o.id
          WHERE o.poll_id = :p
          GROUP BY o.id, o.option_text, o.sort_order
          ORDER BY o.sort_order, o.id'
    );
    $stmt->execute(['p' => $pollId]);
    $options = $stmt->fetchAll();

    $total = 0;
    foreach ($options as &$o) {
        $o['id'] = (int) $o['id'];
        $o['votes'] = (int) $o['votes'];
        $total += $o['votes'];
    }

    return ['options' => $options, 'total' => $total];
}

/** The option id a youth voted for in a poll, or null if they haven't voted. */
function sked_youth_poll_vote(int $userId, int $pollId): ?int
{
    if ($userId <= 0 || $pollId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT option_id FROM poll_responses WHERE poll_id = :p AND user_id = :u LIMIT 1');
    $stmt->execute(['p' => $pollId, 'u' => $userId]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (int) $val;
}

/**
 * Cast a youth's vote. Barangay-scoped (poll must belong to the youth's own
 * barangay), poll must be open, option must belong to the poll, and each
 * youth may vote once (enforced by both an explicit check and the DB unique
 * constraint). Awards points once.
 *
 * @return array{ok:bool,error?:string}
 */
function sked_cast_poll_vote(int $userId, int $youthBarangayId, int $pollId, int $optionId): array
{
    $poll = sked_get_poll($pollId);
    if ($poll === null) {
        return ['ok' => false, 'error' => 'Poll not found.'];
    }
    if ((int) $poll['barangay_id'] !== $youthBarangayId) {
        return ['ok' => false, 'error' => 'This poll is not available to your barangay.'];
    }
    if ($poll['status'] !== 'open') {
        return ['ok' => false, 'error' => 'This poll is not currently open for responses.'];
    }
    if (sked_youth_poll_vote($userId, $pollId) !== null) {
        return ['ok' => false, 'error' => 'You have already voted on this poll.'];
    }

    $optCheck = sked_db()->prepare('SELECT 1 FROM poll_options WHERE id = :o AND poll_id = :p LIMIT 1');
    $optCheck->execute(['o' => $optionId, 'p' => $pollId]);
    if ($optCheck->fetchColumn() === false) {
        return ['ok' => false, 'error' => 'Invalid answer option.'];
    }

    try {
        $stmt = sked_db()->prepare('INSERT INTO poll_responses (poll_id, option_id, user_id) VALUES (:p, :o, :u)');
        $stmt->execute(['p' => $pollId, 'o' => $optionId, 'u' => $userId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'error' => 'You have already voted on this poll.'];
        }
        throw $e;
    }

    sked_award_points($userId, 'poll_answered', 'poll', $pollId);

    return ['ok' => true];
}

/** Move a poll to open or closed. Barangay-scoped by caller. */
function sked_set_poll_status(int $pollId, int $actorBarangayId, string $newStatus): array
{
    $poll = sked_get_poll($pollId);
    if ($poll === null || (int) $poll['barangay_id'] !== $actorBarangayId) {
        return ['ok' => false, 'error' => 'Poll not found.'];
    }
    if (!in_array($newStatus, ['draft', 'open', 'closed'], true)) {
        return ['ok' => false, 'error' => 'Invalid status.'];
    }

    $closedAt = $newStatus === 'closed' ? 'NOW()' : 'NULL';
    sked_db()->prepare("UPDATE polls SET status = :s, closed_at = $closedAt WHERE id = :id")
        ->execute(['s' => $newStatus, 'id' => $pollId]);

    return ['ok' => true];
}
