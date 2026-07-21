<?php
/**
 * ============================================================
 * File     : config/seeds/role_demo_accounts.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Seeds one fully real, persisted, active + verified account per
 *            role (dilg, ppsk, sk, youth) so the user can exercise features
 *            the numeric demo logins (1-4) can't: password changes, contact
 *            edits, being a real verification/dismissal/turnover target,
 *            real event/poll participation, etc.
 *
 * UPSERT by username: creates the row if missing, otherwise resets its
 * password to the value below (so re-running this script after a manual
 * password change restores a known-good credential). Safe to re-run.
 *
 * SK and Youth are both placed in barangay Acevida (id 1), which has no
 * other real persisted active SK, so this doesn't retire/collide with
 * anything.
 *
 * Each role gets its OWN distinct password (not shared) — see the table
 * below or docs/SKed-Credentials-and-Analytics.docx for the full list.
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/role_demo_accounts.php
 * ============================================================
 */

require_once __DIR__ . '/../database.php';

// [role, barangay_id, display name, email, username, password]
$accounts = [
    ['dilg',  null, 'DILG Administrator (Real)',  'dilg.admin@sked.local',     'dilgadmin',      'DilgSked#2026!'],
    ['ppsk',  null, 'PPSK President (Real)',       'ppsk.president@sked.local', 'ppskpresident',  'PpskSked#2026!'],
    ['sk',    1,    'SK Chairman Acevida (Real)',  'sk.acevida@sked.local',     'skacevida',      'SkChairSked#2026!'],
    ['youth', 1,    'Youth Member Acevida (Real)', 'youth.acevida@sked.local',  'youthacevida',   'YouthSked#2026!'],
];

$pdo = sked_db();
$insert = $pdo->prepare(
    "INSERT INTO users (role, status, barangay_id, name, email, username, password_hash, verified)
     VALUES (:role, 'active', :bgy, :name, :email, :username, :hash, 1)"
);
$update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE username = :u');
$exists = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');

$created = 0;
$updated = 0;
foreach ($accounts as [$role, $bgy, $name, $email, $username, $password]) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $exists->execute(['u' => $username]);
    if ($exists->fetchColumn() !== false) {
        $update->execute(['hash' => $hash, 'u' => $username]);
        $updated++;
        continue;
    }
    $insert->execute(['role' => $role, 'bgy' => $bgy, 'name' => $name, 'email' => $email, 'username' => $username, 'hash' => $hash]);
    $created++;
}

echo "Real role demo accounts seed complete. Created: {$created}, password reset: {$updated}.\n";
echo "Credentials:\n";
foreach ($accounts as [$role, , , , $username, $password]) {
    echo "  {$role}: {$username} / {$password}\n";
}
