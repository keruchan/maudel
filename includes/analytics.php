<?php
/**
 * ============================================================
 * File     : includes/analytics.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Prescriptive analytics (P9, spec 3 pillar 1 / D-001). A
 *            transparent, rule-based weighted-scoring recommender — NOT a
 *            machine-learning model — that ranks the canonical interest
 *            categories (sked_interest_categories(), shared by youth
 *            profiling, events, and polls) by three signals:
 *
 *              interest signal (weight 0.5) - % of profiled verified youth
 *                who picked this category in their KK profile (P3).
 *              poll signal     (weight 0.3) - this category's share of all
 *                votes cast on category-tagged polls (P6).
 *              success signal  (weight 0.2) - average youth evaluation
 *                rating (0-5, scaled to 0-100) of completed events tagged
 *                with this category (P4).
 *
 * A category's score is the weighted average of whichever signals actually
 * have data — missing signals are excluded and the remaining weights
 * renormalized, rather than counted as zero. A category with no data at all
 * on any signal is simply omitted from the ranking (nothing to recommend
 * from). This keeps the recommendation explainable: every score can be
 * traced back to the exact evidence behind it.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/profiling.php';
require_once __DIR__ . '/predictive.php';

/** Signal weights. Must sum to 1.0; tune here as the scheme evolves. */
function sked_analytics_weights(): array
{
    return ['interest' => 0.5, 'poll' => 0.3, 'success' => 0.2];
}

/**
 * Share of profiled verified youth (optionally scoped to a barangay) who
 * picked each category, as a percentage (0-100).
 *
 * @return array<string,float> category => percent
 */
function sked_category_interest_shares(?int $barangayId = null): array
{
    $params = [];
    $where = "u.role = 'youth' AND u.status = 'active'";
    if ($barangayId !== null) {
        $where .= ' AND u.barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }

    $totalStmt = sked_db()->prepare("SELECT COUNT(DISTINCT yp.user_id) FROM youth_profiles yp JOIN users u ON u.id = yp.user_id WHERE $where");
    $totalStmt->execute($params);
    $totalProfiled = (int) $totalStmt->fetchColumn();
    if ($totalProfiled === 0) {
        return [];
    }

    $stmt = sked_db()->prepare(
        "SELECT yi.category, COUNT(DISTINCT yi.user_id) AS n
           FROM youth_interests yi
           JOIN users u ON u.id = yi.user_id
          WHERE $where
          GROUP BY yi.category"
    );
    $stmt->execute($params);

    $shares = [];
    foreach ($stmt->fetchAll() as $row) {
        $shares[$row['category']] = round(((int) $row['n']) / $totalProfiled * 100, 1);
    }
    return $shares;
}

/**
 * Each category's share of total votes cast on category-tagged polls
 * (optionally scoped to a barangay), as a percentage (0-100).
 *
 * @return array<string,float> category => percent
 */
function sked_category_poll_shares(?int $barangayId = null): array
{
    $params = [];
    $where = "p.category IS NOT NULL";
    if ($barangayId !== null) {
        $where .= ' AND p.barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }

    $stmt = sked_db()->prepare(
        "SELECT p.category, COUNT(r.id) AS votes
           FROM polls p
      LEFT JOIN poll_responses r ON r.poll_id = p.id
          WHERE $where
          GROUP BY p.category"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totalVotes = array_sum(array_column($rows, 'votes'));
    if ($totalVotes === 0) {
        return [];
    }

    $shares = [];
    foreach ($rows as $row) {
        if ((int) $row['votes'] > 0) {
            $shares[$row['category']] = round(((int) $row['votes']) / $totalVotes * 100, 1);
        }
    }
    return $shares;
}

/**
 * Average evaluation rating (0-5, scaled to 0-100) of completed,
 * category-tagged events, optionally scoped to a barangay.
 *
 * @return array<string,float> category => score (0-100)
 */
function sked_category_success_scores(?int $barangayId = null): array
{
    $params = [];
    $where = "e.category IS NOT NULL AND e.status IN ('completed','evaluation','closed')";
    if ($barangayId !== null) {
        $where .= ' AND e.barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }

    $stmt = sked_db()->prepare(
        "SELECT e.category, AVG(ev.rating) AS avg_rating, COUNT(ev.id) AS n
           FROM events e
           JOIN event_evaluations ev ON ev.event_id = e.id
          WHERE $where
          GROUP BY e.category"
    );
    $stmt->execute($params);

    $scores = [];
    foreach ($stmt->fetchAll() as $row) {
        if ((int) $row['n'] > 0) {
            $scores[$row['category']] = round(((float) $row['avg_rating']) / 5 * 100, 1);
        }
    }
    return $scores;
}

/**
 * Rank all interest categories by weighted recommendation score. Pass
 * $barangayId for a barangay-scoped view (SK), or null for a
 * municipality-wide view (PPSK/DILG).
 *
 * @return array<int,array{category:string,score:float,evidence:array{interest:?float,poll:?float,success:?float}}>
 *         Sorted descending by score, limited to $limit, categories with no
 *         evidence at all omitted.
 */
function sked_recommend_categories(?int $barangayId = null, int $limit = 5): array
{
    $weights = sked_analytics_weights();
    $interest = sked_category_interest_shares($barangayId);
    $poll = sked_category_poll_shares($barangayId);
    $success = sked_category_success_scores($barangayId);

    $ranked = [];
    foreach (sked_interest_categories() as $category) {
        $signals = [];
        if (isset($interest[$category])) {
            $signals['interest'] = $interest[$category];
        }
        if (isset($poll[$category])) {
            $signals['poll'] = $poll[$category];
        }
        if (isset($success[$category])) {
            $signals['success'] = $success[$category];
        }
        if (empty($signals)) {
            continue; // no evidence at all — nothing to recommend from
        }

        $weightSum = 0.0;
        $scoreSum = 0.0;
        foreach ($signals as $key => $value) {
            $scoreSum += $value * $weights[$key];
            $weightSum += $weights[$key];
        }
        $score = round($scoreSum / $weightSum, 1);

        $ranked[] = [
            'category' => $category,
            'score' => $score,
            'evidence' => [
                'interest' => $signals['interest'] ?? null,
                'poll' => $signals['poll'] ?? null,
                'success' => $signals['success'] ?? null,
            ],
        ];
    }

    usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($ranked, 0, max(1, $limit));
}

/** Human-readable one-line explanation of a ranked category's evidence. */
function sked_explain_recommendation(array $entry): string
{
    $parts = [];
    if ($entry['evidence']['interest'] !== null) {
        $parts[] = $entry['evidence']['interest'] . '% of profiled youth are interested';
    }
    if ($entry['evidence']['poll'] !== null) {
        $parts[] = $entry['evidence']['poll'] . '% of poll votes on this topic';
    }
    if ($entry['evidence']['success'] !== null) {
        $parts[] = 'past events rated ' . round($entry['evidence']['success'] / 100 * 5, 1) . '/5';
    }
    return implode(' · ', $parts);
}

/* ============================================================
 * DEEP ANALYTICS (P16) — the three-layer analytics maturity model:
 *   Descriptive  ("what happened")  -> monthly trend + distribution charts
 *   Predictive   ("what's next")    -> OLS linear 3-month forecasts
 *   Prescriptive ("what to do")     -> rule-based recommendations
 *
 * Structure mirrors the reference CENRO/Certreefy analytics layer the user
 * pointed at, adapted to SKed's youth-governance domain (no geographic /
 * mapping layer — deliberately excluded). All functions are pure reads.
 * Forecasting reuses sked_linear_regression() from includes/predictive.php
 * (transparent OLS, no opaque ML — every projection traces to slope/R²).
 *
 * $barangayId null = municipality-wide (PPSK/DILG); a real id = barangay-
 * scoped (SK), consistent with the P9 recommender's convention.
 * ============================================================ */

/** Trailing month buckets ending this month. Each: ['key'=>'YYYY-MM','label'=>'Mon YYYY']. */
function sked_analytics_trend_months(int $count = 12): array
{
    $count = max(2, min($count, 36));
    $months = [];
    $cursor = (new DateTimeImmutable('first day of this month 00:00:00'))->modify('-' . ($count - 1) . ' months');
    for ($i = 0; $i < $count; $i++) {
        $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
        $cursor = $cursor->modify('+1 month');
    }
    return $months;
}

/** Aligns a ['YYYY-MM' => number] map onto the given month axis, filling gaps with 0. */
function sked_analytics_align_to_months(array $months, array $countsByMonth): array
{
    $out = [];
    foreach ($months as $m) {
        $out[] = (int) ($countsByMonth[$m['key']] ?? 0);
    }
    return $out;
}

/** Runs a "SELECT bucket, total" monthly query and returns a ['YYYY-MM' => int] map. */
function sked_analytics_monthly_counts(PDOStatement $stmt): array
{
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(string) $r['bucket']] = (int) $r['total'];
    }
    return $out;
}

/**
 * Wraps sked_linear_regression() into a forecast meta: the next $periods
 * projected values (clamped >= 0), slope, coarse trend, R², and next-month
 * value. Degrades to a flat projection at the series mean when there isn't
 * enough signal for a stable line.
 *
 * @return array{forecast:array<int,int>,slope:float,trend:string,r2:float,next:int}
 */
function sked_analytics_forecast_meta(array $values, int $periods = 3): array
{
    $values = array_values(array_map('floatval', $values));
    $n = count($values);
    $periods = max(1, min($periods, 12));
    $mean = $n > 0 ? array_sum($values) / $n : 0.0;

    $reg = $n >= 2 ? sked_linear_regression(range(0, $n - 1), $values) : null;
    if ($reg === null) {
        $flat = max(0, (int) round($mean));
        return ['forecast' => array_fill(0, $periods, $flat), 'slope' => 0.0, 'trend' => 'flat', 'r2' => 0.0, 'next' => $flat];
    }

    $forecast = [];
    for ($k = 1; $k <= $periods; $k++) {
        $forecast[] = max(0, (int) round($reg['intercept'] + $reg['slope'] * ($n - 1 + $k)));
    }
    // A slope small relative to the series average reads as flat, so sampling
    // noise isn't reported as a real trend.
    $reference = max(1.0, abs($mean) * 0.08);
    $trend = abs($reg['slope']) < $reference ? 'flat' : ($reg['slope'] > 0 ? 'rising' : 'falling');

    return ['forecast' => $forecast, 'slope' => round($reg['slope'], 2), 'trend' => $trend, 'r2' => $reg['r2'], 'next' => $forecast[0]];
}

/**
 * Cross-domain monthly trend series over a trailing window (default 12 months).
 * Deliberately independent of any page date filter — forecasting needs a
 * consistent history. Barangay-scoped when $barangayId is set.
 *
 * @return array{labels:array<int,string>,registrations:array,participations:array,events_held:array,poll_votes:array}
 */
function sked_analytics_trend_series(?int $barangayId = null, int $months = 12): array
{
    $pdo = sked_db();
    $axis = sked_analytics_trend_months($months);
    $since = $axis[0]['key'] . '-01 00:00:00';

    // Youth registrations (users.created_at), scoped by the youth's barangay.
    $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COUNT(*) AS total
              FROM users
             WHERE role = 'youth' AND created_at >= :since"
         . ($barangayId !== null ? ' AND barangay_id = :bgy' : '')
         . ' GROUP BY bucket';
    $stmt = $pdo->prepare($sql);
    $params = ['since' => $since];
    if ($barangayId !== null) { $params['bgy'] = $barangayId; }
    $stmt->execute($params);
    $registrations = sked_analytics_monthly_counts($stmt);

    // Event participation (joins), scoped by the participant's barangay.
    $sql = "SELECT DATE_FORMAT(p.joined_at, '%Y-%m') AS bucket, COUNT(*) AS total
              FROM event_participants p
              JOIN users u ON u.id = p.user_id
             WHERE p.status <> 'cancelled' AND p.joined_at >= :since"
         . ($barangayId !== null ? ' AND u.barangay_id = :bgy' : '')
         . ' GROUP BY bucket';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $participations = sked_analytics_monthly_counts($stmt);

    // Events held (by event_date), scoped by the event's barangay.
    $sql = "SELECT DATE_FORMAT(event_date, '%Y-%m') AS bucket, COUNT(*) AS total
              FROM events
             WHERE event_date >= :since AND status <> 'draft'"
         . ($barangayId !== null ? ' AND barangay_id = :bgy' : '')
         . ' GROUP BY bucket';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $eventsHeld = sked_analytics_monthly_counts($stmt);

    // Poll votes, scoped by the poll's barangay.
    $sql = "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS bucket, COUNT(*) AS total
              FROM poll_responses r
              JOIN polls pl ON pl.id = r.poll_id
             WHERE r.created_at >= :since"
         . ($barangayId !== null ? ' AND pl.barangay_id = :bgy' : '')
         . ' GROUP BY bucket';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pollVotes = sked_analytics_monthly_counts($stmt);

    return [
        'labels' => array_map(static fn($m) => $m['label'], $axis),
        'registrations' => sked_analytics_align_to_months($axis, $registrations),
        'participations' => sked_analytics_align_to_months($axis, $participations),
        'events_held' => sked_analytics_align_to_months($axis, $eventsHeld),
        'poll_votes' => sked_analytics_align_to_months($axis, $pollVotes),
    ];
}

/** Month labels continuing $count months past the last history label ("+1", padded). */
function sked_analytics_forecast_labels(array $labels, int $count = 3): array
{
    $last = end($labels);
    $cursor = DateTimeImmutable::createFromFormat('!M Y', (string) $last) ?: new DateTimeImmutable('first day of this month');
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $cursor = $cursor->modify('+1 month');
        $out[] = $cursor->format('M Y');
    }
    return $out;
}

/**
 * Descriptive trend series + a 3-month OLS forecast per key series, padded so a
 * dashed forecast line visually continues the solid history line in Chart.js.
 * Each series: ['history'=>[...,null,null], 'forecast'=>[null,...,last,f1,f2,f3], 'meta'=>...].
 */
function sked_analytics_forecast_bundle(?int $barangayId = null, int $months = 12, int $periods = 3): array
{
    $series = sked_analytics_trend_series($barangayId, $months);
    $forecastLabels = sked_analytics_forecast_labels($series['labels'], $periods);
    $allLabels = array_merge($series['labels'], $forecastLabels);
    $histLen = count($series['labels']);

    $build = static function (array $history) use ($histLen, $periods): array {
        $meta = sked_analytics_forecast_meta($history, $periods);
        $historyPadded = array_merge($history, array_fill(0, $periods, null));
        $forecastPadded = array_fill(0, $histLen, null);
        $forecastPadded[$histLen - 1] = $history[$histLen - 1] ?? null; // anchor the dashed line to the last actual
        foreach ($meta['forecast'] as $v) {
            $forecastPadded[] = $v;
        }
        return ['history' => $historyPadded, 'forecast' => $forecastPadded, 'meta' => $meta];
    };

    return [
        'labels' => $allLabels,
        'history_length' => $histLen,
        'forecast_length' => $periods,
        'registrations' => $build($series['registrations']),
        'participations' => $build($series['participations']),
        'events_held' => $build($series['events_held']),
        'poll_votes' => $build($series['poll_votes']),
    ];
}

/** Last non-null value of a (possibly padded) history array. */
function sked_analytics_latest_value(array $history): int
{
    for ($i = count($history) - 1; $i >= 0; $i--) {
        if ($history[$i] !== null) {
            return (int) $history[$i];
        }
    }
    return 0;
}

/**
 * Snapshot distributions for the descriptive doughnut charts (point-in-time,
 * not time-filtered). Barangay-scoped when set.
 *
 * @return array{verification:array,event_status:array,interest:array,participation:array}
 */
function sked_analytics_distributions(?int $barangayId = null): array
{
    $pdo = sked_db();
    $bgyClause = $barangayId !== null ? ' AND barangay_id = :bgy' : '';
    $bind = static function (PDOStatement $s) use ($barangayId): void {
        if ($barangayId !== null) { $s->bindValue(':bgy', $barangayId, PDO::PARAM_INT); }
    };

    // Verification funnel: youth by status.
    $stmt = $pdo->prepare("SELECT status, COUNT(*) n FROM users WHERE role = 'youth'$bgyClause GROUP BY status");
    $bind($stmt); $stmt->execute();
    $vf = ['pending' => 0, 'active' => 0, 'rejected' => 0];
    foreach ($stmt->fetchAll() as $r) {
        if (isset($vf[$r['status']])) { $vf[$r['status']] = (int) $r['n']; }
    }

    // Event status mix.
    $stmt = $pdo->prepare("SELECT status, COUNT(*) n FROM events WHERE 1=1$bgyClause GROUP BY status");
    $bind($stmt); $stmt->execute();
    $es = [];
    foreach ($stmt->fetchAll() as $r) {
        $es[(string) $r['status']] = (int) $r['n'];
    }

    // Interest categories (raw counts, from KK profiling).
    $sql = "SELECT yi.category, COUNT(DISTINCT yi.user_id) n
              FROM youth_interests yi JOIN users u ON u.id = yi.user_id
             WHERE u.role = 'youth'" . ($barangayId !== null ? ' AND u.barangay_id = :bgy' : '') . '
             GROUP BY yi.category ORDER BY n DESC';
    $stmt = $pdo->prepare($sql);
    $bind($stmt); $stmt->execute();
    $interest = [];
    foreach ($stmt->fetchAll() as $r) {
        $interest[(string) $r['category']] = (int) $r['n'];
    }

    // Participation status mix (interested/registered/attended/no_show), scoped by participant barangay.
    $sql = "SELECT p.status, COUNT(*) n
              FROM event_participants p JOIN users u ON u.id = p.user_id
             WHERE p.status <> 'cancelled'" . ($barangayId !== null ? ' AND u.barangay_id = :bgy' : '') . '
             GROUP BY p.status';
    $stmt = $pdo->prepare($sql);
    $bind($stmt); $stmt->execute();
    $participation = [];
    foreach ($stmt->fetchAll() as $r) {
        $participation[(string) $r['status']] = (int) $r['n'];
    }

    return ['verification' => $vf, 'event_status' => $es, 'interest' => $interest, 'participation' => $participation];
}

/** Youth-profiling completion: how many verified youth have a saved KK profile. */
function sked_analytics_profiling_completion(?int $barangayId = null): array
{
    $pdo = sked_db();
    $bgyClause = $barangayId !== null ? ' AND u.barangay_id = :bgy' : '';
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS active,
                COUNT(yp.user_id) AS profiled
           FROM users u
      LEFT JOIN youth_profiles yp ON yp.user_id = u.id
          WHERE u.role = 'youth' AND u.status = 'active'$bgyClause"
    );
    if ($barangayId !== null) { $stmt->bindValue(':bgy', $barangayId, PDO::PARAM_INT); }
    $stmt->execute();
    $row = $stmt->fetch();
    $active = (int) ($row['active'] ?? 0);
    $profiled = (int) ($row['profiled'] ?? 0);
    return [
        'active' => $active,
        'profiled' => $profiled,
        'pct' => $active > 0 ? (int) round($profiled / $active * 100) : 0,
    ];
}

/** Count of youth stuck in 'pending' verification (backlog). Barangay-scoped when set. */
function sked_analytics_pending_verifications(?int $barangayId = null): int
{
    $pdo = sked_db();
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'youth' AND status = 'pending'"
         . ($barangayId !== null ? ' AND barangay_id = :bgy' : '');
    $stmt = $pdo->prepare($sql);
    if ($barangayId !== null) { $stmt->bindValue(':bgy', $barangayId, PDO::PARAM_INT); }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/** Published events that already passed their registration deadline but never hit min_participants (at-risk / under-subscribed). */
function sked_analytics_undersubscribed_events(?int $barangayId = null): int
{
    $pdo = sked_db();
    $sql = "SELECT COUNT(*) FROM events e
             WHERE e.status = 'published' AND e.min_participants > 0
               AND e.registration_deadline IS NOT NULL AND e.registration_deadline < CURDATE()
               AND (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id AND p.status IN ('interested','registered','attended')) < e.min_participants"
         . ($barangayId !== null ? ' AND e.barangay_id = :bgy' : '');
    $stmt = $pdo->prepare($sql);
    if ($barangayId !== null) { $stmt->bindValue(':bgy', $barangayId, PDO::PARAM_INT); }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * Prescriptive recommendations, rule-based, derived from the forecast bundle,
 * live backlogs, profiling completion, and the P9 category recommender. Each
 * item: ['level','icon','title','detail'] with level in
 * critical|warning|info|positive, most-urgent first. $openFeedback is passed
 * in by SK-scoped callers (0 for municipality-wide, where there's no single
 * SK inbox to act on).
 */
function sked_analytics_recommendations(?int $barangayId, array $bundle, int $openFeedback = 0): array
{
    $recs = [];

    // 1. Verification backlog.
    $pending = sked_analytics_pending_verifications($barangayId);
    if ($pending > 0) {
        $recs[] = [
            'level' => $pending >= 5 ? 'critical' : 'warning',
            'icon' => 'bi-person-check',
            'title' => $pending . ' youth awaiting verification',
            'detail' => 'Review the Membership Validation queue so these KK members can join events and be counted in profiling.',
        ];
    }

    // 2. Under-subscribed events at risk of auto-cancellation.
    $undersub = sked_analytics_undersubscribed_events($barangayId);
    if ($undersub > 0) {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-calendar-x',
            'title' => $undersub . ' event(s) below minimum participants past their deadline',
            'detail' => 'These are on track to be auto-cancelled. Promote them, extend the deadline, or adjust the minimum before the cron closes them.',
        ];
    }

    // 3. Profiling completion gap.
    $prof = sked_analytics_profiling_completion($barangayId);
    if ($prof['active'] >= 3 && $prof['pct'] < 60) {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-clipboard2-data',
            'title' => 'KK Profiling completion is only ' . $prof['pct'] . '%',
            'detail' => $prof['profiled'] . ' of ' . $prof['active'] . ' verified youth have completed the KK Profiling form. Run a profiling drive — analytics and recommendations sharpen as more youth are profiled.',
        ];
    }

    // 4. Registration outlook (predictive → prescriptive).
    $regTrend = $bundle['registrations']['meta'];
    if ($regTrend['trend'] === 'rising') {
        $recs[] = [
            'level' => 'info',
            'icon' => 'bi-graph-up-arrow',
            'title' => 'Youth registrations are trending up',
            'detail' => 'About ' . $regTrend['next'] . ' new sign-up(s) projected next month. Keep verification turnaround quick so the pipeline doesn\'t back up.',
        ];
    } elseif ($regTrend['trend'] === 'falling') {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-graph-down-arrow',
            'title' => 'Youth registrations are slowing',
            'detail' => 'Projected ~' . $regTrend['next'] . ' next month. Consider an outreach or social-media push to bring more KK members onto the platform.',
        ];
    }

    // 5. Participation outlook.
    $partTrend = $bundle['participations']['meta'];
    if ($partTrend['trend'] === 'falling') {
        $recs[] = [
            'level' => 'warning',
            'icon' => 'bi-people',
            'title' => 'Event participation is declining',
            'detail' => 'Projected ~' . $partTrend['next'] . ' join(s) next month. Try programs in the top recommended focus areas below, or poll youth on what they want.',
        ];
    }

    // 6. Open feedback awaiting a reply (SK only).
    if ($openFeedback > 0) {
        $recs[] = [
            'level' => $openFeedback >= 5 ? 'warning' : 'info',
            'icon' => 'bi-chat-left-text',
            'title' => $openFeedback . ' unaddressed feedback message(s)',
            'detail' => 'Youth are waiting for a reply in Feedback / Concerns. Reviewing them keeps engagement and trust high.',
        ];
    }

    // 7. Top recommended focus area (from the P9 weighted recommender).
    $topCats = sked_recommend_categories($barangayId, 2);
    if (!empty($topCats)) {
        $top = $topCats[0];
        $recs[] = [
            'level' => 'info',
            'icon' => 'bi-stars',
            'title' => 'Focus next programs on "' . $top['category'] . '"',
            'detail' => 'Highest-scoring area from youth interests, polls, and past event ratings (' . sked_explain_recommendation($top) . ').',
        ];
    }

    if ($recs === []) {
        $recs[] = [
            'level' => 'positive',
            'icon' => 'bi-check-circle',
            'title' => 'Everything looks healthy',
            'detail' => 'No verification backlog, at-risk event, low profiling rate, or falling trend was detected for the current period. Keep it up.',
        ];
    }

    return $recs;
}
