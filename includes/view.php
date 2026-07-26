<?php
/**
 * ============================================================
 * File     : includes/view.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Small view-layer helpers shared by every page.
 * ============================================================
 */

/**
 * Escape dynamic text before rendering it into HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
 * Sticky form values.
 *
 * When a submission fails validation the page re-renders in the SAME
 * request, so $_POST still holds everything the user typed. Losing it
 * forces them to retype a long form over one bad field, which is the
 * worst possible response to a small mistake.
 *
 * Usage, once per page, right after the POST handler decides:
 *
 *     sked_form_retain(!$result['ok']);   // keep values only on failure
 *
 * then in the markup:
 *
 *     value="<?php echo e(sked_old('title')); ?>"
 *     <?php echo sked_old_checked('publish', '1') ? 'checked' : ''; ?>
 *     <option <?php echo sked_old_selected('category', $c) ? 'selected' : ''; ?>>
 *
 * On a successful submit (or a fresh GET) retention stays off and every
 * helper falls back to its default, so the form clears as it should.
 * ============================================================ */

/** Turn value retention on/off for the rest of this request. */
function sked_form_retain(bool $on = true): void
{
    $GLOBALS['sked_retain_form'] = $on;
}

/** True when the current request should re-render submitted values. */
function sked_form_retaining(): bool
{
    return !empty($GLOBALS['sked_retain_form']);
}

/**
 * The value to render into a text/number/date/textarea field: what the
 * user submitted when retaining, otherwise $default.
 */
function sked_old(string $field, string $default = ''): string
{
    if (!sked_form_retaining() || !isset($_POST[$field]) || is_array($_POST[$field])) {
        return $default;
    }
    return (string) $_POST[$field];
}

/** Whether a checkbox/radio with this value was ticked on the failed submit. */
function sked_old_checked(string $field, string $value = '1', bool $default = false): bool
{
    if (!sked_form_retaining()) {
        return $default;
    }
    if (!isset($_POST[$field])) {
        return false; // unchecked boxes are simply absent from POST
    }
    if (is_array($_POST[$field])) {
        return in_array($value, array_map('strval', $_POST[$field]), true);
    }
    return (string) $_POST[$field] === $value;
}

/** Whether a <option> should be re-selected. Same rule as a radio. */
function sked_old_selected(string $field, string $value, bool $default = false): bool
{
    if (!sked_form_retaining()) {
        return $default;
    }
    return isset($_POST[$field]) && !is_array($_POST[$field])
        ? (string) $_POST[$field] === $value
        : sked_old_checked($field, $value);
}

/** Submitted values of a multi-select / checkbox group. */
function sked_old_array(string $field, array $default = []): array
{
    if (!sked_form_retaining() || !isset($_POST[$field])) {
        return sked_form_retaining() ? [] : $default;
    }
    return array_map('strval', (array) $_POST[$field]);
}

/**
 * Render a validation-error block to sit INSIDE the form (modal body or
 * panel), so the message appears next to the fields it is about rather
 * than only in a page-level banner the user may have scrolled past.
 *
 * @param array<int,string>|string $errors Already-unescaped message(s).
 */
function sked_render_form_errors($errors, string $title = 'Please fix the following:'): void
{
    $list = is_array($errors) ? array_values(array_filter($errors)) : ($errors !== '' ? [$errors] : []);
    if (empty($list)) {
        return;
    }
    echo '<div class="alert alert-danger" role="alert">';
    echo '<div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>' . e($title) . '</div>';
    if (count($list) === 1) {
        echo '<div class="small mb-0">' . e((string) $list[0]) . '</div>';
    } else {
        echo '<ul class="small mb-0 ps-3">';
        foreach ($list as $msg) {
            echo '<li>' . e((string) $msg) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

/**
 * Re-open any Bootstrap modal marked with data-autoshow="1".
 *
 * Pages can keep validation errors inside the modal body, retain posted field
 * values with sked_old(), and add data-autoshow when that modal needs to come
 * back after a failed submit.
 */
function sked_render_autoshow_modals_script(): void
{
    echo <<<'HTML'
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }
        document.querySelectorAll('.modal[data-autoshow="1"]').forEach(function (modalEl) {
            new bootstrap.Modal(modalEl).show();
        });
    });
</script>
HTML;
}
