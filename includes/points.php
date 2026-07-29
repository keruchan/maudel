<?php
/**
 * ============================================================
 * File     : includes/points.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Participation-points ledger helpers (P5 infra, first used by
 *            P3 profiling). Every point-earning action writes an auditable
 *            row to `points_ledger`; a youth's engagement level is derived
 *            from the sum at any time (engagement-level UI lands in P5).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';

/** Canonical point values per action type. Tweak here as the scheme evolves. */
function sked_points_scheme(): array
{
    return [
        'profiling_completed' => 10,
        'event_joined'        => 5,
        'event_attended'      => 10,
        'evaluation_submitted'=> 5,
        'event_suggested'     => 3,
        'poll_answered'       => 2,
    ];
}

/** Points configured for an action type (0 if unknown). */
function sked_points_for(string $actionType): int
{
    return sked_points_scheme()[$actionType] ?? 0;
}

/**
 * Award points for an action. Idempotent per (user, action_type, ref_type,
 * ref_id): a repeated award for the same reference is silently ignored, so
 * re-saving a profile or re-hitting a page never double-counts.
 *
 * @param int    $points  Explicit override; when < 0, uses sked_points_for().
 * @return bool  True if a new ledger row was created, false if already awarded.
 */
function sked_award_points(int $userId, string $actionType, string $refType = '', int $refId = 0, int $points = -1): bool
{
    if ($userId <= 0) {
        return false;
    }
    if ($points < 0) {
        $points = sked_points_for($actionType);
    }

    try {
        $stmt = sked_db()->prepare(
            'INSERT IGNORE INTO points_ledger (user_id, action_type, points, ref_type, ref_id)
             VALUES (:user_id, :action_type, :points, :ref_type, :ref_id)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'action_type' => $actionType,
            'points'      => $points,
            'ref_type'    => $refType,
            'ref_id'      => $refId,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('sked_award_points failed: ' . $e->getMessage());
        return false;
    }
}

/** Total participation points a user has accrued. */
function sked_total_points(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $stmt = sked_db()->prepare('SELECT COALESCE(SUM(points),0) FROM points_ledger WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Barangay leaderboard based on the same participation-points ledger that
 * powers engagement levels.
 *
 * @return array<int,array{id:int,name:string,username:string,points:int,engagement_count:int,last_engaged_at:?string}>
 */
function sked_top_youth_by_engagement(int $barangayId, int $limit = 10): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));

    try {
        $stmt = sked_db()->prepare(
            "SELECT u.id, u.name, u.username,
                    COALESCE(SUM(pl.points), 0) AS points,
                    COUNT(pl.id) AS engagement_count,
                    MAX(pl.created_at) AS last_engaged_at
               FROM users u
          LEFT JOIN points_ledger pl ON pl.user_id = u.id
              WHERE u.role = 'youth'
                AND u.status = 'active'
                AND u.verified = 1
                AND u.barangay_id = :bgy
           GROUP BY u.id, u.name, u.username
           ORDER BY points DESC, engagement_count DESC, u.name ASC
              LIMIT $limit"
        );
        $stmt->execute(['bgy' => $barangayId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Engagement-level tiers, ascending by point threshold. Tune here as the
 * scheme evolves — nothing else needs to change.
 *
 * 'unlocks' lists what becomes available AT that tier (not cumulative in
 * the data — the UI renders each tier as "everything below, plus these").
 * These are informational/recognition perks, not access gates: nothing in
 * the app currently checks tier to allow/deny a real feature, so display
 * this as "what being active earns you," not as a paywall.
 */
function sked_engagement_levels(): array
{
    return [
        [
            'key' => 'newcomer', 'label' => 'Newcomer', 'icon' => 'bi-seedling', 'min' => 0,
            'tagline' => 'Just verified and getting started.',
            'unlocks' => [
                'Browse and join open barangay events',
                'Digital KK ID with QR for event attendance',
                'Vote in community polls',
            ],
        ],
        [
            'key' => 'bronze', 'label' => 'Bronze Member', 'icon' => 'bi-award', 'min' => 10,
            'tagline' => 'Profile complete, first steps taken.',
            'unlocks' => [
                'Bronze badge shown on your KK ID card',
                'Early view of new announcements',
            ],
        ],
        [
            'key' => 'silver', 'label' => 'Silver Member', 'icon' => 'bi-award-fill', 'min' => 30,
            'tagline' => 'A regular face at barangay activities.',
            'unlocks' => [
                'Priority slot in limited-capacity events',
                'Can suggest event ideas directly to your SK',
            ],
        ],
        [
            'key' => 'gold', 'label' => 'Gold Member', 'icon' => 'bi-trophy', 'min' => 60,
            'tagline' => 'Consistently active across programs.',
            'unlocks' => [
                'Guaranteed slot in limited-capacity events',
                'Invited to KK Assembly planning discussions',
                "Listed on your barangay's youth honor roll",
            ],
        ],
        [
            'key' => 'platinum', 'label' => 'Platinum Member', 'icon' => 'bi-trophy-fill', 'min' => 100,
            'tagline' => 'A core youth volunteer.',
            'unlocks' => [
                'Printable Certificate of Active Participation',
                'First considered for scholarship/training endorsements',
                'Eligible as a youth volunteer marshal at events',
            ],
        ],
    ];
}

/**
 * Derive a youth's engagement level from their total points: current tier,
 * next tier (null if maxed), points still needed, and progress toward it.
 *
 * Derived on the fly rather than stored, so changing a threshold above
 * re-tiers everyone instantly with no migration (same rule as P6 charter
 * success rates and P19 attendance finalization).
 *
 * @return array{points:int,level:array,next:?array,points_to_next:int,progress_pct:int}
 */
function sked_engagement_level(int $totalPoints): array
{
    $levels = sked_engagement_levels();
    $current = $levels[0];
    $next = null;

    foreach ($levels as $i => $lvl) {
        if ($totalPoints >= $lvl['min']) {
            $current = $lvl;
            $next = $levels[$i + 1] ?? null;
        }
    }

    if ($next === null) {
        $progressPct = 100;
        $pointsToNext = 0;
    } else {
        $span = $next['min'] - $current['min'];
        $into = $totalPoints - $current['min'];
        $progressPct = $span > 0 ? (int) round(min(100, max(0, $into / $span * 100))) : 100;
        $pointsToNext = max(0, $next['min'] - $totalPoints);
    }

    return [
        'points' => $totalPoints,
        'level' => $current,
        'next' => $next,
        'points_to_next' => $pointsToNext,
        'progress_pct' => $progressPct,
    ];
}

/** Human-readable label for a ledger action type. */
function sked_points_action_label(string $actionType): string
{
    return match ($actionType) {
        'profiling_completed' => 'Completed your KK profile',
        'event_joined' => 'Joined an event',
        'event_attended' => 'Attended an event',
        'evaluation_submitted' => 'Submitted an event evaluation',
        'event_suggested' => 'Suggested an event',
        'poll_answered' => 'Answered a poll',
        default => ucfirst(str_replace('_', ' ', $actionType)),
    };
}

/**
 * A user's points ledger, newest first, with the related event title
 * attached when the entry references one (ref_type = 'event').
 *
 * @return array<int,array{action_type:string,points:int,ref_type:string,ref_id:int,created_at:string,event_title:?string}>
 */
function sked_points_history(int $userId, int $limit = 50): array
{
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    try {
        $stmt = sked_db()->prepare(
            "SELECT pl.action_type, pl.points, pl.ref_type, pl.ref_id, pl.created_at, e.title AS event_title
               FROM points_ledger pl
          LEFT JOIN events e ON pl.ref_type = 'event' AND e.id = pl.ref_id
              WHERE pl.user_id = :id
              ORDER BY pl.created_at DESC, pl.id DESC
              LIMIT $limit"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
