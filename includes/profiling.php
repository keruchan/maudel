<?php
/**
 * ============================================================
 * File     : includes/profiling.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Youth (KK) profiling questionnaire (P11 v2). Transcribes the
 *            real Brgy. J.P. Rizal "KK Profiling" Google Form (informed
 *            consent, Profile, Demographic Characteristics, KK Assembly,
 *            KK Suggestions) into a database-backed, sectioned form.
 *
 * Design notes:
 *   - Identity fields already captured at registration (surname, given
 *     name, middle name, sex assigned at birth, email, mobile, birthdate)
 *     are NOT asked again here — sked_user_identity() in includes/auth.php
 *     supplies them read-only ("autofill + lock"), the same governance
 *     rule pages/account/settings.php already applies to name/email.
 *   - Age and Youth Age Group are fully derived from birthdate and are
 *     never stored or asked — the source form's own checkbox-list "Age"
 *     question and "Youth Age Group" question are both redundant with it.
 *   - Multi-select checkbox questions live in their own normalized child
 *     tables (youth_interests, youth_classifications, youth_specific_needs,
 *     youth_scholarships, youth_preferred_programs) — no JSON columns,
 *     matching the existing youth_interests pattern from P3/P9.
 *   - "Center of Youth Participation" reuses the existing youth_interests
 *     table (P3) with its option list relabeled to the real form's 10
 *     official categories, so it keeps feeding the P9 analytics engine and
 *     the events/polls category taxonomy without inventing a parallel field.
 *   - Can be filled by the youth themselves (verified only) or entered on
 *     their behalf by their barangay's SK Chairman — see pages/youth/profile.php
 *     and pages/manage/kk_profile.php, which both call the functions here.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

/** Current questionnaire version. Bump when the field set changes. */
const SKED_QUESTIONNAIRE_VERSION = 2;

/** A classification value that reveals the conditional "specific needs" sub-question. */
const SKED_CLASSIFICATION_SPECIFIC_NEEDS = 'Youth with Specific Needs (Mga Kabataang may Espesyal na Pangangailangan)';

/** Single-choice option sets. */
function sked_profiling_options(): array
{
    return [
        'civil_status' => [
            'Single (Walang Asawa)', 'Married (Kasal/May Asawa)', 'Widowed (Balo)',
            'Live-In (Nakikisama)', 'Solo Parent (Solo ng Magulang)', 'Separated (Hiwalay)',
            'Unknown (Hindi alam)',
        ],
        'educational_attainment' => [
            'Elementary Level (Grade 1-5)', 'Elementary Graduate (Grade 6)',
            'Junior High School Level (Grade 7-9)', 'Junior High School Graduate (Grade 10)',
            'Senior High School Level (Grade 11)', 'Senior High School Graduate (Grade 12)',
            '1st Year College', '2nd Year College', '3rd Year College', '4th Year College',
            'College Graduate', 'Masters Level', 'Masters Graduate',
            'Doctorate Level', 'Doctorate Graduate',
        ],
        'work_status' => [
            'Employed (Empleyado)', 'Unemployed (Walang Trabaho)',
            'Self-Employed (Sa sarili nagtatrabaho)',
            'Currently Looking for a Job (Kasalukuyang Naghahanap ng Trabaho)',
            'Not Interested Looking for a Job (Hindi Interesadong sa Paghahanap ng Trabaho)',
            'N/A (Hindi Naaangkop)',
        ],
        'kk_assembly_absence_reason' => [
            'There was no KK Assembly meeting (Walang inilunsad na KK Assembly meeting)',
            'Not interested to attend (Hindi interesadong dumalo)',
            'I am not aware of KK Assembly (Hindi ko batid na may KK Assembly)',
            'N/A (Hindi Naaangkop)',
        ],
    ];
}

/** Gender Identity (single-select; LGBTQIA membership is a separate, independent flag). */
function sked_gender_identity_options(): array
{
    return ['man' => 'Man (Lalaki)', 'woman' => 'Woman (Babae)'];
}

/** Number of children (single-select; 0 doubles as N/A per the source form). */
function sked_num_children_options(): array
{
    return [
        0 => '0 - N/A (Hindi Naaangkop)',
        1 => '1 - One (Isa)',
        2 => '2 - Two (Dalawa)',
        3 => '3 - Three (Tatlo)',
        4 => '4 - Four (Apat)',
        5 => '5 - Five or more (Lima o Higit pa)',
    ];
}

/** How many times attended KK Assembly (only asked when attended = yes). */
function sked_kk_assembly_times_options(): array
{
    return [0 => '0 - 0 (Wala/Hindi)', 1 => '1 - One (Isa)', 2 => '2 - Two (Dalawa)'];
}

/** Youth Classification (multi-select checkboxes — a youth may hold more than one). */
function sked_youth_classifications(): array
{
    return [
        'In School Youth (Nag-aaral pa)',
        'Out of School Youth (Hindi na nag-aaral)',
        'Working Youth (Nagtatrabaho na)',
        'Working Student (Nag-aaral habang nagtatrabaho)',
        SKED_CLASSIFICATION_SPECIFIC_NEEDS,
    ];
}

/** Specific-needs sub-question, only shown/relevant when "Youth with Specific Needs" is checked. */
function sked_specific_needs_options(): array
{
    return [
        'Person with Disability (Kabataang may Kapansanan)',
        'Children in Conflict with Law (Kabataang Salungat sa Batas)',
        'Indigenous People (Mga Katutubo)',
    ];
}

/** Scholarship (multi-select checkboxes + "Others" free text). */
function sked_scholarship_options(): array
{
    return [
        'Pamahalaang Lokal', 'Sangguniang Kabataan', 'DOST', 'CHED-TDP',
        'Academic Scholar', 'Athlete Scholar', 'School/ University Scholar',
        'Iskolar ng Laguna', 'None', 'Others',
    ];
}

/**
 * Canonical youth interest / advocacy categories — the real form's
 * "Center of Youth Participation" list (choose 1 to 3). Shared by the
 * profiling form, event/poll category dropdowns, and the P9 prescriptive
 * analytics aggregation, so the labels always match across the app.
 */
function sked_interest_categories(): array
{
    return [
        'Health', 'Education', 'Economic Empowerment', 'Social Inclusion and Equity',
        'Peace Building and Security', 'Governance', 'Active Citizenship',
        'Environment', 'Global Mobility', 'Agriculture',
    ];
}

/** Preferred programs/projects/activities (multi-select, choose 3 to 5, + "Other" free text). */
function sked_preferred_programs(): array
{
    return [
        'Linggo ng Kabataan',
        'KK Leadership Training and Assembly',
        "Voter's Education Seminar",
        'Disaster Risk Reduction and Resiliency Awareness Campaign',
        'Youth Sports Skills Development Training',
        'Summer League "Bola Kontra Droga" Campaign',
        'SINILYMPICS',
        'Supplemental Feeding Program',
        'Standard First Aid and Basic Life Support Training',
        'Balik-Eskwela Program',
        'SK Free-Printing Service',
        'SK Educational Scholarship Assistance Program',
        'Creative Arts Contest',
        'Anti-Smoking and Substance Abuse Campaign',
        'Clean and Green Program (Clean Up Drive)',
        'Tree Growing and Parenting Activity',
        'Installation of Waste Management Container/Trash Bin',
        'Free Use of Sports Equipment',
    ];
}

/**
 * Verbatim source text (word-for-word, Filipino included) from the Brgy.
 * J.P. Rizal KK Profiling Google Form — the informed-consent notice and the
 * consent statement youth must agree to before submitting. Transcribed as
 * written in the original document, including its own wording/typos.
 *
 * @return array{intro:string,sections:array<int,array{title:string,body:string}>,agreement:string,closing:string}
 */
function sked_kk_consent_text(): array
{
    return [
        'intro' => "Magandang Araw mga ka-KK!\n\n"
            . "Kami ng Sangguniang Kabataan ng Barangay J. P Rizal Siniloan, Laguna ay nagsasagawa sa ngayon ng isang sistematikong pagkuha ng demograpikong impormasyon ng mga miyembro ng Katipunan ng Kabataan sa ating Barangay.\n\n"
            . "Malugod naming hinihikayat ang lahat na makiisa sa pamamagitan ng pagtugon sa Google Survey Form na ito.\n\n"
            . "Nawa ay basahing mabuti ang mga katanungan at sagutan ito nang may buong katapatan. Ang inyong partisipasyon ay lubos naming pahahalagahan at pinasasalamatan.\n\n"
            . "Ang mga impormasyon na malilikom ay mananatiling pribado at nasa pangangasiwa lamang ng Sangguniang Kabataan ng Barangay J. P Rizal.",
        'sections' => [
            [
                'title' => 'Pamagat ng Pananaliksik',
                'body' => 'Katipunan ng Kabataan Profiling (KK Profiling) sa Brgy. J. P Rizal Siniloan, Laguna.',
            ],
            [
                'title' => 'Ahensiyang Konsultasyon',
                'body' => "National Youth Commission (NYC)\nRehiyon IV-A CALABARZON\nLalawigan ng Laguna",
            ],
            [
                'title' => '1. Layunin ng Pag-aaral',
                'body' => "Ang Katipunan ng Kabataan Profiling (KK Profiling) ay naglalayong malikom ang impormasyon at status ng mga miyembro ng Katipunan ng Kabataan (KK) edad 15-30 taong gulang.\n\n"
                    . 'Ang impormasyong malilikom sa KK Profiling ay ligtas at nasa pangangalaga ng Sangguniang Kabataan ng Barangay J. P Rizal Siniloan, Laguna.',
            ],
            [
                'title' => '2. Mga Tuntunin at Tagal ng Pakikiisa',
                'body' => "Magalang na hinihiling ng mga mananaliksik ang pakikibahagi ng lahat ng miyembro ng Katipunan ng Kabataan sa isinasagawang KK Profiling.\n\n"
                    . 'Ang Face to Face at Online Google Form Survey ay maaaring tumagal ng 3-5 minuto upang sagutan.',
            ],
            [
                'title' => '3. Panganib/Kumpidensyalidad',
                'body' => "Ang iyong pakikiisa sa pag-aaral ay ituturing na may pinakamataas na kumpidensyalidad.\n\n"
                    . "Ang mga impormasyon na malilikom ay mananatiling pribado at nasa pangangasiwa lamang ng Sangguniang Kabataan ng Barangay J. P Rizal Siniloan, Laguna.\n\n"
                    . 'Ang anumang impormasyon na malilikom mula sa mga respondente ay gagamitin lamang sa pangangasiwa ng database. Sinisiguro ng mga mananaliksik na ang pakikibahagi sa survey na ito ay walang maidudulot na panganib para sa mga respondente.',
            ],
            [
                'title' => '4. Kompensasyon',
                'body' => "Ang isinasagawang profiling ay ayon sa R.A. No. 10742 at sa alituntunin ng Department of Interior and Local Government at ng National Youth Commission.\n\n"
                    . 'Ang pakikibahagi ay walang katumbas na perang kabayaran maliban sa malugod naming pinasasalamatan ang inyong oras at pagsisikap. Lubos naming pinahahalagahan ang iyong pakikiisa.',
            ],
            [
                'title' => '5. Para sa iba pang katanungan',
                'body' => "Kung mayroon ka mang katanungan hinggil sa Face to Face at Online Google Form Survey o sa pangkalahatang pag-aaral, huwag mag-atubiling makipag-ugnay sa Sangguniang Kabataan ng Barangay J. P Rizal Siniloan, Laguna.\n\n"
                    . "Maaari ang makipag-ugnayan sa Sangguniang Kabataan ng Barangay J. P. Rizal Siniloan, Laguna.\n\n"
                    . 'Facebook Page: Sangguniang Kabataan ng Barangay J. P. Rizal',
            ],
        ],
        'agreement' => 'Naiintindihan ko at sinasang-ayunan ang lahat ng nabanggit sa itaas. Malugod akong makikibahagi sa KK Profiling Survey Form at sasagutan ito nang walang anumang inaasahan na kapalit at ng buong katapatan.',
        'closing' => "Maraming salamat, pag-asa ng bayan!\n"
            . 'Maraming salamat sa iyong oras at partisipasyon! Ang iyong pakikiisa ay malaking tulong para sa masusing pagbuo ng plano at susunod na proyekto para sa mga kabataan ng ating barangay! #ParaSaKabataangSiniloeño',
    ];
}

/**
 * The signed-in youth's profile plus all related multi-select tables, or
 * null if none saved yet.
 *
 * @return array<string,mixed>|null
 */
function sked_get_youth_profile(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM youth_profiles WHERE user_id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $profile = $stmt->fetch();
    if ($profile === false) {
        return null;
    }

    $profile['interests'] = sked_profile_child_values($userId, 'youth_interests', 'category');
    $profile['classifications'] = sked_profile_child_values($userId, 'youth_classifications', 'classification');
    $profile['specific_needs'] = sked_profile_child_values($userId, 'youth_specific_needs', 'need_type');
    $profile['scholarships'] = sked_profile_child_values($userId, 'youth_scholarships', 'scholarship_type');
    $profile['preferred_programs'] = sked_profile_child_values($userId, 'youth_preferred_programs', 'program');

    return $profile;
}

/** Fetch one multi-select child table's values for a user. Table/column names are hardcoded call sites only (never user input). */
function sked_profile_child_values(int $userId, string $table, string $column): array
{
    $stmt = sked_db()->prepare("SELECT $column AS v FROM $table WHERE user_id = :id");
    $stmt->execute(['id' => $userId]);
    return array_map(static fn($r) => $r['v'], $stmt->fetchAll());
}

/** Replace a multi-select child table's rows for a user with a new value set. */
function sked_replace_profile_child_values(int $userId, string $table, string $column, array $values): void
{
    $pdo = sked_db();
    $pdo->prepare("DELETE FROM $table WHERE user_id = :id")->execute(['id' => $userId]);
    if (empty($values)) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO $table (user_id, $column) VALUES (:id, :v)");
    foreach (array_unique($values) as $v) {
        $ins->execute(['id' => $userId, 'v' => $v]);
    }
}

/** Youth Age Group — fully derived from age, never asked or stored (spec: auto-fill + lock derived fields). */
function sked_youth_age_group(?int $age): string
{
    if ($age === null) {
        return '';
    }
    if ($age <= 17) {
        return 'Child Youth (15-17 taong gulang)';
    }
    if ($age <= 24) {
        return 'Core Youth (18-24 taong gulang)';
    }
    return 'Young Adult (25-30 taong gulang)';
}

/**
 * Municipality-wide youth roster summary, one row per barangay (P15,
 * "Consolidated Profiles" for PPSK/DILG oversight). "Active youth" means
 * verified (status='active'); "profiled" means they've saved a KK profile.
 *
 * @return array<int,array{barangay_id:int,barangay_name:string,total_youth:int,active_youth:int,profiled_count:int}>
 */
function sked_youth_profile_summary(): array
{
    $stmt = sked_db()->query(
        "SELECT b.id AS barangay_id, b.name AS barangay_name,
                COUNT(DISTINCT u.id) AS total_youth,
                COALESCE(SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END), 0) AS active_youth,
                COUNT(DISTINCT yp.user_id) AS profiled_count
           FROM barangays b
      LEFT JOIN users u ON u.barangay_id = b.id AND u.role = 'youth' AND u.status IN ('pending', 'active')
      LEFT JOIN youth_profiles yp ON yp.user_id = u.id
          WHERE b.is_active = 1
          GROUP BY b.id, b.name
          ORDER BY b.name ASC"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['barangay_id'] = (int) $row['barangay_id'];
        $row['total_youth'] = (int) $row['total_youth'];
        $row['active_youth'] = (int) $row['active_youth'];
        $row['profiled_count'] = (int) $row['profiled_count'];
    }
    return $rows;
}

/** True if the user has a saved profile. */
function sked_has_youth_profile(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = sked_db()->prepare('SELECT 1 FROM youth_profiles WHERE user_id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Maps a sked_get_youth_profile() row (DB shapes: 0/1/null ints, child
 * arrays) into the flat string/array shape sked_render_kk_profile_form()
 * expects (matching raw $_POST field shapes, so both pages can share one
 * "current values" contract whether freshly loaded or re-shown after a
 * validation error).
 *
 * @param array<string,mixed>|null $profile
 * @return array<string,mixed>
 */
function sked_profile_view_defaults(?array $profile): array
{
    $boolStr = static function ($v): string {
        return $v === null ? '' : (string) (int) $v;
    };
    if ($profile === null) {
        return [
            'consent_agreed' => false, 'civil_status' => '', 'gender_identity' => '',
            'lgbtqia_member' => false, 'facebook_name' => '', 'num_children' => '',
            'classifications' => [], 'specific_needs' => [], 'educational_attainment' => '',
            'scholarships' => [], 'scholarship_other' => '', 'work_status' => '', 'valid_id' => '',
            'sk_voter' => '', 'national_voter' => '', 'voted_last_election' => '',
            'attended_kk_assembly' => '', 'kk_assembly_times' => '', 'kk_assembly_absence_reason' => '',
            'interests' => [], 'preferred_programs' => [], 'preferred_programs_other' => '', 'kk_suggestions' => '',
        ];
    }
    return [
        'consent_agreed' => !empty($profile['consent_agreed']),
        'civil_status' => (string) ($profile['civil_status'] ?? ''),
        'gender_identity' => (string) ($profile['gender_identity'] ?? ''),
        'lgbtqia_member' => !empty($profile['lgbtqia_member']),
        'facebook_name' => (string) ($profile['facebook_name'] ?? ''),
        'num_children' => $profile['num_children'] !== null ? (string) (int) $profile['num_children'] : '',
        'classifications' => $profile['classifications'] ?? [],
        'specific_needs' => $profile['specific_needs'] ?? [],
        'educational_attainment' => (string) ($profile['educational_attainment'] ?? ''),
        'scholarships' => $profile['scholarships'] ?? [],
        'scholarship_other' => (string) ($profile['scholarship_other'] ?? ''),
        'work_status' => (string) ($profile['work_status'] ?? ''),
        'valid_id' => (string) ($profile['valid_id'] ?? ''),
        'sk_voter' => $boolStr($profile['sk_voter'] ?? null),
        'national_voter' => $boolStr($profile['national_voter'] ?? null),
        'voted_last_election' => $boolStr($profile['voted_last_election'] ?? null),
        'attended_kk_assembly' => $boolStr($profile['attended_kk_assembly'] ?? null),
        'kk_assembly_times' => $profile['kk_assembly_times'] !== null ? (string) (int) $profile['kk_assembly_times'] : '',
        'kk_assembly_absence_reason' => (string) ($profile['kk_assembly_absence_reason'] ?? ''),
        'interests' => $profile['interests'] ?? [],
        'preferred_programs' => $profile['preferred_programs'] ?? [],
        'preferred_programs_other' => (string) ($profile['preferred_programs_other'] ?? ''),
        'kk_suggestions' => (string) ($profile['remarks'] ?? ''),
    ];
}

/**
 * Validate + upsert a youth's KK profile and every related multi-select
 * table, then award the one-time profiling-completion points. Works
 * identically whether the youth fills it themselves or their barangay SK
 * fills it on their behalf — $actorId/$actorRole are only used for the
 * audit trail.
 *
 * @param array<string,mixed> $data  Raw form input.
 * @return array{ok:bool,errors:array<int,string>,points_awarded:bool}
 */
function sked_save_youth_profile(int $userId, array $data, int $actorId = 0, string $actorRole = 'youth'): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'errors' => ['Invalid account.'], 'points_awarded' => false];
    }

    $errors = [];
    $options = sked_profiling_options();

    // --- Consent (required before anything is saved) ---
    $consentAgreed = !empty($data['consent_agreed']);
    if (!$consentAgreed) {
        $errors[] = 'You must agree to the consent statement (Pahayag sa Pagsang-ayon) before saving.';
    }

    // --- Single-choice required fields ---
    $civilStatus = trim((string) ($data['civil_status'] ?? ''));
    if (!in_array($civilStatus, $options['civil_status'], true)) {
        $errors[] = 'Please select your civil status.';
    }

    $genderIdentity = trim((string) ($data['gender_identity'] ?? ''));
    if (!array_key_exists($genderIdentity, sked_gender_identity_options())) {
        $errors[] = 'Please select your gender identity.';
    }
    $lgbtqiaMember = !empty($data['lgbtqia_member']) ? 1 : 0;

    $facebookName = trim((string) ($data['facebook_name'] ?? ''));
    if ($facebookName === '') {
        $errors[] = 'Facebook account name is required.';
    }

    $numChildren = (string) ($data['num_children'] ?? '');
    if (!array_key_exists((int) $numChildren, sked_num_children_options()) || $numChildren === '') {
        $errors[] = 'Please select the number of children (choose N/A if none).';
    }
    $numChildren = (int) $numChildren;

    $educationalAttainment = trim((string) ($data['educational_attainment'] ?? ''));
    if (!in_array($educationalAttainment, $options['educational_attainment'], true)) {
        $errors[] = 'Please select your highest educational attainment.';
    }

    $workStatus = trim((string) ($data['work_status'] ?? ''));
    if (!in_array($workStatus, $options['work_status'], true)) {
        $errors[] = 'Please select your work status.';
    }

    $validId = trim((string) ($data['valid_id'] ?? ''));
    if ($validId === '') {
        $errors[] = 'Please state a valid ID (or enter WALA if you have none).';
    }

    // --- Yes/No fields ---
    $skVoter = (string) ($data['sk_voter'] ?? '');
    if (!in_array($skVoter, ['1', '0'], true)) {
        $errors[] = 'Please answer whether you are a registered SK voter.';
    }
    $nationalVoter = (string) ($data['national_voter'] ?? '');
    $nationalVoter = in_array($nationalVoter, ['1', '0'], true) ? (int) $nationalVoter : null;
    $votedLastElection = (string) ($data['voted_last_election'] ?? '');
    $votedLastElection = in_array($votedLastElection, ['1', '0'], true) ? (int) $votedLastElection : null;

    // --- KK Assembly (conditional follow-up) ---
    $attended = (string) ($data['attended_kk_assembly'] ?? '');
    if (!in_array($attended, ['1', '0'], true)) {
        $errors[] = 'Please answer whether you attended a KK Assembly last year.';
    }
    $kkTimes = null;
    $kkAbsenceReason = null;
    if ($attended === '1') {
        $timesRaw = (string) ($data['kk_assembly_times'] ?? '');
        if (!array_key_exists((int) $timesRaw, sked_kk_assembly_times_options()) || $timesRaw === '') {
            $errors[] = 'Please select how many times you attended the KK Assembly.';
        } else {
            $kkTimes = (int) $timesRaw;
        }
    } elseif ($attended === '0') {
        $kkAbsenceReason = trim((string) ($data['kk_assembly_absence_reason'] ?? ''));
        if (!in_array($kkAbsenceReason, $options['kk_assembly_absence_reason'], true)) {
            $errors[] = 'Please select a reason you did not attend the KK Assembly.';
        }
    }

    // --- Youth Classification (multi) + conditional Specific Needs sub-question ---
    $allClassifications = sked_youth_classifications();
    $classifications = array_values(array_intersect($allClassifications, array_map('strval', (array) ($data['classifications'] ?? []))));
    if (empty($classifications)) {
        $errors[] = 'Please select at least one youth classification.';
    }
    $specificNeeds = [];
    if (in_array(SKED_CLASSIFICATION_SPECIFIC_NEEDS, $classifications, true)) {
        $specificNeeds = array_values(array_intersect(sked_specific_needs_options(), array_map('strval', (array) ($data['specific_needs'] ?? []))));
    }

    // --- Scholarship (multi + "Others" free text) ---
    $allScholarships = sked_scholarship_options();
    $scholarships = array_values(array_intersect($allScholarships, array_map('strval', (array) ($data['scholarships'] ?? []))));
    $scholarshipOther = trim((string) ($data['scholarship_other'] ?? ''));
    if (in_array('Others', $scholarships, true) && $scholarshipOther === '') {
        $errors[] = 'Please specify your "Others" scholarship.';
    }
    if (!in_array('Others', $scholarships, true)) {
        $scholarshipOther = '';
    }

    // --- Center of Youth Participation (choose 1 to 3) ---
    $allInterests = sked_interest_categories();
    $interests = array_values(array_intersect($allInterests, array_map('strval', (array) ($data['interests'] ?? []))));
    if (count($interests) < 1 || count($interests) > 3) {
        $errors[] = 'Please choose 1 to 3 centers of youth participation.';
    }

    // --- Preferred programs (choose 3 to 5, "Other" counts as one slot) ---
    $allPrograms = sked_preferred_programs();
    $preferredPrograms = array_values(array_intersect($allPrograms, array_map('strval', (array) ($data['preferred_programs'] ?? []))));
    $preferredProgramsOther = trim((string) ($data['preferred_programs_other'] ?? ''));
    $programSlotCount = count($preferredPrograms) + ($preferredProgramsOther !== '' ? 1 : 0);
    if ($programSlotCount < 3 || $programSlotCount > 5) {
        $errors[] = 'Please choose 3 to 5 preferred programs/projects/activities (an "Other" entry counts as one).';
    }

    // --- Optional free text ---
    $kkSuggestions = trim((string) ($data['kk_suggestions'] ?? ''));
    if (mb_strlen($kkSuggestions) > 1000) {
        $kkSuggestions = mb_substr($kkSuggestions, 0, 1000);
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors, 'points_awarded' => false];
    }

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO youth_profiles
                (user_id, questionnaire_version, consent_agreed, consent_agreed_at,
                 civil_status, gender_identity, lgbtqia_member, facebook_name, num_children,
                 educational_attainment, work_status, valid_id,
                 sk_voter, national_voter, voted_last_election,
                 attended_kk_assembly, kk_assembly_times, kk_assembly_absence_reason,
                 remarks, scholarship_other, preferred_programs_other)
             VALUES
                (:user_id, :ver, 1, NOW(),
                 :civil_status, :gender_identity, :lgbtqia_member, :facebook_name, :num_children,
                 :educational_attainment, :work_status, :valid_id,
                 :sk_voter, :national_voter, :voted_last_election,
                 :attended_kk_assembly, :kk_assembly_times, :kk_assembly_absence_reason,
                 :remarks, :scholarship_other, :preferred_programs_other)
             ON DUPLICATE KEY UPDATE
                questionnaire_version = VALUES(questionnaire_version),
                consent_agreed = VALUES(consent_agreed),
                consent_agreed_at = VALUES(consent_agreed_at),
                civil_status = VALUES(civil_status),
                gender_identity = VALUES(gender_identity),
                lgbtqia_member = VALUES(lgbtqia_member),
                facebook_name = VALUES(facebook_name),
                num_children = VALUES(num_children),
                educational_attainment = VALUES(educational_attainment),
                work_status = VALUES(work_status),
                valid_id = VALUES(valid_id),
                sk_voter = VALUES(sk_voter),
                national_voter = VALUES(national_voter),
                voted_last_election = VALUES(voted_last_election),
                attended_kk_assembly = VALUES(attended_kk_assembly),
                kk_assembly_times = VALUES(kk_assembly_times),
                kk_assembly_absence_reason = VALUES(kk_assembly_absence_reason),
                remarks = VALUES(remarks),
                scholarship_other = VALUES(scholarship_other),
                preferred_programs_other = VALUES(preferred_programs_other)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'ver' => SKED_QUESTIONNAIRE_VERSION,
            'civil_status' => $civilStatus,
            'gender_identity' => $genderIdentity,
            'lgbtqia_member' => $lgbtqiaMember,
            'facebook_name' => $facebookName,
            'num_children' => $numChildren,
            'educational_attainment' => $educationalAttainment,
            'work_status' => $workStatus,
            'valid_id' => $validId,
            'sk_voter' => (int) $skVoter,
            'national_voter' => $nationalVoter,
            'voted_last_election' => $votedLastElection,
            'attended_kk_assembly' => (int) $attended,
            'kk_assembly_times' => $kkTimes,
            'kk_assembly_absence_reason' => $kkAbsenceReason,
            'remarks' => $kkSuggestions !== '' ? $kkSuggestions : null,
            'scholarship_other' => $scholarshipOther !== '' ? $scholarshipOther : null,
            'preferred_programs_other' => $preferredProgramsOther !== '' ? $preferredProgramsOther : null,
        ]);

        sked_replace_profile_child_values($userId, 'youth_interests', 'category', $interests);
        sked_replace_profile_child_values($userId, 'youth_classifications', 'classification', $classifications);
        sked_replace_profile_child_values($userId, 'youth_specific_needs', 'need_type', $specificNeeds);
        sked_replace_profile_child_values($userId, 'youth_scholarships', 'scholarship_type', $scholarships);
        sked_replace_profile_child_values($userId, 'youth_preferred_programs', 'program', $preferredPrograms);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_save_youth_profile failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not save the profile. Please try again.'], 'points_awarded' => false];
    }

    if ($actorId > 0 && $actorRole !== 'youth') {
        sked_audit($actorId, 'kk_profile_assisted_entry', 'user', $userId, 'Filled by ' . $actorRole);
    }

    // One-time points for completing profiling (ref keeps it idempotent).
    $awarded = sked_award_points($userId, 'profiling_completed', 'profile', $userId);

    return ['ok' => true, 'errors' => [], 'points_awarded' => $awarded];
}
