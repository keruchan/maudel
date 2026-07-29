<?php
/**
 * Public landing page for SKed. Serves anonymous visitors and introduces the
 * Sangguniang Kabataan youth profiling & event-management platform. Also
 * reachable by already-authenticated demo users (via the sidebar "Home" link
 * or the SKed logo), so the navbar adapts to show "Dashboard"/"Logout" instead
 * of "Login" rather than asking a signed-in visitor to log in again.
 *
 * Announcements are sourced from published SK/PPSK events, scoped per
 * viewer (see sked_public_announcements() in includes/events.php).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/barangays.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/announcements.php';
require_once __DIR__ . '/../includes/profiling.php';
require_once __DIR__ . '/../includes/cbydp.php';
require_once __DIR__ . '/../includes/abyip.php';

// Live community-reach figures for the hero strip and stats band — no
// hardcoded placeholder counts.
$activeBarangays = sked_barangays();
$totalBarangays = count($activeBarangays);
$youthProfileSummary = sked_youth_profile_summary();
$totalYouthRegistered = array_sum(array_column($youthProfileSummary, 'total_youth'));
$eventsHostedCount = (int) sked_db()->query("SELECT COUNT(*) FROM events WHERE status IN ('completed','closed')")->fetchColumn();
$programsCompletedCount = count(array_filter(sked_cbydp_list_all(), static fn ($p) => $p['status'] === 'finalized'))
    + count(array_filter(sked_abyip_list_all(), static fn ($p) => $p['status'] === 'finalized'));

// Recognize an already-authenticated visitor without requiring one. The role
// dashboard paths are written for callers one directory below pages/ (e.g.
// "../youth/dashboard.php"); this page lives directly under pages/, so the
// leading "../" is stripped.
$viewerDashboardPath = null;
$viewerDisplayName = '';
if (!empty($_SESSION['id']) && !empty($_SESSION['role'])) {
    $roleDashboardPath = dashboard_path_for_role((string) $_SESSION['role']);
    if ($roleDashboardPath !== null) {
        $viewerDashboardPath = substr($roleDashboardPath, 3);
        $viewerDisplayName = trim((string) ($_SESSION['name'] ?? '')) !== ''
            ? (string) $_SESSION['name']
            : 'your account';
        if (empty($_SESSION['csrf_logout_token'])) {
            $_SESSION['csrf_logout_token'] = bin2hex(random_bytes(32));
        }
    }
}
$isAuthenticated = $viewerDashboardPath !== null;
$primaryCtaHref = $isAuthenticated ? $viewerDashboardPath : 'auth/login.php';
$primaryRegisterHref = $isAuthenticated ? $viewerDashboardPath : 'auth/register.php';

// Feed = real announcements merged with published events, scoped to what
// this viewer may see: anonymous / non-youth visitors get municipal-wide
// posts only; a logged-in youth also sees their own barangay's posts and
// any inter-barangay posts that include it (same eligibility rule as the
// youth Browse Events page). See sked_public_announcements() in
// includes/events.php for the merge/eligibility logic.
$viewerBarangayId = ($isAuthenticated && (string) ($_SESSION['role'] ?? '') === 'youth' && !empty($_SESSION['barangay_id']))
    ? (int) $_SESSION['barangay_id']
    : null;
$feedItems = sked_public_announcements($viewerBarangayId, 6);

$scopeLabels = ['barangay' => 'Barangay Post', 'interbarangay' => 'Inter-Barangay', 'municipal' => 'Municipal-Wide'];
$announcements = [];
foreach ($feedItems as $item) {
    $isAnnouncement = ($item['feed_type'] ?? 'event') === 'announcement';
    $pill = $isAnnouncement
        ? ($scopeLabels[$item['scope']] ?? 'SK Post')
        : (!empty($item['category']) ? (string) $item['category'] : ($scopeLabels[$item['scope']] ?? 'SK Post'));
    if ($item['scope'] === 'barangay' && !empty($item['barangay_id'])) {
        $pill .= ' · Brgy. ' . sked_barangay_name((int) $item['barangay_id']);
    }

    if ($isAnnouncement) {
        $schedule = !empty($item['pinned']) ? 'Pinned' : null;
        $body = (string) $item['content'];
        $imageUrl = sked_announcement_image_url($item, 'public/announcement_image.php');
        $detailUrl = 'public/announcement.php?id=' . (int) $item['id'];
    } else {
        $schedule = $item['event_date'] ? date('F j, Y', strtotime((string) $item['event_date'])) : 'Date TBA';
        if (!empty($item['start_time'])) {
            $schedule .= ' · ' . date('g:i A', strtotime((string) $item['start_time']));
        }
        $body = !empty($item['description']) ? (string) $item['description'] : 'See your SK for details on this event.';
        $imageUrl = sked_event_image_url($item, 'public/event_image.php');
        $detailUrl = 'public/event.php?t=' . rawurlencode((string) $item['share_token']);
    }

    $announcements[] = [
        'type_label' => $isAnnouncement ? 'Announcement' : 'Event',
        'pill_icon' => $isAnnouncement ? 'bi-megaphone' : 'bi-people-fill',
        'pill' => $pill,
        'schedule' => $schedule,
        'schedule_icon' => $isAnnouncement ? 'bi-pin-angle-fill' : 'bi-calendar-event',
        'url' => $detailUrl,
        'title' => (string) $item['title'],
        'body' => $body,
        'posted' => 'Posted ' . date('F j, Y', strtotime((string) $item['created_at'])),
        'image_url' => $imageUrl,
    ];
}

$sessionBarangayId = ($isAuthenticated && !empty($_SESSION['barangay_id'])) ? (int) $_SESSION['barangay_id'] : null;
$sessionBarangayName = $sessionBarangayId !== null ? sked_barangay_name($sessionBarangayId) : '';
$barangayGeoPoints = [
    'Acevida' => [14.4070, 121.4380],
    'Bagong Pag-Asa' => [14.4205, 121.4450],
    'Bagumbarangay' => [14.4210, 121.4480],
    'Buhay' => [14.3880, 121.4360],
    'G. Redor' => [14.4230, 121.4460],
    'Gen. Luna' => [14.4300, 121.4550],
    'Halayhayin' => [14.4050, 121.4640],
    'J. Rizal' => [14.4180, 121.4470],
    'Kapatalan' => [14.4500, 121.4550],
    'Laguio' => [14.4400, 121.4330],
    'Liyang' => [14.4230, 121.4310],
    'Llavac' => [14.4490, 121.4750],
    'Macatad' => [14.4010, 121.4510],
    'Magsaysay' => [14.4170, 121.4580],
    'Mayatba' => [14.3910, 121.4210],
    'Mendiola' => [14.4310, 121.4420],
    'P. Burgos' => [14.4250, 121.4490],
    'Pandeno' => [14.4140, 121.4400],
    'Salubungan' => [14.4350, 121.4600],
    'Wawa' => [14.4090, 121.4700],
];
$districtFourMarkers = [
    ['name' => 'DILG Laguna District IV - Santa Cruz area', 'label' => 'DILG District IV Reference', 'lat' => 14.2814, 'lng' => 121.4161, 'type' => 'office'],
    ['name' => 'Cavinti', 'label' => 'District IV Municipality', 'lat' => 14.2466, 'lng' => 121.5076, 'type' => 'municipality'],
    ['name' => 'Famy', 'label' => 'District IV Municipality', 'lat' => 14.4376, 'lng' => 121.4480, 'type' => 'municipality'],
    ['name' => 'Kalayaan', 'label' => 'District IV Municipality', 'lat' => 14.3508, 'lng' => 121.5666, 'type' => 'municipality'],
    ['name' => 'Luisiana', 'label' => 'District IV Municipality', 'lat' => 14.1857, 'lng' => 121.5109, 'type' => 'municipality'],
    ['name' => 'Lumban', 'label' => 'District IV Municipality', 'lat' => 14.2973, 'lng' => 121.4592, 'type' => 'municipality'],
    ['name' => 'Mabitac', 'label' => 'District IV Municipality', 'lat' => 14.4267, 'lng' => 121.4291, 'type' => 'municipality'],
    ['name' => 'Magdalena', 'label' => 'District IV Municipality', 'lat' => 14.1999, 'lng' => 121.4297, 'type' => 'municipality'],
    ['name' => 'Majayjay', 'label' => 'District IV Municipality', 'lat' => 14.1463, 'lng' => 121.4729, 'type' => 'municipality'],
    ['name' => 'Paete', 'label' => 'District IV Municipality', 'lat' => 14.3652, 'lng' => 121.4829, 'type' => 'municipality'],
    ['name' => 'Pagsanjan', 'label' => 'District IV Municipality', 'lat' => 14.2732, 'lng' => 121.4553, 'type' => 'municipality'],
    ['name' => 'Pakil', 'label' => 'District IV Municipality', 'lat' => 14.3835, 'lng' => 121.4786, 'type' => 'municipality'],
    ['name' => 'Pangil', 'label' => 'District IV Municipality', 'lat' => 14.4035, 'lng' => 121.4653, 'type' => 'municipality'],
    ['name' => 'Pila', 'label' => 'District IV Municipality', 'lat' => 14.2325, 'lng' => 121.3648, 'type' => 'municipality'],
    ['name' => 'Santa Cruz', 'label' => 'District IV Municipality', 'lat' => 14.2814, 'lng' => 121.4161, 'type' => 'municipality'],
    ['name' => 'Santa Maria', 'label' => 'District IV Municipality', 'lat' => 14.4719, 'lng' => 121.4288, 'type' => 'municipality'],
    ['name' => 'Siniloan', 'label' => 'District IV Municipality', 'lat' => 14.4217, 'lng' => 121.4461, 'type' => 'municipality'],
];
$geoBarangayView = $sessionBarangayName !== '';
if ($geoBarangayView) {
    [$geoLat, $geoLng] = $barangayGeoPoints[$sessionBarangayName] ?? [14.4217, 121.4461];
    $geoMapConfig = [
        'mode' => 'barangay',
        'title' => 'Barangay ' . $sessionBarangayName,
        'subtitle' => 'Logged-in view focused on your registered barangay.',
        'center' => [$geoLat, $geoLng],
        'zoom' => 14,
        'markers' => [[
            'name' => 'Barangay ' . $sessionBarangayName,
            'label' => 'Your linked barangay',
            'lat' => $geoLat,
            'lng' => $geoLng,
            'type' => 'barangay',
        ]],
        'circleRadius' => 1200,
    ];
} else {
    $geoMapConfig = [
        'mode' => 'district',
        'title' => 'DILG District IV - Laguna',
        'subtitle' => $isAuthenticated && $sessionBarangayName === ''
            ? 'Your account has no barangay scope yet, so the public District IV map is shown.'
            : 'Public view of District IV coverage for no-login visitors.',
        'center' => [14.3200, 121.4500],
        'zoom' => 10,
        'markers' => $districtFourMarkers,
        'circleRadius' => null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SKed | Sangguniang Kabataan — Youth Profiling &amp; Event Management System</title>
  <meta name="description" content="SKed is a web-based youth profiling and event management platform for the Sangguniang Kabataan — register your KK profile, join events, and stay informed.">
  <meta name="keywords" content="SKed, Sangguniang Kabataan, SK, youth profiling, Katipunan ng Kabataan, KK registration, youth events, DILG">
  <meta name="author" content="Sangguniang Kabataan">
  <meta property="og:title" content="SKed | Youth Profiling & Event Management System">
  <meta property="og:description" content="Register your youth profile, join SK events, and stay informed through SKed.">
  <meta property="og:type" content="website">
  <meta name="robots" content="index, follow">

  <!-- Google Fonts: Sora (display) + Plus Jakarta Sans (body) to match the SKed dashboard -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- AOS (Animate on Scroll) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css">
  <!-- Leaflet (public geo map, no API key required) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

  <style>
    :root{
      --civic-deep:#4338ca;    /* civic-600 — primary action indigo (dashboard palette) */
      --civic-fresh:#4f46e5;   /* civic-500 */
      --iris-light:#e0e2f7;    /* soft indigo tint */
      --canvas:#f5f5fb;        /* cool paper */
      --ink:#1f2033;           /* ink-900 */
      --night:#1e1b4b;         /* night-900 — deepest indigo */
      --line:#e2e2f0;
      --civic-deep-rgb:67,56,202;

      /* derived accents borrowed from the dashboard palette, used sparingly for icons/badges */
      --gold:#b45309;
      --gold-light:#fdecc8;
      --coral:#c02748;
      --coral-light:#fbdbe3;
      --sky:#0369a1;
      --sky-light:#d6ebf7;
    }

    *{scroll-behavior:smooth;}

    body{
      font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
      color:var(--ink);
      background-color:#ffffff;
      font-weight:400;
    }

    h1,h2,h3,h4,h5,.brand-font,.section-title,.hero-section h1{
      font-family:'Sora','Segoe UI',system-ui,sans-serif;
      font-weight:600;
      color:var(--ink);
      letter-spacing:0;
    }
    .navbar-sked .navbar-brand,footer .navbar-brand{font-family:'Sora','Segoe UI',system-ui,sans-serif;}

    .eyebrow{
      text-transform:uppercase;
      letter-spacing:.14em;
      font-size:.78rem;
      font-weight:700;
      color:var(--civic-deep);
    }
    .eyebrow.on-dark{color:#c7cafc;}

    a{text-decoration:none;}

    a:focus-visible, button:focus-visible, .btn:focus-visible, .nav-link:focus-visible{
      outline:3px solid var(--civic-fresh);
      outline-offset:2px;
    }

    /* ===== NAVBAR (floating inset pill) ===== */
    .navbar-shell{
      position:sticky;
      top:0;
      z-index:1030;
      padding:.9rem 1rem 0 1rem;
    }
    .navbar-sked{
      background-color:rgba(255,255,255,.92);
      backdrop-filter:blur(10px);
      -webkit-backdrop-filter:blur(10px);
      box-shadow:0 14px 34px -18px rgba(30,27,75,.35), 0 1px 0 rgba(38,40,56,.05);
      border:1px solid var(--line);
      border-radius:999px;
      padding:.55rem .6rem .55rem 1.3rem;
      max-width:1180px;
      margin:0 auto;
    }
    .navbar-sked .navbar-brand{
      font-weight:800;
      font-size:1.28rem;
      color:var(--civic-deep);
      display:flex;
      align-items:center;
      gap:.55rem;
    }
    .navbar-sked .navbar-brand .logo-badge{
      width:38px;height:38px;
      background:linear-gradient(155deg,var(--civic-deep),var(--civic-fresh));
      border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      color:#fff;font-size:1.1rem;
      flex-shrink:0;
      box-shadow:0 6px 16px -4px rgba(67,56,202,.6);
    }
    .navbar-sked .nav-link{
      font-weight:500;
      font-size:.94rem;
      color:var(--ink);
      margin:0 .2rem;
      padding:.4rem .7rem !important;
      border-radius:999px;
      position:relative;
    }
    .navbar-sked .nav-link:hover,
    .navbar-sked .nav-link:focus{color:var(--civic-deep); background:var(--iris-light);}
    .navbar-sked .nav-link.active{color:var(--civic-deep); font-weight:700; background:var(--iris-light);}
    .btn-login{
      background-color:var(--civic-deep);
      color:#fff;
      border-radius:999px;
      font-weight:600;
      font-size:.92rem;
      padding:.5rem 1.2rem;
      border:2px solid var(--civic-deep);
    }
    .btn-login:hover{background-color:var(--civic-fresh);border-color:var(--civic-fresh);color:#fff;}
    .btn-register{
      background-color:#fff;
      color:var(--civic-deep);
      border-radius:999px;
      font-weight:600;
      font-size:.92rem;
      padding:.5rem 1.05rem;
      border:2px solid var(--civic-deep);
    }
    .btn-register:hover{background-color:rgba(129,140,248,.20);border-color:var(--civic-fresh);color:var(--civic-deep);}

    /* ===== HERO ===== */
    .hero-section{
      background:
        radial-gradient(900px 420px at 88% -12%, rgba(67,56,202,.12), transparent 60%),
        linear-gradient(180deg,#eef0fb 0%, var(--canvas) 100%);
      padding-top:3.4rem;
      padding-bottom:3.6rem;
      overflow:hidden;
      position:relative;
    }
    .hero-section h1{
      font-size:clamp(2.1rem, 4vw, 3.05rem);
      line-height:1.14;
    }
    .hero-section .lead-text{
      color:#4a4c68;
      font-size:1.1rem;
      max-width:560px;
    }
    .btn-civic-primary{
      background-color:var(--civic-deep);
      border:2px solid var(--civic-deep);
      color:#fff;
      font-weight:600;
      padding:.75rem 1.6rem;
      border-radius:9px;
      transition:.2s ease-in-out;
    }
    .btn-civic-primary:hover{background-color:#37319e;border-color:#37319e;color:#fff;}
    .btn-civic-outline{
      background-color:transparent;
      border:2px solid var(--civic-deep);
      color:var(--civic-deep);
      font-weight:600;
      padding:.75rem 1.6rem;
      border-radius:9px;
      transition:.2s ease-in-out;
    }
    .btn-civic-outline:hover{background-color:var(--civic-deep);color:#fff;}
    .btn-civic-primary,.btn-civic-outline{box-shadow:0 10px 24px -16px rgba(67,56,202,.9);}

    /* inline hero stat row (replaces floating badge cards) */
    .hero-stats{
      display:flex;
      flex-wrap:wrap;
      gap:0;
      margin-top:2.4rem;
      border-top:1px solid var(--line);
      padding-top:1.3rem;
    }
    .hero-stat{
      padding:0 1.4rem;
      border-right:1px solid var(--line);
      display:flex;
      flex-direction:column;
    }
    .hero-stat:first-child{padding-left:0;}
    .hero-stat:last-child{border-right:none;}
    .hero-stat strong{
      font-family:'Sora',sans-serif;
      font-size:1.35rem;
      color:var(--civic-deep);
      line-height:1.1;
    }
    .hero-stat span{font-size:.8rem; color:#5b5c78; margin-top:.15rem;}

    /* hero mock "device" panel — original graphic, not a photo */
    .hero-panel{
      background:#fff;
      border-radius:22px;
      border:1px solid var(--line);
      box-shadow:0 34px 70px -30px rgba(30,27,75,.35);
      max-width:440px;
      margin-left:auto;
      overflow:hidden;
      transform:rotate(1.2deg);
    }
    .hero-panel-bar{
      display:flex;align-items:center;gap:.4rem;
      background:var(--night);
      padding:.7rem 1rem;
    }
    .hero-panel-bar span{width:9px;height:9px;border-radius:50%;background:rgba(255,255,255,.35);}
    .hero-panel-bar-title{
      color:rgba(255,255,255,.85);
      font-size:.75rem;font-weight:600;letter-spacing:.03em;
      margin-left:.5rem;
    }
    .hero-panel-body{padding:1.5rem 1.4rem 1.6rem;}
    .hero-panel-profile{display:flex;align-items:center;gap:.8rem;margin-bottom:1.2rem;}
    .hero-panel-avatar{
      width:46px;height:46px;border-radius:50%;flex-shrink:0;
      background:var(--iris-light);color:var(--civic-deep);
      display:flex;align-items:center;justify-content:center;font-size:1.25rem;
    }
    .hero-panel-name{font-weight:700;font-size:.98rem;}
    .hero-panel-meta{font-size:.78rem;color:#5b5c78;}
    .hero-panel-status{
      margin-left:auto;
      background:var(--sky-light);color:var(--sky);
      font-size:.7rem;font-weight:700;
      padding:.3rem .55rem;border-radius:8px;
      display:inline-flex;align-items:center;gap:.3rem;
      white-space:nowrap;
    }
    .hero-panel-meter-label{display:flex;justify-content:space-between;font-size:.78rem;color:#5b5c78;margin-bottom:.4rem;}
    .hero-panel-meter-track{height:8px;border-radius:99px;background:var(--canvas);overflow:hidden;}
    .hero-panel-meter-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--civic-deep),var(--civic-fresh));}
    .hero-panel-list{margin-top:1.3rem;display:flex;flex-direction:column;gap:.55rem;}
    .hero-panel-row{
      display:flex;align-items:center;gap:.65rem;
      background:var(--canvas);
      border-radius:11px;
      padding:.55rem .8rem;
      font-size:.82rem;font-weight:600;
    }
    .hero-panel-row i{color:var(--civic-deep);font-size:1rem;}
    .hero-panel-row em{margin-left:auto;font-style:normal;color:#5b5c78;font-weight:600;font-size:.75rem;}

    /* ===== SECTION GENERIC ===== */
    .section-pad{padding:5rem 0;}
    .bg-canvas{background-color:var(--canvas);}
    .section-title{font-size:clamp(1.6rem,3vw,2.2rem);}
    .section-sub{color:#5b5c78; max-width:680px;}

    /* ===== ABOUT ===== */
    .about-stat-strip{
      display:flex;
      border:1px solid var(--line);
      border-radius:14px;
      background:#fff;
      overflow:hidden;
      margin-top:1.6rem;
    }
    .about-stat-strip .about-stat{
      flex:1;
      padding:1.1rem 1rem;
      border-right:1px solid var(--line);
      text-align:center;
    }
    .about-stat-strip .about-stat:last-child{border-right:none;}
    .about-stat-strip .about-stat i{color:var(--civic-deep);font-size:1.2rem;}
    .about-stat-strip .about-stat strong{display:block;font-family:'Sora',sans-serif;font-size:1rem;margin-top:.4rem;}
    .about-stat-strip .about-stat span{font-size:.74rem;color:#5b5c78;}

    .about-bento{display:grid;grid-template-columns:1.2fr 1fr;grid-template-rows:auto auto;gap:1rem;}
    .about-bento-feature{
      grid-row:span 2;
      border-radius:18px;
      padding:1.8rem 1.5rem;
      background:linear-gradient(160deg,var(--civic-deep),var(--civic-fresh));
      color:#fff;
      display:flex;flex-direction:column;justify-content:flex-end;
      min-height:260px;
    }
    .about-bento-feature i{font-size:1.9rem;opacity:.9;}
    .about-bento-small{
      border-radius:16px;
      padding:1.2rem 1.3rem;
      background:#fff;
      border:1px solid var(--line);
      display:flex;align-items:center;gap:.9rem;
    }
    .about-bento-small i{
      width:42px;height:42px;flex-shrink:0;border-radius:11px;
      background:var(--iris-light);color:var(--civic-deep);
      display:flex;align-items:center;justify-content:center;font-size:1.1rem;
    }
    @media (max-width:767px){
      .about-bento{grid-template-columns:1fr;}
      .about-bento-feature{grid-row:auto; min-height:180px;}
    }

    /* ===== SERVICES (operational lanes) ===== */
    .services-layout{
      display:grid;
      grid-template-columns:.78fr 1.22fr;
      gap:1.4rem;
      align-items:stretch;
    }
    .services-intro{
      min-height:100%;
      border:1px solid var(--line);
      border-radius:8px;
      padding:2rem;
      background:
        linear-gradient(155deg,rgba(30,27,75,.96),rgba(67,56,202,.94)),
        #1e1b4b;
      color:#fff;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }
    .services-intro .section-title{color:#fff;}
    .services-intro .section-sub{color:rgba(255,255,255,.78);}
    .services-note{
      display:flex;
      align-items:center;
      gap:.7rem;
      margin-top:2rem;
      padding-top:1.2rem;
      border-top:1px solid rgba(255,255,255,.18);
      color:rgba(255,255,255,.82);
      font-size:.9rem;
      line-height:1.55;
    }
    .services-note i{
      width:38px;height:38px;
      display:flex;align-items:center;justify-content:center;
      flex-shrink:0;
      border-radius:50%;
      background:rgba(255,255,255,.12);
      color:#fff;
    }
    .services-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:1rem;
    }
    .service-card{
      position:relative;
      min-height:210px;
      border:1px solid var(--line);
      border-radius:8px;
      padding:1.45rem;
      background:#fff;
      display:flex;
      flex-direction:column;
      transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .service-card:hover{
      transform:translateY(-4px);
      border-color:#c6c8ee;
      box-shadow:0 18px 38px -28px rgba(30,27,75,.45);
    }
    .service-topline{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:1rem;
      margin-bottom:1rem;
    }
    .service-icon{
      width:48px;height:48px;
      border-radius:8px;
      background:var(--iris-light);
      color:var(--civic-deep);
      display:flex;align-items:center;justify-content:center;
      font-size:1.25rem;
      flex-shrink:0;
    }
    .service-card.tone-sky .service-icon{background:var(--sky-light);color:var(--sky);}
    .service-card.tone-gold .service-icon{background:var(--gold-light);color:var(--gold);}
    .service-card.tone-coral .service-icon{background:var(--coral-light);color:var(--coral);}
    .service-number{
      font-family:'Sora',sans-serif;
      font-size:.78rem;
      font-weight:700;
      color:#8a8ba6;
    }
    .service-card h3{font-size:1.05rem;margin-bottom:.55rem;}
    .service-card p{line-height:1.65;}
    .service-link{
      margin-top:auto;
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      color:var(--civic-deep);
      font-weight:700;
      font-size:.9rem;
    }
    @media (max-width:991px){
      .services-layout{grid-template-columns:1fr;}
    }
    @media (max-width:575px){
      .services-grid{grid-template-columns:1fr;}
      .services-intro{padding:1.5rem;}
    }

    /* ===== PROCESS (horizontal flow) ===== */
    .process-flow{
      display:grid;
      grid-template-columns:repeat(5,minmax(0,1fr));
      gap:.85rem;
      align-items:stretch;
    }
    .process-step{
      position:relative;
      border:1px solid var(--line);
      border-radius:8px;
      background:#fff;
      padding:1.25rem 1rem;
      min-height:210px;
      display:flex;
      flex-direction:column;
      gap:.9rem;
    }
    .process-step:not(:last-child)::after{
      content:"";
      position:absolute;
      top:2.1rem;
      right:-.85rem;
      width:.85rem;
      height:2px;
      background:var(--iris-light);
    }
    .process-number{
      width:34px;height:34px;
      border-radius:50%;
      background:var(--civic-deep);
      color:#fff;
      display:flex;align-items:center;justify-content:center;
      font-weight:800;
      font-size:.9rem;
      flex-shrink:0;
    }
    .process-icon{
      width:46px;height:46px;
      border-radius:8px;
      display:flex;align-items:center;justify-content:center;
      background:var(--canvas);
      color:var(--civic-deep);
      font-size:1.2rem;
    }
    .process-step p{line-height:1.6;}
    @media (max-width:991px){
      .process-flow{grid-template-columns:repeat(2,minmax(0,1fr));}
      .process-step:not(:last-child)::after{display:none;}
    }
    @media (max-width:575px){
      .process-flow{grid-template-columns:1fr;}
      .process-step{min-height:0;}
    }

    /* ===== WHY CHOOSE (badge cards) ===== */
    .why-card{
      background:#fff;
      border-radius:16px;
      padding:2rem 1.4rem;
      height:100%;
      border:1px solid var(--line);
      text-align:center;
      transition:transform .2s ease, box-shadow .2s ease;
    }
    .why-card:hover{transform:translateY(-4px); box-shadow:0 18px 36px -22px rgba(38,40,56,.3);}
    .why-ring{
      width:64px;height:64px;
      border-radius:50%;
      margin:0 auto 1rem auto;
      display:flex;align-items:center;justify-content:center;
      font-size:1.5rem;
    }

    /* ===== STATS ===== */
    .stats-band{
      background:#fff;
      border-top:1px solid var(--line);
      border-bottom:1px solid var(--line);
    }
    .stats-panel{
      display:grid;
      grid-template-columns:.9fr 1.4fr;
      gap:1.2rem;
      align-items:stretch;
    }
    .stats-copy{
      border-radius:8px;
      padding:1.6rem;
      background:var(--night);
      color:#fff;
      display:flex;
      flex-direction:column;
      justify-content:center;
    }
    .stats-copy .section-title{color:#fff;}
    .stats-copy p{color:rgba(255,255,255,.78);}
    .stats-grid{
      display:grid;
      grid-template-columns:repeat(4,minmax(0,1fr));
      gap:.9rem;
    }
    .stat-card{
      border:1px solid var(--line);
      border-radius:8px;
      padding:1.25rem 1rem;
      background:#fff;
      min-height:170px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }
    .stat-card i{
      width:42px;height:42px;
      border-radius:8px;
      display:flex;align-items:center;justify-content:center;
      background:var(--iris-light);
      color:var(--civic-deep);
      font-size:1.1rem;
    }
    .stat-card.tone-sky i{background:var(--sky-light);color:var(--sky);}
    .stat-card.tone-gold i{background:var(--gold-light);color:var(--gold);}
    .stat-card.tone-coral i{background:var(--coral-light);color:var(--coral);}
    .stat-figure{
      font-family:'Sora',sans-serif;
      font-size:clamp(1.65rem,3vw,2.35rem);
      font-weight:800;
      line-height:1;
      color:var(--night);
    }
    .stat-label{
      font-size:.84rem;
      color:#5b5c78;
      margin-top:.35rem;
      font-weight:700;
    }
    @media (max-width:991px){
      .stats-panel{grid-template-columns:1fr;}
    }
    @media (max-width:767px){
      .stats-grid{grid-template-columns:repeat(2,1fr);}
    }
    @media (max-width:420px){
      .stats-grid{grid-template-columns:1fr;}
    }

    /* ===== FAQ ===== */
    .faq-layout{
      display:grid;
      grid-template-columns:.78fr 1.22fr;
      gap:1.5rem;
      align-items:start;
    }
    .faq-guide{
      position:sticky;
      top:6rem;
      border:1px solid var(--line);
      border-radius:8px;
      background:var(--canvas);
      padding:1.5rem;
    }
    .faq-guide-icon{
      width:48px;height:48px;
      border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      background:#fff;
      color:var(--civic-deep);
      border:1px solid var(--line);
      margin-bottom:1rem;
    }
    .faq-chip-row{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:1rem;}
    .faq-chip{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      border:1px solid var(--line);
      border-radius:999px;
      background:#fff;
      color:#5b5c78;
      padding:.45rem .7rem;
      font-size:.8rem;
      font-weight:700;
    }
    .faq-groups{
      display:grid;
      gap:1.2rem;
    }
    .faq-group{
      border:1px solid var(--line);
      border-radius:8px;
      background:#fff;
      padding:1rem;
    }
    .faq-col-label{
      display:flex;
      align-items:center;
      gap:.5rem;
      font-family:'Sora',sans-serif;
      font-size:.82rem;
      font-weight:700;
      color:var(--civic-deep);
      text-transform:uppercase;
      letter-spacing:.06em;
      margin-bottom:.8rem;
    }
    .accordion-button{
      font-weight:700;
      color:var(--ink);
      background-color:#fff;
      font-size:.95rem;
      padding:1rem 1.05rem;
    }
    .accordion-button:not(.collapsed){
      color:var(--civic-deep);
      background-color:#f8f8ff;
      box-shadow:none;
    }
    .accordion-button:focus{box-shadow:0 0 0 .2rem rgba(79,70,229,.18);}
    .accordion-item{
      border:1px solid var(--line);
      border-radius:8px !important;
      overflow:hidden;
      margin-bottom:.65rem;
    }
    .accordion-item:last-child{margin-bottom:0;}
    .accordion-body{line-height:1.7;}
    @media (max-width:991px){
      .faq-layout{grid-template-columns:1fr;}
      .faq-guide{position:static;}
    }

    /* ===== CONTACT ===== */
    .contact-tiles{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;}
    .contact-tile{
      background:#fff;
      border-radius:16px;
      border:1px solid var(--line);
      padding:1.3rem 1.2rem;
    }
    .contact-tile i{
      width:38px;height:38px;
      border-radius:10px;
      background:rgba(67,56,202,.1);
      color:var(--civic-deep);
      display:flex;align-items:center;justify-content:center;
      font-size:1rem;
      margin-bottom:.7rem;
    }
    .geo-map-shell{
      display:grid;
      grid-template-columns:minmax(0,1fr) 260px;
      min-height:390px;
      border:1px solid var(--line);
      border-radius:8px;
      overflow:hidden;
      background:#fff;
      box-shadow:0 20px 45px -36px rgba(30,27,75,.5);
    }
    .geo-map{
      min-height:390px;
      background:var(--canvas);
      z-index:1;
    }
    .geo-map-panel{
      border-left:1px solid var(--line);
      padding:1.2rem;
      display:flex;
      flex-direction:column;
      min-width:0;
    }
    .geo-map-badge{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      width:max-content;
      max-width:100%;
      padding:.4rem .65rem;
      border-radius:999px;
      background:var(--iris-light);
      color:var(--civic-deep);
      font-size:.78rem;
      font-weight:800;
      margin-bottom:.85rem;
    }
    .geo-marker-list{
      margin-top:1rem;
      display:grid;
      gap:.45rem;
      max-height:190px;
      overflow:auto;
      padding-right:.25rem;
    }
    .geo-marker-item{
      display:flex;
      align-items:flex-start;
      gap:.55rem;
      padding:.55rem .6rem;
      border:1px solid var(--line);
      border-radius:8px;
      background:#fbfbff;
      font-size:.84rem;
      line-height:1.35;
    }
    .geo-marker-item i{
      color:var(--civic-deep);
      margin-top:.1rem;
    }
    .geo-marker-label{
      color:#5b5c78;
      font-size:.74rem;
    }
    .geo-map-note{
      margin-top:auto;
      padding-top:1rem;
      color:#5b5c78;
      font-size:.78rem;
      line-height:1.55;
    }
    .leaflet-container{
      font-family:'Plus Jakarta Sans',system-ui,sans-serif;
    }
    @media (max-width:991px){
      .geo-map-shell{grid-template-columns:1fr;}
      .geo-map-panel{border-left:0;border-top:1px solid var(--line);}
    }

    /* ===== FOOTER ===== */
    footer.site-footer{
      background-color:var(--ink);
      color:rgba(255,255,255,.82);
    }
    footer.site-footer a{color:rgba(255,255,255,.72);}
    footer.site-footer a:hover{color:var(--iris-light);}
    footer.site-footer h6{
      color:#fff;
      font-weight:700;
      letter-spacing:.03em;
      font-size:.82rem;
      text-transform:uppercase;
    }
    .footer-divider{border-color:rgba(255,255,255,.12);}
    .footer-top{border-bottom:1px solid rgba(255,255,255,.12);}
    .social-icon{
      width:38px;height:38px;
      border-radius:50%;
      background:rgba(255,255,255,.08);
      display:flex;align-items:center;justify-content:center;
      color:#fff;
    }
    .social-icon:hover{background:var(--civic-fresh);}

    /* ===== ANNOUNCEMENTS CAROUSEL (public bulletin layout) ===== */
    .announce-band{
      background:
        linear-gradient(180deg,#fff 0%,#f7f7fc 100%);
      border-top:1px solid var(--line);
      border-bottom:1px solid var(--line);
    }
    .announce-heading{
      display:flex;
      align-items:end;
      justify-content:space-between;
      gap:1.5rem;
      margin-bottom:1.6rem;
    }
    .announce-heading .section-sub{margin-bottom:0;}
    .announce-count{
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      color:var(--civic-deep);
      background:#fff;
      border:1px solid var(--line);
      border-radius:8px;
      padding:.55rem .8rem;
      font-size:.86rem;
      font-weight:700;
      white-space:nowrap;
      box-shadow:0 12px 28px -24px rgba(30,27,75,.8);
    }
    .announce-carousel{
      overflow:hidden;
      border:1px solid var(--line);
      border-radius:8px;
      background:#fff;
      box-shadow:0 24px 55px -42px rgba(20,19,59,.55);
    }
    .announce-empty{
      padding:3.5rem 1.5rem;
      border:1px dashed #c8cae6;
      border-radius:8px;
      background:#fff;
      color:#5b5c78;
    }
    .announce-empty-icon{
      width:54px;
      height:54px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:50%;
      background:var(--iris-light);
      color:var(--civic-deep);
      font-size:1.55rem;
      margin-bottom:.85rem;
    }
    .announce-slide{
      min-height:370px;
      display:grid;
      grid-template-columns:280px minmax(280px,.8fr) minmax(0,1fr);
      text-decoration:none;
      color:inherit;
      transition:box-shadow .2s ease;
    }
    a.announce-slide:hover{
      color:inherit;
      box-shadow:0 14px 40px rgba(30,27,75,.14);
    }
    a.announce-slide:hover .announce-title{
      text-decoration:underline;
    }
    .announce-rail{
      position:relative;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      gap:2rem;
      padding:2rem;
      color:#fff;
      background:
        linear-gradient(135deg,rgba(67,56,202,.92),rgba(30,27,75,.98)),
        repeating-linear-gradient(135deg,rgba(255,255,255,.12) 0 1px,transparent 1px 14px);
    }
    .announce-rail::after{
      content:"";
      position:absolute;
      top:1.25rem;
      right:0;
      bottom:1.25rem;
      width:1px;
      background:repeating-linear-gradient(180deg,rgba(255,255,255,.55) 0 7px,transparent 7px 15px);
    }
    .announce-index{
      width:62px;
      height:62px;
      display:flex;
      align-items:center;
      justify-content:center;
      border:1px solid rgba(255,255,255,.32);
      border-radius:50%;
      background:rgba(255,255,255,.12);
      font-family:'Sora','Segoe UI',system-ui,sans-serif;
      font-size:1.35rem;
      font-weight:700;
    }
    .announce-pill{
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      max-width:100%;
      color:#fff;
      background:rgba(255,255,255,.14);
      border:1px solid rgba(255,255,255,.2);
      border-radius:8px;
      padding:.48rem .7rem;
      font-size:.72rem;
      font-weight:800;
      letter-spacing:.08em;
      line-height:1.25;
      text-transform:uppercase;
    }
    .announce-type-tag{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      max-width:100%;
      color:#fff;
      border-radius:8px;
      padding:.4rem .65rem;
      font-size:.68rem;
      font-weight:800;
      letter-spacing:.08em;
      line-height:1.25;
      text-transform:uppercase;
    }
    .announce-type-announcement{background:rgba(245,158,11,.28);border:1px solid rgba(245,158,11,.4);}
    .announce-type-event{background:rgba(14,165,164,.28);border:1px solid rgba(14,165,164,.4);}
    .announce-rail-note{
      color:rgba(255,255,255,.76);
      font-size:.86rem;
      line-height:1.6;
      margin:1rem 0 0;
    }
    .announce-media{
      position:relative;
      min-height:100%;
      overflow:hidden;
      background:
        linear-gradient(135deg,rgba(14,165,164,.18),rgba(245,158,11,.2)),
        #eef0fb;
    }
    .announce-media img{
      width:100%;
      height:100%;
      min-height:370px;
      display:block;
      object-fit:cover;
    }
    .announce-media-placeholder{
      height:100%;
      min-height:370px;
      display:flex;
      align-items:center;
      justify-content:center;
      color:var(--civic-deep);
      font-size:3.4rem;
    }
    .announce-media::after{
      content:"";
      position:absolute;
      inset:0;
      background:linear-gradient(180deg,transparent 45%,rgba(30,27,75,.18));
      pointer-events:none;
    }
    .announce-copy{
      display:flex;
      flex-direction:column;
      justify-content:center;
      min-width:0;
      padding:2.5rem 2.7rem;
      background:
        linear-gradient(90deg,rgba(224,226,247,.34),transparent 36%),
        #fff;
    }
    .announce-meta{
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      gap:.7rem;
      margin-bottom:1.15rem;
    }
    .announce-schedule,
    .announce-date{
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      border-radius:8px;
      padding:.52rem .75rem;
      font-size:.86rem;
      font-weight:700;
      line-height:1.25;
    }
    .announce-schedule{background:var(--gold-light);color:var(--gold);}
    .announce-date{background:var(--sky-light);color:var(--sky);}
    .announce-title{
      font-family:'Sora','Segoe UI',system-ui,sans-serif;
      font-size:clamp(1.55rem,3vw,2.35rem);
      font-weight:700;
      line-height:1.13;
      margin-bottom:.9rem;
      color:var(--night);
    }
    .announce-body{
      max-width:760px;
      color:#4a4c68;
      font-size:1rem;
      line-height:1.75;
      margin-bottom:0;
      display:-webkit-box;
      -webkit-line-clamp:4;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .announce-footer{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:1rem;
      padding:1rem 1.15rem;
      border-top:1px solid var(--line);
      background:#fbfbff;
    }
    .announce-carousel .carousel-indicators{
      position:static;
      display:flex;
      align-items:center;
      gap:.5rem;
      justify-content:flex-start;
      margin:0;
      padding:0;
    }
    .announce-carousel .carousel-indicators [data-bs-target]{
      width:2.15rem;
      height:.28rem;
      margin:0;
      border:0;
      border-radius:999px;
      background-color:#b9bbd7;
      opacity:1;
    }
    .announce-carousel .carousel-indicators .active{background-color:var(--civic-deep);}
    .announce-controls{
      display:flex;
      align-items:center;
      gap:.55rem;
      flex-shrink:0;
    }
    .announce-control{
      position:static;
      width:42px;
      height:42px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border:1px solid var(--line);
      border-radius:50%;
      background:#fff;
      color:var(--civic-deep);
      opacity:1;
      box-shadow:0 10px 24px -20px rgba(30,27,75,.8);
    }
    .announce-control:hover,
    .announce-control:focus{background:var(--civic-deep);color:#fff;}
    @media (max-width:991.98px){
      .announce-heading{align-items:flex-start;flex-direction:column;}
      .announce-slide{grid-template-columns:1fr;min-height:0;}
      .announce-rail{
        min-height:190px;
        padding:1.5rem;
      }
      .announce-rail::after{
        top:auto;
        left:1.25rem;
        right:1.25rem;
        bottom:0;
        width:auto;
        height:1px;
        background:repeating-linear-gradient(90deg,rgba(255,255,255,.55) 0 7px,transparent 7px 15px);
      }
      .announce-media,
      .announce-media img,
      .announce-media-placeholder{
        min-height:260px;
      }
      .announce-copy{padding:1.75rem 1.45rem 2rem;}
    }
    @media (max-width:575.98px){
      .announce-count{white-space:normal;}
      .announce-rail{gap:1.4rem;}
      .announce-index{width:52px;height:52px;font-size:1.1rem;}
      .announce-media,
      .announce-media img,
      .announce-media-placeholder{min-height:210px;}
      .announce-title{font-size:1.45rem;}
      .announce-body{-webkit-line-clamp:5;}
      .announce-footer{align-items:flex-start;flex-direction:column;}
      .announce-carousel .carousel-indicators{width:100%;}
      .announce-carousel .carousel-indicators [data-bs-target]{flex:1;}
      .announce-controls{width:100%;justify-content:flex-end;}
    }

    @media (prefers-reduced-motion: reduce){
      *{animation:none !important; transition:none !important;}
    }
  </style>
</head>
<body>

  <!-- =========================================================
       1. NAVBAR (Sticky, floating inset pill)
  ========================================================== -->
  <div class="navbar-shell">
    <header>
      <nav class="navbar navbar-expand-lg navbar-sked" aria-label="Primary navigation">
        <div class="container-fluid px-2">
          <a class="navbar-brand" href="#home">
            <span class="logo-badge"><i class="bi bi-people-fill"></i></span>
            SKed
          </a>
          <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                  aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
          </button>
          <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
              <li class="nav-item"><a class="nav-link active" href="#home">Home</a></li>
              <li class="nav-item"><a class="nav-link" href="#announcements">Announcements</a></li>
              <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
              <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
              <li class="nav-item"><a class="nav-link" href="#process">Process</a></li>
              <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
              <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
              <?php if ($isAuthenticated): ?>
              <li class="nav-item ms-lg-3 mt-2 mt-lg-0">
                <a href="<?php echo e($viewerDashboardPath); ?>" class="btn btn-login"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
              </li>
              <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                <form method="post" action="auth/logout.php" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_logout_token']); ?>">
                  <button type="submit" class="btn btn-register"><i class="bi bi-box-arrow-right me-1"></i>Logout</button>
                </form>
              </li>
              <?php else: ?>
              <li class="nav-item ms-lg-3 mt-2 mt-lg-0">
                <a href="auth/login.php" class="btn btn-login"><i class="bi bi-box-arrow-in-right me-1"></i>Login</a>
              </li>
              <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                <a href="auth/register.php" class="btn btn-register"><i class="bi bi-person-plus me-1"></i>Get Started</a>
              </li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </nav>
    </header>
  </div>

  <main>
    <!-- =========================================================
         2. HERO SECTION
    ========================================================== -->
    <section id="home" class="hero-section">
      <div class="container">
        <div class="row align-items-center gy-5">
          <div class="col-lg-6" data-aos="fade-up">
            <span class="eyebrow"><i class="bi bi-patch-check-fill me-1"></i>Official Sangguniang Kabataan Platform &middot; Youth Governance</span>
            <h1 class="mt-3 mb-3">Empowering the Filipino Youth, One Profile at a Time.</h1>
            <p class="lead-text mb-4">
              SKed lets the youth register their Katipunan ng Kabataan (KK) profile, join Sangguniang
              Kabataan events, and stay informed — while SK Chairpersons, the Pederasyon, and DILG
              manage youth data and programs across barangays in one transparent, accountable system.
            </p>
            <div class="d-flex flex-wrap gap-3">
              <a href="<?php echo e($primaryRegisterHref); ?>" class="btn btn-civic-primary"><i class="bi bi-person-vcard me-2"></i><?php echo $isAuthenticated ? 'Go to Dashboard' : 'Register as Youth'; ?></a>
              <a href="<?php echo e($primaryCtaHref); ?>" class="btn btn-civic-outline"><i class="bi bi-calendar-event me-2"></i>Browse Events</a>
            </div>
            <div class="hero-stats">
              <div class="hero-stat"><strong>15–30</strong><span>years old eligible</span></div>
              <div class="hero-stat"><strong><?php echo (int) $totalBarangays; ?></strong><span>barangays linked</span></div>
              <div class="hero-stat"><strong>DILG</strong><span>-aligned system</span></div>
            </div>
          </div>
          <div class="col-lg-6" data-aos="fade-left" data-aos-delay="100">
            <!-- Signature graphic: mock "device panel" of a youth profile, not a stock photo -->
            <div class="hero-panel">
              <div class="hero-panel-bar">
                <span></span><span></span><span></span>
                <div class="hero-panel-bar-title">KK Youth Profile</div>
              </div>
              <div class="hero-panel-body">
                <div class="hero-panel-profile">
                  <div class="hero-panel-avatar"><i class="bi bi-person-fill"></i></div>
                  <div>
                    <div class="hero-panel-name">Juan Dela Cruz</div>
                    <div class="hero-panel-meta">Brgy. San Isidro &middot; Age 22</div>
                  </div>
                  <span class="hero-panel-status"><i class="bi bi-check-circle-fill"></i>Verified</span>
                </div>
                <div class="hero-panel-meter">
                  <div class="hero-panel-meter-label"><span>Profile completion</span><span>92%</span></div>
                  <div class="hero-panel-meter-track"><div class="hero-panel-meter-fill" style="width:92%;"></div></div>
                </div>
                <div class="hero-panel-list">
                  <div class="hero-panel-row"><i class="bi bi-calendar-event"></i><span>Leadership Summit</span><em>Aug 12</em></div>
                  <div class="hero-panel-row"><i class="bi bi-mortarboard"></i><span>Scholarship Orientation</span><em>Aug 20</em></div>
                  <div class="hero-panel-row"><i class="bi bi-tree"></i><span>Coastal Cleanup Drive</span><em>Sep 3</em></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         2b. ANNOUNCEMENTS CAROUSEL
    ========================================================== -->
    <section id="announcements" class="section-pad announce-band">
      <div class="container">
        <div class="announce-heading" data-aos="fade-up">
          <div>
            <span class="eyebrow"><i class="bi bi-megaphone-fill me-1"></i>Public Advisories</span>
            <h2 class="section-title mt-2 mb-2">Announcements &amp; Youth Programs</h2>
            <p class="section-sub">Official notices from the Sangguniang Kabataan — including upcoming events, registration windows, and youth development programs.</p>
          </div>
          <span class="announce-count"><i class="bi bi-broadcast-pin"></i><?php echo count($announcements); ?> live update<?php echo count($announcements) === 1 ? '' : 's'; ?></span>
        </div>

        <?php if (empty($announcements)): ?>
          <div class="announce-empty text-center" data-aos="fade-up">
            <span class="announce-empty-icon"><i class="bi bi-megaphone"></i></span>
            <h3 class="h5 mb-2">No announcements right now</h3>
            <p class="mb-0">Check back soon for SK advisories, registration windows, and public program updates.</p>
          </div>
        <?php else: ?>
        <div id="announceCarousel" class="carousel slide announce-carousel" data-bs-ride="carousel" data-bs-interval="7000" data-aos="fade-up">
          <div class="carousel-inner">
            <?php foreach ($announcements as $index => $announcement): ?>
              <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                <a class="announce-slide" href="<?php echo e($announcement['url']); ?>">
                  <div class="announce-rail">
                    <div>
                      <div class="announce-index"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></div>
                      <p class="announce-rail-note">Verified public bulletin from SKed's event and program board.</p>
                    </div>
                    <span class="announce-pill"><i class="bi <?php echo e($announcement['pill_icon']); ?>"></i> <?php echo e($announcement['pill']); ?></span>
                    <span class="announce-type-tag announce-type-<?php echo $announcement['type_label'] === 'Announcement' ? 'announcement' : 'event'; ?>"><?php echo e($announcement['type_label']); ?></span>
                  </div>
                  <div class="announce-media">
                    <?php if ($announcement['image_url'] !== ''): ?>
                      <img src="<?php echo e($announcement['image_url']); ?>" alt="<?php echo e($announcement['title']); ?>">
                    <?php else: ?>
                      <div class="announce-media-placeholder" aria-hidden="true"><i class="bi bi-megaphone"></i></div>
                    <?php endif; ?>
                  </div>
                  <div class="announce-copy">
                    <div class="announce-meta">
                      <?php if (!empty($announcement['schedule'])): ?>
                      <span class="announce-schedule"><i class="bi <?php echo e($announcement['schedule_icon']); ?>"></i> <?php echo e($announcement['schedule']); ?></span>
                      <?php endif; ?>
                      <span class="announce-date"><i class="bi bi-clock-history"></i><?php echo e($announcement['posted']); ?></span>
                    </div>
                    <h3 class="announce-title"><?php echo e($announcement['title']); ?></h3>
                    <p class="announce-body"><?php echo e($announcement['body']); ?></p>
                  </div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="announce-footer">
            <div class="carousel-indicators">
              <?php foreach ($announcements as $index => $announcement): ?>
                <button type="button" data-bs-target="#announceCarousel" data-bs-slide-to="<?php echo (int) $index; ?>" <?php echo $index === 0 ? 'class="active" aria-current="true"' : ''; ?> aria-label="Slide <?php echo (int) $index + 1; ?>"></button>
              <?php endforeach; ?>
            </div>
            <div class="announce-controls">
              <button class="carousel-control-prev announce-control" type="button" data-bs-target="#announceCarousel" data-bs-slide="prev">
                <i class="bi bi-arrow-left" aria-hidden="true"></i><span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next announce-control" type="button" data-bs-target="#announceCarousel" data-bs-slide="next">
                <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="visually-hidden">Next</span>
              </button>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- =========================================================
         3. ABOUT SECTION
    ========================================================== -->
    <section id="about" class="section-pad">
      <div class="container">
        <div class="row align-items-center gy-5">
          <div class="col-lg-6" data-aos="fade-right">
            <span class="eyebrow">About SKed</span>
            <h2 class="section-title mt-2 mb-3">A digital bridge between the youth and the Sangguniang Kabataan</h2>
            <p class="section-sub mb-0">
              SKed (Sangguniang Kabataan Electronic Directory) is a web-based youth profiling and event
              management platform for the SK. It brings the Katipunan ng Kabataan (KK) registry online,
              streamlines event registration and attendance, and gives SK Chairpersons, the Pederasyon
              ng mga SK (PPSK), and the DILG a shared, transparent view of youth data across barangays —
              replacing paper forms with a process everyone can track in real time.
            </p>
            <div class="about-stat-strip">
              <div class="about-stat">
                <i class="bi bi-diagram-3"></i>
                <strong>Streamlined</strong>
                <span>Profile to participation</span>
              </div>
              <div class="about-stat">
                <i class="bi bi-eye"></i>
                <strong>Transparent</strong>
                <span>Track events &amp; programs</span>
              </div>
            </div>
          </div>
          <div class="col-lg-6" data-aos="fade-left">
            <div class="about-bento">
              <div class="about-bento-feature">
                <i class="bi bi-person-vcard mb-3"></i>
                <p class="fw-semibold mb-0 fs-5">Youth profiling across every barangay</p>
              </div>
              <div class="about-bento-small">
                <i class="bi bi-calendar-event"></i>
                <p class="fw-semibold mb-0">Organized, trackable SK events</p>
              </div>
              <div class="about-bento-small">
                <i class="bi bi-buildings"></i>
                <p class="fw-semibold mb-0">Aligned with DILG &amp; SK Reform Act</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         4. SERVICES SECTION
    ========================================================== -->
    <section id="services" class="section-pad bg-canvas">
      <div class="container">
        <div class="services-layout" data-aos="fade-up">
          <div class="services-intro">
            <div>
              <span class="eyebrow on-dark">What You Can Do</span>
              <h2 class="section-title mt-2">Our Core Services</h2>
              <p class="section-sub mt-3 mb-0">
                SKed keeps daily youth-service work in one place: verified profiles, program participation, public notices, and direct feedback.
              </p>
            </div>
            <div class="services-note">
              <i class="bi bi-shield-check"></i>
              <span>Built for barangay-level SK operations, with youth-facing access kept simple and accountable.</span>
            </div>
          </div>
          <div class="services-grid">
            <div class="service-card">
              <div class="service-topline">
                <div class="service-icon"><i class="bi bi-person-vcard"></i></div>
                <div class="service-number">01</div>
              </div>
              <h3>Youth Profiling</h3>
              <p class="text-muted mb-3">Register and maintain the Katipunan ng Kabataan profile used for planning and validation.</p>
              <a href="<?php echo e($primaryRegisterHref); ?>" class="service-link">Register now <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="service-card tone-sky">
              <div class="service-topline">
                <div class="service-icon"><i class="bi bi-calendar-event"></i></div>
                <div class="service-number">02</div>
              </div>
              <h3>Event Registration</h3>
              <p class="text-muted mb-3">Browse SK activities, reserve slots, and keep participation records traceable.</p>
              <a href="<?php echo e($primaryCtaHref); ?>" class="service-link">Join events <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="service-card tone-gold">
              <div class="service-topline">
                <div class="service-icon"><i class="bi bi-megaphone"></i></div>
                <div class="service-number">03</div>
              </div>
              <h3>Announcements</h3>
              <p class="text-muted mb-3">Read public advisories, program schedules, and barangay-wide notices from the SK.</p>
              <a href="#announcements" class="service-link">View notices <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="service-card tone-coral">
              <div class="service-topline">
                <div class="service-icon"><i class="bi bi-chat-left-text"></i></div>
                <div class="service-number">04</div>
              </div>
              <h3>Feedback &amp; Concerns</h3>
              <p class="text-muted mb-3">Send community input to the SK Council for review and response.</p>
              <a href="<?php echo e($primaryCtaHref); ?>" class="service-link">Share feedback <i class="bi bi-arrow-right"></i></a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         5. PROCESS SECTION
    ========================================================== -->
    <section id="process" class="section-pad">
      <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
          <span class="eyebrow">How It Works</span>
          <h2 class="section-title mt-2">From Registration to Participation in 5 Steps</h2>
          <p class="section-sub mx-auto mt-2">Every youth follows the same clear path the SK uses to register, validate, and engage members.</p>
        </div>
        <div class="process-flow">
          <div class="process-step" data-aos="fade-up" data-aos-delay="0">
            <div class="d-flex align-items-center justify-content-between">
              <div class="process-number">1</div>
              <div class="process-icon"><i class="bi bi-person-plus"></i></div>
            </div>
              <h3 class="h6 mb-1">Register</h3>
              <p class="text-muted small mb-0">Create your youth (KK) profile online.</p>
          </div>
          <div class="process-step" data-aos="fade-up" data-aos-delay="100">
            <div class="d-flex align-items-center justify-content-between">
              <div class="process-number">2</div>
              <div class="process-icon"><i class="bi bi-patch-check"></i></div>
            </div>
              <h3 class="h6 mb-1">Validation</h3>
              <p class="text-muted small mb-0">Your SK Chairman verifies your details.</p>
          </div>
          <div class="process-step" data-aos="fade-up" data-aos-delay="200">
            <div class="d-flex align-items-center justify-content-between">
              <div class="process-number">3</div>
              <div class="process-icon"><i class="bi bi-person-vcard"></i></div>
            </div>
              <h3 class="h6 mb-1">Approval</h3>
              <p class="text-muted small mb-0">Your profile is added to the KK registry.</p>
          </div>
          <div class="process-step" data-aos="fade-up" data-aos-delay="300">
            <div class="d-flex align-items-center justify-content-between">
              <div class="process-number">4</div>
              <div class="process-icon"><i class="bi bi-calendar-check"></i></div>
            </div>
              <h3 class="h6 mb-1">Join Events</h3>
              <p class="text-muted small mb-0">Register for SK events and programs.</p>
          </div>
          <div class="process-step" data-aos="fade-up" data-aos-delay="400">
            <div class="d-flex align-items-center justify-content-between">
              <div class="process-number">5</div>
              <div class="process-icon"><i class="bi bi-clipboard-data"></i></div>
            </div>
              <h3 class="h6 mb-1">Track</h3>
              <p class="text-muted small mb-0">Follow your participation and attendance.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         6. WHY CHOOSE SKED
    ========================================================== -->
    <section class="section-pad bg-canvas">
      <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
          <span class="eyebrow">Why Choose SKed</span>
          <h2 class="section-title mt-2">Built for Speed, Trust, and Accountability</h2>
        </div>
        <div class="row g-4">
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
            <div class="why-card">
              <div class="why-ring" style="background:var(--gold-light);color:var(--gold);"><i class="bi bi-lightning-charge"></i></div>
              <h3 class="h6 mt-2">Fast Registration</h3>
              <p class="text-muted small mb-0">Register your youth profile online in minutes, no paper forms.</p>
            </div>
          </div>
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
            <div class="why-card">
              <div class="why-ring" style="background:var(--sky-light);color:var(--sky);"><i class="bi bi-eye"></i></div>
              <h3 class="h6 mt-2">Transparent Events</h3>
              <p class="text-muted small mb-0">See every SK event, schedule, and your own registrations clearly.</p>
            </div>
          </div>
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
            <div class="why-card">
              <div class="why-ring" style="background:var(--iris-light);color:var(--civic-deep);"><i class="bi bi-laptop"></i></div>
              <h3 class="h6 mt-2">Fully Digital</h3>
              <p class="text-muted small mb-0">Profile, register, and stay informed online — anytime, anywhere.</p>
            </div>
          </div>
          <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
            <div class="why-card">
              <div class="why-ring" style="background:var(--coral-light);color:var(--coral);"><i class="bi bi-shield-lock"></i></div>
              <h3 class="h6 mt-2">Secure &amp; Verified</h3>
              <p class="text-muted small mb-0">Role-based access protects youth records and SK data.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         7. STATS SECTION
    ========================================================== -->
    <section class="section-pad stats-band">
      <div class="container">
        <div class="stats-panel" data-aos="fade-up">
          <div class="stats-copy">
            <span class="eyebrow on-dark">Community Reach</span>
            <h2 class="section-title mt-2 mb-3">A quick view before the questions</h2>
            <p class="mb-0">These counters frame what SKed is built to organize: youth profiles, programs, barangay coverage, and completed initiatives.</p>
          </div>
          <div class="stats-grid">
            <div class="stat-card">
              <i class="bi bi-people-fill"></i>
              <div>
                <div class="stat-figure"><?php echo number_format($totalYouthRegistered); ?></div>
                <div class="stat-label">Youth Registered</div>
              </div>
            </div>
            <div class="stat-card tone-sky">
              <i class="bi bi-calendar-event"></i>
              <div>
                <div class="stat-figure"><?php echo number_format($eventsHostedCount); ?></div>
                <div class="stat-label">Events Hosted</div>
              </div>
            </div>
            <div class="stat-card tone-gold">
              <i class="bi bi-signpost-split"></i>
              <div>
                <div class="stat-figure"><?php echo (int) $totalBarangays; ?></div>
                <div class="stat-label">Barangays Covered</div>
              </div>
            </div>
            <div class="stat-card tone-coral">
              <i class="bi bi-trophy"></i>
              <div>
                <div class="stat-figure"><?php echo number_format($programsCompletedCount); ?></div>
                <div class="stat-label">Programs Completed</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         8. FAQ SECTION
    ========================================================== -->
    <section id="faq" class="section-pad">
      <div class="container">
        <div class="faq-layout">
          <div class="faq-guide" data-aos="fade-up">
            <div class="faq-guide-icon"><i class="bi bi-question-lg"></i></div>
            <span class="eyebrow">Common Questions</span>
            <h2 class="section-title mt-2 mb-3">Frequently Asked Questions</h2>
            <p class="section-sub mb-0">The essentials for youth registration, account roles, event participation, and tracking activity inside SKed.</p>
            <div class="faq-chip-row">
              <span class="faq-chip"><i class="bi bi-person-vcard"></i> Registration</span>
              <span class="faq-chip"><i class="bi bi-diagram-3"></i> Roles</span>
              <span class="faq-chip"><i class="bi bi-calendar-event"></i> Events</span>
            </div>
          </div>
          <div class="faq-groups">
            <div class="faq-group" data-aos="fade-up">
            <div class="faq-col-label"><i class="bi bi-person-check"></i>Registration &amp; Roles</div>
            <div class="accordion" id="faqAccordionA">
              <div class="accordion-item" data-aos="fade-up">
                <h3 class="accordion-header" id="faqHead1">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqOne" aria-expanded="true" aria-controls="faqOne">
                    Who can register as a youth on SKed?
                  </button>
                </h3>
                <div id="faqOne" class="accordion-collapse collapse show" aria-labelledby="faqHead1" data-bs-parent="#faqAccordionA">
                  <div class="accordion-body text-muted">
                    Any Filipino youth aged 15 to 30 who resides in the barangay may register as a member
                    of the Katipunan ng Kabataan (KK). Your SK Chairman validates your details before your
                    profile is added to the official registry.
                  </div>
                </div>
              </div>
              <div class="accordion-item" data-aos="fade-up">
                <h3 class="accordion-header" id="faqHead3">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqThree" aria-expanded="false" aria-controls="faqThree">
                    What is the difference between the SK Chairman, PPSK, and DILG roles?
                  </button>
                </h3>
                <div id="faqThree" class="accordion-collapse collapse" aria-labelledby="faqHead3" data-bs-parent="#faqAccordionA">
                  <div class="accordion-body text-muted">
                    The SK Chairman manages youth and events at the barangay level. The PPSK (Pederasyon
                    President) oversees all SK councils across barangays. The DILG serves as the system
                    superadmin, providing oversight and administration for the whole platform.
                  </div>
                </div>
              </div>
              <div class="accordion-item" data-aos="fade-up">
                <h3 class="accordion-header" id="faqHead4">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqFour" aria-expanded="false" aria-controls="faqFour">
                    Is there a fee to register or join events?
                  </button>
                </h3>
                <div id="faqFour" class="accordion-collapse collapse" aria-labelledby="faqHead4" data-bs-parent="#faqAccordionA">
                  <div class="accordion-body text-muted">
                    No. Youth profiling and event registration through SKed are free of charge. SKed is a
                    public service of the Sangguniang Kabataan for the youth of the community.
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="faq-group" data-aos="fade-up">
            <div class="faq-col-label"><i class="bi bi-calendar-check"></i>Events &amp; Participation</div>
            <div class="accordion" id="faqAccordionB">
              <div class="accordion-item" data-aos="fade-up">
                <h3 class="accordion-header" id="faqHead2">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqTwo" aria-expanded="false" aria-controls="faqTwo">
                    How do I join an SK event?
                  </button>
                </h3>
                <div id="faqTwo" class="accordion-collapse collapse" aria-labelledby="faqHead2" data-bs-parent="#faqAccordionB">
                  <div class="accordion-body text-muted">
                    Once your youth profile is approved, browse the events listing and register for any
                    open event. You can track your registrations and attendance from your dashboard.
                  </div>
                </div>
              </div>
              <div class="accordion-item" data-aos="fade-up">
                <h3 class="accordion-header" id="faqHead5">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqFive" aria-expanded="false" aria-controls="faqFive">
                    Can I track my participation online?
                  </button>
                </h3>
                <div id="faqFive" class="accordion-collapse collapse" aria-labelledby="faqHead5" data-bs-parent="#faqAccordionB">
                  <div class="accordion-body text-muted">
                    Yes. Once logged in, you can view your profile status, your registered events, and your
                    attendance history — all from your youth dashboard.
                  </div>
                </div>
              </div>
            </div>
          </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =========================================================
         9. CONTACT SECTION
    ========================================================== -->
    <section id="contact" class="section-pad bg-canvas">
      <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
          <span class="eyebrow">Geo Mapping</span>
          <h2 class="section-title mt-2">District and Barangay Coverage Map</h2>
          <p class="section-sub mx-auto mt-2"><?php echo $geoMapConfig['mode'] === 'barangay' ? 'You are signed in, so the map is focused on your registered barangay.' : 'Public visitors see the DILG District IV coverage map.'; ?></p>
        </div>
        <div class="row g-4">
          <div class="col-lg-6" data-aos="fade-right">
            <div class="contact-tiles">
              <div class="contact-tile">
                <i class="bi bi-geo-alt-fill"></i>
                <div class="fw-semibold">Map Focus</div>
                <div class="text-muted small"><?php echo e((string) $geoMapConfig['title']); ?></div>
              </div>
              <div class="contact-tile">
                <i class="bi bi-signpost-split-fill"></i>
                <div class="fw-semibold">Coverage Type</div>
                <div class="text-muted small"><?php echo $geoMapConfig['mode'] === 'barangay' ? 'Barangay-level view' : 'District IV public view'; ?></div>
              </div>
              <div class="contact-tile">
                <i class="bi bi-buildings-fill"></i>
                <div class="fw-semibold">Barangays in SKed</div>
                <div class="text-muted small"><?php echo (int) $totalBarangays; ?> active barangays seeded in the platform</div>
              </div>
              <div class="contact-tile">
                <i class="bi bi-person-check-fill"></i>
                <div class="fw-semibold">Visitor Context</div>
                <div class="text-muted small"><?php echo $isAuthenticated ? 'Signed in as ' . e($viewerDisplayName) : 'No login required'; ?></div>
              </div>
            </div>
          </div>
          <div class="col-lg-6" data-aos="fade-left">
            <div class="geo-map-shell h-100">
              <div id="landingGeoMap" class="geo-map" role="img" aria-label="<?php echo e((string) $geoMapConfig['title']); ?> map"></div>
              <div class="geo-map-panel">
                <span class="geo-map-badge"><i class="bi bi-map"></i><?php echo $geoMapConfig['mode'] === 'barangay' ? 'Barangay View' : 'DILG District IV'; ?></span>
                <h3 class="h5 mb-2"><?php echo e((string) $geoMapConfig['title']); ?></h3>
                <p class="text-muted small mb-0"><?php echo e((string) $geoMapConfig['subtitle']); ?></p>
                <div class="geo-marker-list" aria-label="Mapped locations">
                  <?php foreach ($geoMapConfig['markers'] as $marker): ?>
                    <div class="geo-marker-item">
                      <i class="bi <?php echo $marker['type'] === 'office' ? 'bi-building-check' : ($marker['type'] === 'barangay' ? 'bi-geo-alt-fill' : 'bi-pin-map'); ?>"></i>
                      <div>
                        <div class="fw-semibold"><?php echo e((string) $marker['name']); ?></div>
                        <div class="geo-marker-label"><?php echo e((string) $marker['label']); ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="geo-map-note">
                  Map tiles by OpenStreetMap. Coordinates are approximate public reference points for landing-page orientation.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- =========================================================
       10. FOOTER
  ========================================================== -->
  <footer class="site-footer pt-5 pb-4">
    <div class="container">
      <div class="row g-4 pb-4 footer-top">
        <div class="col-lg-5">
          <a class="navbar-brand text-white d-flex align-items-center gap-2 mb-3" href="#home">
            <span class="logo-badge"><i class="bi bi-people-fill"></i></span>
            SKed
          </a>
          <p class="small" style="max-width:360px;">
            The web-based youth profiling and event-management platform of the Sangguniang Kabataan,
            supporting youth development across barangays.
          </p>
          <div class="d-flex gap-2 mt-3">
            <a href="#" class="social-icon" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
            <a href="#" class="social-icon" aria-label="X (Twitter)"><i class="bi bi-twitter-x"></i></a>
            <a href="#" class="social-icon" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
          </div>
        </div>
        <div class="col-6 col-lg-2 offset-lg-1">
          <h6 class="mb-3">Quick Links</h6>
          <ul class="list-unstyled small">
            <li class="mb-2"><a href="#about">About</a></li>
            <li class="mb-2"><a href="#services">Services</a></li>
            <li class="mb-2"><a href="#process">Process</a></li>
            <li class="mb-2"><a href="#faq">FAQ</a></li>
            <?php if ($isAuthenticated): ?>
            <li class="mb-2"><a href="<?php echo e($viewerDashboardPath); ?>">Dashboard</a></li>
            <?php else: ?>
            <li class="mb-2"><a href="auth/login.php">Login</a></li>
            <li class="mb-2"><a href="auth/register.php">Register</a></li>
            <?php endif; ?>
          </ul>
        </div>
        <div class="col-6 col-lg-2">
          <h6 class="mb-3">Services</h6>
          <ul class="list-unstyled small">
            <li class="mb-2"><a href="#services">Youth Profiling</a></li>
            <li class="mb-2"><a href="#services">Event Registration</a></li>
            <li class="mb-2"><a href="#services">Announcements</a></li>
            <li class="mb-2"><a href="#services">Feedback &amp; Concerns</a></li>
          </ul>
        </div>
        <div class="col-lg-2">
          <h6 class="mb-3">Contact</h6>
          <ul class="list-unstyled small">
            <li class="mb-2"><i class="bi bi-geo-alt me-2"></i>SK Federation Office</li>
            <li class="mb-2"><i class="bi bi-telephone me-2"></i>(000) 000-0000</li>
            <li class="mb-2"><i class="bi bi-envelope me-2"></i>skfederation@example.gov.ph</li>
          </ul>
        </div>
      </div>
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-center small pt-4">
        <div>&copy; 2026 Sangguniang Kabataan — SKed Youth Profiling &amp; Event Management System. All rights reserved.</div>
        <div class="mt-2 mt-md-0">
          <a href="#" class="me-3">Privacy Policy</a>
          <a href="#">Terms of Use</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- Bootstrap Bundle JS (navbar/accordion/carousel) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- AOS JS -->
  <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
  <!-- Leaflet JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    AOS.init({ duration: 700, once: true, easing: 'ease-out-cubic' });

    (function () {
      const config = <?php echo json_encode($geoMapConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const mapElement = document.getElementById('landingGeoMap');
      if (!mapElement || !window.L || !config || !Array.isArray(config.markers)) {
        return;
      }

      const map = L.map(mapElement, {
        scrollWheelZoom: false,
        zoomControl: true
      }).setView(config.center, config.zoom);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      const bounds = [];
      config.markers.forEach((marker) => {
        const latLng = [marker.lat, marker.lng];
        bounds.push(latLng);
        L.marker(latLng)
          .addTo(map)
          .bindPopup('<strong>' + marker.name + '</strong><br>' + marker.label);
      });

      if (config.mode === 'barangay' && config.markers[0] && config.circleRadius) {
        L.circle([config.markers[0].lat, config.markers[0].lng], {
          radius: config.circleRadius,
          color: '#4338ca',
          weight: 2,
          fillColor: '#818cf8',
          fillOpacity: 0.14
        }).addTo(map);
      }

      if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [26, 26] });
      }

      setTimeout(() => map.invalidateSize(), 250);
    }());
  </script>
</body>
</html>
