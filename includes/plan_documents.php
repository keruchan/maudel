<?php
/**
 * ============================================================
 * File     : includes/plan_documents.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Simple file-upload replacement for the old structured
 *            CBYDP/ABYIP data entry (see docs/old_reporting_process.md for
 *            the full pre-rewrite design). SK uploads a finished document
 *            with a date scope — no field-by-field authoring, no
 *            draft/finalized workflow. Every upload is immediately public:
 *            viewable via pages/public/plan_document.php by any user,
 *            including anonymous visitors.
 *
 *            4 document types share one table: CBYDP (3-year cycle),
 *            ABYIP (calendar year), Annual Budget (calendar year), and
 *            Monthly Itemized List of Purchase Request (calendar year +
 *            month). Multiple uploads per barangay/type/period are allowed
 *            (no uniqueness enforced) — the SK can just delete a wrong one
 *            and re-upload rather than needing an "edit" flow.
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/barangays.php';

const SKED_PLAN_DOC_TYPES = ['cbydp', 'abyip', 'annual_budget', 'purchase_request'];
const SKED_PLAN_DOC_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const SKED_PLAN_DOC_ALLOWED_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

/** Human label for a document type. */
function sked_plan_doc_type_label(string $type): string
{
    return match ($type) {
        'cbydp' => 'CBYDP',
        'abyip' => 'ABYIP',
        'annual_budget' => 'Annual Budget',
        'purchase_request' => 'Monthly Itemized List of Purchase Request',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

/** Whether a document type is scoped to a single month (vs. a whole year). */
function sked_plan_doc_is_monthly(string $type): bool
{
    return $type === 'purchase_request';
}

/** Absolute private upload folder for plan documents. */
function sked_plan_document_upload_dir(): string
{
    $dir = __DIR__ . '/../uploads/plan_documents';
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
 * Validate an uploaded plan document file.
 *
 * @return array{ok:bool,errors:array<int,string>,ext?:string,original_name?:string}
 */
function sked_validate_plan_document(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'errors' => ['Please choose a file to upload.']];
    }
    if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['File upload failed. Please try again.']];
    }
    if ((int) ($file['size'] ?? 0) > SKED_PLAN_DOC_MAX_BYTES) {
        return ['ok' => false, 'errors' => ['File is too large (max 10 MB).']];
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'errors' => ['Invalid file upload.']];
    }

    $originalName = (string) ($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, SKED_PLAN_DOC_ALLOWED_EXT, true)) {
        return ['ok' => false, 'errors' => ['Only PDF, Word, Excel, JPG, or PNG files are allowed.']];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png',
    ];
    if (!in_array((string) $mime, $allowedMime, true)) {
        return ['ok' => false, 'errors' => ['That file does not look like a supported document.']];
    }

    return ['ok' => true, 'errors' => [], 'ext' => $ext, 'original_name' => mb_substr($originalName, 0, 255)];
}

/**
 * Upload a new plan document. $creator = ['id'=>int,'name'=>string,'barangay_id'=>int].
 * $data = ['doc_type'=>string,'year'=>int,'month'=>?int].
 *
 * @return array{ok:bool,errors:array<int,string>,document_id?:int}
 */
function sked_create_plan_document(array $creator, array $data, array $file): array
{
    $errors = [];
    $barangayId = (int) ($creator['barangay_id'] ?? 0);
    if ($barangayId <= 0) {
        $errors[] = 'Your account is not assigned to a barangay.';
    }

    $docType = (string) ($data['doc_type'] ?? '');
    if (!in_array($docType, SKED_PLAN_DOC_TYPES, true)) {
        $errors[] = 'Please choose a valid document type.';
    }

    $year = (int) ($data['year'] ?? 0);
    if ($year < 2020 || $year > 2100) {
        $errors[] = 'Please enter a valid year.';
    }

    $month = null;
    if ($docType !== '' && sked_plan_doc_is_monthly($docType)) {
        $month = (int) ($data['month'] ?? 0);
        if ($month < 1 || $month > 12) {
            $errors[] = 'Please choose a valid month.';
        }
    }

    $validated = sked_validate_plan_document($file);
    if (!$validated['ok']) {
        $errors = array_merge($errors, $validated['errors']);
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    if ($docType === 'cbydp') {
        $periodLabel = 'CY ' . $year . '-' . ($year + 2);
        $periodStart = $year . '-01-01';
    } elseif ($month !== null) {
        $periodLabel = date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year;
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
    } else {
        $periodLabel = 'CY ' . $year;
        $periodStart = $year . '-01-01';
    }

    $pdo = sked_db();
    $stmt = $pdo->prepare(
        'INSERT INTO plan_documents (barangay_id, doc_type, period_label, period_start, file_path, file_original_name, uploaded_by, uploaded_by_name)
         VALUES (:bgy, :type, :label, :start, :path, :name, :uid, :uname)'
    );
    $stmt->execute([
        'bgy' => $barangayId,
        'type' => $docType,
        'label' => $periodLabel,
        'start' => $periodStart,
        'path' => '',
        'name' => $validated['original_name'],
        'uid' => (int) ($creator['id'] ?? 0) ?: null,
        'uname' => (string) ($creator['name'] ?? '') ?: null,
    ]);
    $documentId = (int) $pdo->lastInsertId();

    $dir = sked_plan_document_upload_dir();
    $filename = $documentId . '_' . bin2hex(random_bytes(16)) . '.' . $validated['ext'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
        $pdo->prepare('DELETE FROM plan_documents WHERE id = :id')->execute(['id' => $documentId]);
        return ['ok' => false, 'errors' => ['Could not save the uploaded file.']];
    }
    $pdo->prepare('UPDATE plan_documents SET file_path = :path WHERE id = :id')->execute(['path' => $filename, 'id' => $documentId]);

    sked_audit((int) ($creator['id'] ?? 0) ?: null, 'plan_document_uploaded', 'plan_document', $documentId,
        sked_plan_doc_type_label($docType) . ' (' . $periodLabel . ')');

    return ['ok' => true, 'errors' => [], 'document_id' => $documentId];
}

/** Single document by id, with barangay_name attached. */
function sked_get_plan_document(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = sked_db()->prepare(
        'SELECT pd.*, b.name AS barangay_name FROM plan_documents pd
           LEFT JOIN barangays b ON b.id = pd.barangay_id
          WHERE pd.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** All documents for one barangay, optionally filtered by type, newest period first. */
function sked_plan_documents_for_barangay(int $barangayId, string $typeFilter = ''): array
{
    $sql = 'SELECT * FROM plan_documents WHERE barangay_id = :b';
    $params = ['b' => $barangayId];
    if (in_array($typeFilter, SKED_PLAN_DOC_TYPES, true)) {
        $sql .= ' AND doc_type = :t';
        $params['t'] = $typeFilter;
    }
    $sql .= ' ORDER BY period_start DESC, uploaded_at DESC';
    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Every document municipality-wide (joined with barangay name), for PPSK/DILG oversight. */
function sked_plan_documents_all(string $typeFilter = ''): array
{
    $sql = 'SELECT pd.*, b.name AS barangay_name FROM plan_documents pd LEFT JOIN barangays b ON b.id = pd.barangay_id WHERE 1=1';
    $params = [];
    if (in_array($typeFilter, SKED_PLAN_DOC_TYPES, true)) {
        $sql .= ' AND pd.doc_type = :t';
        $params['t'] = $typeFilter;
    }
    $sql .= ' ORDER BY b.name ASC, pd.period_start DESC';
    $stmt = sked_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Delete a document (barangay-scoped) and its file on disk. */
function sked_delete_plan_document(int $id, int $actorBarangayId): array
{
    $doc = sked_get_plan_document($id);
    if ($doc === null || (int) $doc['barangay_id'] !== $actorBarangayId) {
        return ['ok' => false, 'error' => 'Document not found.'];
    }

    sked_db()->prepare('DELETE FROM plan_documents WHERE id = :id')->execute(['id' => $id]);

    $path = sked_plan_document_upload_dir() . '/' . basename((string) $doc['file_path']);
    if (is_file($path)) {
        unlink($path);
    }

    return ['ok' => true];
}
