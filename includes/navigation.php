<?php
/**
 * Shared role-aware navigation for protected SKed pages.
 *
 * Mirrors the source system's sidebar + mobile off-canvas shell from a single
 * source. In this skeleton pass, module links that do not yet have a page point
 * back to the role dashboard (so nothing 404s); they will be repointed to their
 * real pages as each module is built out.
 */

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sk_members.php';

/**
 * Notification bell + dropdown panel (P10, spec 4.5). Markup matches the
 * .notif-* component classes already defined in css/dashboard.css (ported
 * from the source system); js/notifications.js hooks the data-notif-*
 * attributes below to poll pages/api/notifications.php. Called exactly
 * once per page across the app, so it's safe to emit the shared panel +
 * script tag here rather than requiring every page to do it separately.
 */
function render_sked_notification_bell(string $placement): void
{
    $userId = (int) ($_SESSION['id'] ?? 0);
    $notifUserId = sked_notification_user_id_for_session(
        $userId,
        (string) ($_SESSION['role'] ?? ''),
        isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null
    );
    if (empty($_SESSION['csrf_notif_token'])) {
        $_SESSION['csrf_notif_token'] = bin2hex(random_bytes(32));
    }
    $unread = $notifUserId > 0 ? sked_unread_count($notifUserId) : 0;
    $badgeLabel = $unread > 99 ? '99+' : (string) $unread;

    echo '<button type="button" class="notif-bell notif-bell-' . e($placement) . '"'
        . ' aria-label="Notifications" aria-haspopup="true" aria-expanded="false"'
        . ' data-notif-bell data-csrf="' . e((string) $_SESSION['csrf_notif_token']) . '">'
        . '<i class="bi bi-bell"></i>'
        . '<span class="notif-badge"' . ($unread > 0 ? '' : ' hidden') . ' data-notif-badge>' . e($badgeLabel) . '</span>'
        . '</button>';

    echo '<div class="notif-backdrop" hidden data-notif-backdrop></div>';
    echo '<div class="notif-panel" role="dialog" aria-label="Notifications" data-notif-panel>'
        . '<div class="notif-panel-head">'
        . '<span class="notif-panel-title">Notifications</span>'
        . '<button type="button" class="notif-markall" data-notif-markall' . ($unread === 0 ? ' disabled' : '') . '>Mark all read</button>'
        . '</div>'
        . '<div class="notif-list" data-notif-list>'
        . '<div class="notif-state"><i class="bi bi-hourglass-split d-block mb-2"></i>Loading…</div>'
        . '</div>'
        . '</div>';

    echo '<script src="../../js/notifications.js?v=3" defer></script>';
}

/**
 * @return array<int,array{key:string,section:string,href:string,icon:string,label:string}>
 */
function sked_navigation_items_for_role(string $role): array
{
    $home = [
        'key' => 'home', 'section' => 'Overview', 'href' => '../index.php',
        'icon' => 'bi-house-door', 'label' => 'Home',
    ];
    $dashboard = [
        'key' => 'dashboard', 'section' => 'Overview', 'href' => 'dashboard.php',
        'icon' => 'bi-grid-1x2-fill', 'label' => 'Dashboard',
    ];

    switch ($role) {
        case 'dilg':
            return [
                $home, $dashboard,
                ['key' => 'sk_councils', 'section' => 'Oversight', 'href' => 'sk_councils.php', 'icon' => 'bi-diagram-3', 'label' => 'SK Councils'],
                ['key' => 'youth_profiles', 'section' => 'Youth Data', 'href' => '../manage/youth_profiles.php', 'icon' => 'bi-people', 'label' => 'Youth Profiles'],
                ['key' => 'events', 'section' => 'Programs', 'href' => 'events.php', 'icon' => 'bi-calendar-event', 'label' => 'Programs & Events'],
                ['key' => 'announcements', 'section' => 'Programs', 'href' => '../manage/announcements.php', 'icon' => 'bi-megaphone', 'label' => 'Announcements'],
                ['key' => 'plans', 'section' => 'Programs', 'href' => 'plans.php', 'icon' => 'bi-clipboard-data', 'label' => 'Youth Development Plans'],
                ['key' => 'analytics', 'section' => 'Insights', 'href' => 'analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Analytics'],
                ['key' => 'reports', 'section' => 'Insights', 'href' => 'reports.php', 'icon' => 'bi-file-earmark-bar-graph', 'label' => 'Reports'],
                ['key' => 'compliance', 'section' => 'Insights', 'href' => 'compliance.php', 'icon' => 'bi-shield-exclamation', 'label' => 'Dismissal Review'],
                ['key' => 'turnover', 'section' => 'Administration', 'href' => 'turnover.php', 'icon' => 'bi-arrow-left-right', 'label' => 'Turnover of Power'],
                ['key' => 'audit_log', 'section' => 'Administration', 'href' => 'audit.php', 'icon' => 'bi-journal-text', 'label' => 'Audit Log'],
                ['key' => 'account_settings', 'section' => 'Account', 'href' => '../account/settings.php', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
            ];

        case 'ppsk':
            return [
                $home, $dashboard,
                ['key' => 'turnover', 'section' => 'Federation', 'href' => 'turnover.php', 'icon' => 'bi-arrow-left-right', 'label' => 'Turnover of Power'],
                ['key' => 'youth_profiles', 'section' => 'Youth Data', 'href' => '../manage/youth_profiles.php', 'icon' => 'bi-people', 'label' => 'Consolidated Profiles'],
                ['key' => 'events', 'section' => 'Programs', 'href' => 'events.php', 'icon' => 'bi-calendar-event', 'label' => 'Federation Events'],
                ['key' => 'announcements', 'section' => 'Programs', 'href' => '../manage/announcements.php', 'icon' => 'bi-megaphone', 'label' => 'Announcements'],
                ['key' => 'scan_attendance', 'section' => 'Programs', 'href' => '../manage/scan.php', 'icon' => 'bi-upc-scan', 'label' => 'Scan Attendance'],
                ['key' => 'plans', 'section' => 'Programs', 'href' => 'plans.php', 'icon' => 'bi-clipboard-data', 'label' => 'Youth Development Plans'],
                ['key' => 'analytics', 'section' => 'Insights', 'href' => 'analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Recommended Focus Areas'],
                ['key' => 'reports', 'section' => 'Insights', 'href' => 'reports.php', 'icon' => 'bi-file-earmark-bar-graph', 'label' => 'Reports'],
                ['key' => 'compliance', 'section' => 'Insights', 'href' => 'compliance.php', 'icon' => 'bi-shield-exclamation', 'label' => 'SK Compliance'],
                ['key' => 'account_settings', 'section' => 'Account', 'href' => '../account/settings.php', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
            ];

        case 'sk':
            return [
                $home, $dashboard,
                ['key' => 'sk_members', 'section' => 'Youth', 'href' => 'members.php', 'icon' => 'bi-person-badge', 'label' => 'SK Members'],
                ['key' => 'kk_members', 'section' => 'Youth', 'href' => 'kk_members.php', 'icon' => 'bi-people', 'label' => 'KK Members'],
                ['key' => 'validation', 'section' => 'Youth', 'href' => 'verify.php', 'icon' => 'bi-patch-check', 'label' => 'Membership Validation'],
                ['key' => 'kk_profiling', 'section' => 'Youth', 'href' => 'profiling.php', 'icon' => 'bi-clipboard2-data', 'label' => 'KK Profiling'],
                ['key' => 'feedback', 'section' => 'Youth', 'href' => 'feedback.php', 'icon' => 'bi-chat-left-text', 'label' => 'Feedback / Concerns'],
                ['key' => 'manage_events', 'section' => 'Events', 'href' => 'events.php', 'icon' => 'bi-calendar-plus', 'label' => 'Manage Events'],
                ['key' => 'announcements', 'section' => 'Events', 'href' => '../manage/announcements.php', 'icon' => 'bi-megaphone', 'label' => 'Announcements'],
                ['key' => 'scan_attendance', 'section' => 'Events', 'href' => '../manage/scan.php', 'icon' => 'bi-upc-scan', 'label' => 'Scan Attendance'],
                ['key' => 'plans', 'section' => 'Programs', 'href' => 'plans.php', 'icon' => 'bi-clipboard-data', 'label' => 'Youth Development Plans'],
                ['key' => 'polls', 'section' => 'Programs', 'href' => 'polls.php', 'icon' => 'bi-bar-chart-steps', 'label' => 'Polls'],
                ['key' => 'analytics', 'section' => 'Programs', 'href' => 'analytics.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Recommended Focus Areas'],
                ['key' => 'reports', 'section' => 'Compliance', 'href' => 'reports.php', 'icon' => 'bi-file-earmark-text', 'label' => 'Monthly Reports'],
                ['key' => 'katitikan', 'section' => 'Compliance', 'href' => 'katitikan.php', 'icon' => 'bi-journal-text', 'label' => 'Katitikan (Minutes)'],
                ['key' => 'account_settings', 'section' => 'Account', 'href' => '../account/settings.php', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
            ];

        case 'youth':
        default:
            return [
                $home, $dashboard,
                ['key' => 'my_profile', 'section' => 'My Profile', 'href' => 'profile.php', 'icon' => 'bi-person-vcard', 'label' => 'KK Profiling'],
                ['key' => 'my_qr', 'section' => 'My Profile', 'href' => 'my_qr.php', 'icon' => 'bi-qr-code', 'label' => 'My KK ID'],
                ['key' => 'browse_events', 'section' => 'Events', 'href' => 'events.php', 'icon' => 'bi-calendar-event', 'label' => 'Browse Events'],
                ['key' => 'self_checkin', 'section' => 'Events', 'href' => 'scan.php', 'icon' => 'bi-upc-scan', 'label' => 'Self Check-in'],
                ['key' => 'polls', 'section' => 'Community', 'href' => 'polls.php', 'icon' => 'bi-bar-chart-steps', 'label' => 'Community Polls'],
                ['key' => 'leaderboard', 'section' => 'Community', 'href' => 'leaderboard.php', 'icon' => 'bi-trophy', 'label' => 'Top Youth'],
                ['key' => 'feedback', 'section' => 'Community', 'href' => 'feedback.php', 'icon' => 'bi-chat-left-text', 'label' => 'Feedback / Concerns'],
                ['key' => 'full_disclosure', 'section' => 'Community', 'href' => 'full_disclosure.php', 'icon' => 'bi-clipboard-data', 'label' => 'Full Disclosure Board'],
                ['key' => 'profile', 'section' => 'Account', 'href' => 'activity.php', 'icon' => 'bi-person-circle', 'label' => 'Profile'],
                ['key' => 'account_settings', 'section' => 'Account', 'href' => '../account/settings.php', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
            ];
    }
}

function sked_navigation_shell_for_role(string $role): array
{
    $shells = [
        'dilg'  => ['aria_label' => 'DILG oversight navigation', 'mobile_aria_label' => 'DILG mobile navigation', 'brand_subtitle' => 'Oversight Console', 'brand_mark' => 'seal'],
        'ppsk'  => ['aria_label' => 'PPSK federation navigation', 'mobile_aria_label' => 'PPSK mobile navigation', 'brand_subtitle' => 'Federation Office', 'brand_mark' => 'seal'],
        'sk'    => ['aria_label' => 'SK council navigation', 'mobile_aria_label' => 'SK mobile navigation', 'brand_subtitle' => 'Barangay Council', 'brand_mark' => 'seal'],
        'youth' => ['aria_label' => 'Youth portal navigation', 'mobile_aria_label' => 'Youth mobile navigation', 'brand_subtitle' => 'Youth Portal', 'brand_mark' => 'initials'],
    ];

    return $shells[$role] ?? $shells['youth'];
}

function sked_role_display_label(string $role): string
{
    return match ($role) {
        'dilg'  => 'DILG · Superadmin',
        'ppsk'  => 'PPSK · Federation President',
        'sk'    => 'SK Chairman',
        'youth' => 'Youth / Community',
        default => ucfirst($role),
    };
}

/**
 * Resolve a nav item's href against the current page location. Items store
 * dashboard-relative hrefs (e.g. "dashboard.php"); a page rendered from a
 * folder other than the role folder passes $linkBase (e.g. "../sk/") so those
 * links still land on the role's pages. Hrefs already anchored with "../" or a
 * scheme (home, logout, cross-folder links) are left untouched.
 */
function sked_nav_href(string $href, string $linkBase): string
{
    if ($linkBase === '' || str_starts_with($href, '../') || str_contains($href, '://')) {
        return $href;
    }
    return $linkBase . $href;
}

function render_sked_navigation_items(array $items, string $activePage, string $linkBase = ''): void
{
    $currentSection = null;
    foreach ($items as $item) {
        $section = $item['section'] ?? null;
        if ($section !== null && $section !== $currentSection) {
            echo '<div class="nav-section-label">' . e($section) . '</div>';
            $currentSection = $section;
        }

        $isActive = $item['key'] === $activePage;
        $classAttribute = $isActive ? ' class="active"' : '';
        $currentAttribute = $isActive ? ' aria-current="page"' : '';

        echo '<a' . $classAttribute . ' href="' . e(sked_nav_href($item['href'], $linkBase)) . '"' . $currentAttribute
            . '><i class="bi ' . e($item['icon']) . '"></i><span>' . e($item['label']) . '</span></a>';
    }
}

function render_sked_sidebar_identity(string $role): void
{
    $name = trim((string) ($_SESSION['name'] ?? ''));
    if ($name === '') {
        $name = sked_role_display_label($role);
    }
    $initial = strtoupper(substr($name, 0, 1));
    $roleLabel = sked_role_display_label($role);
    $officialBadge = null;
    if ($role === 'youth' && !empty($_SESSION['id'])) {
        $officialBadge = sked_sk_official_badge_for_user((int) $_SESSION['id']);
        if ($officialBadge !== null) {
            $roleLabel = (string) $officialBadge['position'];
        }
    }

    echo '<div class="sidebar-identity">'
        . '<span class="id-avatar">' . e($initial) . '</span>'
        . '<div class="id-meta">'
        . '<div class="id-name">' . e($name) . '</div>'
        . '<div class="id-role">' . e($roleLabel) . '</div>'
        . '</div></div>';
}

function render_sked_brand_mark(string $brandMark): void
{
    if ($brandMark === 'initials') {
        echo '<span class="brand-mark" aria-hidden="true">SK</span>';
        return;
    }

    echo '<span class="registry-seal" aria-hidden="true"><i class="bi bi-people-fill"></i></span>';
}

function render_sked_navigation(string $role, string $activePage, string $linkBase = ''): void
{
    $shell = sked_navigation_shell_for_role($role);
    $items = sked_navigation_items_for_role($role);
    ?>
    <aside class="sidebar" aria-label="<?php echo e($shell['aria_label']); ?>">
        <div class="sidebar-head">
            <a class="brand-block" href="../index.php" title="Go to the SKed home page">
                <?php render_sked_brand_mark($shell['brand_mark']); ?>
                <div>
                    <div class="brand-word">SKed</div>
                    <div class="brand-sub"><?php echo e($shell['brand_subtitle']); ?></div>
                </div>
            </a>
        </div>

        <nav class="nav-panel" aria-label="<?php echo e($shell['aria_label']); ?>">
            <?php render_sked_navigation_items($items, $activePage, $linkBase); ?>
        </nav>

        <div class="nav-divider"></div>
        <div class="sidebar-footer">
            <?php render_sked_sidebar_identity($role); ?>
            <form method="post" action="../auth/logout.php" data-logout-form>
                <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                <input type="hidden" name="reason" value="" data-logout-reason>
                <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
            </form>
        </div>
    </aside>

    <div class="mobile-topbar">
        <a class="d-flex align-items-center gap-2 text-decoration-none" href="../index.php" title="Go to the SKed home page">
            <?php render_sked_brand_mark($shell['brand_mark']); ?>
            <span class="brand-word">SKed</span>
        </a>
        <div class="d-flex align-items-center gap-1">
            <button class="btn-menu-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Open navigation menu">
                <i class="bi bi-list"></i>
            </button>
        </div>
    </div>

    <div class="offcanvas offcanvas-start offcanvas-registry" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
        <div class="offcanvas-header">
            <h2 id="mobileNavLabel" class="brand-word h6 mb-0">Navigation</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <nav class="nav-panel" aria-label="<?php echo e($shell['mobile_aria_label']); ?>">
                <?php render_sked_navigation_items($items, $activePage, $linkBase); ?>
            </nav>
            <div class="nav-divider"></div>
            <div class="sidebar-footer">
                <?php render_sked_sidebar_identity($role); ?>
                <form method="post" action="../auth/logout.php" data-logout-form>
                    <input type="hidden" name="csrf_token" value="<?php echo e((string) ($_SESSION['csrf_logout_token'] ?? '')); ?>">
                    <input type="hidden" name="reason" value="" data-logout-reason>
                    <button type="submit"><i class="bi bi-box-arrow-right"></i><span>Logout</span></button>
                </form>
            </div>
        </div>
    </div>

    <script src="../../js/navigation-state.js?v=1" defer></script>
    <script src="../../js/idle-timeout.js?v=1" defer></script>
    <script src="../../js/table-tools.js?v=4" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.SkedTableToolsAuto) {
                window.SkedTableToolsAuto(document);
            }
        });
    </script>
    <?php
}
