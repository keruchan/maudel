<?php
/**
 * ============================================================
 * File     : includes/plan_uploads.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Signed-copy upload handling shared by CBYDP, ABYIP (P12), and
 *            Katitikan (P13). After exporting the no-logo print template
 *            and collecting physical signatures, the SK uploads the
 *            scanned signed copy here so PPSK/DILG can review it for
 *            compliance reporting.
 *
 * Files are stored under uploads/plans/{cbydp|abyip|katitikan}/ with
 * randomized filenames and are NEVER served directly — uploads/.htaccess
 * denies all direct web access; the only way to read one back is
 * pages/manage/plan_file.php, which re-checks the requester's role/
 * barangay scope before streaming it.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/cbydp.php';
require_once __DIR__ . '/abyip.php';
require_once __DIR__ . '/katitikan.php';
require_once __DIR__ . '/audit.php';

const SKED_PLAN_UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const SKED_PLAN_UPLOAD_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const SKED_PLAN_UPLOAD_TYPES = ['cbydp', 'abyip', 'katitikan'];

/** Absolute path to the upload directory for a plan type, creating it (and its deny-all .htaccess) if missing. */
function sked_plan_upload_dir(string $planType): string
{
    if (!in_array($planType, SKED_PLAN_UPLOAD_TYPES, true)) {
        throw new InvalidArgumentException('Unknown plan type.');
    }
    $dir = __DIR__ . '/../uploads/plans/' . $planType;
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
 * Validate + store an uploaded signed copy for a plan, and record it on
 * the plan row. Barangay-scoped: only the plan's own barangay may upload.
 *
 * @param array<string,mixed> $file One entry from $_FILES.
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_save_signed_upload(string $planType, int $planId, int $actorId, int $actorBarangayId, array $file): array
{
    if (!in_array($planType, SKED_PLAN_UPLOAD_TYPES, true)) {
        return ['ok' => false, 'errors' => ['Invalid plan type.']];
    }
    $plan = match ($planType) {
        'cbydp' => sked_cbydp_get($planId, $actorBarangayId),
        'abyip' => sked_abyip_get($planId, $actorBarangayId),
        'katitikan' => sked_katitikan_get($planId, $actorBarangayId),
    };
    if ($plan === null) {
        return ['ok' => false, 'errors' => ['Plan not found.']];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'errors' => ['Please choose a file to upload.']];
    }
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Upload failed. Please try again.']];
    }
    if ((int) ($file['size'] ?? 0) > SKED_PLAN_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'errors' => ['File is too large (max 10 MB).']];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'errors' => ['Invalid upload.']];
    }

    $originalName = (string) ($file['name'] ?? 'upload');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, SKED_PLAN_UPLOAD_ALLOWED_EXT, true)) {
        return ['ok' => false, 'errors' => ['Only PDF, JPG, or PNG files are allowed.']];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mime, $allowedMime, true)) {
        return ['ok' => false, 'errors' => ['That file does not look like a valid PDF/JPG/PNG.']];
    }

    $dir = sked_plan_upload_dir($planType);
    $filename = $planId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return ['ok' => false, 'errors' => ['Could not save the uploaded file.']];
    }

    $table = match ($planType) {
        'cbydp' => 'cbydp_plans',
        'abyip' => 'abyip_plans',
        'katitikan' => 'katitikan',
    };
    $stmt = sked_db()->prepare(
        "UPDATE $table SET signed_file_path = :path, signed_file_original_name = :name, signed_uploaded_at = NOW()
          WHERE id = :id AND barangay_id = :bgy"
    );
    $stmt->execute([
        'path' => $filename,
        'name' => mb_substr($originalName, 0, 255),
        'id' => $planId,
        'bgy' => $actorBarangayId,
    ]);

    $entityType = match ($planType) {
        'cbydp' => 'cbydp_plan',
        'abyip' => 'abyip_plan',
        'katitikan' => 'katitikan',
    };
    sked_audit($actorId, $planType . '_signed_upload', $entityType, $planId, $originalName);

    return ['ok' => true, 'errors' => []];
}
