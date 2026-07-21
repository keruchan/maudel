<?php
/**
 * ============================================================
 * File     : includes/reports.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Report submission & review (P7, spec 4.1/4.2/4.3).
 *              monthly                  SK -> PPSK, due by the 10th
 *              interbarangay            PPSK -> DILG (event reports)
 *              minutes                  PPSK -> DILG (meeting minutes)
 *              dismissal_recommendation PPSK -> DILG (3-strike escalation)
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

const SKED_REPORT_UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const SKED_REPORT_UPLOAD_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

/** Valid report types and the role each is submitted to. */
function sked_report_target_role(string $type): ?string
{
    return match ($type) {
        'monthly' => 'ppsk',
        'interbarangay', 'minutes', 'dismissal_recommendation' => 'dilg',
        default => null,
    };
}

/** Whether a report form included a file attachment. */
function sked_report_upload_present(?array $file): bool
{
    return is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/** Absolute private upload folder for report attachments. */
function sked_report_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/reports';
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
 * Validate an uploaded report attachment.
 *
 * @return array{ok:bool,errors:array<int,string>,ext?:string,original_name?:string}
 */
function sked_validate_report_attachment(array $file): array
{
    if (!sked_report_upload_present($file)) {
        return ['ok' => true, 'errors' => []];
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Attachment upload failed. Please try again.']];
    }
    if ((int) ($file['size'] ?? 0) > SKED_REPORT_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'errors' => ['Attachment is too large (max 10 MB).']];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'errors' => ['Invalid attachment upload.']];
    }

    $originalName = (string) ($file['name'] ?? 'attachment');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, SKED_REPORT_UPLOAD_ALLOWED_EXT, true)) {
        return ['ok' => false, 'errors' => ['Only PDF, Word, Excel, JPG, or PNG attachments are allowed.']];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
        'image/jpeg',
        'image/png',
    ];
    if (!in_array((string) $mime, $allowedMime, true)) {
        return ['ok' => false, 'errors' => ['That attachment does not look like a supported document or image.']];
    }

    return ['ok' => true, 'errors' => [], 'ext' => $ext, 'original_name' => mb_substr($originalName, 0, 255)];
}

/**
 * Store and attach a previously validated upload to a report row.
 *
 * @param array{ext:string,original_name:string} $validated
 * @return array{ok:bool,errors:array<int,string>}
 */
function sked_store_report_attachment(int $reportId, int $actorId, array $file, array $validated): array
{
    $dir = sked_report_upload_dir();
    $filename = $reportId . '_' . bin2hex(random_bytes(16)) . '.' . $validated['ext'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
        return ['ok' => false, 'errors' => ['Could not save the report attachment.']];
    }

    try {
        $stmt = sked_db()->prepare(
            'UPDATE reports
                SET attachment_file_path = :path,
                    attachment_file_original_name = :name,
                    attachment_uploaded_at = NOW(),
                    attachment_uploaded_by = :actor
              WHERE id = :id'
        );
        $stmt->execute([
            'path' => $filename,
            'name' => $validated['original_name'],
            'actor' => $actorId > 0 ? $actorId : null,
            'id' => $reportId,
        ]);
    } catch (Throwable $e) {
        if (is_file($path)) {
            unlink($path);
        }
        throw $e;
    }

    sked_audit($actorId > 0 ? $actorId : null, 'report_attachment_upload', 'report', $reportId, (string) $validated['original_name']);
    return ['ok' => true, 'errors' => []];
}

/**
 * Submit a report. $submitter = ['id'=>int,'role'=>string,'name'=>string,'barangay_id'=>?int].
 * For 'monthly', barangay_id comes from the submitter (SK). For
 * 'dismissal_recommendation', pass $refUserId (the SK it concerns) and its
 * barangay is looked up automatically.
 *
 * @return array{ok:bool,errors:array<int,string>,report_id?:int}
 */
function sked_submit_report(array $submitter, string $type, string $title, string $content, ?string $periodMonth = null, ?int $refUserId = null, ?array $attachmentFile = null): array
{
    $errors = [];
    $targetRole = sked_report_target_role($type);
    if ($targetRole === null) {
        $errors[] = 'Invalid report type.';
    }

    $title = trim($title);
    $content = trim($content);
    if ($title === '') {
        $errors[] = 'A report title is required.';
    }

    $barangayId = null;
    if ($type === 'monthly') {
        $barangayId = (int) ($submitter['barangay_id'] ?? 0);
        if ($barangayId <= 0) {
            $errors[] = 'Your account is not assigned to a barangay.';
        }
        if ($periodMonth === null || !preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            $errors[] = 'A valid reporting month is required.';
        }
    } elseif ($type === 'dismissal_recommendation') {
        if ($refUserId === null || $refUserId <= 0) {
            $errors[] = 'A target SK account is required.';
        } else {
            $stmt = sked_db()->prepare('SELECT barangay_id FROM users WHERE id = :id AND role = \'sk\' LIMIT 1');
            $stmt->execute(['id' => $refUserId]);
            $bgy = $stmt->fetchColumn();
            if ($bgy === false) {
                $errors[] = 'That SK account could not be found.';
            } else {
                $barangayId = (int) $bgy;
            }
        }
    }

    $attachmentValidation = ['ok' => true, 'errors' => []];
    if (sked_report_upload_present($attachmentFile)) {
        $attachmentValidation = sked_validate_report_attachment($attachmentFile);
        if (!$attachmentValidation['ok']) {
            $errors = array_merge($errors, $attachmentValidation['errors']);
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $db = sked_db();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO reports
                (type, submitted_by, submitted_by_role, submitted_by_name, barangay_id, target_role,
                 period_month, title, content, ref_user_id)
             VALUES
                (:type, :submitted_by, :submitted_by_role, :submitted_by_name, :barangay_id, :target_role,
                 :period_month, :title, :content, :ref_user_id)'
        );
        $stmt->execute([
            'type' => $type,
            'submitted_by' => (int) ($submitter['id'] ?? 0) ?: null,
            'submitted_by_role' => (string) ($submitter['role'] ?? '') ?: null,
            'submitted_by_name' => (string) ($submitter['name'] ?? '') ?: null,
            'barangay_id' => $barangayId,
            'target_role' => $targetRole,
            'period_month' => $type === 'monthly' ? $periodMonth : null,
            'title' => $title,
            'content' => $content !== '' ? $content : null,
            'ref_user_id' => $type === 'dismissal_recommendation' ? $refUserId : null,
        ]);
        $reportId = (int) $db->lastInsertId();

        if (sked_report_upload_present($attachmentFile)) {
            /** @var array{ok:bool,errors:array<int,string>,ext:string,original_name:string} $attachmentValidation */
            $upload = sked_store_report_attachment($reportId, (int) ($submitter['id'] ?? 0), $attachmentFile, $attachmentValidation);
            if (!$upload['ok']) {
                $db->rollBack();
                return ['ok' => false, 'errors' => $upload['errors']];
            }
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'errors' => ['A monthly report for that period has already been submitted.']];
        }
        throw $e;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'errors' => [], 'report_id' => $reportId];
}

/** Whether a barangay has already submitted its monthly report for $periodMonth. */
function sked_has_submitted_monthly_report(int $barangayId, string $periodMonth): bool
{
    $stmt = sked_db()->prepare(
        "SELECT 1 FROM reports WHERE type = 'monthly' AND barangay_id = :b AND period_month = :m LIMIT 1"
    );
    $stmt->execute(['b' => $barangayId, 'm' => $periodMonth]);
    return $stmt->fetchColumn() !== false;
}

/** An SK's own submitted monthly reports, newest first. */
function sked_monthly_reports_for_barangay(int $barangayId): array
{
    if ($barangayId <= 0) {
        return [];
    }
    $stmt = sked_db()->prepare("SELECT * FROM reports WHERE type = 'monthly' AND barangay_id = :b ORDER BY period_month DESC");
    $stmt->execute(['b' => $barangayId]);
    return $stmt->fetchAll();
}

/** Reports addressed to a role (ppsk sees monthly; dilg sees the rest), optionally filtered by type/status. */
function sked_reports_for_role(string $role, array $filters = []): array
{
    $where = ['target_role = :role'];
    $params = ['role' => $role];

    $type = (string) ($filters['type'] ?? '');
    if ($type !== '') {
        $where[] = 'type = :type';
        $params['type'] = $type;
    }
    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, ['submitted', 'reviewed'], true)) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $sql = 'SELECT * FROM reports WHERE ' . implode(' AND ', $where) . ' ORDER BY submitted_at DESC';
    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Mark a report reviewed. */
function sked_mark_report_reviewed(int $reportId, int $reviewerId): bool
{
    $stmt = sked_db()->prepare(
        "UPDATE reports SET status = 'reviewed', reviewed_at = NOW(), reviewed_by = :r WHERE id = :id AND status = 'submitted'"
    );
    $stmt->execute(['r' => $reviewerId, 'id' => $reportId]);
    return $stmt->rowCount() > 0;
}

/** A single report by id, or null. */
function sked_get_report(int $reportId): ?array
{
    if ($reportId <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare('SELECT * FROM reports WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reportId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Shared report visibility guard for download/export routes. */
function sked_can_access_report(array $report, string $role, int $userId, int $barangayId = 0): bool
{
    if ($role === 'dilg') {
        return (string) ($report['target_role'] ?? '') === 'dilg';
    }
    if ($role === 'ppsk') {
        return (string) ($report['target_role'] ?? '') === 'ppsk'
            || (int) ($report['submitted_by'] ?? 0) === $userId;
    }
    if ($role === 'sk') {
        return (int) ($report['submitted_by'] ?? 0) === $userId
            || ($barangayId > 0 && (int) ($report['barangay_id'] ?? 0) === $barangayId);
    }
    return false;
}
