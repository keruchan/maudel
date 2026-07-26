<?php
/**
 * ============================================================
 * File     : config/seeds/focus_barangay_reset.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : FULL DATA RESET + focused re-seed for user acceptance testing.
 *
 *   1. Wipes every data table (TRUNCATE, FK checks off) EXCEPT `barangays`
 *      — that table is reference/PSGC data, not user data, so it stays.
 *   2. Creates exactly ONE simple-credential account per role.
 *   3. Seeds ONE barangay (Acevida, id 1) heavily: youths, KK profiles,
 *      SK officials, ~30 barangay events walked through their real
 *      lifecycle (join/attend/evaluate), polls with votes, CBYDP/ABYIP,
 *      Katitikan, Feedback/Concerns, monthly reports.
 *   4. Seeds a handful of PPSK-run interbarangay/municipal events that
 *      include Acevida in scope, so the federation-level pages and
 *      municipality-wide analytics aren't empty either.
 *
 * Every insert flows through the SAME business-logic functions the app
 * itself uses (sked_create_event, sked_join_event, sked_save_youth_profile,
 * sked_cast_poll_vote, sked_submit_feedback, ...) — not raw fixture rows —
 * so points, notifications, and derived counts stay internally consistent.
 * Timestamps are backdated afterwards via targeted UPDATEs so analytics
 * trend/forecast charts have a real multi-month spread.
 *
 * THIS SCRIPT IS DESTRUCTIVE. It deletes every row in every table it
 * touches before reseeding. Only ever run it deliberately.
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/focus_barangay_reset.php
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
require_once __DIR__ . '/../../includes/katitikan.php';
require_once __DIR__ . '/../../includes/feedback.php';
require_once __DIR__ . '/../../includes/reports.php';

$pdo = sked_db();
const FOCUS_BARANGAY_ID = 1; // Acevida

/* ============================================================
 * STEP 1 — WIPE. Every data table except barangays.
 * ============================================================ */
$allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$wipeTables = array_values(array_diff($allTables, ['barangays']));

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($wipeTables as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo 'Wiped ' . count($wipeTables) . " tables (kept barangays).\n";

// CRITICAL: ids 1-999 are reserved by sked_demo_users() as the no-DB demo
// shortcut ("$isDemo = $userId < 1000" is checked throughout the youth pages
// to skip real persistence for those accounts). A fresh TRUNCATE resets
// AUTO_INCREMENT to 1, which would silently put every real seeded account
// into that reserved range and make their points/profile saves no-ops.
$pdo->exec('ALTER TABLE users AUTO_INCREMENT = 1000');

$barangayName = sked_barangay_name(FOCUS_BARANGAY_ID);
echo "Focus barangay: {$barangayName} (id " . FOCUS_BARANGAY_ID . ")\n\n";

/* ------------------------------------------------------------
 * Shared helpers
 * ------------------------------------------------------------ */
$givenMale = ['Juan','Jose','Antonio','Ramon','Carlos','Miguel','Rafael','Angelo','Marco','Paolo',
    'Ricardo','Eduardo','Francisco','Andres','Diego','Gabriel','Vicente','Emilio','Renato','Bryan',
    'Kevin','Mark','John','Christian','Joshua','Nathaniel','Adrian','Julius','Xavier','Leandro'];
$givenFemale = ['Maria','Ana','Rosa','Carmen','Teresa','Luisa','Elena','Cristina','Gabriela','Isabel',
    'Camille','Angelica','Bianca','Patricia','Katrina','Michelle','Andrea','Samantha','Jasmine','Nicole',
    'Grace','Joy','Faith','Charlene','Kristine','Precious','Angel','Kimberly','Alyssa','Danica'];
$surnames = ['Santos','Reyes','Cruz','Bautista','Ocampo','Garcia','Torres','Ramos','Mendoza','Castillo',
    'Aquino','Del Rosario','Fernandez','Villanueva','Gonzales','Rivera','Salazar','Aguilar','Marquez','Pascual',
    'Domingo','Navarro','Rosales','Manalo','Guevarra','Lazaro','Valdez','Sarmiento','Cabrera','Espinosa'];
$middleNames = ['Santos','Reyes','Cruz','Garcia','Ramos','Torres','Mendoza','Aquino','Fernandez','Rivera'];

function rpick(array $a) { return $a[array_rand($a)]; }
function rweighted(array $w): string
{
    $total = array_sum($w);
    $r = mt_rand(1, (int) ($total * 1000)) / 1000;
    $acc = 0;
    foreach ($w as $k => $v) { $acc += $v; if ($r <= $acc) return (string) $k; }
    return (string) array_key_last($w);
}
function birthdateFor(int $minAge, int $maxAge): string
{
    $age = mt_rand($minAge, $maxAge);
    $d = new DateTime('today');
    $d->modify("-{$age} years")->modify('-' . mt_rand(0, 364) . ' days');
    return $d->format('Y-m-d');
}
function pastDate(int $minDaysAgo, int $maxDaysAgo): string
{
    $d = new DateTime('today');
    $d->modify('-' . mt_rand($minDaysAgo, $maxDaysAgo) . ' days');
    return $d->format('Y-m-d');
}
function futureDate(int $minDaysAhead, int $maxDaysAhead): string
{
    $d = new DateTime('today');
    $d->modify('+' . mt_rand($minDaysAhead, $maxDaysAhead) . ' days');
    return $d->format('Y-m-d');
}

$interestCategories = sked_interest_categories();
$preferredProgramsAll = sked_preferred_programs();
$scholarshipOptions = sked_scholarship_options();
$profilingOptions = sked_profiling_options();
$classificationList = sked_youth_classifications();
$specificNeedsList = sked_specific_needs_options();

/* ============================================================
 * STEP 2 — Four simple-credential accounts, one per role.
 * ============================================================ */
const SIMPLE_PASSWORD = 'sked123';
$simpleHash = password_hash(SIMPLE_PASSWORD, PASSWORD_DEFAULT);

$insertRole = $pdo->prepare(
    "INSERT INTO users (role, status, barangay_id, name, surname, given_name, sex_assigned_at_birth,
        email, mobile, birthdate, username, password_hash, verified)
     VALUES (:role, 'active', :bgy, :name, :surname, :given, :sex, :email, :mobile, :birthdate, :username, :hash, 1)"
);

function seedRoleAccount(PDO $pdo, PDOStatement $insertRole, string $hash, string $role, string $username, string $name, ?int $barangayId): int
{
    [$given, $surname] = array_pad(explode(' ', $name, 2), 2, '');
    $insertRole->execute([
        'role' => $role, 'bgy' => $barangayId, 'name' => $name, 'surname' => $surname, 'given' => $given,
        'sex' => 'male', 'email' => $username . '@sked-sample.local', 'mobile' => '09' . mt_rand(150000000, 999999999),
        'birthdate' => birthdateFor(24, 30), 'username' => $username, 'hash' => $hash,
    ]);
    return (int) $pdo->lastInsertId();
}

$dilgId = seedRoleAccount($pdo, $insertRole, $simpleHash, 'dilg', 'dilg', 'DILG Administrator', null);
$ppskId = seedRoleAccount($pdo, $insertRole, $simpleHash, 'ppsk', 'ppsk', 'PPSK President', null);
$skId   = seedRoleAccount($pdo, $insertRole, $simpleHash, 'sk',   'sk',   'SK Chairperson ' . $barangayName, FOCUS_BARANGAY_ID);

// The one youth account is inserted with the youth column set (surname/given/middle/sex),
// verified + active, and gets its own real KK profile + participation history below.
$youthUsername = 'youth';
$pdo->prepare(
    "INSERT INTO users (role, status, barangay_id, name, surname, given_name, middle_name,
        sex_assigned_at_birth, email, mobile, birthdate, username, password_hash, verified)
     VALUES ('youth', 'active', :bgy, :name, :surname, :given, :middle, :sex, :email, :mobile, :birthdate, :username, :hash, 1)"
)->execute([
    'bgy' => FOCUS_BARANGAY_ID, 'name' => 'Juan Dela Cruz', 'surname' => 'Dela Cruz', 'given' => 'Juan',
    'middle' => 'Santos', 'sex' => 'male', 'email' => 'youth@sked-sample.local', 'mobile' => '09171234567',
    'birthdate' => birthdateFor(18, 22), 'username' => $youthUsername, 'hash' => $simpleHash,
]);
$youthId = (int) $pdo->lastInsertId();

$skActor = ['id' => $skId, 'role' => 'sk', 'name' => 'SK Chairperson ' . $barangayName, 'barangay_id' => FOCUS_BARANGAY_ID];
$ppskActor = ['id' => $ppskId, 'role' => 'ppsk', 'name' => 'PPSK President', 'barangay_id' => null];

echo "Accounts created (all share the password '" . SIMPLE_PASSWORD . "'):\n";
echo "  dilg  -> DILG oversight\n  ppsk  -> PPSK federation\n  sk    -> SK chairperson, {$barangayName}\n  youth -> Youth member, {$barangayName}\n\n";

/* ============================================================
 * STEP 3 — SK officials roster for the focus barangay.
 * ============================================================ */
sked_sk_official_save(FOCUS_BARANGAY_ID, $skId, [
    'user_id' => $skId, 'position' => 'SK Chairperson', 'full_name' => $skActor['name'], 'status' => 'active',
    'term_start' => pastDate(300, 600), 'term_end' => futureDate(300, 700),
]);
$officialsCreated = 1;
foreach (['SK Secretary', 'SK Treasurer', 'SK Kagawad', 'SK Kagawad', 'SK Auditor'] as $pos) {
    $sex = mt_rand(0, 1) ? 'male' : 'female';
    $name = ($sex === 'male' ? rpick($givenMale) : rpick($givenFemale)) . ' ' . rpick($surnames);
    sked_sk_official_save(FOCUS_BARANGAY_ID, $skId, [
        'position' => $pos, 'full_name' => $name, 'status' => 'active',
        'term_start' => pastDate(300, 600), 'term_end' => futureDate(300, 700),
    ]);
    $officialsCreated++;
}
echo "SK officials created: {$officialsCreated}\n";

/* ============================================================
 * STEP 4 — Youths in the focus barangay (60, varied age/sex/status).
 * ============================================================ */
$insertYouth = $pdo->prepare(
    'INSERT INTO users (role, status, barangay_id, name, surname, given_name, middle_name,
        sex_assigned_at_birth, email, mobile, birthdate, username, password_hash, verified)
     VALUES (\'youth\', :status, :bgy, :name, :surname, :given, :middle, :sex, :email, :mobile, :birthdate, :username, :hash, :verified)'
);
$backdateUser = $pdo->prepare('UPDATE users SET created_at = :d WHERE id = :id');
$bulkHash = password_hash('Youth2026!', PASSWORD_DEFAULT);

$youths = []; // ['id','status','sex','birthdate','age']
$youthCount = 60;
for ($i = 1; $i <= $youthCount; $i++) {
    $username = 'yt' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $sex = mt_rand(0, 1) ? 'male' : 'female';
    $given = $sex === 'male' ? rpick($givenMale) : rpick($givenFemale);
    $middle = rpick($middleNames);
    $surname = rpick($surnames);
    $birthdate = birthdateFor(15, 30);
    $age = (int) (new DateTime($birthdate))->diff(new DateTime('today'))->y;
    $status = rweighted(['active' => 70, 'pending' => 22, 'rejected' => 8]);

    $insertYouth->execute([
        'status' => $status, 'bgy' => FOCUS_BARANGAY_ID, 'name' => "$given $middle $surname",
        'surname' => $surname, 'given' => $given, 'middle' => $middle, 'sex' => $sex,
        'email' => $username . '@sked-sample.local', 'mobile' => '09' . mt_rand(150000000, 999999999),
        'birthdate' => $birthdate, 'username' => $username, 'hash' => $bulkHash,
        'verified' => $status === 'active' ? 1 : 0,
    ]);
    $uid = (int) $pdo->lastInsertId();
    $regDate = pastDate(1, 480) . ' ' . sprintf('%02d:%02d:00', mt_rand(7, 20), mt_rand(0, 59));
    $backdateUser->execute(['d' => $regDate, 'id' => $uid]);

    $youths[] = ['id' => $uid, 'status' => $status, 'sex' => $sex, 'birthdate' => $birthdate, 'age' => $age];
}
echo "Youths created in {$barangayName}: {$youthCount} (shared password: Youth2026!)\n";

$activeYouthIds = array_values(array_map(fn ($y) => $y['id'], array_filter($youths, fn ($y) => $y['status'] === 'active')));

/* ============================================================
 * STEP 5 — KK Profiles for ~80% of active youths + the one youth account.
 * ============================================================ */
function educationFor(int $age): string
{
    if ($age <= 17) return rpick(['Junior High School Level (Grade 7-9)', 'Junior High School Graduate (Grade 10)', 'Senior High School Level (Grade 11)']);
    if ($age <= 20) return rpick(['Senior High School Graduate (Grade 12)', '1st Year College', '2nd Year College']);
    if ($age <= 24) return rpick(['2nd Year College', '3rd Year College', '4th Year College', 'College Graduate']);
    return rpick(['College Graduate', 'College Graduate', 'Masters Level', 'College Graduate']);
}
function workStatusFor(int $age): string
{
    if ($age <= 20) return rweighted(['N/A (Hindi Naaangkop)' => 45, 'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 15, 'Unemployed (Walang Trabaho)' => 20, 'Employed (Empleyado)' => 20]);
    return rweighted(['Employed (Empleyado)' => 45, 'Self-Employed (Sa sarili nagtatrabaho)' => 15, 'Unemployed (Walang Trabaho)' => 15, 'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 20, 'Not Interested Looking for a Job (Hindi Interesadong sa Paghahanap ng Trabaho)' => 5]);
}
function buildProfileData(int $age, string $sex): array
{
    global $classificationList, $specificNeedsList, $scholarshipOptions, $interestCategories, $preferredProgramsAll, $profilingOptions, $givenMale, $givenFemale;

    $genderIdentity = mt_rand(1, 100) <= 95 ? ($sex === 'male' ? 'man' : 'woman') : ($sex === 'male' ? 'woman' : 'man');
    $classifications = [$classificationList[array_rand($classificationList)]];
    if (mt_rand(1, 100) <= 30) {
        $second = $classificationList[array_rand($classificationList)];
        if (!in_array($second, $classifications, true)) { $classifications[] = $second; }
    }
    $specificNeeds = in_array(SKED_CLASSIFICATION_SPECIFIC_NEEDS, $classifications, true) ? [$specificNeedsList[array_rand($specificNeedsList)]] : [];
    $scholarships = mt_rand(1, 100) <= 25 ? [rpick(array_diff($scholarshipOptions, ['None', 'Others']))] : ['None'];

    $numInterests = mt_rand(1, 3);
    $iKeys = array_rand($interestCategories, $numInterests);
    $interests = is_array($iKeys) ? array_map(fn ($k) => $interestCategories[$k], $iKeys) : [$interestCategories[$iKeys]];

    $numPrograms = mt_rand(3, 5);
    $pKeys = array_rand($preferredProgramsAll, $numPrograms);
    $preferredPrograms = is_array($pKeys) ? array_map(fn ($k) => $preferredProgramsAll[$k], $pKeys) : [$preferredProgramsAll[$pKeys]];

    $attended = mt_rand(1, 100) <= 55 ? '1' : '0';

    return [
        'consent_agreed' => 1,
        'civil_status' => $age >= 25 ? rweighted(['Single (Walang Asawa)' => 55, 'Married (Kasal/May Asawa)' => 30, 'Live-In (Nakikisama)' => 10, 'Separated (Hiwalay)' => 5]) : 'Single (Walang Asawa)',
        'gender_identity' => $genderIdentity,
        'lgbtqia_member' => mt_rand(1, 100) <= 10 ? 1 : 0,
        'facebook_name' => strtolower(str_replace(' ', '.', $sex === 'male' ? rpick($givenMale) : rpick($givenFemale))) . mt_rand(10, 99),
        'num_children' => $age >= 22 ? rweighted([0 => 60, 1 => 25, 2 => 10, 3 => 5]) : 0,
        'educational_attainment' => educationFor($age),
        'work_status' => workStatusFor($age),
        'valid_id' => rpick(['Philippine National ID', "Voter's ID", 'School ID', 'Postal ID']),
        'sk_voter' => mt_rand(1, 100) <= 85 ? '1' : '0',
        'national_voter' => $age >= 18 ? (mt_rand(1, 100) <= 80 ? '1' : '0') : '0',
        'voted_last_election' => $age >= 18 ? (mt_rand(1, 100) <= 70 ? '1' : '0') : '0',
        'attended_kk_assembly' => $attended,
        'kk_assembly_times' => $attended === '1' ? mt_rand(1, 2) : null,
        'kk_assembly_absence_reason' => $attended === '0' ? rpick($profilingOptions['kk_assembly_absence_reason']) : null,
        'classifications' => $classifications,
        'specific_needs' => $specificNeeds,
        'scholarships' => $scholarships,
        'scholarship_other' => '',
        'interests' => $interests,
        'preferred_programs' => $preferredPrograms,
        'preferred_programs_other' => '',
        'kk_suggestions' => '',
    ];
}

$profilesCreated = 0;
foreach ($youths as $y) {
    if ($y['status'] !== 'active') { continue; }
    if (mt_rand(1, 100) > 80) { continue; }
    $res = sked_save_youth_profile($y['id'], buildProfileData($y['age'], $y['sex']), 0, 'youth');
    if ($res['ok']) { $profilesCreated++; }
}
// The showcase youth account always gets a complete profile.
sked_save_youth_profile($youthId, buildProfileData(20, 'male'), 0, 'youth');
echo "KK Profiles created: {$profilesCreated} (+ 1 for the showcase youth account)\n";

/* ============================================================
 * STEP 6 — Barangay events (28 historical/ongoing + upcoming), full lifecycle.
 * ============================================================ */
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
    ['Mental Health Awareness Seminar', 'Health'],
    ['Basic Life Support Training', 'Health'],
    ['Scholarship Orientation', 'Education'],
    ['Leadership Training for KK Officers', 'Active Citizenship'],
    ['Agri-Youth Gardening Project', 'Agriculture'],
    ['Digital Literacy Workshop', 'Education'],
];

function processLifecycle(PDO $pdo, array $eventResult, array $eligibleYouthIds, int $actorId, string $eventDate, ?int $capacity): int
{
    $eventId = (int) $eventResult['event_id'];
    $event = sked_get_event($eventId);
    $isPast = $eventDate < date('Y-m-d');
    $joinedCount = 0;

    sked_set_event_status($actorId, $event, 'published');
    $event = sked_get_event($eventId);

    $poolSize = count($eligibleYouthIds);
    if ($poolSize === 0) { return 0; }
    $targetJoins = min($poolSize, $capacity ?? $poolSize, mt_rand(8, min(35, $poolSize)));
    shuffle($eligibleYouthIds);
    $joiners = array_slice($eligibleYouthIds, 0, $targetJoins);

    foreach ($joiners as $uid) {
        $barangayIdStmt = $pdo->prepare('SELECT barangay_id FROM users WHERE id = :id');
        $barangayIdStmt->execute(['id' => $uid]);
        $ybgy = (int) $barangayIdStmt->fetchColumn();
        $res = sked_join_event($uid, $ybgy, $eventId, '');
        if ($res['ok']) { $joinedCount++; }
    }

    if (!$isPast) {
        if (mt_rand(1, 100) <= 40) { sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed'); }
        return $joinedCount;
    }

    sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed');
    sked_set_event_status($actorId, sked_get_event($eventId), 'ongoing');
    sked_set_event_status($actorId, sked_get_event($eventId), 'completed');

    $roster = sked_event_roster($eventId);
    foreach ($roster as $p) {
        sked_mark_attendance($actorId, $eventId, (int) $p['user_id'], mt_rand(1, 100) <= 82);
    }

    sked_set_event_status($actorId, sked_get_event($eventId), 'evaluation');
    foreach (sked_event_roster($eventId) as $p) {
        if ($p['status'] !== 'attended') { continue; }
        if (mt_rand(1, 100) > 65) { continue; }
        $rating = (int) rweighted([3 => 15, 4 => 45, 5 => 40]);
        sked_submit_evaluation((int) $p['user_id'], $eventId, $rating, '');
    }

    if ($eventDate < date('Y-m-d', strtotime('-45 days'))) {
        sked_set_event_status($actorId, sked_get_event($eventId), 'closed');
    }
    return $joinedCount;
}

$setCreatedAt = $pdo->prepare('UPDATE events SET created_at = :d WHERE id = :id');
$setJoinedAt = $pdo->prepare('UPDATE event_participants SET joined_at = DATE_SUB(:evd, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE event_id = :eid');

$eventsCreated = 0;
$participationsCreated = 0;
$numEvents = 28;
$templates = $barangayEventTemplates;
for ($i = 0; $i < $numEvents; $i++) {
    [$title, $category] = $templates[$i % count($templates)];
    $isUpcoming = $i >= ($numEvents - 4); // last 4 are upcoming/ongoing
    $eventDate = $isUpcoming ? futureDate(3, 45) : pastDate(3, 400);
    $type = mt_rand(1, 100) <= 30 ? 'register' : 'interested';
    $capacity = $type === 'register' ? mt_rand(20, 45) : null;

    $result = sked_create_event($skActor, [
        'title' => $title . ($i >= count($templates) ? ' ' . (intdiv($i, count($templates)) + 1) : ''),
        'description' => "Barangay-wide activity for the youth of {$barangayName}.",
        'category' => $category, 'scope' => 'barangay', 'type' => $type, 'is_team_sport' => 0,
        'location' => "Barangay {$barangayName} Covered Court", 'event_date' => $eventDate,
        'min_participants' => 5, 'capacity' => $capacity, 'publish' => false,
    ]);
    if (!$result['ok']) { continue; }
    $eventsCreated++;
    $eventId = (int) $result['event_id'];

    $joined = processLifecycle($pdo, $result, $activeYouthIds, $skId, $eventDate, $capacity);
    $participationsCreated += $joined;

    $createdAt = date('Y-m-d H:i:s', strtotime($eventDate . ' -' . mt_rand(14, 45) . ' days'));
    $setCreatedAt->execute(['d' => $createdAt, 'id' => $eventId]);
    $setJoinedAt->execute(['evd' => $eventDate, 'eid' => $eventId]);
}
echo "Barangay events created: {$eventsCreated}, participations: {$participationsCreated}\n";

// Make sure the showcase youth account has a clear, visible attendance story:
// one already-evaluated past event, one attended-but-not-yet-evaluated (so the
// "Finalize your attendance" panel has something to show), one upcoming sign-up.
//
// sked_join_event() only accepts CURRENTLY OPEN events (published/confirmed/
// ongoing) — by this point every historical event has already been walked
// through to completed/evaluation/closed, so joining them the normal way is
// rejected. For a past event we instead insert the participant row directly
// (exactly what sked_join_event() itself would have inserted, back when the
// event was open) and award the same points, then use the real
// sked_mark_attendance()/sked_submit_evaluation() to finish the story — those
// two gate on the event's CURRENT status, which does include completed/closed.
$insertParticipant = $pdo->prepare(
    "INSERT INTO event_participants (event_id, user_id, status, joined_at) VALUES (:e, :u, 'interested', NOW())"
);

// Lifecycle walks 'completed' straight through to 'evaluation' (and then
// 'closed' once older than 45 days) in the same pass, so 'completed' never
// actually persists here — pick from the two statuses that do.
$pastEventRow = $pdo->query("SELECT id FROM events WHERE scope='barangay' AND status IN ('closed','evaluation') ORDER BY RAND() LIMIT 1")->fetchColumn();
$pastEventRow2 = $pdo->query("SELECT id FROM events WHERE scope='barangay' AND status IN ('closed','evaluation') AND id <> " . (int) $pastEventRow . ' ORDER BY RAND() LIMIT 1')->fetchColumn();
$upcomingEventRow = $pdo->query("SELECT id FROM events WHERE scope='barangay' AND status IN ('published','confirmed') ORDER BY RAND() LIMIT 1")->fetchColumn();

if ($pastEventRow) {
    $insertParticipant->execute(['e' => (int) $pastEventRow, 'u' => $youthId]);
    sked_award_points($youthId, 'event_joined', 'event', (int) $pastEventRow);
    sked_mark_attendance($skId, (int) $pastEventRow, $youthId, true);
    sked_submit_evaluation($youthId, (int) $pastEventRow, 5, 'Ang galing ng programa, sobrang nakakatulong! Salamat SK.');
}
if ($pastEventRow2) {
    $insertParticipant->execute(['e' => (int) $pastEventRow2, 'u' => $youthId]);
    sked_award_points($youthId, 'event_joined', 'event', (int) $pastEventRow2);
    sked_mark_attendance($skId, (int) $pastEventRow2, $youthId, true);
    // Deliberately NOT evaluated — this is the "needs evaluation" example.
}
if ($upcomingEventRow) {
    sked_join_event($youthId, FOCUS_BARANGAY_ID, (int) $upcomingEventRow, '');
}

/* ============================================================
 * STEP 7 — Federation (PPSK) events including the focus barangay.
 * ============================================================ */
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
$allBarangayIds = array_map('intval', $pdo->query('SELECT id FROM barangays')->fetchAll(PDO::FETCH_COLUMN));

$fedEventsCreated = 0;
$fedParticipations = 0;
foreach ($fedEventTemplates as [$title, $scope, $category]) {
    $isUpcoming = mt_rand(1, 100) <= 25;
    $eventDate = $isUpcoming ? futureDate(10, 60) : pastDate(5, 330);
    $targetBarangays = [];
    if ($scope === 'interbarangay') {
        $others = array_values(array_diff($allBarangayIds, [FOCUS_BARANGAY_ID]));
        shuffle($others);
        $targetBarangays = array_merge([FOCUS_BARANGAY_ID], array_slice($others, 0, mt_rand(2, 4)));
    }
    $result = sked_create_event($ppskActor, [
        'title' => $title, 'description' => 'Federation-led activity for SK councils across Siniloan.',
        'category' => $category, 'scope' => $scope, 'type' => 'interested', 'is_team_sport' => 0,
        'location' => 'Siniloan Municipal Gymnasium', 'event_date' => $eventDate,
        'min_participants' => 10, 'capacity' => null, 'publish' => false, 'target_barangays' => $targetBarangays,
    ]);
    if (!$result['ok']) { continue; }
    $fedEventsCreated++;
    $joined = processLifecycle($pdo, $result, $activeYouthIds, $ppskId, $eventDate, null);
    $fedParticipations += $joined;

    $eventId = (int) $result['event_id'];
    $createdAt = date('Y-m-d H:i:s', strtotime($eventDate . ' -' . mt_rand(20, 60) . ' days'));
    $setCreatedAt->execute(['d' => $createdAt, 'id' => $eventId]);
    $setJoinedAt->execute(['evd' => $eventDate, 'eid' => $eventId]);
}
echo "Federation events created: {$fedEventsCreated}, participations: {$fedParticipations}\n";

/* ============================================================
 * STEP 8 — Polls for the focus barangay.
 * ============================================================ */
$pollTemplates = [
    ['What youth program should we prioritize next quarter?', 'Governance', ['Sports & Wellness', 'Livelihood Training', 'Environmental Clean-up', 'Educational Assistance']],
    ['Which day works best for the next KK Assembly?', 'Governance', ['Saturday morning', 'Saturday afternoon', 'Sunday morning', 'Sunday afternoon']],
    ['What should our next community project focus on?', 'Social Inclusion and Equity', ['Youth with disabilities support', 'Solo parent assistance', 'Out-of-school youth outreach', 'Senior-youth bridge program']],
    ['Preferred venue for the Youth Sports Fest?', 'Health', ['Barangay covered court', 'Municipal gym', 'School grounds']],
    ['Best format for the Career Orientation Seminar?', 'Education', ['Half-day on-site', 'Full-day with employers', 'Online webinar']],
    ['Most useful livelihood skill to train next?', 'Economic Empowerment', ['Baking', 'Basic computer/office skills', 'Urban gardening', 'Handicrafts']],
];

$pollsCreated = 0;
$votesCreated = 0;
$pollCreatedAtStmt = $pdo->prepare('UPDATE polls SET created_at = :d WHERE id = :id');
$voteCreatedAtStmt = $pdo->prepare('UPDATE poll_responses SET created_at = DATE_ADD(:pcreated, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE poll_id = :pid');

foreach ($pollTemplates as [$question, $category, $options]) {
    $result = sked_create_poll($skActor, $question, $options, true, $category);
    if (!$result['ok']) { continue; }
    $pollsCreated++;
    $pollId = (int) $result['poll_id'];
    $pollCreatedDate = pastDate(15, 250);
    $pollCreatedAtStmt->execute(['d' => $pollCreatedDate . ' 09:00:00', 'id' => $pollId]);

    $optRows = $pdo->prepare('SELECT id FROM poll_options WHERE poll_id = :p');
    $optRows->execute(['p' => $pollId]);
    $optionIds = array_map(fn ($r) => (int) $r['id'], $optRows->fetchAll());
    if (empty($optionIds)) { continue; }

    $pool = $activeYouthIds;
    shuffle($pool);
    $voterCount = (int) round(count($pool) * (mt_rand(40, 75) / 100));
    foreach (array_slice($pool, 0, $voterCount) as $uid) {
        $res = sked_cast_poll_vote($uid, FOCUS_BARANGAY_ID, $pollId, $optionIds[array_rand($optionIds)]);
        if ($res['ok']) { $votesCreated++; }
    }
    $voteCreatedAtStmt->execute(['pcreated' => $pollCreatedDate, 'pid' => $pollId]);
    if (mt_rand(1, 100) <= 25) { sked_set_poll_status($pollId, FOCUS_BARANGAY_ID, 'closed'); }
}
// The showcase youth votes in at least one open poll too.
$openPoll = $pdo->query("SELECT id FROM polls WHERE barangay_id = " . FOCUS_BARANGAY_ID . " AND status = 'open' ORDER BY RAND() LIMIT 1")->fetchColumn();
if ($openPoll) {
    $opt = $pdo->query('SELECT id FROM poll_options WHERE poll_id = ' . (int) $openPoll . ' ORDER BY RAND() LIMIT 1')->fetchColumn();
    if ($opt) { sked_cast_poll_vote($youthId, FOCUS_BARANGAY_ID, (int) $openPoll, (int) $opt); }
}
echo "Polls created: {$pollsCreated}, votes cast: {$votesCreated}\n";

/* ============================================================
 * STEP 9 — CBYDP + ABYIP for the focus barangay.
 * ============================================================ */
$currentYear = (int) date('Y');
$cbydp = sked_cbydp_create($skActor, $currentYear - 1, $skActor['name']);
$cbydpCreated = 0;
$abyipCreated = 0;
if ($cbydp['ok']) {
    $cbydpCreated = 1;
    $planId = (int) $cbydp['plan_id'];
    foreach (array_slice($interestCategories, 0, 3) as $center) {
        $sec = sked_cbydp_add_section($planId, FOCUS_BARANGAY_ID, $center, "Priority youth-development agenda for {$center}.");
        if (!$sec['ok']) { continue; }
        sked_cbydp_add_line_item($sec['section_id'], FOCUS_BARANGAY_ID, [
            'youth_development_concern' => "Limited youth engagement in {$center} programs",
            'objective' => "Increase youth participation in {$center} activities",
            'performance_indicator' => 'Number of youth participants per activity',
            'target_year1' => '30 participants', 'target_year2' => '45 participants', 'target_year3' => '60 participants',
            'ppas' => "{$center} Youth Program", 'budget' => (string) mt_rand(20000, 80000),
            'person_responsible' => 'SK Chairperson',
        ]);
    }
    sked_cbydp_set_status($planId, FOCUS_BARANGAY_ID, 'finalized');
    $abyip = sked_abyip_create_from_cbydp($skActor, $planId, $currentYear - 1, $skActor['name']);
    if ($abyip['ok']) {
        $abyipCreated = 1;
        sked_abyip_set_status($abyip['plan_id'], FOCUS_BARANGAY_ID, 'finalized');
    }
}
echo "CBYDP plans created: {$cbydpCreated}, ABYIP plans created: {$abyipCreated}\n";

/* ============================================================
 * STEP 10 — Katitikan (Minutes) for the focus barangay.
 * ============================================================ */
$katitikanCreated = 0;
$roster = sked_sk_officials_for_barangay(FOCUS_BARANGAY_ID);
for ($s = 1; $s <= 2; $s++) {
    $meetingDate = pastDate(30, 200);
    $k = sked_katitikan_create($skActor, [
        'session_no' => (string) $s, 'series_year' => $currentYear, 'meeting_date' => $meetingDate,
        'meeting_time' => sprintf('%02d:%02d', mt_rand(13, 17), rpick([0, 30])),
        'venue' => "Barangay {$barangayName} Session Hall", 'presiding_officer' => $skActor['name'],
        'prepared_by_name' => 'SK Secretary',
    ]);
    if (!$k['ok']) { continue; }
    $katitikanCreated++;
    $kid = (int) $k['katitikan_id'];
    foreach (array_slice($roster, 0, mt_rand(3, 5)) as $official) {
        sked_katitikan_add_attendee($kid, FOCUS_BARANGAY_ID, (string) $official['full_name'], (string) $official['position'], mt_rand(1, 100) <= 90 ? 'present' : 'absent', (int) $official['id']);
    }
    sked_katitikan_add_agenda_item($kid, FOCUS_BARANGAY_ID, 'new', 'Review of upcoming barangay youth programs and budget allocation.');
    sked_katitikan_add_privilege_item($kid, FOCUS_BARANGAY_ID, $skActor['name'], 'Motion to approve the CBYDP-derived ABYIP for the coming year.');
    sked_katitikan_set_status($kid, FOCUS_BARANGAY_ID, 'finalized');
    if ($s === 1) {
        sked_katitikan_submit_to_dilg($skActor, $kid);
    }
}
echo "Katitikan records created: {$katitikanCreated}\n";

/* ============================================================
 * STEP 11 — Feedback/Concerns (rich text for word cloud + sentiment).
 * ============================================================ */
$feedbackPool = [
    "Salamat sa maayos na pagsasaayos ng huling Sports Fest, sobrang saya ng lahat ng participants!",
    "Grateful for the livelihood training last month — very helpful and well organized, matuto kami ng bagong skills.",
    "Maganda ang turnout ng KK Assembly this year, proud ako sa barangay namin.",
    "The scholarship orientation was excellent and clear. Thank you sa SK for reaching out to us.",
    "Nakakatuwa yung Clean and Green drive, engaged talaga ang mga kabataan. Sana regular ito.",
    "Great job sa disaster preparedness drill, very informative and organized ang flow.",
    "Salamat po sa pagbibigay ng katanungan sa amin during the youth congress, na-appreciate namin ang effort.",
    "Proud to be part of this year's Linggo ng Kabataan — maayos at masaya ang mga activities.",
    "Medyo mahirap sundan ang schedule ng events kasi late ang announcement, sana mapaaga next time.",
    "The last sports fest felt disorganized — nagulat kami sa pagbabago ng venue nang huling minuto.",
    "Kulang ang communication about the livelihood program requirements, nalito kami sa mga dokumento.",
    "Disappointed with the delay sa pagbibigay ng results ng scholarship application, matagal bago sumagot.",
    "Nakakainis na madalas nacancel ang mga session, sayang ang oras namin.",
    "The venue for the last seminar was too small and crowded, hindi comfortable manood o makinig.",
    "Sana mas maayos ang pagdi-distribute ng slots, parang paborito lang ang paulit-ulit na participants.",
    "Confusing yung registration process, nag-duplicate submissions kami dahil hindi malinaw ang steps.",
    "Sana po madagdagan ang schedule ng sports activities lalo na tuwing weekend.",
    "Suggestion lang po: mas dagdagan ang livelihood seminars para sa out-of-school youth.",
    "Sana may regular update sa social media page ng SK about upcoming events.",
    "Would like to see more environment-focused projects, tree planting and clean-up drives.",
];

$feedbackCreated = 0;
$reviewedCount = 0;
$feedbackYouthPool = $activeYouthIds;
shuffle($feedbackYouthPool);
foreach (array_slice($feedbackYouthPool, 0, count($feedbackPool)) as $i => $uid) {
    $message = $feedbackPool[$i];
    $result = sked_submit_feedback(['id' => $uid, 'barangay_id' => FOCUS_BARANGAY_ID], $message);
    if (!$result['ok']) { continue; }
    $feedbackCreated++;
    $daysAgo = mt_rand(3, 200);
    $pdo->prepare('UPDATE youth_feedback SET created_at = DATE_SUB(NOW(), INTERVAL :d DAY) WHERE id = :id')
        ->execute(['d' => $daysAgo, 'id' => (int) $result['feedback_id']]);
    if (mt_rand(1, 100) <= 45) {
        sked_mark_feedback_reviewed((int) $result['feedback_id'], $skId, FOCUS_BARANGAY_ID);
        $reviewedCount++;
    }
}
// The showcase youth submits one feedback message too (kept open, unreviewed).
sked_submit_feedback(['id' => $youthId, 'barangay_id' => FOCUS_BARANGAY_ID], 'Sana po madagdagan ang mga sports at livelihood program para sa amin.');
echo "Feedback/Concerns created: {$feedbackCreated} (reviewed: {$reviewedCount}) + 1 from the showcase youth\n";

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
    "More sports facilities and equipment sana para sa regular sports activities.",
    "Grateful sa SK sa pag-abot sa amin, sana ituloy niyo lang po ang mga programang ito.",
    "Suggestion: mas maayos na venue at sound system sa mga malalaking event.",
];
$profileTargets = $pdo->query("SELECT user_id FROM youth_profiles WHERE (remarks IS NULL OR remarks = '') AND user_id <> $youthId")->fetchAll(PDO::FETCH_COLUMN);
$updateRemarks = $pdo->prepare('UPDATE youth_profiles SET remarks = :r WHERE user_id = :u');
$suggestionsAdded = 0;
foreach ($profileTargets as $uid) {
    if (mt_rand(1, 100) > 55) { continue; }
    $updateRemarks->execute(['r' => rpick($suggestionPool), 'u' => (int) $uid]);
    $suggestionsAdded++;
}
echo "KK Suggestion texts added: {$suggestionsAdded}\n";

/* ============================================================
 * STEP 12 — A couple of monthly reports, SK -> PPSK.
 * ============================================================ */
$reportsCreated = 0;
for ($m = 1; $m <= 2; $m++) {
    $period = date('Y-m', strtotime("-{$m} months"));
    $r = sked_submit_report($skActor, 'monthly', "Monthly Activity Report - {$period}",
        "Nagsagawa ang barangay ng iba't ibang programa para sa kabataan kabilang ang sports, livelihood, at KK Assembly. "
        . "Patuloy ang pagpo-profile ng mga bagong miyembro ng Katipunan ng Kabataan.", $period);
    if ($r['ok']) {
        $reportsCreated++;
        if ($m === 2) {
            $pdo->prepare("UPDATE reports SET status='reviewed', reviewed_at=NOW(), reviewed_by=:r WHERE id=:id")
                ->execute(['r' => $ppskId, 'id' => $r['report_id']]);
        }
    }
}
echo "Monthly reports submitted: {$reportsCreated}\n";

/* ============================================================
 * DONE.
 * ============================================================ */
echo "\n================ SEED COMPLETE ================\n";
echo "Focus barangay : {$barangayName}\n";
echo "Login credentials (password for all four: " . SIMPLE_PASSWORD . "):\n";
echo "  DILG  -> username: dilg\n";
echo "  PPSK  -> username: ppsk\n";
echo "  SK    -> username: sk\n";
echo "  Youth -> username: youth\n";
echo "=================================================\n";
