<?php
/**
 * ============================================================
 * File     : includes/feedback_insights.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : "Voice of the Youth" analytics tab data — pulls the two places
 *            youth write free text (youth_feedback.message from P15's
 *            Feedback/Concerns channel, and youth_profiles.remarks, the KK
 *            Profiling form's "KK Suggestions" field), then runs word-
 *            frequency + sentiment analysis (includes/sentiment.php) over
 *            it. Same ?int $barangayId convention as includes/analytics.php:
 *            null = municipality-wide (PPSK/DILG), a real id = barangay (SK).
 * ============================================================ */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sentiment.php';

/**
 * Every piece of youth free text available for mining, newest first.
 *
 * @return array<int,array{text:string,source:string,barangay_id:int,created_at:string}>
 */
function sked_feedback_corpus(?int $barangayId = null): array
{
    $pdo = sked_db();
    $params = [];
    $scopeSql = '';
    if ($barangayId !== null) {
        $scopeSql = ' AND barangay_id = :bgy';
        $params['bgy'] = $barangayId;
    }

    $rows = [];

    $stmt = $pdo->prepare(
        "SELECT message AS text, barangay_id, created_at
           FROM youth_feedback
          WHERE message IS NOT NULL AND message <> ''" . $scopeSql . '
          ORDER BY created_at DESC'
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = ['text' => (string) $r['text'], 'source' => 'feedback', 'barangay_id' => (int) $r['barangay_id'], 'created_at' => (string) $r['created_at']];
    }

    $scopeSql2 = $barangayId !== null ? ' AND u.barangay_id = :bgy' : '';
    $stmt = $pdo->prepare(
        "SELECT yp.remarks AS text, u.barangay_id, yp.created_at AS created_at
           FROM youth_profiles yp
           JOIN users u ON u.id = yp.user_id
          WHERE yp.remarks IS NOT NULL AND yp.remarks <> ''" . $scopeSql2 . '
          ORDER BY yp.created_at DESC'
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = ['text' => (string) $r['text'], 'source' => 'kk_suggestion', 'barangay_id' => (int) $r['barangay_id'], 'created_at' => (string) $r['created_at']];
    }

    $scopeSql3 = $barangayId !== null ? ' AND u.barangay_id = :bgy' : '';
    $stmt = $pdo->prepare(
        "SELECT ev.comments AS text, u.barangay_id, ev.submitted_at AS created_at
           FROM event_evaluations ev
           JOIN users u ON u.id = ev.user_id
          WHERE ev.comments IS NOT NULL AND ev.comments <> ''" . $scopeSql3 . '
          ORDER BY ev.submitted_at DESC'
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = ['text' => (string) $r['text'], 'source' => 'event_evaluation', 'barangay_id' => (int) $r['barangay_id'], 'created_at' => (string) $r['created_at']];
    }

    usort($rows, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $rows;
}

/** Human label for a corpus entry's source, for the UI. */
function sked_feedback_source_label(string $source): string
{
    return match ($source) {
        'feedback' => 'Feedback / Concern',
        'event_evaluation' => 'Event Evaluation',
        default => 'KK Suggestion',
    };
}

/**
 * Word frequencies across the corpus, top $limit, with a 1-4 "tier" for
 * word-cloud sizing (1 = most frequent) assigned by rank quartile rather
 * than raw count, so one outlier word can't blow out the whole scale.
 *
 * Each word also carries a SENTIMENT so the cloud can be filtered by tone:
 *   - if the word is itself in the lexicon, that is its sentiment
 *     ("delayed" is negative wherever it appears);
 *   - otherwise it inherits the dominant sentiment of the entries it
 *     appears in ("gym" is negative if people only mention it to complain),
 *     which is what actually makes the filter useful — most topical words
 *     carry no inherent tone, only the context they show up in.
 * A word needs a clear majority (>50% of its mentions) to be tagged, else
 * it stays neutral rather than being forced into a camp on a coin flip.
 *
 * Entry indexes are attached too, so clicking a word can reveal the exact
 * comments it came from.
 *
 * @param array<int,array{text:string,label:string}> $entries Sentiment-labeled corpus.
 * @return array<int,array{word:string,count:int,pct:float,tier:int,sentiment:string,entries:int[]}>
 */
function sked_feedback_word_frequencies(array $entries, int $limit = 30): array
{
    $counts = [];
    $byLabel = [];   // word => ['positive'=>n,'negative'=>n,'neutral'=>n]
    $entryIdx = [];  // word => [entry index, ...]
    $totalTokens = 0;

    foreach ($entries as $idx => $entry) {
        $label = (string) ($entry['label'] ?? 'neutral');
        $seenInThisEntry = [];
        foreach (sked_tokenize_text((string) $entry['text']) as $word) {
            $counts[$word] = ($counts[$word] ?? 0) + 1;
            $totalTokens++;
            if (!isset($byLabel[$word])) {
                $byLabel[$word] = ['positive' => 0, 'negative' => 0, 'neutral' => 0];
            }
            if (!isset($seenInThisEntry[$word])) {
                $byLabel[$word][$label]++;
                $entryIdx[$word][] = $idx;
                $seenInThisEntry[$word] = true;
            }
        }
    }
    if (empty($counts)) { return []; }

    arsort($counts);
    $top = array_slice($counts, 0, $limit, true);
    $n = count($top);
    $i = 0;
    $out = [];

    foreach ($top as $word => $count) {
        $rankPct = $n > 1 ? $i / ($n - 1) : 0; // 0 = most frequent, 1 = least
        $tier = $rankPct <= 0.15 ? 1 : ($rankPct <= 0.4 ? 2 : ($rankPct <= 0.7 ? 3 : 4));

        // Inherent lexicon tone wins; otherwise take a clear contextual majority.
        $sentiment = sked_word_sentiment((string) $word);
        if ($sentiment === null) {
            $spread = $byLabel[$word] ?? ['positive' => 0, 'negative' => 0, 'neutral' => 0];
            $mentions = array_sum($spread);
            $sentiment = 'neutral';
            if ($mentions > 0) {
                arsort($spread);
                $topLabel = (string) array_key_first($spread);
                if ($topLabel !== 'neutral' && $spread[$topLabel] / $mentions > 0.5) {
                    $sentiment = $topLabel;
                }
            }
        }

        $out[] = [
            'word' => (string) $word,
            'count' => $count,
            'pct' => $totalTokens > 0 ? round($count / $totalTokens * 100, 1) : 0.0,
            'tier' => $tier,
            'sentiment' => $sentiment,
            'entries' => array_values(array_unique($entryIdx[$word] ?? [])),
        ];
        $i++;
    }
    return $out;
}

/**
 * Runs sentiment scoring over the whole corpus and summarizes it.
 *
 * @param array<int,array{text:string,source:string,created_at:string}> $corpus
 * @return array{total:int,positive:int,neutral:int,negative:int,pct:array,avg_score:float,overall:string,entries:array}
 */
function sked_feedback_sentiment_overview(array $corpus): array
{
    $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
    $sumScore = 0.0;
    $entries = [];

    foreach ($corpus as $entry) {
        $result = sked_sentiment_score($entry['text']);
        $counts[$result['label']]++;
        $sumScore += $result['score'];
        $entries[] = [
            'text' => $entry['text'],
            'source' => $entry['source'],
            'created_at' => $entry['created_at'],
            'label' => $result['label'],
            'score' => $result['score'],
            // Why this label — lets the UI show its working instead of
            // asking an SK official to trust an unexplained verdict.
            'why' => sked_sentiment_explain($result),
        ];
    }

    $total = count($corpus);
    $pct = ['positive' => 0.0, 'neutral' => 0.0, 'negative' => 0.0];
    if ($total > 0) {
        foreach ($counts as $label => $n) {
            $pct[$label] = round($n / $total * 100, 1);
        }
    }
    $avgScore = $total > 0 ? round($sumScore / $total, 3) : 0.0;
    $overall = $avgScore > 0.1 ? 'positive' : ($avgScore < -0.1 ? 'negative' : 'neutral');

    return [
        'total' => $total,
        'positive' => $counts['positive'],
        'neutral' => $counts['neutral'],
        'negative' => $counts['negative'],
        'pct' => $pct,
        'avg_score' => $avgScore,
        'overall' => $overall,
        'entries' => $entries,
    ];
}

/**
 * A representative spotlight sample for the "recent feedback" list — quota'd
 * across positive/neutral/negative rather than strict recency, so an entry
 * pool that happens to cluster in time (e.g. a bulk import) doesn't hide
 * every negative example behind a wall of neutral ones. Within each label,
 * newest first.
 *
 * @param array<int,array{label:string,created_at:string}> $entries
 * @return array<int,array>
 */
function sked_feedback_spotlight(array $entries, int $limit = 8): array
{
    $byLabel = ['negative' => [], 'neutral' => [], 'positive' => []];
    foreach ($entries as $entry) {
        $byLabel[$entry['label']][] = $entry;
    }
    foreach ($byLabel as &$list) {
        usort($list, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
    }
    unset($list);

    // Prioritize negative and positive examples (the actionable signal) over
    // neutral, while still guaranteeing at least a couple of neutral ones.
    $quota = ['negative' => 3, 'positive' => 3, 'neutral' => 2];
    $picked = [];
    foreach ($quota as $label => $n) {
        $picked = array_merge($picked, array_slice($byLabel[$label], 0, $n));
    }
    // Top up from whatever's left (any label) if under the limit.
    if (count($picked) < $limit) {
        $pickedTexts = array_map(static fn ($e) => $e['text'] . '|' . $e['created_at'], $picked);
        foreach (['negative', 'positive', 'neutral'] as $label) {
            foreach ($byLabel[$label] as $entry) {
                if (count($picked) >= $limit) { break 2; }
                $key = $entry['text'] . '|' . $entry['created_at'];
                if (in_array($key, $pickedTexts, true)) { continue; }
                $picked[] = $entry;
                $pickedTexts[] = $key;
            }
        }
    }

    usort($picked, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return array_slice($picked, 0, $limit);
}

/**
 * One-call bundle for the analytics page: corpus + word cloud + sentiment +
 * a spotlight sample.
 *
 * Sentiment is scored FIRST so the word cloud can inherit each entry's label
 * — that is what lets cloud words be filtered by tone.
 */
function sked_feedback_insights_bundle(?int $barangayId = null): array
{
    $corpus = sked_feedback_corpus($barangayId);
    $sentiment = sked_feedback_sentiment_overview($corpus);

    return [
        'corpus_count' => count($corpus),
        'words' => sked_feedback_word_frequencies($sentiment['entries'], 40),
        'sentiment' => $sentiment,
        'recent' => sked_feedback_spotlight($sentiment['entries'], 8),
    ];
}
