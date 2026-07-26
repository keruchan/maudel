<?php
/**
 * ============================================================
 * File     : pages/auth/register.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Self-service registration for community (youth) accounts.
 *
 * Registered accounts are persisted via includes/auth.php's JSON-backed
 * store (no database yet — see sked_register_youth_user()) and can sign
 * in immediately, but start UNVERIFIED: they may explore SKed and manage
 * their KK profile right away, but joining events and earning
 * participation points stay locked until their Barangay SK verifies their
 * profile during profiling. See sked_is_verified().
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';

// Already signed in? Go straight to the right dashboard.
if (!empty($_SESSION['id']) && !empty($_SESSION['role'])) {
    if (!redirect_by_role((string) $_SESSION['role'])) {
        sked_clear_session();
    }
}

$errors = [];
$surname = '';
$givenName = '';
$middleName = '';
$sex = '';
$email = '';
$mobile = '';
$birthdate = '';
$municipality = SKED_DEFAULT_MUNICIPALITY;
$barangayId = 0;
$purok = '';
$username = '';
$agreedTerms = false;

if (empty($_SESSION['csrf_register_token'])) {
    $_SESSION['csrf_register_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surname = trim((string) ($_POST['surname'] ?? ''));
    $givenName = trim((string) ($_POST['given_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $sex = trim((string) ($_POST['sex'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $birthdate = trim((string) ($_POST['birthdate'] ?? ''));
    $municipality = trim((string) ($_POST['municipality'] ?? SKED_DEFAULT_MUNICIPALITY));
    $barangayId = (int) ($_POST['barangay_id'] ?? 0);
    $purok = trim((string) ($_POST['purok'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $agreedTerms = isset($_POST['agree_terms']);

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_register_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if (!$agreedTerms) {
        $errors[] = 'Please confirm you understand the verification requirement before continuing.';
    }

    if (empty($errors)) {
        $result = sked_register_youth_user($surname, $givenName, $middleName, $sex, $email, $mobile, $birthdate, $barangayId, $username, $password, $confirmPassword, $municipality, $purok);

        if ($result['ok']) {
            unset($_SESSION['csrf_register_token']);
            sked_registered_login($username, $password);
            header('Location: ../youth/dashboard.php');
            exit;
        }

        $errors = $result['errors'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Create a Youth Account</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="auth.css">
</head>
<body class="register-auth-page">
    <main class="auth-shell auth-shell-wide">
        <section class="auth-pane auth-pane-brief">
            <div class="pane-grid" aria-hidden="true"></div>
            <div class="pane-ring" aria-hidden="true"></div>

            <a href="../index.php" class="brand-row text-decoration-none">
                <span class="brand-seal" aria-hidden="true"><i class="bi bi-people-fill"></i></span>
                <span>
                    <span class="brand-word d-block">SKed</span>
                    <span class="brand-sub d-block">Sangguniang Kabataan</span>
                </span>
            </a>

            <div class="pane-body">
                <p class="pane-eyebrow">Community Registration</p>
                <h1 class="pane-headline">Create your Youth (KK) account.</h1>

                <ul class="feature-list list-unstyled">
                    <li>
                        <span class="feature-icon feature-icon-sky" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
                        <span>
                            <span class="feature-title">Available right away</span>
                            <span class="feature-desc">Sign in, build your KK profile, read announcements, and send feedback</span>
                        </span>
                    </li>
                    <li>
                        <span class="feature-icon feature-icon-gold" aria-hidden="true"><i class="bi bi-lock"></i></span>
                        <span>
                            <span class="feature-title">Unlocks after verification</span>
                            <span class="feature-desc">Joining events and earning participation points open once your Barangay SK verifies your profile</span>
                        </span>
                    </li>
                    <li>
                        <span class="feature-icon feature-icon-coral" aria-hidden="true"><i class="bi bi-patch-check"></i></span>
                        <span>
                            <span class="feature-title">Verified during profiling</span>
                            <span class="feature-desc">Your SK Chairman reviews and confirms your KK details in person or on file</span>
                        </span>
                    </li>
                </ul>

                <p class="pane-footnote">Use your real KK details so your Barangay SK can match your account during youth profiling.</p>
            </div>
        </section>

        <section class="auth-pane auth-pane-form">
            <div class="form-wrap">
                <p class="section-label mb-2">Youth / Community</p>
                <h2 class="auth-title mb-1">Register a new account</h2>
                <p class="text-secondary mb-4">Free for every KK-eligible resident, 15&ndash;30 years old.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="register.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_register_token']); ?>">

                    <p class="section-label mb-2 mt-1">Name (as on your KK/valid ID)</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="field-group">
                                <label for="surname" class="form-label">Surname (Apelyido)</label>
                                <div class="input-affix">
                                    <i class="bi bi-person-badge"></i>
                                    <input type="text" class="form-control" id="surname" name="surname" value="<?php echo e($surname); ?>" autocomplete="family-name" placeholder="Dela Cruz" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-group">
                                <label for="given_name" class="form-label">Given name (Pangalan)</label>
                                <div class="input-affix">
                                    <i class="bi bi-person"></i>
                                    <input type="text" class="form-control" id="given_name" name="given_name" value="<?php echo e($givenName); ?>" autocomplete="given-name" placeholder="Juan" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-group">
                                <label for="middle_name" class="form-label">Middle name <span class="text-secondary fw-normal">(optional)</span></label>
                                <div class="input-affix">
                                    <i class="bi bi-person"></i>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo e($middleName); ?>" autocomplete="additional-name" placeholder="Guilingan">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="form-label d-block">Sex assigned at birth (Kasarian sa Kapanganakan)</label>
                                <div class="d-flex gap-3 pt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="sex" id="sex_male" value="male" <?php echo $sex === 'male' ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="sex_male">Male (Lalaki)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="sex" id="sex_female" value="female" <?php echo $sex === 'female' ? 'checked' : ''; ?> required>
                                        <label class="form-check-label" for="sex_female">Female (Babae)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="email" class="form-label">Email address</label>
                                <div class="input-affix">
                                    <i class="bi bi-envelope"></i>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo e($email); ?>" autocomplete="email" placeholder="juan@example.com" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="mobile" class="form-label">Contact number</label>
                                <div class="input-affix">
                                    <i class="bi bi-telephone"></i>
                                    <input type="tel" class="form-control" id="mobile" name="mobile" value="<?php echo e($mobile); ?>" autocomplete="tel" placeholder="0912-345-6789" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="birthdate" class="form-label">Date of birth</label>
                                <div class="input-affix">
                                    <i class="bi bi-calendar-heart"></i>
                                    <input type="date" class="form-control" id="birthdate" name="birthdate" value="<?php echo e($birthdate); ?>" max="<?php echo e(date('Y-m-d')); ?>" required>
                                </div>
                                <small class="text-secondary">Must be 15&ndash;30 years old (KK eligibility).</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="region_display" class="form-label">Region</label>
                                <div class="input-affix">
                                    <i class="bi bi-map"></i>
                                    <select class="form-select" id="region_display" disabled>
                                        <option selected><?php echo e(SKED_REGION_NAME); ?></option>
                                    </select>
                                </div>
                                <input type="hidden" name="region" value="<?php echo e(SKED_REGION_NAME); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="province_display" class="form-label">Province</label>
                                <div class="input-affix">
                                    <i class="bi bi-map"></i>
                                    <select class="form-select" id="province_display" disabled>
                                        <option selected><?php echo e(SKED_PROVINCE_NAME); ?></option>
                                    </select>
                                </div>
                                <input type="hidden" name="province" value="<?php echo e(SKED_PROVINCE_NAME); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="municipality_display" class="form-label">Municipality</label>
                                <div class="input-affix">
                                    <i class="bi bi-building"></i>
                                    <select class="form-select" id="municipality_display" disabled>
                                        <?php sked_render_municipality_options($municipality); ?>
                                    </select>
                                </div>
                                <input type="hidden" name="municipality" value="<?php echo e(SKED_DEFAULT_MUNICIPALITY); ?>">
                                <small class="text-secondary">SKed registration is currently scoped to Siniloan, Laguna.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="barangay_id" class="form-label">Barangay</label>
                                <div class="input-affix">
                                    <i class="bi bi-geo-alt"></i>
                                    <select class="form-select" id="barangay_id" name="barangay_id" required>
                                        <?php sked_render_barangay_options($barangayId > 0 ? $barangayId : null, 'Select your barangay...'); ?>
                                    </select>
                                </div>
                                <small class="text-secondary">Siniloan, Laguna. Your SK Chairman here verifies your account.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="purok" class="form-label">Purok</label>
                                <div class="input-affix">
                                    <i class="bi bi-signpost"></i>
                                    <select class="form-select" id="purok" name="purok" required>
                                        <?php sked_render_purok_options($purok); ?>
                                    </select>
                                </div>
                                <small class="text-secondary">Your SK Chairman uses this for resident verification.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-affix">
                                    <i class="bi bi-at"></i>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo e($username); ?>" maxlength="20" autocomplete="username" placeholder="juandc" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-affix">
                                    <i class="bi bi-lock"></i>
                                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" placeholder="At least 6 characters" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label for="confirm_password" class="form-label">Confirm password</label>
                                <div class="input-affix">
                                    <i class="bi bi-lock-fill"></i>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" placeholder="Re-enter password" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="verify-note my-4">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>New accounts start <strong>unverified</strong>. You can sign in and manage your profile immediately, but you can't join events or earn participation points until your Barangay SK verifies your KK profile.</span>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="agree_terms" id="agree_terms" <?php echo $agreedTerms ? 'checked' : ''; ?>>
                        <label class="form-check-label small text-secondary" for="agree_terms">
                            I understand my account is limited until my KK profile is verified.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-sked w-100">Create account <i class="bi bi-arrow-right ms-1"></i></button>
                </form>

                <p class="auth-switch mt-3 mb-0">Already registered? <a href="login.php" class="auth-link">Login</a></p>
            </div>
        </section>
    </main>
</body>
</html>
