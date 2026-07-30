<?php
/**
 * ============================================================
 * File     : includes/events.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Events & engagement core (P4). Event creation (barangay /
 *            inter-barangay / municipal), role-scoped listing, youth
 *            eligibility + join/register, and participant queries.
 *
 * Scope model:
 *   barangay      -> single barangay_id                 (created by SK)
 *   interbarangay -> a set of barangays (event_barangays) (created by PPSK)
 *   municipal     -> whole municipality                 (created by PPSK/DILG)
 *
 * Type: 'interested' (join, no cap) vs 'register' (capacity/slots).
 * Team-sport rule is satisfied by enforcing verified + in-scope on every
 * individual registrant (each youth registers with their team_name).
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/points.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/barangays.php';
require_once __DIR__ . '/profiling.php';

const SKED_EVENT_IMAGE_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const SKED_EVENT_IMAGE_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];
const SKED_EVENT_TARGET_DIMENSIONS = ['classification', 'specific_need', 'sex', 'interest'];

/**
 * Optional "For You" targeting dimensions an event can be tagged with,
 * reusing the exact KK Profiling vocabulary so a targeting value always
 * matches a real youth_classifications/youth_specific_needs/youth_interests
 * row (or users.sex_assigned_at_birth) verbatim.
 */
function sked_event_targeting_options(): array
{
    return [
        'classification' => sked_youth_classifications(),
        'specific_need' => sked_specific_needs_options(),
        'sex' => ['male', 'female'],
        'interest' => sked_interest_categories(),
    ];
}

/**
 * Statuses in which a youth who was marked ATTENDED may submit the event
 * evaluation. Attendance can only be taken from 'confirmed' onwards (see
 * SKED_ATTENDANCE_OPEN_STATUSES), so gating on "attended" already does the
 * real work here; this list only excludes draft/cancelled. 'confirmed' is
 * included on purpose so an event whose official never advanced its status
 * does not trap its attendees with an un-finalizable attendance.
 */
const SKED_EVALUATION_OPEN_STATUSES = ['confirmed', 'ongoing', 'completed', 'evaluation', 'closed'];

/** The same list as a quoted SQL fragment for IN () clauses. Literals only. */
function sked_evaluation_open_statuses_sql(): string
{
    return "'" . implode("','", SKED_EVALUATION_OPEN_STATUSES) . "'";
}

/**
 * The fixed set of Likert-scored evaluation criteria (1=Strongly Disagree ..
 * 5=Strongly Agree), grouped for display. Keys are stable identifiers stored
 * in event_evaluation_answers.question_key — reordering/relabeling here is
 * safe, but renaming or removing a key orphans any already-submitted answers
 * under it (same tradeoff sked_points_scheme() action keys already accept).
 *
 * @return array<string,array{group:string,text:string}>
 */
function sked_evaluation_criteria(): array
{
    return [
        'objectives_clear' => ['group' => 'Program Content', 'text' => "The event's objectives were clearly explained."],
        'content_relevant' => ['group' => 'Program Content', 'text' => 'The topics/activities were relevant to youth needs and interests.'],
        'learned_something' => ['group' => 'Program Content', 'text' => 'I learned something new or useful.'],
        'content_as_promoted' => ['group' => 'Program Content', 'text' => 'The content matched what was promoted/advertised.'],

        'well_organized' => ['group' => 'Organization & Logistics', 'text' => 'The event was well-organized overall.'],
        'started_ended_on_time' => ['group' => 'Organization & Logistics', 'text' => 'The event started and ended on time.'],
        'venue_adequate' => ['group' => 'Organization & Logistics', 'text' => 'The venue/facilities were adequate and comfortable.'],
        'registration_easy' => ['group' => 'Organization & Logistics', 'text' => 'Registration/check-in was quick and easy.'],

        'facilitators_knowledgeable' => ['group' => 'Facilitators & Delivery', 'text' => 'The speakers/facilitators were knowledgeable.'],
        'facilitators_engaging' => ['group' => 'Facilitators & Delivery', 'text' => 'The speakers/facilitators were engaging and easy to understand.'],
        'enough_participation' => ['group' => 'Facilitators & Delivery', 'text' => 'I had enough opportunity to participate or ask questions.'],

        'met_expectations' => ['group' => 'Impact & Satisfaction', 'text' => 'The event met my expectations.'],
        'more_active' => ['group' => 'Impact & Satisfaction', 'text' => 'This event will help me be more active in SK/community activities.'],
        'would_recommend' => ['group' => 'Impact & Satisfaction', 'text' => 'I would recommend this event to other youth.'],
        'overall_satisfied' => ['group' => 'Impact & Satisfaction', 'text' => 'Overall, I am satisfied with this event.'],
    ];
}

/** Display labels for the 1-5 Likert scale used by every evaluation criterion. */
function sked_evaluation_scale_labels(): array
{
    return [1 => 'Strongly Disagree', 2 => 'Disagree', 3 => 'Neutral', 4 => 'Agree', 5 => 'Strongly Agree'];
}

/** 32-char public share token for a promotable event link. */
function sked_generate_share_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Whether an event form included an image upload. */
function sked_event_image_upload_present(?array $file): bool
{
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** Absolute private upload folder for event images. */
function sked_event_image_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/events';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = __DIR__ . '/../uploads/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/**
 * Validate an uploaded event image.
 *
 * @return array{ok:bool,errors:array<int,string>,ext?:string,original_name?:string}
 */
function sked_validate_event_image(array $file): array
{
    if (!sked_event_image_upload_present($file)) {
        return ['ok' => true, 'errors' => []];
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Image upload failed. Please try again.']];
    }
    if ((int) ($file['size'] ?? 0) > SKED_EVENT_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'errors' => ['Event image is too large (max 5 MB).']];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'errors' => ['Invalid event image upload.']];
    }

    $originalName = (string) ($file['name'] ?? 'event-image');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, SKED_EVENT_IMAGE_ALLOWED_EXT, true)) {
        return ['ok' => false, 'errors' => ['Only JPG, PNG, or WebP event images are allowed.']];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array((string) $mime, $allowedMime, true)) {
        return ['ok' => false, 'errors' => ['That file does not look like a supported image.']];
    }

    return ['ok' => true, 'errors' => [], 'ext' => $ext, 'original_name' => mb_substr($originalName, 0, 255)];
}

/**
 * Store and attach a previously validated image to an event row.
 *
 * @param array{ext:string,original_name:string} $validated
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_store_event_image(int $eventId, int $actorId, array $file, array $validated): array
{
    $dir = sked_event_image_upload_dir();
    $filename = $eventId . '_' . bin2hex(random_bytes(16)) . '.' . $validated['ext'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
        return ['ok' => false, 'errors' => ['Could not save the event image.']];
    }

    try {
        $stmt = sked_db()->prepare(
            'UPDATE events
                SET image_file_path = :path,
                    image_file_original_name = :name,
                    image_uploaded_at = NOW(),
                    image_uploaded_by = :actor
              WHERE id = :id'
        );
        $stmt->execute([
            'path' => $filename,
            'name' => $validated['original_name'],
            'actor' => $actorId > 0 ? $actorId : null,
            'id' => $eventId,
        ]);
    } catch (Throwable $e) {
        if (is_file($path)) {
            unlink($path);
        }
        throw $e;
    }

    sked_audit($actorId > 0 ? $actorId : null, 'event_image_upload', 'event', $eventId, (string) $validated['original_name']);
    return ['ok' => true, 'errors' => []];
}

/** Absolute path for a stored event image, if present. */
function sked_event_image_path(array $event): ?string
{
    if (empty($event['image_file_path'])) {
        return null;
    }
    return sked_event_image_upload_dir() . '/' . basename((string) $event['image_file_path']);
}

/** Relative URL to the event image streamer, suitable for the current page. */
function sked_event_image_url(array $event, string $endpoint = '../public/event_image.php'): string
{
    if (empty($event['image_file_path']) || empty($event['id'])) {
        return '';
    }
    $version = !empty($event['image_uploaded_at']) ? strtotime((string) $event['image_uploaded_at']) : time();
    $token = !empty($event['share_token']) ? '&t=' . rawurlencode((string) $event['share_token']) : '';
    return $endpoint . '?id=' . (int) $event['id'] . $token . '&v=' . (int) $version;
}

/** Scopes an official role may create. */
function sked_allowed_scopes_for_role(string $role): array
{
    return match ($role) {
        'sk'   => ['barangay'],
        'ppsk' => ['interbarangay', 'municipal'],
        'dilg' => ['barangay', 'interbarangay', 'municipal'],
        default => [],
    };
}

/**
 * Create an event. $creator is the session-derived actor:
 *   ['id'=>int,'role'=>string,'name'=>string,'barangay_id'=>?int]
 *
 * @param array<string,mixed> $data
 * @return array{ok:bool,errors:array<int,string>,event_id?:int,share_token?:string}
 */
function sked_create_event(array $creator, array $data, ?array $imageFile = null): array
{
    $errors = [];
    $role = (string) ($creator['role'] ?? '');
    $allowedScopes = sked_allowed_scopes_for_role($role);

    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $category = trim((string) ($data['category'] ?? ''));
    $scope = (string) ($data['scope'] ?? '');
    $type = (string) ($data['type'] ?? 'interested');
    $isTeamSport = !empty($data['is_team_sport']) ? 1 : 0;
    $location = trim((string) ($data['location'] ?? ''));
    $eventDate = trim((string) ($data['event_date'] ?? ''));
    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime = trim((string) ($data['end_time'] ?? ''));
    $regDeadline = trim((string) ($data['registration_deadline'] ?? ''));
    $minParticipants = max(0, (int) ($data['min_participants'] ?? 0));
    $capacity = ($data['capacity'] ?? '') === '' ? null : max(0, (int) $data['capacity']);
    $publish = !empty($data['publish']);
    $targetBarangays = array_map('intval', (array) ($data['target_barangays'] ?? []));
    $imageValidation = ['ok' => true, 'errors' => []];

    // "For You" targeting (all optional) — validated against the real KK
    // Profiling vocab so a typo'd/forged value can never silently mismatch.
    $targetingOptions = sked_event_targeting_options();
    $targeting = [
        'classification' => array_values(array_intersect((array) ($data['target_classifications'] ?? []), $targetingOptions['classification'])),
        'specific_need' => array_values(array_intersect((array) ($data['target_specific_needs'] ?? []), $targetingOptions['specific_need'])),
        'sex' => array_values(array_intersect((array) ($data['target_sex'] ?? []), $targetingOptions['sex'])),
        'interest' => array_values(array_intersect((array) ($data['target_interests'] ?? []), $targetingOptions['interest'])),
    ];
    $hasAnyTargeting = array_sum(array_map('count', $targeting)) > 0;
    $targetingStrict = !empty($data['targeting_strict']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Event title is required.';
    }
    if (!in_array($scope, $allowedScopes, true)) {
        $errors[] = 'You are not allowed to create an event with that scope.';
    }
    if (!in_array($type, ['interested', 'register'], true)) {
        $errors[] = 'Invalid event type.';
    }

    // Resolve the barangay target(s) per scope.
    $barangayId = null;
    if ($scope === 'barangay') {
        $barangayId = $role === 'sk'
            ? (int) ($creator['barangay_id'] ?? 0)
            : (int) ($data['barangay_id'] ?? 0);
        if ($barangayId <= 0 || !sked_barangay_exists($barangayId)) {
            $errors[] = 'A valid barangay is required for a barangay-level event.';
        }
    } elseif ($scope === 'interbarangay') {
        $targetBarangays = array_values(array_filter($targetBarangays, 'sked_barangay_exists'));
        if (count($targetBarangays) < 2) {
            $errors[] = 'Select at least two barangays for an inter-barangay event.';
        }
    }

    if ($eventDate === '' || DateTime::createFromFormat('!Y-m-d', $eventDate) === false) {
        $errors[] = 'A valid event date is required.';
    }
    if ($regDeadline !== '' && DateTime::createFromFormat('!Y-m-d', $regDeadline) === false) {
        $errors[] = 'Invalid registration deadline.';
    }
    if ($eventDate !== '' && $regDeadline !== '' && $regDeadline > $eventDate) {
        $errors[] = 'Registration deadline cannot be after the event date.';
    }
    if ($type === 'register' && ($capacity === null || $capacity <= 0)) {
        $errors[] = 'Register-type events need a capacity (number of slots).';
    }
    if (sked_event_image_upload_present($imageFile)) {
        $imageValidation = sked_validate_event_image($imageFile);
        if (!$imageValidation['ok']) {
            $errors = array_merge($errors, $imageValidation['errors']);
        }
    }
    if ($targetingStrict && !$hasAnyTargeting) {
        $errors[] = 'Strictly apply requires at least one targeting option selected above.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $shareToken = sked_generate_share_token();
    $status = $publish ? 'published' : 'draft';

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO events
                (title, description, category, scope, barangay_id, type, is_team_sport,
                 location, event_date, start_time, end_time, registration_deadline,
                 min_participants, capacity, targeting_strict, share_token, status,
                 created_by, created_by_role, created_by_name)
             VALUES
                (:title, :description, :category, :scope, :barangay_id, :type, :team,
                 :location, :event_date, :start_time, :end_time, :reg_deadline,
                 :min_participants, :capacity, :targeting_strict, :share_token, :status,
                 :created_by, :created_by_role, :created_by_name)'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category' => $category !== '' ? $category : null,
            'scope' => $scope,
            'barangay_id' => $barangayId,
            'type' => $type,
            'team' => $isTeamSport,
            'location' => $location !== '' ? $location : null,
            'event_date' => $eventDate,
            'start_time' => $startTime !== '' ? $startTime : null,
            'end_time' => $endTime !== '' ? $endTime : null,
            'reg_deadline' => $regDeadline !== '' ? $regDeadline : null,
            'min_participants' => $minParticipants,
            'capacity' => $capacity,
            'targeting_strict' => $targetingStrict,
            'share_token' => $shareToken,
            'status' => $status,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
            'created_by_role' => $role !== '' ? $role : null,
            'created_by_name' => (string) ($creator['name'] ?? '') ?: null,
        ]);
        $eventId = (int) $pdo->lastInsertId();

        if ($scope === 'interbarangay') {
            $ins = $pdo->prepare('INSERT IGNORE INTO event_barangays (event_id, barangay_id) VALUES (:e, :b)');
            foreach ($targetBarangays as $b) {
                $ins->execute(['e' => $eventId, 'b' => $b]);
            }
        }

        $targetIns = $pdo->prepare('INSERT IGNORE INTO event_targeting (event_id, dimension, value) VALUES (:e, :d, :v)');
        foreach ($targeting as $dimension => $values) {
            foreach ($values as $value) {
                $targetIns->execute(['e' => $eventId, 'd' => $dimension, 'v' => $value]);
            }
        }

        if (sked_event_image_upload_present($imageFile)) {
            /** @var array{ok:bool,errors:array<int,string>,ext:string,original_name:string} $imageValidation */
            $upload = sked_store_event_image($eventId, (int) ($creator['id'] ?? 0), $imageFile, $imageValidation);
            if (!$upload['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => $upload['errors']];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_create_event failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not create the event. Please try again.']];
    }

    return ['ok' => true, 'errors' => [], 'event_id' => $eventId, 'share_token' => $shareToken];
}

/** Full event row + target barangay ids (for interbarangay). */
function sked_get_event(int $eventId): ?array
{
    if ($eventId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
    if ($event === false) {
        return null;
    }
    $bs = sked_db()->prepare('SELECT barangay_id FROM event_barangays WHERE event_id = :e');
    $bs->execute(['e' => $eventId]);
    $event['target_barangays'] = array_map(static fn($r) => (int) $r['barangay_id'], $bs->fetchAll());

    $targeting = array_fill_keys(SKED_EVENT_TARGET_DIMENSIONS, []);
    $ts = sked_db()->prepare('SELECT dimension, value FROM event_targeting WHERE event_id = :e');
    $ts->execute(['e' => $eventId]);
    foreach ($ts->fetchAll() as $row) {
        $targeting[(string) $row['dimension']][] = (string) $row['value'];
    }
    $event['targeting'] = $targeting;

    return $event;
}

/** Get an event by its public share token (management link target). */
function sked_get_event_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT id FROM events WHERE share_token = :t LIMIT 1');
    $stmt->execute(['t' => $token]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : sked_get_event((int) $id);
}

/** Participant tallies for an event, keyed by status (+ 'active' = joined/registered/attended). */
function sked_participant_counts(int $eventId): array
{
    $out = ['interested' => 0, 'pending' => 0, 'registered' => 0, 'attended' => 0, 'declined' => 0, 'cancelled' => 0, 'no_show' => 0, 'active' => 0];
    if ($eventId <= 0) {
        return $out;
    }
    $stmt = sked_db()->prepare('SELECT status, COUNT(*) n FROM event_participants WHERE event_id = :e GROUP BY status');
    $stmt->execute(['e' => $eventId]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($out[$r['status']])) {
            $out[$r['status']] = (int) $r['n'];
        }
    }
    $out['active'] = $out['interested'] + $out['registered'] + $out['attended'];
    return $out;
}

/**
 * Which of the three display buckets an event belongs to (Ongoing / Past /
 * Upcoming), used by every role's events page.
 *
 * Status leads, but the CALENDAR is the backstop. Status alone is not
 * enough in practice: officials routinely publish an event and never
 * advance it afterwards, and a purely status-driven rule would leave that
 * finished event sitting under "Upcoming" forever — the past would quietly
 * under-report. So a non-terminal event whose date has already gone by is
 * treated as past regardless, and one dated today is treated as ongoing.
 *
 * Drafts are never shown as "ongoing": an unpublished draft is not live to
 * anyone, so it stays upcoming until its date passes (then it is past, and
 * flagged by sked_event_needs_closeout() as something to tidy up).
 *
 * @param array{status:string,event_date?:?string} $event
 */
function sked_event_time_bucket(array $event): string
{
    $status = (string) ($event['status'] ?? '');

    // Terminal statuses are past no matter what the calendar says.
    if (in_array($status, ['completed', 'evaluation', 'closed', 'cancelled'], true)) {
        return 'past';
    }
    if ($status === 'ongoing') {
        return 'ongoing';
    }

    $date = trim((string) ($event['event_date'] ?? ''));
    if ($date === '') {
        return 'upcoming'; // date TBA — nothing to compare against
    }
    $today = date('Y-m-d');

    if ($status === 'draft') {
        return $date < $today ? 'past' : 'upcoming';
    }

    // published / confirmed
    if ($date < $today) {
        return 'past';
    }
    if ($date === $today) {
        return 'ongoing';
    }
    return 'upcoming';
}

/** Bootstrap badge color for an event's visible lifecycle label. */
function sked_event_display_badge_class(array $event): string
{
    if (sked_event_time_bucket($event) === 'ongoing'
        && in_array((string) ($event['status'] ?? ''), ['published', 'confirmed', 'ongoing'], true)) {
        return 'info';
    }

    return [
        'draft' => 'secondary',
        'published' => 'primary',
        'confirmed' => 'info',
        'ongoing' => 'info',
        'completed' => 'success',
        'cancelled' => 'danger',
        'evaluation' => 'warning',
        'closed' => 'dark',
    ][(string) ($event['status'] ?? '')] ?? 'secondary';
}

/** Human label for event listings; today/live events appear as Ongoing. */
function sked_event_display_status_label(array $event): string
{
    if (sked_event_time_bucket($event) === 'ongoing'
        && in_array((string) ($event['status'] ?? ''), ['published', 'confirmed', 'ongoing'], true)) {
        return 'Ongoing';
    }

    return ucfirst(str_replace('_', ' ', (string) ($event['status'] ?? 'draft')));
}

/**
 * True when an event has effectively finished (it is in the past bucket)
 * but its status was never advanced out of draft/published/confirmed —
 * i.e. the official still needs to close it out. Surfaced as a hint on the
 * officials' Past Events tables so the backlog is visible instead of
 * silently rotting.
 */
function sked_event_needs_closeout(array $event): bool
{
    return sked_event_time_bucket($event) === 'past'
        && in_array((string) ($event['status'] ?? ''), ['draft', 'published', 'confirmed'], true);
}

/** Events an official manages, newest first. */
function sked_events_for_manager(string $role, int $userId, ?int $barangayId): array
{
    $pdo = sked_db();
    if ($role === 'sk') {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE scope = 'barangay' AND barangay_id = :b ORDER BY created_at DESC");
        $stmt->execute(['b' => (int) $barangayId]);
    } elseif ($role === 'ppsk') {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE scope IN ('interbarangay','municipal') ORDER BY created_at DESC");
        $stmt->execute();
    } else { // dilg: everything
        $stmt = $pdo->query('SELECT * FROM events ORDER BY created_at DESC');
    }
    return $stmt->fetchAll();
}

/**
 * "For You" match map for a set of event ids against one youth's own KK
 * Profiling values (classification/specific_need/interest/sex). ANY
 * dimension match is enough. $youthId <= 0 (no profile to compare, e.g. an
 * anonymous landing-page visitor) matches nothing, same as a youth with a
 * completely empty profile.
 *
 * @param array<int,int> $eventIds
 * @return array<int,bool> event_id => is_for_you
 */
function sked_events_for_you_map(array $eventIds, int $youthId): array
{
    $map = array_fill_keys($eventIds, false);
    if (empty($eventIds) || $youthId <= 0) {
        return $map;
    }

    $pdo = sked_db();
    $ownValues = [
        'classification' => sked_profile_child_values($youthId, 'youth_classifications', 'classification'),
        'specific_need' => sked_profile_child_values($youthId, 'youth_specific_needs', 'need_type'),
        'interest' => sked_profile_child_values($youthId, 'youth_interests', 'category'),
    ];
    $sexStmt = $pdo->prepare('SELECT sex_assigned_at_birth FROM users WHERE id = :id LIMIT 1');
    $sexStmt->execute(['id' => $youthId]);
    $ownSex = (string) ($sexStmt->fetchColumn() ?: '');
    $ownValues['sex'] = $ownSex !== '' ? [$ownSex] : [];

    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $ts = $pdo->prepare("SELECT event_id, dimension, value FROM event_targeting WHERE event_id IN ($in)");
    $ts->execute($eventIds);
    $targetingByEvent = [];
    foreach ($ts->fetchAll() as $r) {
        $targetingByEvent[(int) $r['event_id']][(string) $r['dimension']][] = (string) $r['value'];
    }

    foreach ($eventIds as $id) {
        $eventTargeting = $targetingByEvent[$id] ?? [];
        foreach ($eventTargeting as $dimension => $values) {
            if (array_intersect($values, $ownValues[$dimension] ?? []) !== []) {
                $map[$id] = true;
                break;
            }
        }
    }
    return $map;
}

/**
 * Published events a youth in $barangayId is eligible to see, with their own
 * participation status attached. Newest event date first. Events flagged
 * `targeting_strict` are dropped entirely for a youth who doesn't match any
 * of their targeting dimensions — a real visibility filter, unlike the
 * default "For You" behavior (badge + sort boost, nobody excluded).
 */
function sked_events_for_youth(int $youthId, int $barangayId): array
{
    $pdo = sked_db();
    $stmt = $pdo->prepare(
        "SELECT e.* FROM events e
          WHERE e.status IN ('published','confirmed','ongoing')
            AND (
                 (e.scope = 'municipal')
              OR (e.scope = 'barangay' AND e.barangay_id = :b1)
              OR (e.scope = 'interbarangay' AND EXISTS (
                    SELECT 1 FROM event_barangays eb WHERE eb.event_id = e.id AND eb.barangay_id = :b2))
            )
          ORDER BY e.event_date ASC, e.id DESC"
    );
    $stmt->execute(['b1' => $barangayId, 'b2' => $barangayId]);
    $events = $stmt->fetchAll();

    if (!empty($events)) {
        $ids = array_column($events, 'id');
        $mine = [];
        if ($youthId > 0) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ps = $pdo->prepare("SELECT event_id, status FROM event_participants WHERE user_id = ? AND event_id IN ($in)");
            $ps->execute(array_merge([$youthId], $ids));
            foreach ($ps->fetchAll() as $r) {
                $mine[(int) $r['event_id']] = $r['status'];
            }
        }

        $forYouMap = sked_events_for_you_map($ids, $youthId);
        foreach ($events as &$e) {
            $e['my_status'] = $mine[(int) $e['id']] ?? null;
            $e['is_for_you'] = $forYouMap[(int) $e['id']] ?? false;
        }
        unset($e);

        $events = array_values(array_filter($events, static fn ($e) =>
            !((int) ($e['targeting_strict'] ?? 0) === 1 && empty($e['is_for_you']))
        ));
    }
    return $events;
}

/**
 * Public landing-page feed: real announcements (includes/announcements.php)
 * merged with public events, pinned-first then newest-first. Each row is
 * tagged 'feed_type' => 'announcement'|'event' so the caller can render them
 * distinctly. Requires includes/announcements.php (require_once'd here to
 * avoid making every existing caller of this function add that require
 * themselves — mutual require_once with events.php is safe since neither
 * file calls the other's functions at top level, only inside function
 * bodies called after both are loaded).
 *
 * $viewerBarangayId null => anonymous/no-barangay viewer: municipal-scope
 * items only (or municipal + every inter-barangay item when
 * $includeAllInterbarangay is set — for PPSK, whose scope is federation-wide
 * rather than tied to one barangay, see sked_allowed_scopes_for_role()). A
 * real barangay id => same eligibility rule as sked_events_for_youth
 * (municipal + own barangay + inter-barangay that includes that barangay)
 * — e.g. a barangay-scoped post from Acevida's SK is only returned when
 * $viewerBarangayId is Acevida's id. Used for both youth and SK viewers
 * (SK passes their own session barangay_id, same as youth).
 */
function sked_public_announcements(?int $viewerBarangayId, int $limit = 6, bool $includeAllInterbarangay = false, ?int $viewerYouthId = null): array
{
    require_once __DIR__ . '/announcements.php';

    $pdo = sked_db();
    $limit = max(1, $limit);

    if ($viewerBarangayId === null || $viewerBarangayId <= 0) {
        $scopeSql = $includeAllInterbarangay ? "e.scope IN ('municipal','interbarangay')" : "e.scope = 'municipal'";
        $stmt = $pdo->prepare(
            "SELECT e.* FROM events e
              WHERE e.status IN ('published','confirmed','ongoing') AND {$scopeSql}
              ORDER BY e.event_date ASC, e.id DESC"
        );
        $stmt->execute();
        $events = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT e.* FROM events e
              WHERE e.status IN ('published','confirmed','ongoing')
                AND (
                     (e.scope = 'municipal')
                  OR (e.scope = 'barangay' AND e.barangay_id = :b1)
                  OR (e.scope = 'interbarangay' AND EXISTS (
                        SELECT 1 FROM event_barangays eb WHERE eb.event_id = e.id AND eb.barangay_id = :b2))
                )
              ORDER BY e.event_date ASC, e.id DESC"
        );
        $stmt->execute(['b1' => $viewerBarangayId, 'b2' => $viewerBarangayId]);
        $events = $stmt->fetchAll();
    }

    // "For You" tagging + strict-targeting visibility filter, same rule as
    // sked_events_for_youth(): a non-youth/anonymous viewer ($viewerYouthId
    // null) matches nothing, so a strictly-targeted event never appears on
    // the public feed for them either.
    if (!empty($events)) {
        $forYouMap = sked_events_for_you_map(array_column($events, 'id'), (int) $viewerYouthId);
        foreach ($events as &$e) {
            $e['is_for_you'] = $forYouMap[(int) $e['id']] ?? false;
        }
        unset($e);
        $events = array_values(array_filter($events, static fn ($e) =>
            !((int) ($e['targeting_strict'] ?? 0) === 1 && empty($e['is_for_you']))
        ));
    }

    foreach ($events as &$e) {
        $e['feed_type'] = 'event';
        $e['pinned'] = 0;
        $e['feed_sort_at'] = (string) $e['created_at'];
    }
    unset($e);

    $announcements = sked_published_announcements_for_feed($viewerBarangayId, $limit, $includeAllInterbarangay);
    foreach ($announcements as &$a) {
        $a['feed_type'] = 'announcement';
        $a['feed_sort_at'] = (string) $a['created_at'];
        $a['is_for_you'] = false; // personalization only ever applies to events, not announcements
    }
    unset($a);

    // "For You" outranks everything else, including Pinned — a personalized
    // match is more relevant to THIS viewer than a generically-important
    // pinned notice. Pinned still breaks ties within each of those two
    // groups, then newest first.
    $merged = array_merge($announcements, $events);
    usort($merged, static function ($a, $b) {
        $forYouCmp = (int) !empty($b['is_for_you']) <=> (int) !empty($a['is_for_you']);
        if ($forYouCmp !== 0) {
            return $forYouCmp;
        }
        $pinCmp = (int) $b['pinned'] <=> (int) $a['pinned'];
        return $pinCmp !== 0 ? $pinCmp : strcmp((string) $b['feed_sort_at'], (string) $a['feed_sort_at']);
    });

    return array_slice($merged, 0, $limit);
}

/** A youth's own participations (joined events), newest event date first. */
function sked_youth_participations(int $youthId): array
{
    if ($youthId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare(
        'SELECT e.*, p.status AS my_status, p.team_name, p.joined_at
           FROM event_participants p JOIN events e ON e.id = p.event_id
          WHERE p.user_id = :u AND p.status <> \'cancelled\'
          ORDER BY e.event_date ASC, e.id DESC'
    );
    $stmt->execute(['u' => $youthId]);
    return $stmt->fetchAll();
}

/** Events a youth attended that are open for evaluation and not yet evaluated by them. */
function sked_youth_evaluable_events(int $youthId): array
{
    if ($youthId <= 0) {
        return [];
    }
    $statuses = sked_evaluation_open_statuses_sql();
    $stmt = sked_db()->prepare(
        "SELECT e.id, e.title, e.event_date, ev.rating AS my_rating, ev.comments AS my_comments
           FROM event_participants p
           JOIN events e ON e.id = p.event_id
      LEFT JOIN event_evaluations ev ON ev.event_id = e.id AND ev.user_id = p.user_id
          WHERE p.user_id = :u AND p.status = 'attended'
            AND e.status IN ($statuses)
          ORDER BY e.event_date DESC"
    );
    $stmt->execute(['u' => $youthId]);
    return $stmt->fetchAll();
}

/** Is a youth in $barangayId within the event's eligibility scope? */
function sked_event_in_scope_for_barangay(array $event, int $barangayId): bool
{
    switch ($event['scope']) {
        case 'municipal':
            return true;
        case 'barangay':
            return (int) $event['barangay_id'] === $barangayId;
        case 'interbarangay':
            $targets = $event['target_barangays'] ?? array_map(
                static fn($r) => (int) $r['barangay_id'],
                (function (int $eid) {
                    $s = sked_db()->prepare('SELECT barangay_id FROM event_barangays WHERE event_id = :e');
                    $s->execute(['e' => $eid]);
                    return $s->fetchAll();
                })((int) $event['id'])
            );
            return in_array($barangayId, array_map('intval', $targets), true);
        default:
            return false;
    }
}

/** Whether an event is currently open for youth to join/register. */
function sked_event_is_open(array $event): bool
{
    if (!in_array($event['status'], ['published', 'confirmed', 'ongoing'], true)) {
        return false;
    }
    if (!empty($event['registration_deadline']) && $event['registration_deadline'] < date('Y-m-d')) {
        return false;
    }
    return true;
}

/**
 * Youth joins (interested) or registers for an event. Enforces verified +
 * in-scope + open. Team sports: the team_name is recorded; scope/verified
 * is enforced per registrant.
 *
 * Register-type events land in 'pending' — a pre-registration request only,
 * not yet confirmed — since the SK/PPSK office still needs to settle fees
 * or verify documents in person. Capacity is intentionally NOT enforced
 * here (a pending queue can run ahead of capacity); it's checked as a
 * strong-but-non-blocking warning when the office accepts, in
 * sked_accept_registration(). Points for register-type also move to that
 * acceptance step, since 'pending' isn't a confirmed signup yet.
 * Interested-type "Join" events are unaffected: instant, points immediately.
 *
 * @return array{ok:bool,error?:string,pending?:bool}
 */
function sked_join_event(int $youthId, int $youthBarangayId, int $eventId, string $teamName = ''): array
{
    $event = sked_get_event($eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }
    if (!sked_event_is_open($event)) {
        return ['ok' => false, 'error' => 'This event is not open for registration.'];
    }
    if (!sked_event_in_scope_for_barangay($event, $youthBarangayId)) {
        return ['ok' => false, 'error' => 'This event is not available to your barangay.'];
    }

    $teamName = trim($teamName);
    if ((int) $event['is_team_sport'] === 1 && $teamName === '') {
        return ['ok' => false, 'error' => 'This is a team event — please enter your team name.'];
    }

    // Already joined?
    $existing = sked_db()->prepare('SELECT status FROM event_participants WHERE event_id = :e AND user_id = :u LIMIT 1');
    $existing->execute(['e' => $eventId, 'u' => $youthId]);
    $cur = $existing->fetchColumn();
    if ($cur !== false && !in_array($cur, ['cancelled', 'declined'], true)) {
        return ['ok' => false, 'error' => 'You have already signed up for this event.'];
    }

    $isRegisterType = $event['type'] === 'register';
    $participantStatus = $isRegisterType ? 'pending' : 'interested';

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        // Re-activate a prior cancellation/decline, or insert fresh.
        if ($cur !== false) {
            $up = $pdo->prepare(
                'UPDATE event_participants SET status = :s, team_name = :t, joined_at = NOW()
                  WHERE event_id = :e AND user_id = :u'
            );
            $up->execute(['s' => $participantStatus, 't' => $teamName !== '' ? $teamName : null, 'e' => $eventId, 'u' => $youthId]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO event_participants (event_id, user_id, status, team_name)
                 VALUES (:e, :u, :s, :t)'
            );
            $ins->execute(['e' => $eventId, 'u' => $youthId, 's' => $participantStatus, 't' => $teamName !== '' ? $teamName : null]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('sked_join_event failed: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'Could not sign you up. Please try again.'];
    }

    if (!$isRegisterType) {
        // Points for joining (once per event) — register-type earns this on acceptance instead.
        sked_award_points($youthId, 'event_joined', 'event', $eventId);
    }

    return ['ok' => true, 'pending' => $isRegisterType];
}

/** Youth cancels their own participation (including a still-pending registration request). */
function sked_cancel_participation(int $youthId, int $eventId): array
{
    $stmt = sked_db()->prepare(
        "UPDATE event_participants SET status = 'cancelled'
          WHERE event_id = :e AND user_id = :u AND status IN ('interested','pending','registered')"
    );
    $stmt->execute(['e' => $eventId, 'u' => $youthId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Nothing to cancel for this event.'];
    }
    return ['ok' => true];
}

/**
 * SK/PPSK accepts a pending registration request, confirming the slot.
 * Awards the join points (deferred from sked_join_event() for register-type
 * signups) and notifies the youth with the "finalize at the office"
 * advisory. Capacity is a strong warning only — accepting still succeeds
 * even if it pushes the event over its expected capacity; the caller
 * decides whether to surface that (exceeds_capacity) as a confirmation
 * prompt before submitting and/or a flash after.
 *
 * @return array{ok:bool,error?:string,exceeds_capacity?:bool,confirmed_count?:int,capacity?:?int}
 */
function sked_accept_registration(int $actorId, int $eventId, int $participantUserId): array
{
    $event = sked_get_event($eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $stmt = sked_db()->prepare(
        "UPDATE event_participants SET status = 'registered'
          WHERE event_id = :e AND user_id = :u AND status = 'pending'"
    );
    $stmt->execute(['e' => $eventId, 'u' => $participantUserId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'This registration is no longer pending.'];
    }

    sked_award_points($participantUserId, 'event_joined', 'event', $eventId);
    sked_audit($actorId, 'registration_accepted', 'event', $eventId, 'user #' . $participantUserId);
    sked_notify(
        $participantUserId,
        'event',
        'Registration confirmed: ' . $event['title'],
        'Your registration for "' . $event['title'] . '" has been confirmed. Please proceed to your SK/PPSK office to finalize it (e.g. settlement of entrance fee/payment, submission of documents).',
        '../youth/events.php'
    );

    $counts = sked_participant_counts($eventId);
    $capacity = $event['capacity'] !== null ? (int) $event['capacity'] : null;
    $confirmedCount = $counts['registered'] + $counts['attended'];

    return [
        'ok' => true,
        'exceeds_capacity' => $capacity !== null && $confirmedCount > $capacity,
        'confirmed_count' => $confirmedCount,
        'capacity' => $capacity,
    ];
}

/** SK/PPSK declines a pending registration request. */
function sked_decline_registration(int $actorId, int $eventId, int $participantUserId): array
{
    $event = sked_get_event($eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $stmt = sked_db()->prepare(
        "UPDATE event_participants SET status = 'declined'
          WHERE event_id = :e AND user_id = :u AND status = 'pending'"
    );
    $stmt->execute(['e' => $eventId, 'u' => $participantUserId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'This registration is no longer pending.'];
    }

    sked_audit($actorId, 'registration_declined', 'event', $eventId, 'user #' . $participantUserId);
    sked_notify(
        $participantUserId,
        'event',
        'Registration not approved: ' . $event['title'],
        'Your registration for "' . $event['title'] . '" was not approved. Please contact your SK/PPSK office for details.',
        '../youth/events.php'
    );

    return ['ok' => true];
}

/**
 * Every active (non-cancelled) participant across every event a manager
 * oversees, flattened into one list, newest event first. SK is
 * barangay-scoped; PPSK sees interbarangay/municipal events; DILG sees all.
 * Feeds the consolidated "Participants" table on the Manage Events page —
 * per-event roster + attendance actions still live on pages/manage/event.php.
 */
function sked_participants_for_manager(string $role, ?int $barangayId): array
{
    $where = '1=1';
    $params = [];
    if ($role === 'sk') {
        $where = "e.scope = 'barangay' AND e.barangay_id = :bgy";
        $params['bgy'] = $barangayId;
    } elseif ($role === 'ppsk') {
        $where = "e.scope IN ('interbarangay', 'municipal')";
    }

    $stmt = sked_db()->prepare(
        "SELECT e.id AS event_id, e.title AS event_title, e.event_date, e.is_team_sport,
                p.id AS participant_id, p.user_id, p.status, p.team_name, p.joined_at, p.attended_at,
                u.name, u.username
           FROM event_participants p
           JOIN events e ON e.id = p.event_id
           JOIN users u ON u.id = p.user_id
          WHERE $where AND p.status <> 'cancelled'
          ORDER BY e.event_date DESC, e.id DESC, p.joined_at ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Roster for an event (for the managing official). */
function sked_event_roster(int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare(
        'SELECT p.id, p.user_id, p.status, p.team_name, p.joined_at, p.attended_at,
                u.name, u.username, u.barangay_id
           FROM event_participants p JOIN users u ON u.id = p.user_id
          WHERE p.event_id = :e
          ORDER BY p.team_name IS NULL, p.team_name, u.name'
    );
    $stmt->execute(['e' => $eventId]);
    return $stmt->fetchAll();
}

/* ============================================================
 * Management: authorization, lifecycle, attendance, evaluations
 * ============================================================ */

/** Can this official manage this event? SK: own-barangay events; PPSK: fed events; DILG: all. */
function sked_can_manage_event(string $role, ?int $barangayId, array $event): bool
{
    return match ($role) {
        'dilg' => true,
        'ppsk' => in_array($event['scope'], ['interbarangay', 'municipal'], true),
        'sk'   => $event['scope'] === 'barangay' && (int) $event['barangay_id'] === (int) $barangayId,
        default => false,
    };
}

/** Allowed lifecycle transitions (kept intentionally simple/linear + cancel). */
function sked_event_next_statuses(string $current): array
{
    return match ($current) {
        'draft'      => ['published', 'cancelled'],
        'published'  => ['confirmed', 'ongoing', 'cancelled'],
        'confirmed'  => ['ongoing', 'cancelled'],
        'ongoing'    => ['completed', 'cancelled'],
        'completed'  => ['evaluation', 'closed'],
        'evaluation' => ['closed'],
        default      => [],
    };
}

/** Move an event to a new status if the transition is allowed. */
function sked_set_event_status(int $actorId, array $event, string $newStatus): array
{
    $eventId = (int) $event['id'];
    if (!in_array($newStatus, sked_event_next_statuses((string) $event['status']), true)) {
        return ['ok' => false, 'error' => 'That status change is not allowed from “' . $event['status'] . '”.'];
    }
    sked_db()->prepare('UPDATE events SET status = :s WHERE id = :id')
        ->execute(['s' => $newStatus, 'id' => $eventId]);
    sked_audit($actorId, 'event_status_' . $newStatus, 'event', $eventId, (string) $event['title']);

    // Notify participants on cancellation / evaluation opening.
    if ($newStatus === 'cancelled') {
        sked_notify_event_participants($eventId, 'event',
            'Event cancelled: ' . $event['title'],
            'The event “' . $event['title'] . '” has been cancelled by the organizers.');
    } elseif ($newStatus === 'evaluation') {
        sked_notify_event_participants($eventId, 'event',
            'Share your feedback: ' . $event['title'],
            'The event “' . $event['title'] . '” is now open for evaluation. Submit yours to earn points.',
            '../youth/events.php', true);
    }
    return ['ok' => true];
}

/** Notify an event's participants. $attendedOnly limits to those marked attended. */
function sked_notify_event_participants(int $eventId, string $type, string $title, string $message, ?string $link = null, bool $attendedOnly = false): void
{
    $sql = "SELECT user_id FROM event_participants WHERE event_id = :e AND status IN ("
         . ($attendedOnly ? "'attended'" : "'interested','registered','attended'") . ")";
    $stmt = sked_db()->prepare($sql);
    $stmt->execute(['e' => $eventId]);
    foreach ($stmt->fetchAll() as $r) {
        sked_notify((int) $r['user_id'], $type, $title, $message, $link);
    }
}

/** Mark one participant present/absent. Present awards attendance points (once). */
function sked_mark_attendance(int $actorId, int $eventId, int $userId, bool $present): array
{
    $stmt = sked_db()->prepare(
        'UPDATE event_participants
            SET status = :s, attended_at = ' . ($present ? 'NOW()' : 'NULL') . '
          WHERE event_id = :e AND user_id = :u'
    );
    $stmt->execute(['s' => $present ? 'attended' : 'no_show', 'e' => $eventId, 'u' => $userId]);
    if ($stmt->rowCount() === 0 && $stmt->errorCode() !== '00000') {
        return ['ok' => false, 'error' => 'Could not update attendance.'];
    }
    if ($present) {
        sked_award_points($userId, 'event_attended', 'event', $eventId);
    }
    return ['ok' => true];
}

/**
 * Youth submits a post-event evaluation. Awards points once.
 *
 * 'ongoing' is accepted alongside the finished statuses because QR
 * attendance (P19) marks youth present while the event is still running,
 * and the evaluation is what finalizes that attendance — so it has to be
 * submittable there and then, not only after the event is closed out.
 */
function sked_submit_evaluation(int $youthId, int $eventId, array $answers, string $comments = ''): array
{
    $event = sked_get_event($eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }
    if (!in_array($event['status'], SKED_EVALUATION_OPEN_STATUSES, true)) {
        return ['ok' => false, 'error' => 'Evaluation is not open for this event yet.'];
    }
    $chk = sked_db()->prepare("SELECT 1 FROM event_participants WHERE event_id = :e AND user_id = :u AND status = 'attended' LIMIT 1");
    $chk->execute(['e' => $eventId, 'u' => $youthId]);
    if ($chk->fetchColumn() === false) {
        return ['ok' => false, 'error' => 'Only attendees can evaluate this event.'];
    }

    $criteria = sked_evaluation_criteria();
    $clamped = [];
    foreach (array_keys($criteria) as $key) {
        if (!isset($answers[$key]) || (int) $answers[$key] < 1) {
            return ['ok' => false, 'error' => 'Please answer every question.'];
        }
        $clamped[$key] = max(1, min(5, (int) $answers[$key]));
    }
    $avg = round(array_sum($clamped) / count($clamped), 2);
    $comments = trim($comments);

    $pdo = sked_db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO event_evaluations (event_id, user_id, rating, comments)
             VALUES (:e, :u, :r, :c)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comments = VALUES(comments), id = LAST_INSERT_ID(id)'
        );
        $stmt->execute(['e' => $eventId, 'u' => $youthId, 'r' => $avg, 'c' => $comments !== '' ? $comments : null]);
        $evaluationId = (int) $pdo->lastInsertId();

        $ansStmt = $pdo->prepare(
            'INSERT INTO event_evaluation_answers (evaluation_id, question_key, answer_value)
             VALUES (:eval, :k, :v)
             ON DUPLICATE KEY UPDATE answer_value = VALUES(answer_value)'
        );
        foreach ($clamped as $key => $value) {
            $ansStmt->execute(['eval' => $evaluationId, 'k' => $key, 'v' => $value]);
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('sked_submit_evaluation failed: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'Could not save your evaluation.'];
    }

    sked_award_points($youthId, 'evaluation_submitted', 'event', $eventId);
    return ['ok' => true];
}

/** Average rating + count for an event (feeds success rate / P6). */
function sked_event_rating(int $eventId): array
{
    $stmt = sked_db()->prepare('SELECT COUNT(*) n, AVG(rating) avg FROM event_evaluations WHERE event_id = :e');
    $stmt->execute(['e' => $eventId]);
    $row = $stmt->fetch();
    return ['count' => (int) ($row['n'] ?? 0), 'avg' => $row['avg'] !== null ? round((float) $row['avg'], 2) : null];
}

/**
 * Per-criterion average score for an event, in sked_evaluation_criteria()
 * order, for the officer-facing evaluation breakdown. Criteria with zero
 * answers so far (e.g. no evaluations yet, or evaluations submitted before
 * this per-criterion table existed) are still listed with avg=null so the
 * UI can render a "no data" state rather than silently omitting a row.
 */
function sked_event_evaluation_breakdown(int $eventId): array
{
    $stmt = sked_db()->prepare(
        'SELECT a.question_key, AVG(a.answer_value) avg, COUNT(*) n
           FROM event_evaluation_answers a
           JOIN event_evaluations ev ON ev.id = a.evaluation_id
          WHERE ev.event_id = :e
          GROUP BY a.question_key'
    );
    $stmt->execute(['e' => $eventId]);
    $scores = [];
    foreach ($stmt->fetchAll() as $row) {
        $scores[(string) $row['question_key']] = ['avg' => round((float) $row['avg'], 2), 'n' => (int) $row['n']];
    }

    $out = [];
    foreach (sked_evaluation_criteria() as $key => $c) {
        $out[] = [
            'key' => $key,
            'group' => $c['group'],
            'text' => $c['text'],
            'avg' => $scores[$key]['avg'] ?? null,
            'n' => $scores[$key]['n'] ?? 0,
        ];
    }
    return $out;
}

/**
 * Scheduled maintenance (cron): auto-cancel under-subscribed events past their
 * registration deadline, and send day-before reminders. Returns a summary.
 */
function sked_run_event_maintenance(): array
{
    $pdo = sked_db();
    $today = date('Y-m-d');
    $cancelled = 0;
    $reminded = 0;

    // 1. Auto-cancel: published events whose reg deadline has passed and that
    //    didn't reach min_participants.
    $stmt = $pdo->query(
        "SELECT id, title, min_participants FROM events
          WHERE status = 'published' AND registration_deadline IS NOT NULL
            AND registration_deadline < '" . $today . "' AND min_participants > 0"
    );
    foreach ($stmt->fetchAll() as $ev) {
        $counts = sked_participant_counts((int) $ev['id']);
        if ($counts['active'] < (int) $ev['min_participants']) {
            $pdo->prepare("UPDATE events SET status = 'cancelled' WHERE id = :id")->execute(['id' => $ev['id']]);
            sked_audit(null, 'event_auto_cancelled', 'event', (int) $ev['id'],
                'Min ' . $ev['min_participants'] . ', had ' . $counts['active']);
            sked_notify_event_participants((int) $ev['id'], 'event',
                'Event cancelled: ' . $ev['title'],
                'The event “' . $ev['title'] . '” did not reach the minimum participants by the deadline and has been cancelled.');
            $cancelled++;
        }
    }

    // 2. Reminders: events happening tomorrow that are still active.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt = $pdo->prepare(
        "SELECT id, title FROM events
          WHERE event_date = :d AND status IN ('published','confirmed','ongoing')"
    );
    $stmt->execute(['d' => $tomorrow]);
    foreach ($stmt->fetchAll() as $ev) {
        sked_notify_event_participants((int) $ev['id'], 'reminder',
            'Reminder: ' . $ev['title'] . ' is tomorrow',
            'Don’t forget — “' . $ev['title'] . '” takes place tomorrow. See you there!');
        $reminded++;
    }

    return ['cancelled' => $cancelled, 'reminded' => $reminded];
}
