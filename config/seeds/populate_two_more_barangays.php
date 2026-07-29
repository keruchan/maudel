<?php
/**
 * ============================================================
 * File     : config/seeds/populate_two_more_barangays.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Populate 2 more barangays (Bagong Pag-Asa id=2, Bagumbarangay
 *            id=3) with realistic, rich data, for testing/demoing the
 *            system's overall potential — specifically DILG's oversight
 *            surface (SK Councils, Youth Profiles, Programs & Events,
 *            Reports, Compliance/Dismissal Review, Analytics).
 *
 *            Barangay 2 (Bagong Pag-Asa) = a healthy, well-run barangay:
 *            SK council, youth, KK profiles, events with full lifecycle,
 *            polls, and monthly reports submitted ON TIME (no strikes).
 *
 *            Barangay 3 (Bagumbarangay) = a struggling barangay: same
 *            shape of data but thinner, PLUS 3 real compliance strikes
 *            (missed monthly report deadlines for 3 consecutive months)
 *            escalated to DILG as a pending 'dismissal_recommendation'
 *            report — left PENDING (not processed) so DILG's actual
 *            "process dismissal" action can be tested live in the UI.
 *
 *            Every insert flows through the same real business-logic
 *            functions the app itself uses (sked_create_event,
 *            sked_join_event, sked_save_youth_profile, sked_submit_report,
 *            sked_add_strike, sked_escalate_to_dilg, etc.) — same
 *            convention as config/seeds/populate_demo_data.php, whose
 *            helper functions this script re-implements locally (not
 *            require_once'd, since that file executes on load).
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/populate_two_more_barangays.php
 * ============================================================
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/polls.php';
require_once __DIR__ . '/../../includes/profiling.php';
require_once __DIR__ . '/../../includes/sk_members.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/compliance.php';

$pdo = sked_db();

/* ------------------------------------------------------------
 * Name pools + small helpers (same as populate_demo_data.php)
 * ------------------------------------------------------------ */
$givenMale = ['Juan','Jose','Antonio','Ramon','Carlos','Miguel','Rafael','Angelo','Marco','Paolo',
    'Ricardo','Eduardo','Francisco','Andres','Diego','Gabriel','Vicente','Emilio','Leandro','Renato',
    'Bryan','Kevin','Mark','John','Christian','Joshua','Nathaniel','Adrian','Julius','Xavier'];
$givenFemale = ['Maria','Ana','Rosa','Carmen','Teresa','Luisa','Elena','Cristina','Gabriela','Isabel',
    'Camille','Angelica','Bianca','Patricia','Katrina','Michelle','Andrea','Samantha','Jasmine','Nicole',
    'Grace','Joy','Faith','Charlene','Kristine','Precious','Angel','Kimberly','Alyssa','Danica'];
$surnames = ['Santos','Reyes','Cruz','Bautista','Ocampo','Garcia','Torres','Ramos','Mendoza','Castillo',
    'Aquino','Del Rosario','Fernandez','Villanueva','Gonzales','Rivera','Salazar','Aguilar','Marquez','Pascual',
    'Domingo','Navarro','Rosales','Manalo','Guevarra','Lazaro','Valdez','Sarmiento','Cabrera','Espinosa'];
$middleNames = ['Santos','Reyes','Cruz','Garcia','Ramos','Torres','Mendoza','Aquino','Fernandez','Rivera'];

function sked_seed2_rand_pick(array $arr) { return $arr[array_rand($arr)]; }
function sked_seed2_rand_weighted(array $weighted): string
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
function sked_seed2_slug(string $name): string
{
    $s = strtolower($name);
    $s = preg_replace('/\([^)]*\)/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s;
}
function sked_seed2_birthdate(int $minAge, int $maxAge): string
{
    $age = mt_rand($minAge, $maxAge);
    $d = new DateTime('today');
    $d->modify("-{$age} years");
    $d->modify('-' . mt_rand(0, 364) . ' days');
    return $d->format('Y-m-d');
}
function sked_seed2_past_date(int $minDaysAgo, int $maxDaysAgo): string
{
    $d = new DateTime('today');
    $d->modify('-' . mt_rand($minDaysAgo, $maxDaysAgo) . ' days');
    return $d->format('Y-m-d');
}
function sked_seed2_future_date(int $minDaysAhead, int $maxDaysAhead): string
{
    $d = new DateTime('today');
    $d->modify('+' . mt_rand($minDaysAhead, $maxDaysAhead) . ' days');
    return $d->format('Y-m-d');
}
function sked_seed2_education_for_age(int $age): string
{
    if ($age <= 17) return sked_seed2_rand_pick(['Junior High School Level (Grade 7-9)', 'Junior High School Graduate (Grade 10)', 'Senior High School Level (Grade 11)']);
    if ($age <= 20) return sked_seed2_rand_pick(['Senior High School Graduate (Grade 12)', '1st Year College', '2nd Year College']);
    if ($age <= 24) return sked_seed2_rand_pick(['2nd Year College', '3rd Year College', '4th Year College', 'College Graduate']);
    return sked_seed2_rand_pick(['College Graduate', 'College Graduate', 'Masters Level', 'College Graduate']);
}
function sked_seed2_work_status_for_age(int $age): string
{
    if ($age <= 20) return sked_seed2_rand_weighted([
        'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 25,
        'Unemployed (Walang Trabaho)' => 20, 'Employed (Empleyado)' => 20,
        'Not Interested Looking for a Job (Hindi Interesadong sa Paghahanap ng Trabaho)' => 35,
    ]);
    return sked_seed2_rand_weighted([
        'Employed (Empleyado)' => 45, 'Self-Employed (Sa sarili nagtatrabaho)' => 15,
        'Unemployed (Walang Trabaho)' => 15, 'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)' => 20,
        'Not Interested Looking for a Job (Hindi Interesadong sa Paghahanap ng Trabaho)' => 5,
    ]);
}
function sked_seed2_youth_barangay(PDO $pdo, int $userId): int
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $stmt = $pdo->prepare('SELECT barangay_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $cache[$userId] = (int) $stmt->fetchColumn();
    }
    return $cache[$userId];
}
function sked_seed2_process_lifecycle(PDO $pdo, array $eventResult, array $eligibleYouthIds, int $actorId, string $eventDate, ?int $capacity, float $attendanceRate, float $ratingBias): int
{
    $eventId = (int) $eventResult['event_id'];
    $event = sked_get_event($eventId);
    $isPast = $eventDate < date('Y-m-d');

    sked_set_event_status($actorId, $event, 'published');
    $event = sked_get_event($eventId);

    $poolSize = count($eligibleYouthIds);
    if ($poolSize === 0) { return 0; }
    $targetJoins = min($poolSize, $capacity ?? $poolSize, mt_rand(5, min(20, $poolSize)));
    shuffle($eligibleYouthIds);
    $joiners = array_slice($eligibleYouthIds, 0, $targetJoins);

    $joinedCount = 0;
    foreach ($joiners as $uid) {
        $res = sked_join_event($uid, sked_seed2_youth_barangay($pdo, $uid), $eventId, '');
        if ($res['ok']) { $joinedCount++; }
    }

    if (!$isPast) {
        if (mt_rand(1, 100) <= 40) {
            sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed');
        }
        return $joinedCount;
    }

    sked_set_event_status($actorId, sked_get_event($eventId), 'confirmed');
    sked_set_event_status($actorId, sked_get_event($eventId), 'ongoing');
    sked_set_event_status($actorId, sked_get_event($eventId), 'completed');

    $roster = sked_event_roster($eventId);
    foreach ($roster as $p) {
        $present = (mt_rand(1, 100) / 100) <= $attendanceRate;
        sked_mark_attendance($actorId, $eventId, (int) $p['user_id'], $present);
    }

    sked_set_event_status($actorId, sked_get_event($eventId), 'evaluation');
    $criteriaKeys = array_keys(sked_evaluation_criteria());
    foreach (sked_event_roster($eventId) as $p) {
        if ($p['status'] !== 'attended') { continue; }
        if (mt_rand(1, 100) > 55) { continue; }
        $answers = [];
        foreach ($criteriaKeys as $key) {
            $answers[$key] = max(1, min(5, (int) round(mt_rand(1, 5) * 0.4 + $ratingBias * 0.6)));
        }
        sked_submit_evaluation((int) $p['user_id'], $eventId, $answers, '');
    }

    if ($eventDate < date('Y-m-d', strtotime('-45 days'))) {
        sked_set_event_status($actorId, sked_get_event($eventId), 'closed');
    }
    return $joinedCount;
}

$interestCategories = sked_interest_categories();
$preferredProgramsAll = sked_preferred_programs();
$scholarshipOptions = sked_scholarship_options();
$profilingOptions = sked_profiling_options();
$classificationList = sked_youth_classifications();
$specificNeedsList = sked_specific_needs_options();

$allBarangays = sked_barangays();
$barangayName = [];
foreach ($allBarangays as $b) { $barangayName[(int) $b['id']] = (string) $b['name']; }

$ppskRow = $pdo->query("SELECT id, name FROM users WHERE role = 'ppsk' AND status = 'active' LIMIT 1")->fetch();
$ppskActor = $ppskRow !== false ? ['id' => (int) $ppskRow['id'], 'role' => 'ppsk', 'name' => (string) $ppskRow['name'], 'barangay_id' => null] : null;
$dilgRows = $pdo->query("SELECT id, name FROM users WHERE role = 'dilg' AND status = 'active'")->fetchAll();

$eventTemplates = [
    ['Barangay KK General Assembly', 'Governance'],
    ['Clean and Green Community Drive', 'Environment'],
    ['Youth Sports Fest', 'Health'],
    ['Livelihood Skills Training', 'Economic Empowerment'],
    ['Anti-Drug Abuse Awareness Seminar', 'Peace Building and Security'],
    ["Voter's Education Seminar", 'Governance'],
    ['Blood Letting Activity', 'Health'],
    ['Career Orientation Seminar', 'Education'],
    ['Disaster Preparedness Drill', 'Peace Building and Security'],
    ['Barangay Feeding Program', 'Health'],
];
$pollTemplates = [
    ['What youth program should we prioritize next quarter?', 'Governance', ['Sports & Wellness', 'Livelihood Training', 'Environmental Clean-up', 'Educational Assistance']],
    ['Which day works best for the next KK Assembly?', 'Governance', ['Saturday morning', 'Saturday afternoon', 'Sunday morning', 'Sunday afternoon']],
    ['Preferred venue for the Youth Sports Fest?', 'Health', ['Barangay covered court', 'Municipal gym', 'School grounds']],
];

$skPassword = 'SkSeed@2026!';
$skHash = password_hash($skPassword, PASSWORD_DEFAULT);
$youthPassword = 'Youth@2026!';
$youthHash = password_hash($youthPassword, PASSWORD_DEFAULT);

/**
 * Fully populate one barangay: SK + officials, youth + profiles, events,
 * polls. Returns ['sk' => ['id'=>,'name'=>], 'active_youth_ids' => [...]].
 */
function sked_seed2_populate_barangay(
    PDO $pdo, int $bgyId, string $bgyName, int $youthCount, array $statusWeights,
    float $attendanceRate, float $ratingBias, int $eventCount,
    string $skPassword, string $skHash, string $youthPassword, string $youthHash,
    array $givenMale, array $givenFemale, array $surnames, array $middleNames,
    array $interestCategories, array $preferredProgramsAll, array $scholarshipOptions,
    array $profilingOptions, array $classificationList, array $specificNeedsList,
    array $eventTemplates, array $pollTemplates
): array {
    echo "\n=== Barangay {$bgyName} (id {$bgyId}) ===\n";

    // --- SK Chairperson ---
    $slug = sked_seed2_slug($bgyName);
    $username = 'sk_' . $slug;
    $existsStmt = $pdo->prepare('SELECT id, name FROM users WHERE username = :u LIMIT 1');
    $existsStmt->execute(['u' => $username]);
    $found = $existsStmt->fetch();
    if ($found !== false) {
        $skUser = ['id' => (int) $found['id'], 'name' => (string) $found['name']];
        echo "SK already exists: {$skUser['name']} (username {$username})\n";
    } else {
        $sex = mt_rand(0, 1) ? 'male' : 'female';
        $given = $sex === 'male' ? sked_seed2_rand_pick($givenMale) : sked_seed2_rand_pick($givenFemale);
        $surname = sked_seed2_rand_pick($surnames);
        $name = "$given $surname";
        $insertSk = $pdo->prepare(
            "INSERT INTO users (role, status, barangay_id, name, surname, given_name, sex_assigned_at_birth,
                email, mobile, birthdate, username, password_hash, verified)
             VALUES ('sk', 'active', :bgy, :name, :surname, :given, :sex,
                :email, :mobile, :birthdate, :username, :hash, 1)"
        );
        $insertSk->execute([
            'bgy' => $bgyId, 'name' => $name, 'surname' => $surname, 'given' => $given, 'sex' => $sex,
            'email' => $username . '@sked-sample.local', 'mobile' => '09' . mt_rand(150000000, 999999999),
            'birthdate' => sked_seed2_birthdate(24, 30), 'username' => $username, 'hash' => $skHash,
        ]);
        $skUser = ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
        echo "SK created: {$name} (username {$username}, password {$skPassword})\n";
    }

    // --- SK Officials roster ---
    // Inserted directly (not via sked_sk_official_save()) because that
    // function requires user_id to reference a role='youth' account — but
    // the Chairperson row legitimately points at the SK login account
    // itself (role='sk'), exactly matching how barangay 1's real seeded
    // roster is shaped (Chairperson linked, other seats name-only/user_id
    // NULL — verified by inspecting that live data before writing this).
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM sk_officials WHERE barangay_id = :b');
    $countStmt->execute(['b' => $bgyId]);
    if ((int) $countStmt->fetchColumn() === 0) {
        $insertOfficial = $pdo->prepare(
            'INSERT INTO sk_officials (barangay_id, user_id, full_name, position, status, term_start, term_end, created_by)
             VALUES (:bgy, :uid, :name, :pos, :status, :ts, :te, :by)'
        );
        $termStart = sked_seed2_past_date(300, 700);
        $termEnd = sked_seed2_future_date(300, 700);
        $insertOfficial->execute([
            'bgy' => $bgyId, 'uid' => $skUser['id'], 'name' => $skUser['name'], 'pos' => 'SK Chairperson',
            'status' => 'active', 'ts' => $termStart, 'te' => $termEnd, 'by' => $skUser['id'],
        ]);
        $officialsCreated = 1;
        $positionsExtra = ['SK Secretary', 'SK Treasurer', 'SK Kagawad', 'SK Kagawad', 'SK Auditor'];
        foreach (array_slice($positionsExtra, 0, mt_rand(3, 5)) as $pos) {
            $sex = mt_rand(0, 1) ? 'male' : 'female';
            $fullName = ($sex === 'male' ? sked_seed2_rand_pick($givenMale) : sked_seed2_rand_pick($givenFemale)) . ' ' . sked_seed2_rand_pick($surnames);
            $insertOfficial->execute([
                'bgy' => $bgyId, 'uid' => null, 'name' => $fullName, 'pos' => $pos,
                'status' => mt_rand(1, 100) <= 90 ? 'active' : 'inactive',
                'ts' => sked_seed2_past_date(300, 700), 'te' => sked_seed2_future_date(300, 700), 'by' => $skUser['id'],
            ]);
            $officialsCreated++;
        }
        echo "SK officials created: {$officialsCreated}\n";
    }

    // --- Youth ---
    $insertYouth = $pdo->prepare(
        'INSERT INTO users (role, status, barangay_id, name, surname, given_name, middle_name,
            sex_assigned_at_birth, email, mobile, birthdate, username, password_hash, verified)
         VALUES (\'youth\', :status, :bgy, :name, :surname, :given, :middle,
            :sex, :email, :mobile, :birthdate, :username, :hash, :verified)'
    );
    $backdateUser = $pdo->prepare('UPDATE users SET created_at = :d WHERE id = :id');
    $checkSeq = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');

    $prefix = 'yt' . str_pad((string) $bgyId, 2, '0', STR_PAD_LEFT);
    $existingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='youth' AND barangay_id = :b");
    $existingCountStmt->execute(['b' => $bgyId]);
    $youthList = [];
    if ((int) $existingCountStmt->fetchColumn() > 0) {
        $rows = $pdo->prepare("SELECT id, status, sex_assigned_at_birth AS sex, birthdate FROM users WHERE role='youth' AND barangay_id = :b");
        $rows->execute(['b' => $bgyId]);
        foreach ($rows->fetchAll() as $r) {
            $age = (int) (new DateTime((string) $r['birthdate']))->diff(new DateTime('today'))->y;
            $youthList[] = ['id' => (int) $r['id'], 'status' => $r['status'], 'sex' => $r['sex'], 'birthdate' => $r['birthdate'], 'age' => $age];
        }
        echo "Youth already exist: " . count($youthList) . "\n";
    } else {
        $seq = 1;
        for ($i = 0; $i < $youthCount; $i++) {
            while (true) {
                $username = $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                $checkSeq->execute(['u' => $username]);
                if ($checkSeq->fetch() === false) { break; }
                $seq++;
            }
            $sex = mt_rand(0, 1) ? 'male' : 'female';
            $given = $sex === 'male' ? sked_seed2_rand_pick($givenMale) : sked_seed2_rand_pick($givenFemale);
            $middle = sked_seed2_rand_pick($middleNames);
            $surname = sked_seed2_rand_pick($surnames);
            $name = "$given $middle $surname";
            $birthdate = sked_seed2_birthdate(15, 30);
            $age = (int) (new DateTime($birthdate))->diff(new DateTime('today'))->y;
            $status = sked_seed2_rand_weighted($statusWeights);
            $verified = $status === 'active' ? 1 : 0;

            $insertYouth->execute([
                'status' => $status, 'bgy' => $bgyId, 'name' => $name, 'surname' => $surname, 'given' => $given,
                'middle' => $middle, 'sex' => $sex, 'email' => $username . '@sked-sample.local',
                'mobile' => '09' . mt_rand(150000000, 999999999), 'birthdate' => $birthdate, 'username' => $username,
                'hash' => $youthHash, 'verified' => $verified,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $regDate = sked_seed2_past_date(1, 400) . ' ' . sprintf('%02d:%02d:00', mt_rand(7, 20), mt_rand(0, 59));
            $backdateUser->execute(['d' => $regDate, 'id' => $userId]);

            $youthList[] = ['id' => $userId, 'status' => $status, 'sex' => $sex, 'birthdate' => $birthdate, 'age' => $age];
            $seq++;
        }
        echo "Youth created: " . count($youthList) . " (password {$youthPassword})\n";
    }

    // --- KK Profiles (~70% of active) ---
    $profilesCreated = 0;
    foreach ($youthList as $y) {
        if ($y['status'] !== 'active') { continue; }
        if (mt_rand(1, 100) > 70) { continue; }
        if (sked_has_youth_profile($y['id'])) { continue; }

        $age = $y['age']; $sex = $y['sex'];
        $genderIdentity = mt_rand(1, 100) <= 95 ? ($sex === 'male' ? 'man' : 'woman') : ($sex === 'male' ? 'woman' : 'man');
        $classifications = [$classificationList[array_rand($classificationList)]];
        $specificNeeds = in_array(SKED_CLASSIFICATION_SPECIFIC_NEEDS, $classifications, true)
            ? [$specificNeedsList[array_rand($specificNeedsList)]] : [];
        $scholarships = mt_rand(1, 100) <= 25 ? [sked_seed2_rand_pick(array_diff($scholarshipOptions, ['None', 'Others']))] : ['None'];
        $numInterests = mt_rand(1, 3);
        $interestKeys = array_rand($interestCategories, $numInterests);
        $interests = is_array($interestKeys) ? array_map(static fn ($k) => $interestCategories[$k], $interestKeys) : [$interestCategories[$interestKeys]];
        $numPrograms = mt_rand(3, 5);
        $programKeys = array_rand($preferredProgramsAll, $numPrograms);
        $preferredPrograms = is_array($programKeys) ? array_map(static fn ($k) => $preferredProgramsAll[$k], $programKeys) : [$preferredProgramsAll[$programKeys]];
        $attended = mt_rand(1, 100) <= 55 ? '1' : '0';

        $data = [
            'consent_agreed' => 1,
            'civil_status' => $age >= 25 ? sked_seed2_rand_weighted(['Single (Walang Asawa)' => 55, 'Married (Kasal/May Asawa)' => 30, 'Live-In (Nakikisama)' => 10, 'Separated (Hiwalay)' => 5]) : 'Single (Walang Asawa)',
            'gender_identity' => $genderIdentity,
            'lgbtqia_member' => mt_rand(1, 100) <= 10 ? 1 : 0,
            'num_children' => $age >= 22 ? sked_seed2_rand_weighted([0 => 60, 1 => 25, 2 => 10, 3 => 5]) : 0,
            'educational_attainment' => sked_seed2_education_for_age($age),
            'work_status' => sked_seed2_work_status_for_age($age),
            'valid_id' => sked_seed2_rand_pick(['Philippine National ID', "Voter's ID", 'School ID', 'Postal ID']),
            'sk_voter' => mt_rand(1, 100) <= 85 ? '1' : '0',
            'national_voter' => $age >= 18 ? (mt_rand(1, 100) <= 80 ? '1' : '0') : '0',
            'voted_last_election' => $age >= 18 ? (mt_rand(1, 100) <= 70 ? '1' : '0') : '0',
            'attended_kk_assembly' => $attended,
            'kk_assembly_times' => $attended === '1' ? sked_seed2_rand_pick([1, 3, 5]) : null,
            'kk_assembly_absence_reason' => $attended === '0' ? sked_seed2_rand_pick($profilingOptions['kk_assembly_absence_reason']) : null,
            'classifications' => $classifications, 'specific_needs' => $specificNeeds,
            'scholarships' => $scholarships, 'scholarship_other' => '',
            'interests' => $interests, 'preferred_programs' => $preferredPrograms, 'preferred_programs_other' => '',
            'kk_suggestions' => mt_rand(1, 100) <= 20 ? 'Sana po madagdagan ang mga sports at livelihood program para sa amin.' : '',
        ];
        $res = sked_save_youth_profile($y['id'], $data, 0, 'youth');
        if ($res['ok']) { $profilesCreated++; }
    }
    echo "KK Profiles created: {$profilesCreated}\n";

    // --- Events ---
    $activeYouthIds = array_values(array_map(static fn ($y) => $y['id'], array_filter($youthList, static fn ($y) => $y['status'] === 'active')));
    $eventsCreated = 0; $participationsCreated = 0;
    $setStatusStmt = $pdo->prepare('UPDATE events SET created_at = :d WHERE id = :id');
    $joinedAtStmt = $pdo->prepare('UPDATE event_participants SET joined_at = DATE_SUB(:evd, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE event_id = :eid');

    $existingEvCount = $pdo->prepare("SELECT COUNT(*) FROM events WHERE scope='barangay' AND barangay_id = :b");
    $existingEvCount->execute(['b' => $bgyId]);
    if ((int) $existingEvCount->fetchColumn() === 0) {
        $actor = ['id' => $skUser['id'], 'role' => 'sk', 'name' => $skUser['name'], 'barangay_id' => $bgyId];
        $templates = $eventTemplates;
        shuffle($templates);
        for ($i = 0; $i < $eventCount; $i++) {
            [$title, $category] = $templates[$i % count($templates)];
            $isUpcoming = mt_rand(1, 100) <= 20;
            $eventDate = $isUpcoming ? sked_seed2_future_date(5, 60) : sked_seed2_past_date(3, 300);
            $type = mt_rand(1, 100) <= 30 ? 'register' : 'interested';
            $capacity = $type === 'register' ? mt_rand(15, 35) : null;

            $result = sked_create_event($actor, [
                'title' => $title, 'description' => "Barangay-wide activity for the youth of {$bgyName}.",
                'category' => $category, 'scope' => 'barangay', 'type' => $type, 'is_team_sport' => 0,
                'location' => 'Barangay ' . $bgyName . ' Covered Court', 'event_date' => $eventDate,
                'min_participants' => 5, 'capacity' => $capacity, 'publish' => false,
            ]);
            if (!$result['ok']) { continue; }
            $eventsCreated++;
            $eventId = (int) $result['event_id'];
            $joined = sked_seed2_process_lifecycle($pdo, $result, $activeYouthIds, $skUser['id'], $eventDate, $capacity, $attendanceRate, $ratingBias);
            $participationsCreated += $joined;

            $createdAt = date('Y-m-d H:i:s', strtotime($eventDate . ' -' . mt_rand(14, 40) . ' days'));
            $setStatusStmt->execute(['d' => $createdAt, 'id' => $eventId]);
            $joinedAtStmt->execute(['evd' => $eventDate, 'eid' => $eventId]);
        }
        echo "Events created: {$eventsCreated}, participations: {$participationsCreated}\n";
    } else {
        echo "Events already exist for this barangay, skipping.\n";
    }

    // --- Polls ---
    $pollsCreated = 0; $votesCreated = 0;
    $existingPollCount = $pdo->prepare('SELECT COUNT(*) FROM polls WHERE barangay_id = :b');
    $existingPollCount->execute(['b' => $bgyId]);
    if ((int) $existingPollCount->fetchColumn() === 0) {
        $actor = ['id' => $skUser['id'], 'role' => 'sk', 'name' => $skUser['name'], 'barangay_id' => $bgyId];
        $pollCreatedAtStmt = $pdo->prepare('UPDATE polls SET created_at = :d WHERE id = :id');
        $voteCreatedAtStmt = $pdo->prepare('UPDATE poll_responses SET created_at = DATE_ADD(:pcreated, INTERVAL FLOOR(1 + RAND() * 20) DAY) WHERE poll_id = :pid');
        $templates = $pollTemplates;
        shuffle($templates);
        foreach (array_slice($templates, 0, mt_rand(1, 2)) as [$question, $category, $options]) {
            $closesAt = (new DateTime())->modify('+' . mt_rand(20, 60) . ' days')->format('Y-m-d\TH:i');
            $result = sked_create_poll($actor, $question, $options, true, $category, $closesAt);
            if (!$result['ok']) { continue; }
            $pollsCreated++;
            $pollId = (int) $result['poll_id'];
            $pollCreatedDate = sked_seed2_past_date(15, 150);
            $pollCreatedAtStmt->execute(['d' => $pollCreatedDate . ' 09:00:00', 'id' => $pollId]);

            $optRows = $pdo->prepare('SELECT id FROM poll_options WHERE poll_id = :p');
            $optRows->execute(['p' => $pollId]);
            $optionIds = array_map(static fn ($r) => (int) $r['id'], $optRows->fetchAll());
            if (empty($optionIds)) { continue; }

            $pool = $activeYouthIds;
            shuffle($pool);
            $voterCount = (int) round(count($pool) * (mt_rand(35, 70) / 100));
            foreach (array_slice($pool, 0, $voterCount) as $uid) {
                $res = sked_cast_poll_vote($uid, $bgyId, $pollId, $optionIds[array_rand($optionIds)]);
                if ($res['ok']) { $votesCreated++; }
            }
            $voteCreatedAtStmt->execute(['pcreated' => $pollCreatedDate, 'pid' => $pollId]);
        }
        echo "Polls created: {$pollsCreated}, votes cast: {$votesCreated}\n";
    } else {
        echo "Polls already exist for this barangay, skipping.\n";
    }

    return ['sk' => $skUser, 'active_youth_ids' => $activeYouthIds];
}

/* ------------------------------------------------------------
 * Barangay 2 — Bagong Pag-Asa: healthy, clean compliance record.
 * ------------------------------------------------------------ */
$b2 = sked_seed2_populate_barangay(
    $pdo, 2, $barangayName[2], 22, ['active' => 72, 'pending' => 18, 'rejected' => 10],
    0.85, 4.2, 6,
    $skPassword, $skHash, $youthPassword, $youthHash,
    $givenMale, $givenFemale, $surnames, $middleNames,
    $interestCategories, $preferredProgramsAll, $scholarshipOptions, $profilingOptions,
    $classificationList, $specificNeedsList, $eventTemplates, $pollTemplates
);

/* ------------------------------------------------------------
 * Barangay 3 — Bagumbarangay: thinner, struggling. This is the one
 * subject for dismissal review.
 * ------------------------------------------------------------ */
$b3 = sked_seed2_populate_barangay(
    $pdo, 3, $barangayName[3], 14, ['active' => 45, 'pending' => 40, 'rejected' => 15],
    0.55, 2.6, 3,
    $skPassword, $skHash, $youthPassword, $youthHash,
    $givenMale, $givenFemale, $surnames, $middleNames,
    $interestCategories, $preferredProgramsAll, $scholarshipOptions, $profilingOptions,
    $classificationList, $specificNeedsList, $eventTemplates, $pollTemplates
);

/* ------------------------------------------------------------
 * Monthly reports — Barangay 2 (Bagong Pag-Asa): on time, mostly
 * acknowledged, one left pending so DILG has something live to act on.
 * ------------------------------------------------------------ */
echo "\n=== Monthly reports: Bagong Pag-Asa (healthy) ===\n";
$reportSubmittedAtStmt = $pdo->prepare('UPDATE reports SET submitted_at = :d WHERE id = :id');
$b2Actor = ['id' => $b2['sk']['id'], 'role' => 'sk', 'name' => $barangayName[2] !== '' ? null : null, 'barangay_id' => 2];
$skNameStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
$skNameStmt->execute(['id' => $b2['sk']['id']]);
$b2Actor['name'] = (string) $skNameStmt->fetchColumn();

$existingReportsB2 = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE type='monthly' AND barangay_id = 2");
$existingReportsB2->execute();
if ((int) $existingReportsB2->fetchColumn() === 0) {
    $periods = [];
    for ($i = 1; $i <= 3; $i++) {
        $periods[] = (new DateTime("first day of -$i month"))->format('Y-m');
    }
    $b2ReportsCreated = 0;
    foreach ($periods as $idx => $period) {
        $r = sked_submit_report($b2Actor, 'monthly', 'Monthly Activity Report - ' . $period,
            "Summary of KK profiling progress, barangay events held, and youth engagement for {$period}. Submitted on time.",
            $period, null, null);
        if (!$r['ok']) { continue; }
        $b2ReportsCreated++;
        $reportId = null;
        $findId = $pdo->prepare("SELECT id FROM reports WHERE type='monthly' AND barangay_id=2 AND period_month=:p LIMIT 1");
        $findId->execute(['p' => $period]);
        $reportId = (int) $findId->fetchColumn();
        // Backdate submission to just before the 10th of the following month (on time).
        $submittedAt = (new DateTime($period . '-01'))->modify('+1 month')->modify('-' . mt_rand(1, 6) . ' days')->format('Y-m-d H:i:s');
        $reportSubmittedAtStmt->execute(['d' => $submittedAt, 'id' => $reportId]);

        // Acknowledge all but the most recent one, so one is left pending for live DILG testing.
        if ($idx > 0 && !empty($dilgRows)) {
            sked_acknowledge_report($reportId, (int) $dilgRows[0]['id']);
        }
    }
    echo "Monthly reports submitted: {$b2ReportsCreated} (on time, mostly acknowledged, latest left pending)\n";
} else {
    echo "Monthly reports already exist for Bagong Pag-Asa, skipping.\n";
}

/* ------------------------------------------------------------
 * Monthly reports + STRIKES — Barangay 3 (Bagumbarangay): one early
 * report submitted, then 3 consecutive missed deadlines -> 3 strikes ->
 * escalated to DILG as a PENDING dismissal_recommendation report.
 * ------------------------------------------------------------ */
echo "\n=== Monthly reports + compliance: Bagumbarangay (dismissal review) ===\n";
$b3SkId = $b3['sk']['id'];
$skNameStmt->execute(['id' => $b3SkId]);
$b3SkName = (string) $skNameStmt->fetchColumn();
$b3Actor = ['id' => $b3SkId, 'role' => 'sk', 'name' => $b3SkName, 'barangay_id' => 3];

$existingReportsB3 = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE type='monthly' AND barangay_id = 3");
$existingReportsB3->execute();
if ((int) $existingReportsB3->fetchColumn() === 0) {
    // One early, on-time report (4 months ago) to show they used to comply.
    $earlyPeriod = (new DateTime('first day of -4 month'))->format('Y-m');
    $r = sked_submit_report($b3Actor, 'monthly', 'Monthly Activity Report - ' . $earlyPeriod,
        "Summary of activities for {$earlyPeriod}. Submitted on time.", $earlyPeriod, null, null);
    if ($r['ok']) {
        $findId = $pdo->prepare("SELECT id FROM reports WHERE type='monthly' AND barangay_id=3 AND period_month=:p LIMIT 1");
        $findId->execute(['p' => $earlyPeriod]);
        $reportId = (int) $findId->fetchColumn();
        $submittedAt = (new DateTime($earlyPeriod . '-01'))->modify('+1 month')->modify('-3 days')->format('Y-m-d H:i:s');
        $reportSubmittedAtStmt->execute(['d' => $submittedAt, 'id' => $reportId]);
        if (!empty($dilgRows)) {
            sked_acknowledge_report($reportId, (int) $dilgRows[0]['id']);
        }
        echo "Early on-time report submitted for {$earlyPeriod} (acknowledged).\n";
    }
} else {
    echo "Monthly reports already exist for Bagumbarangay, skipping report submission.\n";
}

// 3 missed deadlines -> 3 strikes for the last 3 months. sked_add_strike()
// is idempotent per SK+period (INSERT IGNORE on a unique key), so this is
// safe to re-run.
$strikeCreatedAtStmt = $pdo->prepare('UPDATE sk_strikes SET created_at = :d WHERE sk_user_id = :sk AND period_month = :p');
$missedPeriods = [];
for ($i = 1; $i <= 3; $i++) {
    $missedPeriods[] = (new DateTime("first day of -$i month"))->format('Y-m');
}
$strikesAdded = 0;
foreach ($missedPeriods as $period) {
    if (sked_add_strike($b3SkId, 3, $period)) {
        $strikesAdded++;
        $strikeDate = (new DateTime($period . '-01'))->modify('+1 month')->modify('+' . mt_rand(1, 5) . ' days')->format('Y-m-d H:i:s');
        $strikeCreatedAtStmt->execute(['d' => $strikeDate, 'sk' => $b3SkId, 'p' => $period]);
    }
}
$totalStrikes = sked_strike_count($b3SkId);
echo "Compliance strikes added this run: {$strikesAdded} (total on file: {$totalStrikes})\n";

if ($totalStrikes >= SKED_DISMISSAL_STRIKE_THRESHOLD && $ppskActor !== null) {
    $esc = sked_escalate_to_dilg($ppskActor, $b3SkId);
    if ($esc['ok']) {
        echo "Escalated to DILG: dismissal recommendation for {$b3SkName} (Bagumbarangay) is now PENDING review.\n";
    } else {
        echo "Escalation not created: " . implode('; ', $esc['errors']) . "\n";
    }
}

echo "\nSeed complete. Barangay 2 (Bagong Pag-Asa) = healthy; Barangay 3 (Bagumbarangay) = subject for dismissal review.\n";
echo "Shared passwords — SK: {$skPassword} | Youth: {$youthPassword}\n";
