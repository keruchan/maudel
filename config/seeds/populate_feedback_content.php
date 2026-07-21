<?php
/**
 * ============================================================
 * File     : config/seeds/populate_feedback_content.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Supplementary seed — varied, realistic free-text content for
 *            the new "Voice of the Youth" word-cloud + sentiment analytics
 *            (Feedback/Concerns messages + KK Profiling "Suggestions").
 *            config/seeds/populate_demo_data.php seeded the structured data
 *            (youths/events/profiles) but left youth_feedback empty and
 *            only gave ~25% of profiles one repeated suggestion string —
 *            not enough real variety to demonstrate text mining honestly.
 *            This script only ADDS text content on top of that existing
 *            data; it does not touch users/events/points.
 *
 * Idempotent: bails out if youth_feedback already has more than 20 rows.
 * Feedback goes through the real sked_submit_feedback() (+ a realistic
 * portion marked reviewed via sked_mark_feedback_reviewed()) so points/
 * notifications/audit stay consistent; KK-suggestion backfill is a direct
 * UPDATE of youth_profiles.remarks (plain free-text field, no workflow to
 * replay) limited to rows that currently have no suggestion text.
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/populate_feedback_content.php
 * ============================================================ */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../includes/feedback.php';

$pdo = sked_db();

$existing = (int) $pdo->query('SELECT COUNT(*) FROM youth_feedback')->fetchColumn();
if ($existing > 20) {
    echo "youth_feedback already has {$existing} rows — looks seeded already. Skipping.\n";
    exit(0);
}

/* ------------------------------------------------------------
 * Feedback / Concerns messages — mixed sentiment, Filipino + English,
 * realistic youth-governance topics (events, scheduling, communication,
 * facilities, KK Profiling, program variety).
 * ------------------------------------------------------------ */
$feedbackPool = [
    // Positive
    "Salamat sa maayos na pagsasaayos ng huling Sports Fest, sobrang saya ng lahat ng participants!",
    "Grateful for the livelihood training last month — very helpful and well organized, matuto kami ng bagong skills.",
    "Maganda ang turnout ng KK Assembly this year, proud ako sa barangay namin.",
    "The scholarship orientation was excellent and clear. Thank you sa SK for reaching out to us.",
    "Nakakatuwa yung Clean and Green drive, engaged talaga ang mga kabataan. Sana regular ito.",
    "Great job sa disaster preparedness drill, very informative and organized ang flow.",
    "Salamat po sa pagbibigay ng katanungan sa amin during the youth congress, na-appreciate namin ang effort.",
    "Proud to be part of this year's Linggo ng Kabataan — maayos at masaya ang mga activities.",
    // Negative
    "Medyo mahirap sundan ang schedule ng events kasi late ang announcement, sana mapaaga next time.",
    "The last sports fest felt disorganized — nagulat kami sa pagbabago ng venue nang huling minuto.",
    "Kulang ang communication about the livelihood program requirements, nalito kami sa mga dokumento.",
    "Disappointed with the delay sa pagbibigay ng results ng scholarship application, matagal bago sumagot.",
    "Nakakainis na madalas nacancel ang mga session, sayang ang oras namin.",
    "The venue for the last seminar was too small and crowded, hindi comfortable manood o makinig.",
    "Sana mas maayos ang pagdi-distribute ng slots, parang paborito lang ang paulit-ulit na participants.",
    "Confusing yung registration process, nag-duplicate submissions kami dahil hindi malinaw ang steps.",
    // Neutral / mixed / suggestion-flavored
    "Sana po madagdagan ang schedule ng sports activities lalo na tuwing weekend.",
    "Suggestion lang po: mas dagdagan ang livelihood seminars para sa out-of-school youth.",
    "Sana may regular update sa social media page ng SK about upcoming events.",
    "Okay lang po yung nakaraang programa pero sana mas madami pang options for out-of-school youth.",
    "Hopefully next year mas madaming schedule ng KK Assembly para sa working youth din.",
    "Would like to see more environment-focused projects, tree planting and clean-up drives.",
];

$feedbackCreated = 0;
$reviewedCount = 0;

$barangays = $pdo->query('SELECT id FROM barangays ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
foreach ($barangays as $bgyId) {
    $bgyId = (int) $bgyId;

    $skStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'sk' AND status = 'active' AND barangay_id = :b LIMIT 1");
    $skStmt->execute(['b' => $bgyId]);
    $skUserId = (int) $skStmt->fetchColumn();

    $youthStmt = $pdo->prepare(
        "SELECT id FROM users WHERE role = 'youth' AND status = 'active' AND barangay_id = :b ORDER BY RAND() LIMIT 5"
    );
    $youthStmt->execute(['b' => $bgyId]);
    $youthIds = $youthStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($youthIds)) { continue; }

    $numMessages = min(count($youthIds), mt_rand(1, 4));
    shuffle($feedbackPool);
    for ($i = 0; $i < $numMessages; $i++) {
        $userId = (int) $youthIds[$i];
        $message = $feedbackPool[$i % count($feedbackPool)];
        $result = sked_submit_feedback(['id' => $userId, 'barangay_id' => $bgyId], $message);
        if (!$result['ok']) { continue; }
        $feedbackCreated++;

        // Backdate to spread across the last few months, and mark a realistic
        // portion already reviewed by the barangay's SK (if it has one).
        $daysAgo = mt_rand(3, 200);
        $pdo->prepare('UPDATE youth_feedback SET created_at = DATE_SUB(NOW(), INTERVAL :d DAY) WHERE id = :id')
            ->execute(['d' => $daysAgo, 'id' => (int) $result['feedback_id']]);

        if ($skUserId > 0 && mt_rand(1, 100) <= 45) {
            sked_mark_feedback_reviewed((int) $result['feedback_id'], $skUserId, $bgyId);
            $reviewedCount++;
        }
    }
}
echo "Feedback/Concerns messages created: {$feedbackCreated} (reviewed: {$reviewedCount})\n";

/* ------------------------------------------------------------
 * Backfill varied KK Suggestions text for profiles that have none yet.
 * ------------------------------------------------------------ */
$suggestionPool = [
    "Sana po madagdagan ang mga sports at livelihood program para sa amin.",
    "Sana mas madami pang scholarship opportunities para sa mga out-of-school youth.",
    "Suggestion ko po sana mas regular ang KK Assembly, minsan hindi kami updated.",
    "Sana magkaroon ng mental health awareness seminars para sa kabataan.",
    "More environment programs please, tulad ng tree planting at clean-up drive.",
    "Sana mas madaling maintindihan ang requirements sa KK Profiling form.",
    "Would appreciate more career orientation and job-readiness seminars.",
    "Sana mas maaga ang pag-announce ng mga upcoming events sa social media.",
    "Hopefully next year magkaroon ng leadership training para sa mga bagong kabataan leaders.",
    "Sana magkaroon din ng mga programa para sa mga batang may specific needs.",
    "More sports facilities and equipment sana para sa regular sports activities.",
    "Sana magkaroon ng disaster preparedness training kada taon, importante ito.",
    "Grateful sa SK sa pag-abot sa amin, sana ituloy niyo lang po ang mga programang ito.",
    "Sana mas madami pang out-of-town exposure trips o exchange programs para sa youth.",
    "Suggestion: mas maayos na venue at sound system sa mga malalaking event.",
];

$profileStmt = $pdo->prepare('SELECT user_id FROM youth_profiles WHERE remarks IS NULL OR remarks = \'\'');
$profileStmt->execute();
$targets = $profileStmt->fetchAll(PDO::FETCH_COLUMN);

$updateRemarks = $pdo->prepare('UPDATE youth_profiles SET remarks = :r WHERE user_id = :u');
$suggestionsAdded = 0;
foreach ($targets as $userId) {
    if (mt_rand(1, 100) > 55) { continue; } // leave a realistic share blank, not everyone writes one
    $text = $suggestionPool[array_rand($suggestionPool)];
    $updateRemarks->execute(['r' => $text, 'u' => (int) $userId]);
    $suggestionsAdded++;
}
echo "KK Suggestion texts backfilled: {$suggestionsAdded}\n";

echo "\nFeedback content seed complete.\n";
