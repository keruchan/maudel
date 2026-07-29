<?php
/**
 * ============================================================
 * File     : includes/announcements.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Real text announcements SK/PPSK/DILG can post — a lightweight
 *            title/content notice board, distinct from events (no
 *            date/capacity/registration). Scope model (barangay /
 *            interbarangay / municipal) and the target-barangay junction
 *            table pattern mirror events exactly (see includes/events.php),
 *            reusing sked_allowed_scopes_for_role() rather than duplicating
 *            the permission rule.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/barangays.php';

const SKED_ANNOUNCEMENT_IMAGE_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const SKED_ANNOUNCEMENT_IMAGE_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp'];

/** Whether an announcement form included an image upload. */
function sked_announcement_image_upload_present(?array $file): bool
{
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** Absolute private upload folder for announcement images. */
function sked_announcement_image_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/announcements';
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
 * Validate an uploaded announcement image.
 *
 * @return array{ok:bool,errors:array<int,string>,ext?:string,original_name?:string}
 */
function sked_validate_announcement_image(array $file): array
{
    if (!sked_announcement_image_upload_present($file)) {
        return ['ok' => true, 'errors' => []];
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Image upload failed. Please try again.']];
    }
    if ((int) ($file['size'] ?? 0) > SKED_ANNOUNCEMENT_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'errors' => ['Announcement image is too large (max 5 MB).']];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'errors' => ['Invalid announcement image upload.']];
    }

    $originalName = (string) ($file['name'] ?? 'announcement-image');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, SKED_ANNOUNCEMENT_IMAGE_ALLOWED_EXT, true)) {
        return ['ok' => false, 'errors' => ['Only JPG, PNG, or WebP announcement images are allowed.']];
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
 * Store and attach a previously validated image to an announcement row.
 *
 * @param array{ext:string,original_name:string} $validated
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_store_announcement_image(int $announcementId, array $file, array $validated): array
{
    $dir = sked_announcement_image_upload_dir();
    $filename = $announcementId . '_' . bin2hex(random_bytes(16)) . '.' . $validated['ext'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
        return ['ok' => false, 'errors' => ['Could not save the announcement image.']];
    }

    try {
        $stmt = sked_db()->prepare(
            'UPDATE announcements
                SET image_file_path = :path, image_file_original_name = :name, image_uploaded_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute(['path' => $filename, 'name' => $validated['original_name'], 'id' => $announcementId]);
    } catch (Throwable $e) {
        if (is_file($path)) {
            unlink($path);
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => []];
}

/** Absolute path for a stored announcement image, if present. */
function sked_announcement_image_path(array $announcement): ?string
{
    if (empty($announcement['image_file_path'])) {
        return null;
    }
    return sked_announcement_image_upload_dir() . '/' . basename((string) $announcement['image_file_path']);
}

/** Relative URL to the announcement image streamer, suitable for the current page. */
function sked_announcement_image_url(array $announcement, string $endpoint = '../public/announcement_image.php'): string
{
    if (empty($announcement['image_file_path']) || empty($announcement['id'])) {
        return '';
    }
    $version = !empty($announcement['image_uploaded_at']) ? strtotime((string) $announcement['image_uploaded_at']) : time();
    return $endpoint . '?id=' . (int) $announcement['id'] . '&v=' . (int) $version;
}

/**
 * Notify affected youth that an announcement just went live. Barangay scope
 * targets that barangay; interbarangay loops each target barangay; municipal
 * passes barangayId=null, which sked_notify_role() already treats as "every
 * active youth" — exactly "municipal-wide" here.
 */
function sked_notify_announcement_published(array $announcement, array $targetBarangays): void
{
    $link = '../index.php#announcements';
    $title = 'New announcement: ' . (string) $announcement['title'];
    $message = mb_strimwidth((string) $announcement['content'], 0, 200, '…');

    if ($announcement['scope'] === 'barangay') {
        sked_notify_role('youth', 'announcement', $title, $message, $link, (int) $announcement['barangay_id']);
    } elseif ($announcement['scope'] === 'interbarangay') {
        foreach ($targetBarangays as $barangayId) {
            sked_notify_role('youth', 'announcement', $title, $message, $link, (int) $barangayId);
        }
    } else {
        sked_notify_role('youth', 'announcement', $title, $message, $link, null);
    }
}

/**
 * Create an announcement. $creator = ['id'=>int,'role'=>string,'name'=>string,'barangay_id'=>?int].
 *
 * @return array{ok:bool,errors:array<int,string>,announcement_id?:int}
 */
function sked_create_announcement(array $creator, array $data, ?array $imageFile = null): array
{
    $errors = [];
    $role = (string) ($creator['role'] ?? '');
    $allowedScopes = sked_allowed_scopes_for_role($role);

    $title = trim((string) ($data['title'] ?? ''));
    $content = trim((string) ($data['content'] ?? ''));
    $scope = (string) ($data['scope'] ?? '');
    $pinned = !empty($data['pinned']) ? 1 : 0;
    $publish = !empty($data['publish']);
    $targetBarangays = array_map('intval', (array) ($data['target_barangays'] ?? []));
    $imageValidation = ['ok' => true, 'errors' => []];

    if ($title === '') {
        $errors[] = 'A title is required.';
    }
    if ($content === '') {
        $errors[] = 'Announcement content is required.';
    }
    if (!in_array($scope, $allowedScopes, true)) {
        $errors[] = 'You are not allowed to post an announcement with that scope.';
    }

    $barangayId = null;
    if ($scope === 'barangay') {
        $barangayId = $role === 'sk'
            ? (int) ($creator['barangay_id'] ?? 0)
            : (int) ($data['barangay_id'] ?? 0);
        if ($barangayId <= 0 || !sked_barangay_exists($barangayId)) {
            $errors[] = 'A valid barangay is required for a barangay-level announcement.';
        }
    } elseif ($scope === 'interbarangay') {
        $targetBarangays = array_values(array_filter($targetBarangays, 'sked_barangay_exists'));
        if (count($targetBarangays) < 2) {
            $errors[] = 'Select at least two barangays for an inter-barangay announcement.';
        }
    }

    if (sked_announcement_image_upload_present($imageFile)) {
        $imageValidation = sked_validate_announcement_image($imageFile);
        if (!$imageValidation['ok']) {
            $errors = array_merge($errors, $imageValidation['errors']);
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $status = $publish ? 'published' : 'draft';

    $pdo = sked_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO announcements
                (title, content, scope, barangay_id, pinned, status, created_by, created_by_role, created_by_name)
             VALUES
                (:title, :content, :scope, :barangay_id, :pinned, :status, :created_by, :created_by_role, :created_by_name)'
        );
        $stmt->execute([
            'title' => $title,
            'content' => $content,
            'scope' => $scope,
            'barangay_id' => $barangayId,
            'pinned' => $pinned,
            'status' => $status,
            'created_by' => (int) ($creator['id'] ?? 0) ?: null,
            'created_by_role' => $role !== '' ? $role : null,
            'created_by_name' => (string) ($creator['name'] ?? '') ?: null,
        ]);
        $announcementId = (int) $pdo->lastInsertId();

        if ($scope === 'interbarangay') {
            $ins = $pdo->prepare('INSERT IGNORE INTO announcement_barangays (announcement_id, barangay_id) VALUES (:a, :b)');
            foreach ($targetBarangays as $b) {
                $ins->execute(['a' => $announcementId, 'b' => $b]);
            }
        }

        if (sked_announcement_image_upload_present($imageFile)) {
            /** @var array{ok:bool,errors:array<int,string>,ext:string,original_name:string} $imageValidation */
            $upload = sked_store_announcement_image($announcementId, $imageFile, $imageValidation);
            if (!$upload['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => $upload['errors']];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('sked_create_announcement failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not post the announcement. Please try again.']];
    }

    sked_audit((int) ($creator['id'] ?? 0) ?: null, 'announcement_created', 'announcement', $announcementId, $title);
    if ($status === 'published') {
        $announcement = sked_get_announcement($announcementId);
        if ($announcement !== null) {
            sked_notify_announcement_published($announcement, $targetBarangays);
        }
    }

    return ['ok' => true, 'errors' => [], 'announcement_id' => $announcementId];
}

/** Full announcement row + target barangay ids (for interbarangay). */
function sked_get_announcement(int $announcementId): ?array
{
    if ($announcementId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM announcements WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $announcementId]);
    $announcement = $stmt->fetch();
    if ($announcement === false) {
        return null;
    }
    $bs = sked_db()->prepare('SELECT barangay_id FROM announcement_barangays WHERE announcement_id = :a');
    $bs->execute(['a' => $announcementId]);
    $announcement['target_barangays'] = array_map(static fn ($r) => (int) $r['barangay_id'], $bs->fetchAll());
    return $announcement;
}

/** Whether $role (scoped to $barangayId, if any) may manage this announcement. */
function sked_can_manage_announcement(string $role, ?int $barangayId, array $announcement): bool
{
    return match ($role) {
        'dilg' => true,
        'ppsk' => in_array($announcement['scope'], ['interbarangay', 'municipal'], true),
        'sk' => $announcement['scope'] === 'barangay' && (int) $announcement['barangay_id'] === (int) $barangayId,
        default => false,
    };
}

/** Announcements a role may manage — sk sees its own barangay's; ppsk sees interbarangay/municipal; dilg sees all. */
function sked_announcements_for_manager(string $role, ?int $barangayId): array
{
    $pdo = sked_db();
    if ($role === 'sk') {
        $stmt = $pdo->prepare("SELECT * FROM announcements WHERE scope = 'barangay' AND barangay_id = :b ORDER BY pinned DESC, created_at DESC");
        $stmt->execute(['b' => (int) $barangayId]);
    } elseif ($role === 'ppsk') {
        $stmt = $pdo->query("SELECT * FROM announcements WHERE scope IN ('interbarangay','municipal') ORDER BY pinned DESC, created_at DESC");
    } else {
        $stmt = $pdo->query('SELECT * FROM announcements ORDER BY pinned DESC, created_at DESC');
    }
    return $stmt->fetchAll();
}

/** Publish or unpublish an announcement; notifies affected youth when newly published. */
function sked_set_announcement_status(int $announcementId, string $status): array
{
    if (!in_array($status, ['draft', 'published'], true)) {
        return ['ok' => false, 'error' => 'Invalid status.'];
    }
    $announcement = sked_get_announcement($announcementId);
    if ($announcement === null) {
        return ['ok' => false, 'error' => 'Announcement not found.'];
    }
    if ($announcement['status'] === $status) {
        return ['ok' => true];
    }

    $stmt = sked_db()->prepare('UPDATE announcements SET status = :s WHERE id = :id');
    $stmt->execute(['s' => $status, 'id' => $announcementId]);

    if ($status === 'published') {
        sked_notify_announcement_published($announcement, $announcement['target_barangays']);
    }

    return ['ok' => true];
}

/** Hard-delete an announcement (no participants/attendance tied to it, unlike events). */
function sked_delete_announcement(int $announcementId): array
{
    $announcement = sked_get_announcement($announcementId);
    if ($announcement === null) {
        return ['ok' => false, 'error' => 'Announcement not found.'];
    }

    $path = sked_announcement_image_path($announcement);
    $stmt = sked_db()->prepare('DELETE FROM announcements WHERE id = :id');
    $stmt->execute(['id' => $announcementId]);

    if ($path !== null && is_file($path)) {
        unlink($path);
    }

    return ['ok' => true];
}

/**
 * Published announcements eligible for a viewer's barangay (or municipal-only
 * for an anonymous/no-barangay viewer) — the announcement half of the merged
 * landing-page feed. Same eligibility rule as sked_public_announcements()'s
 * existing events query (includes/events.php), applied to `announcements`.
 */
function sked_published_announcements_for_feed(?int $viewerBarangayId, int $limit = 6, bool $includeAllInterbarangay = false): array
{
    $pdo = sked_db();
    $limit = max(1, $limit);

    if ($viewerBarangayId === null || $viewerBarangayId <= 0) {
        $scopeSql = $includeAllInterbarangay ? "scope IN ('municipal','interbarangay')" : "scope = 'municipal'";
        $stmt = $pdo->prepare(
            "SELECT * FROM announcements
              WHERE status = 'published' AND {$scopeSql}
              ORDER BY pinned DESC, created_at DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT a.* FROM announcements a
          WHERE a.status = 'published'
            AND (
                 (a.scope = 'municipal')
              OR (a.scope = 'barangay' AND a.barangay_id = :b1)
              OR (a.scope = 'interbarangay' AND EXISTS (
                    SELECT 1 FROM announcement_barangays ab WHERE ab.announcement_id = a.id AND ab.barangay_id = :b2))
            )
          ORDER BY a.pinned DESC, a.created_at DESC
          LIMIT " . (int) $limit
    );
    $stmt->execute(['b1' => $viewerBarangayId, 'b2' => $viewerBarangayId]);
    return $stmt->fetchAll();
}
