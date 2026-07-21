<?php
/**
 * ============================================================
 * File     : config/seeds/populate_demo_data.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Bulk realistic seed data across all 20 real barangays so the
 *            app is no longer near-empty — youths (varied age/sex/status),
 *            KK profiles, SK officials, events (barangay/interbarangay/
 *            municipal, past + upcoming, with participants/attendance/
 *            evaluations), polls with votes, and a handful of CBYDP/ABYIP
 *            plans. Every insert flows through the SAME real business-logic
 *            functions the app itself uses (sked_create_event,
 *            sked_join_event, sked_save_youth_profile, sked_cast_poll_vote,
 *            etc.) so points, notifications, and derived counts stay
 *            consistent — this is not raw fixture data bypassing the app.
 *
 * Idempotent-ish: usernames are deterministic (yt{barangay}{seq},
 * sk_{barangay-slug}), so accounts are skipped if already present. The
 * generative steps (profiles/events/polls/plans) are guarded by a
 * whole-script bail-out if youths already look seeded (see MIN_SEED_YOUTH
 * below) — this is meant to run ONCE, not repeatedly.
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/populate_demo_data.php
 * ============================================================
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/polls.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/sk_members.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';

$pdo = sked_db();

$existingYouth = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'youth'")->fetchColumn();
if ($existingYouth > 60) {
    echo "Looks like this has already been seeded (found {$existingYouth} youth accounts). Skipping to avoid duplicating data.\n";
    echo "Delete rows manually first if you really want to re-run this from scratch.\n";
    exit(0);
}

/* ------------------------------------------------------------
 * Name pools + small helpers
 * ------------------------------------------------------------ */
$givenMale = ['Juan','Jose','Antonio','Ramon','Carlos','Miguel','Rafael','Angelo','Marco','Paolo',
    'Ricardo','Eduardo','Francisco','Andres','Diego','Gabriel','Vicente','Emilio','Leandro','Renato',
    'Bryan','Kevin','Mark','John','Christian','Joshua','Nathaniel','Adrian','Julius','Xavier'];
$givenFemale = ['Maria','Ana','Rosa','Carmen','Teresa','Luisa','Elena','Cristina','Gabriela','Isabel',
    'Camille','Angelica','Bianca','Patricia','Katrina','Michelle','Andrea','Samantha','Jasmine','Nicole',
    'Grace','Joy','Faith','Charlene','Kristine','Precious','Angel','Kimberly','Alyssa','Danica'];
$surnames = ['Santos','Reyes','Cruz','Bautista','Ocampo','Garcia','Torres','Ramos','Mendoza','Castillo',
    'Aquino','Del Rosario','Fernandez','Villanueva','Gonzales','Rivera','Salazar','Aguilar','Marquez','Pascual',
    'Domingo','Navarro','Rosales','Manalo','Guevarra','Lazaro','Valdez','Sarmiento','Cabrera','Espinosa',
    'Mercado','Bonifacio','Silang','Rizal','Trinidad','Concepcion','Dizon','Ilagan','Panganiban','Tolentino'];
$middleNames = ['Santos','Reyes','Cruz','Garcia','Ramos','Torres','Mendoza','Aquino','Fernandez','Rivera'];

function sked_seed_rand_pick(array $arr) { return $arr[array_rand($arr)]; }
function sked_seed_rand_weighted(array $weighted): string
{
    $total = array_sum($weighted);
    $r = mt_rand(1, (int) ($total * 1000)) / 1000;
    $acc = 0;
    foreach ($weighted as $key => $w) {
        $acc += $w;
        if ($r <= $acc) return (string) $key;
    }
    return (string) array_key_last($weighted);
}
function sked_seed_slug(string $name): string
{
    $s = strtolower($name);
    $s = preg_replace('/\([^)]*\)/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s;
}
/** Random birthdate for an age range, as of today. */
function sked_seed_birthdate(int $minAge, int $maxAge): string
{
    $age = mt_rand($minAge, $maxAge);
    $today = new DateTime('today');
    $d = clone $today;
    $d->modify("-{$age} years");
    $d->modify('-' . mt_rand(0, 364) . ' days');
    return $d->format('Y-m-d');
}
/** Random date N1..N2 days in the past from today. */
function sked_seed_past_date(int $minDaysAgo, int $maxDaysAgo): string
{
    $d = new DateTime('today');
    $d->modify('-' . mt_rand($minDaysAgo, $maxDaysAgo) . ' days');
    return $d->format('Y-m-d');
}
/** Random date N1..N2 days in the future from today. */
function sked_seed_future_date(int $minDaysAhead, int $maxDaysAhead): string
{
    $d = new DateTime('today');
    $d->modify('+' . mt_rand($minDaysAhead, $maxDaysAhead) . ' days');
    return $d->format('Y-m-d');
}

$interestCategories = sked_interest_categories();
$preferredProgramsAll = sked_preferred_programs();
$scholarshipOptions = sked_scholarship_options();
$profilingOptions = sked_profiling_options();

/* ------------------------------------------------------------
 * STEP 1 — Barangays with vs. without an active SK council.
 * Acevida (id 1) already has a real seeded SK (skacevida). We add active SK
 * accounts for 13 more (ids 2-14), leaving 15-20 without one — a realistic
 * partial-coverage picture rather than every barangay perfectly staffed.
 * ------------------------------------------------------------ */
$allBarangays = sked_barangays();
$barangayName = [];
foreach ($allBarangays as $b) { $barangayName[(int) $b['id']] = (string) $b['name']; }

$skBarangayIds = range(1, 14);
$noSkBarangayIds = range(15, 20);

$skUserForBarangay = []; // barangayId => ['id'=>, 'name'=>]
$skUserForBarangay[1] = null; // filled in below from the existing skacevida row

$existing = $pdo->prepare("SELECT id, name FROM users WHERE role = 'sk' AND barangay_id = :b AND status = 'active' LIMIT 1");
$existing->execute(['b' => 1]);
$row = $existing->fetch();
if ($row !== false) {
    $skUserForBarangay[1] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
}

$skPassword = 'SkSeed@2026!';
$skHash = password_hash($skPassword, PASSWORD_DEFAULT);
$skCreated = 0;
$existsStmt = $pdo->prepare('SELECT id, name FROM users WHERE username = :u LIMIT 1');
$insertSk = $pdo->prepare(
    "INSERT INTO users (role, status, barangay_id, name, surname, given_name, sex_assigned_at_birth,
        email, mobile, birthdate, username, password_hash, verified)
     VALUES ('sk', 'active', :bgy, :name, :surname, :given, :sex,
        :email, :mobile, :birthdate, :username, :hash, 1)"
);
foreach ($skBarangayIds as $bgyId) {
    if ($bgyId === 1) { continue; } // Acevida already has skacevida
    $slug = sked_seed_slug($barangayName[$bgyId] ?? ('brgy' . $bgyId));
    $username = 'sk_' . $slug;
    $existsStmt->execute(['u' => $username]);
    $found = $existsStmt->fetch();
    if ($found !== false) {
        $skUserForBarangay[$bgyId] = ['id' => (int) $found['id'], 'name' => (string) $found['name']];
        continue;
    }
    $sex = mt_rand(0, 1) ? 'male' : 'female';
    $given = $sex === 'male' ? sked_seed_rand_pick($givenMale) : sked_seed_rand_pick($givenFemale);
    $surname = sked_seed_rand_pick($surnames);
    $name = "$given $surname";
    $insertSk->execute([
        'bgy' => $bgyId,
        'name' => $name,
        'surname' => $surname,
        'given' => $given,
        'sex' => $sex,
        'email' => $username . '@sked-sample.local',
        'mobile' => '09' . mt_rand(150000000, 999999999),
        'birthdate' => sked_seed_birthdate(24, 30),
        'username' => $username,
        'hash' => $skHash,
    ]);
    $skUserForBarangay[$bgyId] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
    $skCreated++;
}
echo "SK chairperson accounts created: {$skCreated} (shared password: {$skPassword})\n";

/* ------------------------------------------------------------
 * STEP 2 — SK officials roster for every barangay with an SK.
 * ------------------------------------------------------------ */
$officialsCreated = 0;
$positionsExtra = ['SK Secretary', 'SK Treasurer', 'SK Kagawad', 'SK Kagawad', 'SK Auditor'];
foreach ($skBarangayIds as $bgyId) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM sk_officials WHERE barangay_id = :b');
    $countStmt->execute(['b' => $bgyId]);
    if ((int) $countStmt->fetchColumn() > 0) { continue; }

    $skUser = $skUserForBarangay[$bgyId] ?? null;
    if ($skUser === null) { continue; }

    sked_sk_official_save($bgyId, $skUser['id'], [
        'user_id' => $skUser['id'],
        'position' => 'SK Chairperson',
        'full_name' => $skUser['name'],
        'status' => 'active',
        'term_start' => sked_seed_past_date(300, 700),
        'term_end' => sked_seed_future_date(300, 700),
    ]);
    $officialsCreated++;

    foreach (array_slice($positionsExtra, 0, mt_rand(3, 5)) as $pos) {
        $sex = mt_rand(0, 1) ? 'male' : 'female';
        $given = $sex === 'male' ? sked_seed_rand_pick($givenMale) : sked_seed_rand_pick($givenFemale);
        $fullName = "$given " . sked_seed_rand_pick($surnames);
        sked_sk_official_save($bgyId, $skUser['id'], [
            'position' => $pos,
            'full_name' => $fullName,
            'status' => mt_rand(1, 100) <= 90 ? 'active' : 'inactive',
            'term_start' => sked_seed_past_date(300, 700),
            'term_end' => sked_seed_future_date(300, 700),
        ]);
        $officialsCreated++;
    }
}
echo "SK officials created: {$officialsCreated}\n";

/* ------------------------------------------------------------
 * STEP 3 — Youths per barangay.
 * ------------------------------------------------------------ */
$insertYouth = $pdo->prepare(
    'INSERT INTO users (role, status, barangay_id, name, surname, given_name, middle_name,
        sex_assigned_at_birth, email, mobile, birthdate, username, password_hash, verified)
     VALUES (\'youth\', :status, :bgy, :name, :surname, :given, :middle,
        :sex, :email, :mobile, :birthdate, :username, :hash, :verified)'
);
$backdateUser = $pdo->prepare('UPDATE users SET created_at = :d WHERE id = :id');
$youthPassword = 'Youth@2026!';
$youthHash = password_hash($youthPassword, PASSWORD_DEFAULT);

$youthsByBarangay = []; // bgyId => list of ['id','status','sex','birthdate','age']
$totalYouthCreated = 0;

foreach (range(1, 20) as $bgyId) {
    $hasSk = in_array($bgyId, $skBarangayIds, true);
    $count = $hasSk ? mt_rand(15, 24) : mt_rand(5, 10);
    $youthsByBarangay[$bgyId] = [];

    // Find the next free sequence number for this barangay's username prefix.
    $prefix = 'yt' . str_pad((string) $bgyId, 2, '0', STR_PAD_LEFT);
    $seq = 1;
    $checkSeq = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');

    for ($i = 0; $i < $count; $i++) {
        while (true) {
            $username = $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $checkSeq->execute(['u' => $username]);
            $found = $checkSeq->fetch();
            if ($found === false) { break; }
            $youthsByBarangay[$bgyId][] = null; // placeholder skipped below; existing row, don't re-profile it here
            $seq++;
        }

        $sex = mt_rand(0, 1) ? 'male' : 'female';
        $given = $sex === 'male' ? sked_seed_rand_pick($givenMale) : sked_seed_rand_pick($givenFemale);
        $middle = sked_seed_rand_pick($middleNames);
        $surname = sked_seed_rand_pick($surnames);
        $name = "$given $middle $surname";
        $birthdate = sked_seed_birthdate(15, 30);
        $age = (int) (new DateTime($birthdate))->diff(new DateTime('today'))->y;

        $status = $hasSk
            ? sked_seed_rand_weighted(['active' => 70, 'pending' => 20, 'rejected' => 10])
            : sked_seed_rand_weighted(['active' => 10, 'pending' => 85, 'rejected' => 5]);
        $verified = $status === 'active' ? 1 : 0;

        $insertYouth->execute([
            'status' => $status,
            'bgy' => $bgyId,
            'name' => $name,
            'surname' => $surname,
            'given' => $given,
            'middle' => $middle,
            'sex' => $sex,
            'email' => $username . '@sked-sample.local',
            'mobile' => '09' . mt_rand(150000000, 999999999),
            'birthdate' => $birthdate,
            'username' => $username,
            'hash' => $youthHash,
            'verified' => $verified,
        ]);
        $userId = (int) $pdo->lastInsertId();

        // Spread registration dates across the last ~18 months for a real trend line.
        $regDate = sked_seed_past_date(1, 540) . ' ' . sprintf('%02d:%02d:00', mt_rand(7, 20), mt_rand(0, 59));
        $backdateUser->execute(['d' => $regDate, 'id' => $userId]);

        $youthsByBarangay[$bgyId][] = ['id' => $userId, 'status' => $status, 'sex' => $sex, 'birthdate' => $birthdate, 'age' => $age];
        $totalYouthCreated++;
        $seq++;
    }
}
echo "Youth accounts created: {$totalYouthCreated} (shared password: {$youthPassword})\n";

/* ------------------------------------------------------------
 * STEP 4 — KK Profiles for ~75% of active youths.
 * ------------------------------------------------------------ */
function sked_seed_education_for_age(int $age): string
{
    if ($age <= 17) return sked_seed_rand_pick(['Junior High School Level (Grade 7-9)', 'Junior High School Graduate (Grade 10)', 'Senior High School Level (Grade 11)']);
    if ($age <= 20) return sked_seed_rand_pick(['Senior High School Graduate (Grade 12)', '1st Year College', '2nd Year College']);
    if ($age <= 24) return sked_seed_rand_pick(['2nd Year College', '3rd Year College', '4th Year College', 'College Graduate']);
    return sked_seed_rand_pick(['College Graduate', 'College Graduate', 'Masters Level', 'College Graduate']);
}
function sked_seed_work_status_for_age(int $age): string
{
    if ($age <= 20) return sked_seed_rand_weighted([
        'N/A (Hindi Naaangkop)' => 45, 'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 15,
        'Unemployed (Walang Trabaho)' => 20, 'Employed (Empleyado)' => 20,
    ]);
    return sked_seed_rand_weighted([
        'Employed (Empleyado)' => 45, 'Self-Employed (Sa sarili nagtatrabaho)' => 15,
        'Unemployed (Walang Trabaho)' => 15, 'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 20,
        'Not Interested Looking for a Job (Hindi Interesadong sa Paghahanap ng Trabaho)' => 5,
    ]);
}

$profilesCreated = 0;
$classificationList = sked_youth_classifications();
$specificNeedsList = sked_specific_needs_options();

foreach ($youthsByBarangay as $bgyId => $list) {
    foreach ($list as $y) {
        if ($y === null || $y['status'] !== 'active') { continue; }
        if (mt_rand(1, 100) > 75) { continue; }
        if (sked_has_youth_profile($y['id'])) { continue; }

        $age = $y['age'];
        $sex = $y['sex'];
        $genderIdentity = mt_rand(1, 100) <= 95 ? ($sex === 'male' ? 'man' : 'woman') : ($sex === 'male' ? 'woman' : 'man');

        $classifications = [$classificationList[array_rand($classificationList)]];
        if (mt_rand(1, 100) <= 30 && count($classifications) < 2) {
            $second = $classificationList[array_rand($classificationList)];
            if (!in_array($second, $classifications, true)) { $classifications[] = $second; }
        }
        $specificNeeds = in_array(SKED_CLASSIFICATION_SPECIFIC_NEEDS, $classifications, true)
            ? [$specificNeedsList[array_rand($specificNeedsList)]]
            : [];

        $scholarships = mt_rand(1, 100) <= 25
            ? [sked_seed_rand_pick(array_diff($scholarshipOptions, ['None', 'Others']))]
            : ['None'];

        $numInterests = mt_rand(1, 3);
        $interestKeys = array_rand($interestCategories, $numInterests);
        $interests = is_array($interestKeys)
            ? array_map(static fn ($k) => $interestCategories[$k], $interestKeys)
            : [$interestCategories[$interestKeys]];

        $numPrograms = mt_rand(3, 5);
        $programKeys = array_rand($preferredProgramsAll, $numPrograms);
        $preferredPrograms = is_array($programKeys)
            ? array_map(static fn ($k) => $preferredProgramsAll[$k], $programKeys)
            : [$preferredProgramsAll[$programKeys]];

        $attended = mt_rand(1, 100) <= 55 ? '1' : '0';

        $data = [
            'consent_agreed' => 1,
            'civil_status' => $age >= 25 ? sked_seed_rand_weighted(['Single (Walang Asawa)' => 55, 'Married (Kasal/May Asawa)' => 30, 'Live-In (Nakikisama)' => 10, 'Separated (Hiwalay)' => 5])
                              : 'Single (Walang Asawa)',
            'gender_identity' => $genderIdentity,
            'lgbtqia_member' => mt_rand(1, 100) <= 10 ? 1 : 0,
            'num_children' => $age >= 22 ? sked_seed_rand_weighted([0 => 60, 1 => 25, 2 => 10, 3 => 5]) : 0,
            'educational_attainment' => sked_seed_education_for_age($age),
            'work_status' => sked_seed_work_status_for_age($age),
            'valid_id' => sked_seed_rand_pick(['Philippine National ID', "Voter's ID", 'School ID', 'Postal ID']),
            'sk_voter' => mt_rand(1, 100) <= 85 ? '1' : '0',
            'national_voter' => $age >= 18 ? (mt_rand(1, 100) <= 80 ? '1' : '0') : '0',
            'voted_last_election' => $age >= 18 ? (mt_rand(1, 100) <= 70 ? '1' : '0') : '0',
            'attended_kk_assembly' => $attended,
            'kk_assembly_times' => $attended === '1' ? mt_rand(1, 2) : null,
            'kk_assembly_absence_reason' => $attended === '0' ? sked_seed_rand_pick($profilingOptions['kk_assembly_absence_reason']) : null,
            'classifications' => $classifications,
            'specific_needs' => $specificNeeds,
            'scholarships' => $scholarships,
            'scholarship_other' => '',
            'interests' => $interests,
            'preferred_programs' => $preferredPrograms,
            'preferred_programs_other' => '',
            'kk_suggestions' => mt_rand(1, 100) <= 25 ? 'Sana po madagdagan ang mga sports at livelihood program para sa amin.' : '',
        ];
        $data['facebook_name'] = strtolower(str_replace(' ', '.', $sex === 'male' ? sked_seed_rand_pick($givenMale) : sked_seed_rand_pick($givenFemale))) . mt_rand(10, 99);

        $res = sked_save_youth_profile($y['id'], $data, 0, 'youth');
        if ($res['ok']) { $profilesCreated++; }
    }
}
echo "KK Profiles created: {$profilesCreated}\n";

/* ------------------------------------------------------------
 * STEP 5 — Events (barangay-scope from each SK, plus inter-barangay /
 * municipal from PPSK), spread across the last 12 months + a few upcoming.
 * ------------------------------------------------------------ */
$ppskRow = $pdo->query("SELECT id, name FROM users WHERE role = 'ppsk' AND status = 'active' LIMIT 1")->fetch();
$ppskActor = $ppskRow !== false ? ['id' => (int) $ppskRow['id'], 'role' => 'ppsk', 'name' => (string) $ppskRow['name'], 'barangay_id' => null] : null;

$barangayEventTemplates = [
    ['Barangay KK General Assembly', 'Governance'],
    ['Clean and Green Community Drive', 'Environment'],
    ['Youth Sports Fest', 'Health'],
    ['Livelihood Skills Training', 'Economic Empowerment'],
    ['Anti-Drug Abuse Awareness Seminar', 'Peace Building and Security'],
    ['Tree Growing and Parenting Activity', 'Environment'],
    ["Voter's Education Seminar", 'Governance'],
    ['Blood Letting Activity', 'Health'],
    ['Career Orientation Seminar', 'Education'],
    ['Disaster Preparedness Drill', 'Peace Building and Security'],
    ['Creative Arts Contest', 'Social Inclusion and Equity'],
    ['Barangay Feeding Program', 'Health'],
];
$fedEventTemplates = [
    ['Municipal Youth Congress', 'municipal', 'Governance'],
    ['SK Federation General Assembly', 'municipal', 'Governance'],
    ['Interbarangay Sports Fest', 'interbarangay', 'Health'],
    ['Provincial Youth Leadership Summit', 'municipal', 'Education'],
    ['Interbarangay Environmental Congress', 'interbarangay', 'Environment'],
    ['Municipal Disaster Preparedness Drill', 'municipal', 'Peace Building and Security'],
    ['SINILYMPICS', 'municipal', 'Health'],
    ['Interbarangay Livelihood Fair', 'interbarangay', 'Economic Empowerment'],
];

$setStatusStmt = $pdo->prepare('UPDATE events SET created_at = :d WHERE id = :id');
$joinedAtStmt = $pdo->prepare('UPDATE event_participants SET joined_at = DATE_SUB(:evd, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE event_id = :eid');

$eventsCreated = 0;
$participationsCreated = 0;

function sked_seed_process_lifecycle(PDO $pdo, array $eventResult, array $eligibleYouthIds, int $actorId, string $eventDate, bool $isTeamSport, ?int $capacity): int
{
    $eventId = (int) $eventResult['event_id'];
    $event = sked_get_event($eventId);
    $isPast = $eventDate < date('Y-m-d');
    $joinedCount = 0;

    // Publish first (created as draft).
    sked_set_event_status($actorId, $event, 'published');
    $event = sked_get_event($eventId);

    $poolSize = count($eligibleYouthIds);
    if ($poolSize === 0) { return 0; }
    $targetJoins = min($poolSize, $capacity ?? $poolSize, mt_rand(6, min(25, $poolSize)));
    shuffle($eligibleYouthIds);
    $joiners = array_slice($eligibleYouthIds, 0, $targetJoins);

    foreach ($joiners as $uid) {
        $res = sked_join_event($uid, sked_seed_youth_scope_barangay($pdo, $uid), $eventId, '');
        if ($res['ok']) { $joinedCount++; }
    }

    if (!$isPast) {
        // Upcoming event: leave it published/confirmed, no attendance yet.
        if (mt_rand(1, 100) <= 40) {
            sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed');
        }
        return $joinedCount;
    }

    // Historical event: walk the full lifecycle.
    sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed');
    sked_set_event_status($actorId, sked_get_event($eventId), 'ongoing');
    sked_set_event_status($actorId, sked_get_event($eventId), 'completed');

    $roster = sked_event_roster($eventId);
    foreach ($roster as $p) {
        $present = mt_rand(1, 100) <= 82;
        sked_mark_attendance($actorId, $eventId, (int) $p['user_id'], $present);
    }

    sked_set_event_status($actorId, sked_get_event($eventId), 'evaluation');
    $rosterAfter = sked_event_roster($eventId);
    foreach ($rosterAfter as $p) {
        if ($p['status'] !== 'attended') { continue; }
        if (mt_rand(1, 100) > 60) { continue; }
        $rating = (int) sked_seed_rand_weighted([3 => 15, 4 => 45, 5 => 40]);
        sked_submit_evaluation((int) $p['user_id'], $eventId, $rating, '');
    }

    // Older completed events get archived to "closed".
    if ($eventDate < date('Y-m-d', strtotime('-45 days'))) {
        sked_set_event_status($actorId, sked_get_event($eventId), 'closed');
    }

    return $joinedCount;
}

function sked_seed_youth_scope_barangay(PDO $pdo, int $userId): int
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $stmt = $pdo->prepare('SELECT barangay_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $cache[$userId] = (int) $stmt->fetchColumn();
    }
    return $cache[$userId];
}

// Flat pool of active youth ids per barangay, for eligibility sampling.
$activeYouthIdsByBarangay = [];
foreach ($youthsByBarangay as $bgyId => $list) {
    $activeYouthIdsByBarangay[$bgyId] = array_values(array_map(
        static fn ($y) => $y['id'],
        array_filter($list, static fn ($y) => $y !== null && $y['status'] === 'active')
    ));
}
$allActiveYouthIds = array_merge(...array_values($activeYouthIdsByBarangay));

foreach ($skBarangayIds as $bgyId) {
    $skUser = $skUserForBarangay[$bgyId] ?? null;
    if ($skUser === null) { continue; }
    $actor = ['id' => $skUser['id'], 'role' => 'sk', 'name' => $skUser['name'], 'barangay_id' => $bgyId];

    $countCheck = $pdo->prepare("SELECT COUNT(*) FROM events WHERE scope = 'barangay' AND barangay_id = :b");
    $countCheck->execute(['b' => $bgyId]);
    if ((int) $countCheck->fetchColumn() > 0) { continue; }

    $numEvents = mt_rand(4, 7);
    $templates = $barangayEventTemplates;
    shuffle($templates);
    for ($i = 0; $i < $numEvents; $i++) {
        [$title, $category] = $templates[$i % count($templates)];
        $isUpcoming = mt_rand(1, 100) <= 20;
        $eventDate = $isUpcoming ? sked_seed_future_date(5, 60) : sked_seed_past_date(3, 365);
        $type = mt_rand(1, 100) <= 30 ? 'register' : 'interested';
        $capacity = $type === 'register' ? mt_rand(20, 45) : null;

        $result = sked_create_event($actor, [
            'title' => $title,
            'description' => "Barangay-wide activity for the youth of {$barangayName[$bgyId]}.",
            'category' => $category,
            'scope' => 'barangay',
            'type' => $type,
            'is_team_sport' => 0,
            'location' => 'Barangay ' . $barangayName[$bgyId] . ' Covered Court',
            'event_date' => $eventDate,
            'min_participants' => 5,
            'capacity' => $capacity,
            'publish' => false,
        ]);
        if (!$result['ok']) { continue; }
        $eventsCreated++;
        $eventId = (int) $result['event_id'];

        $pool = $activeYouthIdsByBarangay[$bgyId];
        $joined = sked_seed_process_lifecycle($pdo, $result, $pool, $skUser['id'], $eventDate, false, $capacity);
        $participationsCreated += $joined;

        $createdAt = date('Y-m-d H:i:s', strtotime($eventDate . ' -' . mt_rand(14, 45) . ' days'));
        $setStatusStmt->execute(['d' => $createdAt, 'id' => $eventId]);
        $joinedAtStmt->execute(['evd' => $eventDate, 'eid' => $eventId]);
    }
}
echo "Barangay events created: {$eventsCreated}, participations: {$participationsCreated}\n";

$fedEventsCreated = 0;
$fedParticipations = 0;
if ($ppskActor !== null) {
    $existingFed = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE scope IN ('interbarangay','municipal')")->fetchColumn();
    if ($existingFed === 0) {
        foreach ($fedEventTemplates as [$title, $scope, $category]) {
            $isUpcoming = mt_rand(1, 100) <= 25;
            $eventDate = $isUpcoming ? sked_seed_future_date(10, 75) : sked_seed_past_date(5, 330);
            $targetBarangays = [];
            if ($scope === 'interbarangay') {
                $picked = $skBarangayIds;
                shuffle($picked);
                $targetBarangays = array_slice($picked, 0, mt_rand(3, 6));
            }

            $result = sked_create_event($ppskActor, [
                'title' => $title,
                'description' => 'Federation-led activity for SK councils across Siniloan.',
                'category' => $category,
                'scope' => $scope,
                'type' => 'interested',
                'is_team_sport' => 0,
                'location' => 'Siniloan Municipal Gymnasium',
                'event_date' => $eventDate,
                'min_participants' => 10,
                'capacity' => null,
                'publish' => false,
                'target_barangays' => $targetBarangays,
            ]);
            if (!$result['ok']) { continue; }
            $fedEventsCreated++;
            $eventId = (int) $result['event_id'];

            $pool = $scope === 'municipal'
                ? $allActiveYouthIds
                : array_merge(...array_map(static fn ($b) => $activeYouthIdsByBarangay[$b] ?? [], $targetBarangays));
            $joined = sked_seed_process_lifecycle($pdo, $result, $pool, $ppskActor['id'], $eventDate, false, null);
            $fedParticipations += $joined;

            $createdAt = date('Y-m-d H:i:s', strtotime($eventDate . ' -' . mt_rand(20, 60) . ' days'));
            $setStatusStmt->execute(['d' => $createdAt, 'id' => $eventId]);
            $joinedAtStmt->execute(['evd' => $eventDate, 'eid' => $eventId]);
        }
    }
}
echo "Federation (interbarangay/municipal) events created: {$fedEventsCreated}, participations: {$fedParticipations}\n";

/* ------------------------------------------------------------
 * STEP 6 — Polls per SK barangay, with votes.
 * ------------------------------------------------------------ */
$pollTemplates = [
    ['What youth program should we prioritize next quarter?', 'Governance', ['Sports & Wellness', 'Livelihood Training', 'Environmental Clean-up', 'Educational Assistance']],
    ['Which day works best for the next KK Assembly?', 'Governance', ['Saturday morning', 'Saturday afternoon', 'Sunday morning', 'Sunday afternoon']],
    ['What should our next community project focus on?', 'Social Inclusion and Equity', ['Youth with disabilities support', 'Solo parent assistance', 'Out-of-school youth outreach', 'Senior-youth bridge program']],
    ['Preferred venue for the Youth Sports Fest?', 'Health', ['Barangay covered court', 'Municipal gym', 'School grounds']],
];
$pollsCreated = 0;
$votesCreated = 0;
$pollCreatedAtStmt = $pdo->prepare('UPDATE polls SET created_at = :d WHERE id = :id');
$voteCreatedAtStmt = $pdo->prepare('UPDATE poll_responses SET created_at = DATE_ADD(:pcreated, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE poll_id = :pid');

foreach ($skBarangayIds as $bgyId) {
    $skUser = $skUserForBarangay[$bgyId] ?? null;
    if ($skUser === null) { continue; }
    $countCheck = $pdo->prepare('SELECT COUNT(*) FROM polls WHERE barangay_id = :b');
    $countCheck->execute(['b' => $bgyId]);
    if ((int) $countCheck->fetchColumn() > 0) { continue; }

    $actor = ['id' => $skUser['id'], 'role' => 'sk', 'name' => $skUser['name'], 'barangay_id' => $bgyId];
    $numPolls = mt_rand(1, 2);
    $templates = $pollTemplates;
    shuffle($templates);
    for ($i = 0; $i < $numPolls; $i++) {
        [$question, $category, $options] = $templates[$i % count($templates)];
        $result = sked_create_poll($actor, $question, $options, true, $category);
        if (!$result['ok']) { continue; }
        $pollsCreated++;
        $pollId = (int) $result['poll_id'];

        $pollCreatedDate = sked_seed_past_date(15, 200);
        $pollCreatedAtStmt->execute(['d' => $pollCreatedDate . ' 09:00:00', 'id' => $pollId]);

        $optRows = $pdo->prepare('SELECT id FROM poll_options WHERE poll_id = :p');
        $optRows->execute(['p' => $pollId]);
        $optionIds = array_map(static fn ($r) => (int) $r['id'], $optRows->fetchAll());
        if (empty($optionIds)) { continue; }

        $pool = $activeYouthIdsByBarangay[$bgyId];
        shuffle($pool);
        $voterCount = (int) round(count($pool) * (mt_rand(40, 75) / 100));
        foreach (array_slice($pool, 0, $voterCount) as $uid) {
            $optionId = $optionIds[array_rand($optionIds)];
            $res = sked_cast_poll_vote($uid, $bgyId, $pollId, $optionId);
            if ($res['ok']) { $votesCreated++; }
        }
        $voteCreatedAtStmt->execute(['pcreated' => $pollCreatedDate, 'pid' => $pollId]);

        if (mt_rand(1, 100) <= 30) {
            sked_set_poll_status($pollId, $bgyId, 'closed');
        }
    }
}
echo "Polls created: {$pollsCreated}, votes cast: {$votesCreated}\n";

/* ------------------------------------------------------------
 * STEP 7 — A handful of CBYDP / ABYIP plans so "Programs Completed" and
 * the planning pages aren't empty either. Kept light on purpose.
 * ------------------------------------------------------------ */
$cbydpCreated = 0;
$abyipCreated = 0;
$planBarangays = array_slice($skBarangayIds, 0, 6);
$currentYear = (int) date('Y');

foreach ($planBarangays as $bgyId) {
    $skUser = $skUserForBarangay[$bgyId] ?? null;
    if ($skUser === null) { continue; }
    $already = $pdo->prepare('SELECT COUNT(*) FROM cbydp_plans WHERE barangay_id = :b');
    $already->execute(['b' => $bgyId]);
    if ((int) $already->fetchColumn() > 0) { continue; }

    $actor = ['id' => $skUser['id'], 'role' => 'sk', 'name' => $skUser['name'], 'barangay_id' => $bgyId];
    $yearStart = $currentYear - 1;
    $cbydp = sked_cbydp_create($actor, $yearStart, $skUser['name']);
    if (!$cbydp['ok']) { continue; }
    $cbydpCreated++;
    $planId = (int) $cbydp['plan_id'];

    foreach (array_slice($interestCategories, 0, 2) as $center) {
        $sec = sked_cbydp_add_section($planId, $bgyId, $center, "Priority youth-development agenda for {$center}.");
        if (!$sec['ok']) { continue; }
        sked_cbydp_add_line_item($sec['section_id'], $bgyId, [
            'youth_development_concern' => "Limited youth engagement in {$center} programs",
            'objective' => "Increase youth participation in {$center} activities",
            'performance_indicator' => 'Number of youth participants per activity',
            'target_year1' => '30 participants', 'target_year2' => '45 participants', 'target_year3' => '60 participants',
            'ppas' => "{$center} Youth Program",
            'budget' => (string) mt_rand(20000, 80000),
            'person_responsible' => 'SK Chairperson',
        ]);
    }

    if (mt_rand(1, 100) <= 60) {
        sked_cbydp_set_status($planId, $bgyId, 'finalized');

        $abyip = sked_abyip_create_from_cbydp($actor, $planId, $yearStart, $skUser['name']);
        if ($abyip['ok']) {
            $abyipCreated++;
            if (mt_rand(1, 100) <= 60) {
                sked_abyip_set_status($abyip['plan_id'], $bgyId, 'finalized');
            }
        }
    }
}
echo "CBYDP plans created: {$cbydpCreated}, ABYIP plans created: {$abyipCreated}\n";

echo "\nSeed complete.\n";
