<?php
/**
 * Demo authentication + role-routing helpers for the SKed skeleton.
 *
 * IMPORTANT (skeleton pass): There is no database and no password checking
 * here. Authentication is a simple demo: the usernames "1", "2", "3", "4"
 * map to the four roles so each role's dashboard can be reached and reviewed.
 * Real credential verification, account status, throttling, and audit logging
 * will replace this file in a later functional pass. Callers live one
 * directory below pages/, so routes use "../".
 *
 * Roles:
 *   dilg  -> DILG (Superadmin)            username 1
 *   ppsk  -> PPSK Federation President    username 2
 *   sk    -> SK Chairman (barangay)       username 3
 *   youth -> Youth / Community            username 4
 */

require_once __DIR__ . '/barangays.php';

/** KK eligibility bounds (RA 10742), inclusive. */
const SKED_MIN_AGE = 15;
const SKED_MAX_AGE = 30;

/**
 * Age in whole years from a YYYY-MM-DD birthdate as of today, or null if
 * the string isn't a valid calendar date.
 */
function sked_age_from_birthdate(string $birthdate): ?int
{
    $birthdate = trim($birthdate);
    $d = DateTime::createFromFormat('!Y-m-d', $birthdate);
    $errors = DateTime::getLastErrors();
    if ($d === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        return null;
    }
    if ($d > new DateTime('today')) {
        return null; // future date
    }
    return (int) $d->diff(new DateTime('today'))->y;
}

/**
 * The demo credential table. No passwords by design in this pass.
 *
 * @return array<string,array{role:string,name:string}>
 */
function sked_demo_users(): array
{
    // barangay_id gives the demo SK/Youth a concrete barangay (Acevida = 1)
    // so barangay-scoped features (e.g. youth verification) are demonstrable.
    // The youth demo account also carries split-name/sex/contact fields so
    // the KK Profiling form's autofill-and-lock preview has something to show.
    return [
        '1' => ['role' => 'dilg',  'name' => 'DILG Administrator', 'barangay_id' => null],
        '2' => ['role' => 'ppsk',  'name' => 'Pederasyon President', 'barangay_id' => null],
        '3' => ['role' => 'sk',    'name' => 'SK Chairman', 'barangay_id' => 1],
        '4' => [
            'role' => 'youth', 'name' => 'Juan dela Cruz', 'barangay_id' => 1,
            'surname' => 'Dela Cruz', 'given_name' => 'Juan', 'middle_name' => 'Reyes',
            'sex_assigned_at_birth' => 'male', 'email' => 'juan.delacruz@demo.sked',
            'mobile' => '0912-345-6789', 'birthdate' => '2003-05-14', 'purok' => 'Purok 1',
        ],
    ];
}

/**
 * Identity fields shown read-only ("autofill + lock") on the KK Profiling
 * form: name parts, sex assigned at birth, email, mobile, birthdate, and
 * barangay — all collected once at registration (or the demo seed) and
 * governed the same way as pages/account/settings.php's read-only fields.
 * Returns null only if the user id can't be resolved at all.
 *
 * @return array{name:string,surname:?string,given_name:?string,middle_name:?string,sex_assigned_at_birth:?string,email:?string,mobile:?string,birthdate:?string,barangay_id:?int,purok:?string}|null
 */
function sked_user_identity(int $userId): ?array
{
    if ($userId > 0 && $userId < 1000) {
        $demo = sked_demo_users()[(string) $userId] ?? null;
        if ($demo === null) {
            return null;
        }
        return [
            'name' => (string) $demo['name'],
            'surname' => $demo['surname'] ?? null,
            'given_name' => $demo['given_name'] ?? null,
            'middle_name' => $demo['middle_name'] ?? null,
            'sex_assigned_at_birth' => $demo['sex_assigned_at_birth'] ?? null,
            'email' => $demo['email'] ?? null,
            'mobile' => $demo['mobile'] ?? null,
            'birthdate' => $demo['birthdate'] ?? null,
            'barangay_id' => $demo['barangay_id'] ?? null,
            'purok' => $demo['purok'] ?? null,
        ];
    }

    $u = sked_find_user_by_id($userId);
    if ($u === null) {
        return null;
    }
    return [
        'name' => (string) $u['name'],
        'surname' => $u['surname'] ?? null,
        'given_name' => $u['given_name'] ?? null,
        'middle_name' => $u['middle_name'] ?? null,
        'sex_assigned_at_birth' => $u['sex_assigned_at_birth'] ?? null,
        'email' => $u['email'] ?? null,
        'mobile' => $u['mobile'] ?? null,
        'birthdate' => $u['birthdate'] ?? null,
        'barangay_id' => $u['barangay_id'] !== null ? (int) $u['barangay_id'] : null,
        'purok' => $u['purok'] ?? null,
    ];
}

/** All valid role keys in the system. */
function sked_roles(): array
{
    return ['dilg', 'ppsk', 'sk', 'youth'];
}

/**
 * ------------------------------------------------------------
 * Community self-registration (youth accounts only).
 *
 * Registered accounts are persisted in the `users` table of the MySQL
 * `sked_db` database (see config/database.php, config/schema.sql) via
 * sked_db(). Demo usernames "1"-"4" never touch this table — they stay
 * the hardcoded, no-DB shortcut in sked_demo_users()/sked_demo_login().
 *
 * New accounts start UNVERIFIED: they can sign in and use the youth
 * dashboard right away, but joining events and earning participation
 * points stay locked until their Barangay SK verifies their KK profile
 * during profiling. See sked_is_verified().
 * ------------------------------------------------------------
 */

/** Looks up a registered account by username OR email (case-insensitive). */
function sked_find_registered_user(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    // PDO::ATTR_EMULATE_PREPARES is off (real prepared statements), and
    // MySQL's native protocol does not allow reusing one named placeholder
    // twice in a statement — hence two distinct params bound to the same
    // value rather than a single :identifier used on both sides of the OR.
    $stmt = sked_db()->prepare(
        'SELECT id, role, status, barangay_id, purok, name, surname, given_name, middle_name, sex_assigned_at_birth,
                email, mobile, birthdate, username, password_hash, verified, former_role_badge, created_at
         FROM users
         WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email)
         LIMIT 1'
    );
    $stmt->execute(['username' => $identifier, 'email' => $identifier]);
    $user = $stmt->fetch();

    return $user === false ? null : $user;
}

/** Looks up a registered account by primary-key id. */
function sked_find_user_by_id(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare(
        'SELECT id, role, status, barangay_id, purok, name, surname, given_name, middle_name, sex_assigned_at_birth,
                email, mobile, birthdate, username, password_hash, verified, former_role_badge, created_at
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user === false ? null : $user;
}

/**
 * Registers a new youth (community) account.
 *
 * Youth belong to a barangay and must be KK-eligible (age 15-30). New
 * accounts are stored with status = 'pending' (awaiting SK verification)
 * and verified = 0. Name parts, sex assigned at birth, email, mobile, and
 * birthdate are collected here (not in the later KK Profiling form) so
 * that form can show them read-only ("autofill + lock") — the same
 * governance rule pages/account/settings.php already applies.
 *
 * @return array{ok:bool,errors:array<int,string>,user?:array}
 */
function sked_register_youth_user(string $surname, string $givenName, string $middleName, string $sex, string $email, string $mobile, string $birthdate, int $barangayId, string $username, string $password, string $confirmPassword, string $municipality = SKED_DEFAULT_MUNICIPALITY, string $purok = ''): array
{
    $errors = [];

    $surname = trim($surname);
    $givenName = trim($givenName);
    $middleName = trim($middleName);
    if ($middleName !== '' && in_array(strtolower($middleName), ['n/a', 'na', 'none', 'wala'], true)) {
        $middleName = '';
    }
    $sex = trim($sex);
    $email = trim($email);
    $mobile = trim($mobile);
    $birthdate = trim($birthdate);
    $municipality = trim($municipality);
    $purok = trim($purok);
    $username = trim($username);

    if ($surname === '') {
        $errors[] = 'Surname is required.';
    }
    if ($givenName === '') {
        $errors[] = 'Given name is required.';
    }
    if (!in_array($sex, ['male', 'female'], true)) {
        $errors[] = 'Please select your sex assigned at birth.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
        $errors[] = 'A valid contact number is required.';
    }

    if ($municipality !== SKED_DEFAULT_MUNICIPALITY || !sked_laguna_municipality_exists($municipality)) {
        $errors[] = 'Please select a valid municipality.';
    }

    if ($barangayId <= 0 || !sked_barangay_in_scope($barangayId, $municipality)) {
        $errors[] = 'Please select your barangay.';
    }

    if ($purok === '' || !in_array($purok, sked_purok_options(), true)) {
        $errors[] = 'Please select your purok.';
    }

    $age = $birthdate === '' ? null : sked_age_from_birthdate($birthdate);
    if ($birthdate === '' || $age === null) {
        $errors[] = 'Please enter a valid date of birth.';
    } elseif ($age < SKED_MIN_AGE || $age > SKED_MAX_AGE) {
        $errors[] = 'KK membership is open to youth aged ' . SKED_MIN_AGE . '-' . SKED_MAX_AGE . ' years old (you are ' . $age . ').';
    }

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_.]{3,20}$/', $username)) {
        $errors[] = 'Username must be 3-20 characters (letters, numbers, underscore, or period only).';
    } elseif (array_key_exists(strtolower($username), sked_demo_users())) {
        $errors[] = 'That username is reserved. Please choose another.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        if ($username !== '' && sked_find_registered_user($username) !== null) {
            $errors[] = 'That username is already taken.';
        } elseif ($email !== '' && sked_find_registered_user($email) !== null) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = trim(preg_replace('/\s+/', ' ', "$givenName $middleName $surname"));

    try {
        $stmt = sked_db()->prepare(
            'INSERT INTO users (role, status, barangay_id, purok, name, surname, given_name, middle_name,
                sex_assigned_at_birth, email, mobile, birthdate, username, password_hash, verified)
             VALUES (\'youth\', \'pending\', :barangay_id, :purok, :name, :surname, :given_name, :middle_name,
                :sex, :email, :mobile, :birthdate, :username, :password_hash, 0)'
        );
        $stmt->execute([
            'barangay_id' => $barangayId,
            'purok' => $purok,
            'name' => $fullName,
            'surname' => $surname,
            'given_name' => $givenName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'sex' => $sex,
            'email' => $email,
            'mobile' => $mobile,
            'birthdate' => $birthdate,
            'username' => $username,
            'password_hash' => $passwordHash,
        ]);
    } catch (PDOException $e) {
        // Unique-key race (username/email taken between the check above and
        // the insert) surfaces as a 23000 integrity-constraint violation.
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ['That username or email is already registered.']];
        }
        throw $e;
    }

    $record = sked_find_registered_user($username);

    return ['ok' => true, 'errors' => [], 'user' => $record];
}

/**
 * Signs in a previously registered community account (username or email +
 * password). Demo usernames "1"-"4" are handled separately by
 * sked_demo_login() and never reach this store.
 */
function sked_registered_login(string $identifier, string $password): bool
{
    $user = sked_find_registered_user($identifier);
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    // Verification-rejected accounts cannot sign in (an SK denied residency/age).
    if (($user['status'] ?? '') === 'rejected') {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['status'] = (string) $user['status'];
    $_SESSION['barangay_id'] = $user['barangay_id'] !== null ? (int) $user['barangay_id'] : null;
    $_SESSION['purok'] = $user['purok'] ?? null;
    $_SESSION['verified'] = (bool) $user['verified'];
    if (empty($_SESSION['csrf_logout_token'])) {
        $_SESSION['csrf_logout_token'] = bin2hex(random_bytes(32));
    }

    return true;
}

/**
 * Changes the signed-in account's password after re-checking the current one.
 * Demo accounts (id < 1000, not in the DB) cannot change credentials.
 *
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_update_password(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
{
    $user = sked_find_user_by_id($userId);
    if ($user === null) {
        return ['ok' => false, 'errors' => ['Demo accounts cannot change their password. This is available for registered accounts only.']];
    }

    $errors = [];
    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match.';
    } elseif ($currentPassword === $newPassword) {
        $errors[] = 'New password must be different from the current one.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = sked_db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $stmt->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);

    return ['ok' => true, 'errors' => []];
}

/**
 * Updates the signed-in account's own contact fields (mobile only for now;
 * name/email/barangay stay under SK/DILG control for governance integrity).
 *
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_update_own_contact(int $userId, string $mobile): array
{
    $user = sked_find_user_by_id($userId);
    if ($user === null) {
        return ['ok' => false, 'errors' => ['Demo accounts cannot edit their contact details.']];
    }

    $mobile = trim($mobile);
    if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
        return ['ok' => false, 'errors' => ['Please enter a valid mobile number.']];
    }

    $stmt = sked_db()->prepare('UPDATE users SET mobile = :mobile WHERE id = :id');
    $stmt->execute(['mobile' => $mobile !== '' ? $mobile : null, 'id' => $userId]);

    if (isset($_SESSION['id']) && (int) $_SESSION['id'] === $userId) {
        $_SESSION['mobile'] = $mobile !== '' ? $mobile : null;
    }

    return ['ok' => true, 'errors' => []];
}

/**
 * Whether the signed-in account has completed KK profile verification.
 * Demo accounts (see sked_demo_login()) are always treated as verified.
 * Unverified community accounts may sign in and use their dashboard, but
 * event registration and participation points stay locked until this
 * returns true.
 */
function sked_is_verified(): bool
{
    return !empty($_SESSION['verified']);
}

/**
 * Page guard for verified-only youth features (profiling submission, event
 * registration, point accrual, polls). Assumes a session already exists
 * (call require_role('youth') first). Unverified accounts are redirected
 * back to their dashboard rather than shown the gated feature.
 */
function sked_require_verified(string $redirectTo = '../youth/dashboard.php'): void
{
    if (!sked_is_verified()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

function dashboard_path_for_role(string $role): ?string
{
    $routes = [
        'dilg'  => '../dilg/dashboard.php',
        'ppsk'  => '../ppsk/dashboard.php',
        'sk'    => '../sk/dashboard.php',
        'youth' => '../youth/dashboard.php',
    ];

    return $routes[$role] ?? null;
}

function redirect_by_role(string $role): bool
{
    $path = dashboard_path_for_role($role);

    if ($path === null) {
        return false;
    }

    header('Location: ' . $path);
    exit;
}

/** Establishes the demo session for a given username, or returns false. */
function sked_demo_login(string $username): bool
{
    $users = sked_demo_users();
    if (!isset($users[$username])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['id'] = (int) $username;
    $_SESSION['username'] = $username;
    $_SESSION['name'] = $users[$username]['name'];
    $_SESSION['role'] = $users[$username]['role'];
    // Demo accounts stand in for already-onboarded officials/members, so
    // they are always treated as active + verified (see sked_is_verified()).
    $_SESSION['status'] = 'active';
    $_SESSION['barangay_id'] = $users[$username]['barangay_id'] ?? null;
    $_SESSION['purok'] = $users[$username]['purok'] ?? null;
    $_SESSION['verified'] = true;
    if (empty($_SESSION['csrf_logout_token'])) {
        $_SESSION['csrf_logout_token'] = bin2hex(random_bytes(32));
    }

    return true;
}

/**
 * Idle-session timeout: 30 minutes of no page navigation. Enforced
 * server-side in require_roles() (the authoritative check — the
 * js/idle-timeout.js client-side timer is a UX nicety on top of this, not a
 * substitute for it). Background AJAX polling (pages/api/notifications.php)
 * deliberately does NOT touch $_SESSION['last_activity'], so leaving a tab
 * open with the notification poller running does not itself keep a session
 * alive — only real navigation/form submissions do.
 */
const SKED_IDLE_TIMEOUT_SECONDS = 1800;

/** True if the current session has an id but has been idle past the timeout. */
function sked_session_idle_timed_out(): bool
{
    if (empty($_SESSION['id'])) {
        return false;
    }
    $last = (int) ($_SESSION['last_activity'] ?? 0);
    if ($last === 0) {
        return false; // first request of this session; nothing to compare yet
    }
    return (time() - $last) > SKED_IDLE_TIMEOUT_SECONDS;
}

/** Marks the session as active right now. Call only on genuine navigation/POST, not background polling. */
function sked_touch_session_activity(): void
{
    $_SESSION['last_activity'] = time();
}

/**
 * Page guard: allow only sessions whose role is in $allowedRoles. In this
 * skeleton the check is session-only (no DB lookup). Signature omits the $pdo
 * argument the source system uses, since there is no connection yet.
 */
function require_roles(array $allowedRoles): void
{
    if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    if (sked_session_idle_timed_out()) {
        sked_clear_session();
        header('Location: ../auth/login.php?timeout=1');
        exit;
    }
    sked_touch_session_activity();

    $currentRole = (string) $_SESSION['role'];

    if (in_array($currentRole, $allowedRoles, true)) {
        if (empty($_SESSION['csrf_logout_token'])) {
            $_SESSION['csrf_logout_token'] = bin2hex(random_bytes(32));
        }
        return;
    }

    // Signed in but wrong role for this page: send to their own dashboard.
    if (!redirect_by_role($currentRole)) {
        sked_clear_session();
        header('Location: ../auth/login.php');
        exit;
    }
}

function require_role(string $requiredRole): void
{
    require_roles([$requiredRole]);
}

function sked_clear_session(): void
{
    unset(
        $_SESSION['id'],
        $_SESSION['username'],
        $_SESSION['name'],
        $_SESSION['role'],
        $_SESSION['status'],
        $_SESSION['barangay_id'],
        $_SESSION['purok'],
        $_SESSION['mobile'],
        $_SESSION['verified'],
        $_SESSION['csrf_logout_token'],
        $_SESSION['last_activity']
    );
}
