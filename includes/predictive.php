<?php
/**
 * ============================================================
 * File     : includes/predictive.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Predictive analytics (P14). Unlike P9's rule-based weighted
 *            scoring (includes/analytics.php), these two features fit
 *            actual regression models to historical data:
 *
 *              1. Event turnout prediction — how many youth will likely
 *                 join an upcoming event, from an OLS trend line over
 *                 comparable past events, blended with live registration
 *                 pacing as the event approaches.
 *              2. Youth registration forecast — next month's new-youth
 *                 sign-ups, from an OLS trend line over monthly counts.
 *
 * Method choice (deliberate, matching D-001's "transparent over fancy"
 * spirit): simple OLS linear regression, not ARIMA. This system's real
 * series are short and low-frequency — a single barangay's events or
 * monthly registrations rarely exceed a few dozen points, often fewer
 * than a season's worth. ARIMA needs materially more history to fit
 * (and re-fit) an AR/MA/differencing structure reliably, and an
 * unstable ARIMA fit is worse than an honest straight-line trend for a
 * barangay official trying to plan capacity. A single-slope OLS trend
 * is exactly as sophisticated as the data supports, and every prediction
 * traces back to a formula (slope, intercept, R²) instead of a black box.
 * Every function below degrades to a plain historical average (and says
 * so) when there isn't even enough data for a stable regression line.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/events.php';

/** Minimum historical points before an OLS trend line is trusted over a plain average. */
const SKED_TURNOUT_MIN_HISTORY = 4;
const SKED_FORECAST_MIN_MONTHS = 3;

/**
 * Ordinary least squares simple linear regression: y = intercept + slope*x.
 * Returns null if there are fewer than 2 points or x has no variance
 * (a vertical/undefined fit).
 *
 * @param array<int,float> $xs
 * @param array<int,float> $ys
 * @return array{slope:float,intercept:float,r2:float,n:int}|null
 */
function sked_linear_regression(array $xs, array $ys): ?array
{
    $n = count($xs);
    if ($n < 2 || $n !== count($ys)) {
        return null;
    }

    $meanX = array_sum($xs) / $n;
    $meanY = array_sum($ys) / $n;

    $sumXY = 0.0;
    $sumXX = 0.0;
    foreach ($xs as $i => $x) {
        $sumXY += ($x - $meanX) * ($ys[$i] - $meanY);
        $sumXX += ($x - $meanX) ** 2;
    }
    if (abs($sumXX) < 1e-9) {
        return null; // x has no variance — can't fit a slope
    }

    $slope = $sumXY / $sumXX;
    $intercept = $meanY - $slope * $meanX;

    $ssRes = 0.0;
    $ssTot = 0.0;
    foreach ($xs as $i => $x) {
        $yPred = $intercept + $slope * $x;
        $ssRes += ($ys[$i] - $yPred) ** 2;
        $ssTot += ($ys[$i] - $meanY) ** 2;
    }
    $r2 = $ssTot > 0 ? max(0.0, 1 - $ssRes / $ssTot) : ($ssRes < 1e-9 ? 1.0 : 0.0);

    return ['slope' => $slope, 'intercept' => $intercept, 'r2' => round($r2, 3), 'n' => $n];
}

/** Sample standard deviation (n-1 denominator); 0 for fewer than 2 values. */
function sked_stddev(array $values, ?float $mean = null): float
{
    $n = count($values);
    if ($n < 2) {
        return 0.0;
    }
    $mean ??= array_sum($values) / $n;
    $variance = 0.0;
    foreach ($values as $v) {
        $variance += ($v - $mean) ** 2;
    }
    return sqrt($variance / ($n - 1));
}

/**
 * Finished events (final headcount is settled — completed, evaluated,
 * closed, or cancelled; draft/published/confirmed/ongoing are excluded
 * since their numbers can still change), chronological, with each one's
 * final active-participant count.
 */
function sked_turnout_history_by_category(string $category, int $excludeEventId = 0): array
{
    $stmt = sked_db()->prepare(
        "SELECT e.id, e.event_date,
                (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id AND p.status IN ('interested','registered','attended')) AS active_count
           FROM events e
          WHERE e.category = :cat AND e.status IN ('completed','evaluation','closed','cancelled') AND e.id <> :ex
          ORDER BY e.event_date ASC, e.id ASC"
    );
    $stmt->execute(['cat' => $category, 'ex' => $excludeEventId]);
    return $stmt->fetchAll();
}

/** Same as above, scoped by event scope (+ barangay, when given) instead of category. */
function sked_turnout_history_by_scope(string $scope, ?int $barangayId, int $excludeEventId = 0): array
{
    $sql = "SELECT e.id, e.event_date,
                (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id AND p.status IN ('interested','registered','attended')) AS active_count
           FROM events e
          WHERE e.scope = :scope AND e.status IN ('completed','evaluation','closed','cancelled') AND e.id <> :ex";
    $params = ['scope' => $scope, 'ex' => $excludeEventId];
    if ($barangayId !== null) {
        $sql .= ' AND e.barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }
    $sql .= ' ORDER BY e.event_date ASC, e.id ASC';

    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Predict how many youth will ultimately join $event (interested +
 * registered + attended). Only meaningful for events whose numbers
 * aren't final yet — callers should skip this for completed/closed/
 * cancelled events, which already have a real count.
 *
 * Method: pick the most specific historical pool with data (same
 * category, falling back to same scope+barangay, then same scope
 * municipality-wide). With ≥4 points, fit an OLS trend line over those
 * events in chronological order and extrapolate one step ahead;
 * otherwise use the pool's plain average. If the event already has real
 * sign-ups and a registration deadline, blend in a live pacing
 * projection (sign-ups so far ÷ fraction of the registration window
 * elapsed) that gets more weight as more of that window passes — the
 * same idea a box-office tracker uses to project opening-week turnout
 * from early sales.
 *
 * @return array{predicted:?int,low:?int,high:?int,method:string,basis:string,n:int,r2:?float,live_signups:?int,pace_weight:?float,explanation:string}
 */
function sked_predict_event_turnout(array $event): array
{
    $category = trim((string) ($event['category'] ?? ''));
    $scope = (string) $event['scope'];
    $barangayId = $event['barangay_id'] !== null ? (int) $event['barangay_id'] : null;
    $excludeId = (int) ($event['id'] ?? 0);

    $pool = [];
    $basis = '';
    if ($category !== '') {
        $pool = sked_turnout_history_by_category($category, $excludeId);
        $basis = 'past "' . $category . '" events';
    }
    if (count($pool) < 3) {
        $scopePool = sked_turnout_history_by_scope($scope, $scope === 'barangay' ? $barangayId : null, $excludeId);
        if (count($scopePool) > count($pool)) {
            $pool = $scopePool;
            $basis = $scope === 'barangay' ? 'past events in this barangay' : "past $scope events";
        }
    }
    if (count($pool) < 3 && $scope === 'barangay') {
        $municipalPool = sked_turnout_history_by_scope('barangay', null, $excludeId);
        if (count($municipalPool) > count($pool)) {
            $pool = $municipalPool;
            $basis = 'past barangay events municipality-wide';
        }
    }

    if (empty($pool)) {
        return [
            'predicted' => null, 'low' => null, 'high' => null, 'method' => 'none', 'basis' => '',
            'n' => 0, 'r2' => null, 'live_signups' => null, 'pace_weight' => null,
            'explanation' => 'No finished events yet to base a prediction on.',
        ];
    }

    $counts = array_map(static fn($r) => (float) $r['active_count'], $pool);
    $mean = array_sum($counts) / count($counts);
    $predicted = $mean;
    $method = 'average';
    $r2 = null;

    if (count($pool) >= SKED_TURNOUT_MIN_HISTORY) {
        $xs = range(1, count($pool));
        $reg = sked_linear_regression($xs, $counts);
        if ($reg !== null) {
            $trendPrediction = $reg['intercept'] + $reg['slope'] * (count($pool) + 1);
            if ($trendPrediction >= 0) {
                $predicted = $trendPrediction;
                $method = 'ols_trend';
                $r2 = $reg['r2'];
            }
        }
    }

    $capacity = ($event['type'] === 'register' && $event['capacity'] !== null) ? (float) $event['capacity'] : null;
    if ($capacity !== null) {
        $predicted = min($predicted, $capacity);
    }

    // Live pacing blend: once the event has real sign-ups and a deadline, lean
    // increasingly on "how fast is it actually filling" over the historical baseline.
    $liveCount = null;
    $paceWeight = null;
    if ($excludeId > 0) {
        $liveCounts = sked_participant_counts($excludeId);
        $liveCount = $liveCounts['active'];
        if ($liveCount > 0 && !empty($event['registration_deadline']) && !empty($event['created_at'])) {
            $created = strtotime((string) $event['created_at']);
            $deadline = strtotime((string) $event['registration_deadline']);
            $now = time();
            $totalWindow = $deadline - $created;
            $elapsed = $now - $created;
            if ($totalWindow > 0 && $elapsed > 0) {
                $fraction = min(1.0, $elapsed / $totalWindow);
                if ($fraction > 0.05) { // too early in the window → pacing math is too noisy to trust
                    $paceProjection = $liveCount / $fraction;
                    if ($capacity !== null) {
                        $paceProjection = min($paceProjection, $capacity);
                    }
                    $paceWeight = round(min(0.85, $fraction), 2);
                    $predicted = ($predicted * (1 - $paceWeight)) + ($paceProjection * $paceWeight);
                    $method .= '+pacing';
                }
            }
        }
    }

    $margin = max(2, (int) round(sked_stddev($counts, $mean)));
    $predicted = max(0.0, round($predicted));
    $high = $predicted + $margin;
    if ($capacity !== null) {
        $high = min($high, $capacity);
    }

    $explanationParts = [
        $method === 'average' ? 'Average of ' . count($pool) . ' ' . $basis : 'Trend line over ' . count($pool) . ' ' . $basis . ($r2 !== null ? ' (R² ' . $r2 . ')' : ''),
    ];
    if ($paceWeight !== null) {
        $explanationParts[] = 'blended with live sign-up pace (' . (int) round($paceWeight * 100) . '% weight, ' . $liveCount . ' signed up so far)';
    }

    return [
        'predicted' => (int) $predicted,
        'low' => (int) max(0, $predicted - $margin),
        'high' => (int) $high,
        'method' => $method,
        'basis' => $basis,
        'n' => count($pool),
        'r2' => $r2,
        'live_signups' => $liveCount,
        'pace_weight' => $paceWeight,
        'explanation' => implode('; ', $explanationParts) . '.',
    ];
}

/** Monthly new-youth-registration counts (optionally barangay-scoped), oldest first, last $monthsBack months. */
function sked_registration_history(?int $barangayId, int $monthsBack = 12): array
{
    $where = "role = 'youth'";
    $params = [];
    if ($barangayId !== null) {
        $where .= ' AND barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }
    $stmt = sked_db()->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
           FROM users WHERE $where
          GROUP BY ym ORDER BY ym ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return $monthsBack > 0 ? array_slice($rows, -$monthsBack) : $rows;
}

/**
 * Forecast next month's new-youth-registration count from an OLS trend
 * line over the last 12 months (falls back to a plain average below
 * SKED_FORECAST_MIN_MONTHS of history, and reports "not enough data" with
 * fewer than 2 months at all).
 *
 * @return array{available:bool,history:array,forecast:?int,next_month_label:?string,method:string,slope:?float,r2:?float,trend:?string}
 */
function sked_registration_forecast(?int $barangayId): array
{
    $history = sked_registration_history($barangayId, 12);
    if (count($history) < 2) {
        return ['available' => false, 'history' => $history, 'forecast' => null, 'next_month_label' => null, 'method' => 'insufficient_data', 'slope' => null, 'r2' => null, 'trend' => null];
    }

    $lastMonth = end($history)['ym'];
    $nextMonthLabel = date('F Y', strtotime($lastMonth . '-01 +1 month'));

    $counts = array_map(static fn($r) => (float) $r['n'], $history);
    if (count($history) < SKED_FORECAST_MIN_MONTHS) {
        $avg = array_sum($counts) / count($counts);
        return [
            'available' => true, 'history' => $history, 'forecast' => (int) round($avg),
            'next_month_label' => $nextMonthLabel, 'method' => 'average', 'slope' => null, 'r2' => null, 'trend' => null,
        ];
    }

    $xs = range(1, count($history));
    $reg = sked_linear_regression($xs, $counts);
    if ($reg === null) {
        $avg = array_sum($counts) / count($counts);
        return [
            'available' => true, 'history' => $history, 'forecast' => (int) round($avg),
            'next_month_label' => $nextMonthLabel, 'method' => 'average', 'slope' => null, 'r2' => null, 'trend' => null,
        ];
    }

    $forecast = max(0.0, round($reg['intercept'] + $reg['slope'] * (count($history) + 1)));
    $trend = $reg['slope'] > 0.05 ? 'rising' : ($reg['slope'] < -0.05 ? 'declining' : 'flat');

    return [
        'available' => true,
        'history' => $history,
        'forecast' => (int) $forecast,
        'next_month_label' => $nextMonthLabel,
        'method' => 'ols_trend',
        'slope' => round($reg['slope'], 2),
        'r2' => $reg['r2'],
        'trend' => $trend,
    ];
}
