<?php
/**
 * ============================================================
 * File     : config/seeds/demo_pending_youth.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Idempotent demo data — a few PENDING youth accounts in
 *            Barangay Acevida (id 1, the demo SK's barangay) so the SK
 *            verification queue has something to review. Safe to re-run;
 *            existing rows (matched by username) are left untouched.
 *
 * All demo youth share the password: youth123
 *
 * Run with:
 *   "C:\xampp\php\php.exe" config/seeds/demo_pending_youth.php
 * ============================================================
 */

require_once __DIR__ . '/../database.php';

$barangayId = 1; // Acevida
$password = 'youth123';
$hash = password_hash($password, PASSWORD_DEFAULT);

$youth = [
    ['demo_maria',   'Maria Clara Santos',   'maria.santos@example.com',   '09171110001', '2006-03-14'],
    ['demo_jose',    'Jose Rizal Mercado',   'jose.mercado@example.com',   '09171110002', '2004-06-19'],
    ['demo_andres',  'Andres Bonifacio Cruz','andres.cruz@example.com',    '09171110003', '2008-11-30'],
    ['demo_gabriela','Gabriela Silang Reyes','gabriela.reyes@example.com', null,          '2001-09-05'],
];

$pdo = sked_db();
$insert = $pdo->prepare(
    "INSERT INTO users (role, status, barangay_id, name, email, mobile, birthdate, username, password_hash, verified)
     VALUES ('youth', 'pending', :bgy, :name, :email, :mobile, :birthdate, :username, :hash, 0)"
);
$exists = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');

$created = 0;
$skipped = 0;
foreach ($youth as [$username, $name, $email, $mobile, $birthdate]) {
    $exists->execute(['u' => $username]);
    if ($exists->fetchColumn() !== false) {
        $skipped++;
        continue;
    }
    $insert->execute([
        'bgy' => $barangayId,
        'name' => $name,
        'email' => $email,
        'mobile' => $mobile,
        'birthdate' => $birthdate,
        'username' => $username,
        'hash' => $hash,
    ]);
    $created++;
}

echo "Demo pending youth seed complete. Created: {$created}, already present: {$skipped}.\n";
echo "All demo youth password: {$password}\n";
