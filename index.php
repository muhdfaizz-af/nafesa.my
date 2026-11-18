<?php
// ============================================================
// DB + DATA FETCH - OPTIMIZED VERSION
// ============================================================
include 'config/db.php';

// Enable query cache for better performance (only if supported by the MySQL version/config)
// MySQL 8 removed query cache, so attempting to set it will cause a fatal error.
try {
  $conn->query("SET SESSION query_cache_type = ON");
} catch (mysqli_sql_exception $e) {
  // Ignore if query cache system variables are not available.
}


if (!function_exists('makeSlug')) {
  function makeSlug($str) {
    return strtolower(preg_replace('/\s+/', '', trim($str)));
  }
}

function safeQ($conn, $sql) {
    $r = $conn->query($sql);
    $rows = [];
    if ($r && $r !== true) while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// ============================================================
// OPTIMIZATION: Single combined query to reduce DB calls
// ============================================================
// Get all needed data in minimal queries
$site = safeQ($conn, "SELECT site_status FROM site_settings WHERE id=1 LIMIT 1");
$site_status = !empty($site) ? intval($site[0]['site_status']) : 1;
if ($site_status === 0) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nafesa — Maintenance</title><style>body{margin:0;font-family:Georgia,serif;background:#1e2417;color:rgba(255,255,255,0.7);display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;}h1{font-style:italic;font-weight:300;font-size:3rem;color:white;letter-spacing:0.08em;}p{font-size:0.8rem;letter-spacing:0.3em;text-transform:uppercase;color:rgba(184,150,106,0.7);margin-top:1rem;}</style></head><body><div><h1>Nafesa</h1><p>Site Under Maintenance</p><p style="margin-top:0.5rem;font-size:0.65rem;">Coming Back Soon</p></div></body></html>';
    exit();
}

// OPTIMIZED: Reduced LIMIT from 200 to 40 for search (more than enough)
$new_arrivals = safeQ($conn, "SELECT id, title, image, images, default_image_index, price, tag, name FROM collection_hijab_products WHERE active=1 AND new_arrival=1 ORDER BY sort_order ASC, id DESC LIMIT 12");

// OPTIMIZED: Only fetch essential fields for search, reduced to 40 items
$all_products = safeQ($conn, "SELECT id, title, image, name FROM collection_hijab_products WHERE active=1 ORDER BY id DESC LIMIT 40");

// OPTIMIZED: Get collections with essential fields only
$collections = safeQ($conn, "SELECT id, title, mobile_banner_image, banner_image, link FROM collection_hijab_sections WHERE active=1 ORDER BY sort_order ASC LIMIT 20");

$banners = safeQ($conn, "SELECT * FROM banners WHERE active=1 ORDER BY sort_order ASC LIMIT 10");

// Exclusive collections check - optimized
$excl_collections = [];
$chk = $conn->query("SHOW COLUMNS FROM collection_hijab_sections LIKE 'is_exclusive'");
if ($chk && $chk->num_rows > 0) {
    $excl_collections = safeQ($conn, "SELECT id, title, mobile_banner_image, banner_image, link FROM collection_hijab_sections WHERE active=1 AND is_exclusive=1 ORDER BY sort_order ASC LIMIT 6");
}

// Designs for exclusive grid - only if needed
$excl_designs = [];
if (!empty($excl_collections)) {
    $excl_ids = implode(',', array_map(fn($c) => intval($c['id']), $excl_collections));
    $all_excl_designs = safeQ($conn, "SELECT id, collection_id, name, image FROM designs WHERE active=1 AND collection_id IN ($excl_ids) ORDER BY sort_order ASC LIMIT 30");
    foreach ($all_excl_designs as $d) {
        $excl_designs[intval($d['collection_id'])][] = $d;
    }
}

$collabs = safeQ($conn, "SELECT * FROM exclusive_collaborations WHERE active=1 ORDER BY sort_order ASC LIMIT 10");

// OPTIMIZED: Accessories with limit
$accessories = safeQ($conn, "SELECT id, accessory_name, accessories_image, accessories_link FROM accessories WHERE active=1 ORDER BY sort_order ASC, id ASC LIMIT 20");

// Build search data - optimized
$search_js = [];
foreach ($all_products as $p) {
  $thumb = $p['image'] ?? '';
  $search_js[] = [
    'name'    => htmlspecialchars($p['title'] ?? ($p['name'] ?? ''), ENT_QUOTES),
    'type'    => 'Product',
    'section' => '#new-arrivals',
    'thumb'   => $thumb,
  ];
}
$search_json = json_encode($search_js, JSON_UNESCAPED_UNICODE);

// Banner left panel for split section
$hero_banner = null;
$hijab_banners = safeQ($conn, "SELECT * FROM collection_hijab_banner WHERE active=1 ORDER BY sort_order ASC LIMIT 1");
if (!empty($hijab_banners)) {
  $hero_banner = $hijab_banners[0];
}

// Build collection slides
$slide_items = !empty($collections) ? $collections : [
  ['title'=>'Bawal Qaseh',      'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
  ['title'=>'Bawal Legend',     'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
  ['title'=>'Bawal Warna',      'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
  ['title'=>'Bawal Klasik',     'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
  ['title'=>'Instant Premium',  'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
  ['title'=>'Bawal Kristal',    'mobile_banner_image'=>'', 'banner_image'=>'', 'link'=>''],
];

// Pre-calculate base path once
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// ============================================================
// OPTIMIZATION: Output buffering for faster rendering
// ============================================================
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Nafesa — Premium Hijab & Tudung Malaysia | Modest Luxury</title>
  <meta name="description" content="Nafesa ialah jenama premium hijab dan tudung di Malaysia. Terokai koleksi bawal, shawl, instant, dan exclusive dengan sentuhan modest luxury untuk wanita moden.">
  <meta name="keywords" content="Nafesa, hijab Malaysia, tudung Malaysia, bawal premium, shawl premium, instant hijab, tudung exclusive, modest luxury">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="theme-color" content="#565E3E">
  <link rel="canonical" href="https://nafesa.my/">
  <meta property="og:site_name" content="Nafesa">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://nafesa.my/">
  <meta property="og:title" content="Nafesa — Premium Hijab & Tudung Malaysia | Modest Luxury">
  <meta property="og:description" content="Nafesa ialah jenama premium hijab dan tudung di Malaysia. Terokai koleksi bawal, shawl, instant, dan exclusive dengan sentuhan modest luxury untuk wanita moden.">
  <meta property="og:image" content="<?= $basePath ?>/assets/images/icon_nafesa_gold.png">
  <meta property="twitter:card" content="summary_large_image">
  <meta property="twitter:title" content="Nafesa — Premium Hijab & Tudung Malaysia | Modest Luxury">
  <meta property="twitter:description" content="Nafesa ialah jenama premium hijab dan tudung di Malaysia. Terokai koleksi bawal, shawl, instant, dan exclusive dengan sentuhan modest luxury untuk wanita moden.">
  <meta property="twitter:image" content="<?= $basePath ?>/assets/images/icon_nafesa_gold.png">
  <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png" sizes="32x32">
  <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png" sizes="96x96">
  <link rel="apple-touch-icon" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png">

  <!-- OPTIMIZED: Preload critical assets -->
  <link rel="preload" as="image" href="<?= $basePath ?>/assets/images/nafesa_white.png">
  <link rel="preload" as="image" href="<?= $basePath ?>/assets/images/nafesa_black.png">
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "Nafesa",
    "url": "https://nafesa.my/",
    "logo": "https://nafesa.my/assets/images/icon_nafesa_gold.png",
    "sameAs": [
      "https://www.instagram.com/nafesahq",
      "https://www.tiktok.com/@nafesahq"
    ]
  }
  </script>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "Nafesa",
    "url": "https://nafesa.my/",
    "potentialAction": {
      "@type": "SearchAction",
      "target": "https://nafesa.my/?s={search_term_string}",
      "query-input": "required name=search_term_string"
    }
  }
  </script>

  <!-- OPTIMIZED: Font loading with swap and reduced blocking -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap"></noscript>

  <style>
    /* ── INLINE: Owl Carousel core (minified) ── */
    .owl-carousel{display:none;width:100%;-webkit-tap-highlight-color:transparent;position:relative;z-index:1}.owl-carousel .owl-stage{position:relative;-ms-touch-action:pan-Y;touch-action:manipulation;-moz-backface-visibility:hidden}.owl-carousel .owl-stage:after{content:".";display:block;clear:both;visibility:hidden;line-height:0;height:0}.owl-carousel .owl-stage-outer{position:relative;overflow:hidden;-webkit-transform:translate3d(0,0,0)}.owl-carousel .owl-item{position:relative;min-height:1px;float:left;-webkit-backface-visibility:hidden;-webkit-tap-highlight-color:transparent}.owl-carousel .owl-item img{display:block;width:100%}.owl-carousel .owl-nav.disabled,.owl-carousel .owl-dots.disabled{display:none}.owl-carousel .owl-nav button.owl-prev,.owl-carousel .owl-nav button.owl-next,.owl-carousel button.owl-dot{background:none;color:inherit;border:none;padding:0!important;font:inherit}.owl-carousel.owl-loaded{display:block}.owl-carousel.owl-loading{opacity:0;display:block}.owl-carousel.owl-hidden{opacity:0}.owl-theme .owl-nav{margin-top:10px;text-align:center}.owl-theme .owl-nav [class*='owl-']{color:#fff;font-size:14px;margin:5px;padding:4px 7px;background:#d6d6d6;display:inline-block;cursor:pointer;border-radius:3px}.owl-theme .owl-nav [class*='owl-']:hover{background:#869791;color:#fff}.owl-theme .owl-nav .disabled{opacity:.5;cursor:default}.owl-theme .owl-dots{text-align:center}.owl-theme .owl-dots .owl-dot{display:inline-block;zoom:1}.owl-theme .owl-dots .owl-dot span{width:10px;height:10px;margin:5px 7px;background:#d6d6d6;display:block;border-radius:30px}.owl-theme .owl-dots .owl-dot.active span,.owl-theme .owl-dots .owl-dot:hover span{background:#869791}

    :root {
      --sage: #4a5235; --sage-md: #565E3E; --sage-dk: #2e3520;
      --gold: #b8966a; --gold-lt: #cead87; --gold-pale: #e8d8c0;
      --cream: #f5f1eb; --ivory: #faf7f3; --forest: #1e2417;
      --ink: #1c1c1c; --ink-soft: #3a3a3a; --muted: #8a8a8a;
      --border: rgba(74,82,53,0.1);
      --ff-display: 'Cormorant Garamond', Georgia, serif;
      --ff-body: 'DM Sans', sans-serif;
      --ease-out: cubic-bezier(0.22,1,0.36,1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
    body { font-family: var(--ff-body); font-weight: 300; color: var(--ink); background: var(--ivory); overflow-x: hidden;
      cursor: url("assets/images/mouse.png") 16 16, auto;
    }
    * { cursor: url("assets/images/mouse.png") 16 16, auto; }
    img { display: block; max-width: 100%; height: auto; }
    a { text-decoration: none; color: inherit; }

    .eyebrow { display: block; font-family: var(--ff-body); font-size: 0.6rem; font-weight: 500; letter-spacing: 0.35em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.875rem; }
    .section-title { font-family: var(--ff-display); font-size: clamp(2.4rem,5vw,3.8rem); font-weight: 300; font-style: italic; letter-spacing: 0.01em; color: var(--ink); line-height: 1.1; }
    .section-accent-line { display: flex; align-items: center; justify-content: center; gap: 1rem; margin: 1.5rem auto 3.5rem; }
    .section-accent-line::before, .section-accent-line::after { content: ''; height: 1px; width: 60px; background: linear-gradient(to right, transparent, var(--gold)); opacity: 0.4; }
    .section-accent-line::after { background: linear-gradient(to left, transparent, var(--gold)); }
    .section-accent-dot { width: 4px; height: 4px; border-radius: 50%; background: var(--gold); opacity: 0.5; }

    .mobile-only  { display: block; }
    .desktop-only { display: none; }
    @media (min-width: 768px) {
      .mobile-only  { display: none !important; }
      .desktop-only { display: block; }
    }
    .nf-flex         { display: flex; }
    .nf-items-center { align-items: center; }
    .nf-justify-between { justify-content: space-between; }
    .nf-justify-end  { justify-content: flex-end; }
    .nf-gap-5        { gap: 1.25rem; }
    .nf-gap-4        { gap: 1rem; }
    .nf-gap-10       { gap: 2.5rem; }
    .nf-relative     { position: relative; }
    .nf-absolute-center { position: absolute; left: 50%; transform: translateX(-50%); }
    .nf-h-full       { height: 100%; }
    .nf-px-4         { padding-left: 1rem; padding-right: 1rem; }
    .nf-px-6         { padding-left: 1.5rem; padding-right: 1.5rem; }
    .nf-ml-2         { margin-left: 0.5rem; }
    .nf-max-w-7xl    { max-width: 80rem; }
    .nf-mx-auto      { margin-left: auto; margin-right: auto; }
    .nf-hidden-lg    { display: none; }
    @media (min-width: 1024px) { .nf-flex-lg { display: flex !important; } }
    .nf-font-semibold { font-weight: 600; }
    .nf-uppercase    { text-transform: uppercase; }
    .nf-text-xs      { font-size: 0.75rem; }
    .nf-text-white   { color: white; }
    .nf-rounded-full { border-radius: 9999px; }
    .nf-transition   { transition: all 0.3s; }
    .nf-py-2         { padding-top: 0.5rem; padding-bottom: 0.5rem; }
    .nf-p-2          { padding: 0.5rem; }
    .nf-neg-ml-2     { margin-left: -0.5rem; }
    .nf-neg-mr-2     { margin-right: -0.5rem; }

    .hero-desktop {
      position: relative;
      width: 100%;
      overflow: hidden;
      background: var(--forest);
    }
    .hero-desktop__inner {
      position: relative;
      width: 100%;
    }
    .hero-desktop video {
      width: 100%; height: 100%; object-fit: cover;
    }

    .hero-mobile { width: 100%; position: relative; overflow: hidden; background: var(--forest); }
    .hero-mobile .video-wrap { position: relative; padding-top: 125%; }
    .hero-mobile .video-wrap video {
      position: absolute; inset: 0;
      width: 100%; height: 100%; object-fit: cover;
    }
    .hero-mobile .video-wrap::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(to bottom, transparent 50%, rgba(0,0,0,0.45) 100%);
      pointer-events: none;
    }

    .video-controls {
      position: absolute; bottom: 1.25rem; right: 1.25rem;
      display: flex; gap: 0.5rem; z-index: 10;
    }
    .v-btn {
      width: 2rem; height: 2rem;
      background: rgba(255,255,255,0.1);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.18);
      border-radius: 50%;
      color: white; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background 0.25s, transform 0.2s;
    }
    .v-btn:hover { background: rgba(255,255,255,0.2); transform: scale(1.1); }

    .hd-logo { height: 36px; width: auto; object-fit: contain; filter: brightness(0) invert(1); transition: filter 0.35s; }
    #main-header-desktop.sticky .hd-logo,
    #main-header-desktop.nav-active .hd-logo,
    #main-header-desktop:hover .hd-logo {
      filter: brightness(0) saturate(100%) !important;
    }

    #main-header { position:fixed;top:0;left:0;right:0;height:60px;background:transparent;border-bottom:1px solid transparent;z-index:1000;transition:background .4s,border-color .4s,box-shadow .4s;display:none; }
    #main-header.scrolled { background:rgba(250,247,243,0.96);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom-color:var(--border);box-shadow:0 2px 20px rgba(0,0,0,.06); }
    #main-header .inner { display:flex;align-items:center;justify-content:space-between;height:100%;padding:0 1rem;max-width:1280px;margin:0 auto;position:relative; }
    #main-header .logo-img { height:24px;width:auto;object-fit:contain;filter:brightness(0) invert(1);transition:filter .4s; }
    #main-header.scrolled .logo-img { filter:none; }
    #main-header .logo-wrap { position:absolute;left:50%;transform:translateX(-50%); }
    #main-header button { color:white;background:none;border:none;cursor:pointer;padding:.5rem;transition:color .4s; }
    #main-header.scrolled button { color:var(--ink); }
    @media (max-width:767px) { #main-header { display:block; } body { padding-top:0; } }

    #mobile-menu { position:fixed;top:60px;left:0;right:0;bottom:0;background:var(--ivory);z-index:999;padding:2.5rem 2rem;transform:translateX(-100%);transition:transform .4s var(--ease-out);display:flex;flex-direction:column;gap:0; }
    #mobile-menu.open { transform:translateX(0); }
    #mobile-menu .menu-link { font-family:var(--ff-display);font-size:2rem;font-weight:300;font-style:italic;color:var(--ink);padding:1.1rem 0;border-bottom:1px solid var(--border);display:block;transition:color .25s,padding-left .3s;letter-spacing:.02em; }
    #mobile-menu .menu-link:hover { color:var(--gold);padding-left:.5rem; }
    #mobile-menu .menu-social { margin-top:auto;padding-top:2.5rem;display:flex;gap:1.5rem; }
    #mobile-menu .menu-social a { font-size:.62rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);transition:color .25s; }
    .ham-icon { width:22px;height:14px;position:relative;display:flex;flex-direction:column;justify-content:space-between; }
    .ham-line { display:block;height:1px;background:currentColor;transition:all .35s var(--ease-out);transform-origin:center; }
    .ham-open .ham-line:nth-child(1) { transform:translateY(6.5px) rotate(45deg); }
    .ham-open .ham-line:nth-child(2) { opacity:0;transform:scaleX(0); }
    .ham-open .ham-line:nth-child(3) { transform:translateY(-6.5px) rotate(-45deg); }

    #main-header-desktop { position:fixed;top:0;left:0;right:0;z-index:100;background:transparent;transition:background .45s,box-shadow .45s;display:none; }
    #main-header-desktop.sticky,
    #main-header-desktop.nav-active {
      background:rgba(250,247,243,0.96);
      backdrop-filter:blur(20px);
      box-shadow:0 1px 0 var(--border);
    }
    #main-header-desktop .hd-inner { display:flex;align-items:center;justify-content:space-between;height:80px;padding:0 2rem;max-width:1280px;margin:0 auto;position:relative; }
    .hd-logo { height:34px;width:auto;object-fit:contain;transition:opacity .35s; }
    #main-header-desktop nav { position:absolute;left:50%;transform:translateX(-50%);display:flex;gap:2.5rem; }
    #main-header-desktop nav a { font-family:var(--ff-body);font-size:13px;font-weight:400;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.82);position:relative;transition:color .25s; }
    #main-header-desktop nav a::after { content:'';position:absolute;bottom:-2px;left:0;width:0;height:1px;background:currentColor;transition:width .35s var(--ease-out); }
    #main-header-desktop nav a:hover::after { width:100%; }
    #main-header-desktop.sticky nav a,
    #main-header-desktop.nav-active nav a { color:var(--ink); }
    #main-header-desktop.sticky nav a:hover,
    #main-header-desktop.nav-active nav a:hover { color:var(--sage-md); }
    .hd-actions { display:flex;align-items:center;gap:1.25rem; }
    .hd-btn { background:none;border:none;cursor:pointer;color:rgba(255,255,255,.82);display:flex;align-items:center;transition:color .25s;padding:.25rem; }
    .hd-btn:hover { color:white; }
    #main-header-desktop.sticky .hd-btn,
    #main-header-desktop.nav-active .hd-btn { color:var(--ink); }
    #main-header-desktop.sticky .hd-btn:hover,
    #main-header-desktop.nav-active .hd-btn:hover { color:var(--ink) !important; }
    .hd-shop-btn {
      background: none; border: none; cursor: pointer;
      color: white; font-family: var(--ff-body); font-size: 0.75rem;
      font-weight: 600; letter-spacing: 0.15em; text-transform: uppercase;
      padding: 0.5rem 1rem; border-radius: 9999px;
      transition: background 0.3s, color 0.3s;
      margin-left: 0.5rem;
    }
    .hd-shop-btn:hover { background: var(--sage-dk); color: white; }
    #main-header-desktop.sticky .hd-shop-btn,
    #main-header-desktop.nav-active .hd-shop-btn { color: var(--ink); }
    #main-header-desktop.sticky .hd-shop-btn:hover,
    #main-header-desktop.nav-active .hd-shop-btn:hover { background: var(--sage-dk); color: white; }
    @media (min-width:768px) { #main-header-desktop { display:block; } }

    .new-arrivals { padding:3rem 0 2rem;background:#ffffff; }
    @media (max-width:767px) { .new-arrivals { padding:2rem 0 1.5rem; } }
    .section-header { text-align:center;margin-bottom:0; }
    .carousel-container { max-width:1280px;margin:0 auto;padding:0 1.5rem; }
    .product-card { cursor:pointer;padding:0 4px; }
    .product-img-wrap { overflow:hidden;position:relative;background:var(--cream);aspect-ratio:1/1; }
    .product-img-wrap::after { content:'';position:absolute;inset:0;background:rgba(74,82,53,0);transition:background .5s;pointer-events:none; }
    .product-card:hover .product-img-wrap::after { background:rgba(74,82,53,.04); }
    .product-img-wrap picture { position:absolute;inset:0;width:100%;height:100%; }
    .product-image { position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .85s var(--ease-out); }
    .product-card:hover .product-image { transform:scale(1.07); }
    .product-meta { padding:1rem .5rem .75rem;text-align:center; }
    .product-name { font-family:var(--ff-display);font-size:1rem;font-weight:400;letter-spacing:.02em;color:var(--ink);line-height:1.3; }
    .product-price { font-family:var(--ff-body);font-size:.78rem;font-weight:300;color:var(--muted);margin-top:.3rem; }
    .product-badge { display:inline-block;font-size:.56rem;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);margin-top:.25rem; }
    .owl-new-arrivals .owl-stage-outer { padding-bottom:4px; }
    .owl-new-arrivals .owl-dots { margin-top:2rem;text-align:center;line-height:1; }
    .owl-new-arrivals .owl-dot { display:inline-block; }
    .owl-new-arrivals .owl-dot span { display:block;width:5px;height:5px;border-radius:50%;background:rgba(74,82,53,.18);margin:0 4px;transition:all .35s; }
    .owl-new-arrivals .owl-dot.active span { background:var(--sage-md);transform:scale(1.8); }
    .owl-new-arrivals .owl-nav { display:none; }
    .no-arrivals { text-align:center;padding:4rem 2rem;color:var(--muted); }
    .no-arrivals span { display:block;font-family:var(--ff-display);font-size:1.4rem;font-style:italic;color:rgba(28,28,28,.25);margin-bottom:.5rem; }
    .tile-1{background:linear-gradient(135deg,#c8bfb0,#e0d5c5)} .tile-2{background:linear-gradient(135deg,#b5c4a8,#d0dcca)} .tile-3{background:linear-gradient(135deg,#c9b99a,#e0cfb5)} .tile-4{background:linear-gradient(135deg,#a8b5a0,#c5d0bc)} .tile-5{background:linear-gradient(135deg,#bfb0a5,#d8ccbf)} .tile-6{background:linear-gradient(135deg,#b2c0ad,#cad5c4)}

    #hijab-collection { scroll-margin-top:64px; background:#ffffff; }
    .hc-section-header { text-align:center; padding:3rem 1.5rem 0; background:#ffffff; }
    @media (max-width:767px) { .hc-section-header { padding:2rem 1.5rem 0; } }
    .hc-owl-section { background:#ffffff; padding:0 0 3rem; }
    .hc-owl-wrap { max-width:1400px; margin:0 auto; padding:0.2rem 4% 0; }
    @media (max-width:767px) { .hc-owl-wrap { padding:1.5rem 5% 0; } }
    .hc-owl-slide { padding:0 8px; }
    @media (max-width:767px) { .hc-owl-slide { padding:0 5px; } }
    .hc-owl-card { background:#ffffff; border:1px solid var(--border); overflow:hidden; }
    .hc-owl-img { position:relative; overflow:hidden; padding-top:124.375%; cursor:pointer; background:#ffffff; }
    .hc-owl-img img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .75s var(--ease-out); }
    .hc-owl-card:hover .hc-owl-img img { transform:scale(1.05); }
    .hc-owl-img-overlay { position:absolute; inset:0; background:linear-gradient(to top,rgba(14,18,10,.5) 0%,transparent 45%); z-index:2; pointer-events:none; }
    .hc-owl-placeholder { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#e8e3db,#d8d0c8); }
    .hc-owl-placeholder span { font-family:var(--ff-display); font-size:1rem; font-style:italic; color:rgba(74,82,53,.4); text-align:center; padding:0 1rem; }
    .hc-owl-actions { background:#ffffff; border-top:1px solid var(--border); padding:1.1rem 1rem 1.2rem; display:flex; flex-direction:column; align-items:center; gap:.7rem; text-align:center; }
    .hc-owl-title { font-family:var(--ff-display); font-size:clamp(.95rem,1.3vw,1.25rem); font-weight:300; font-style:italic; color:var(--ink); }
    .hc-owl-btns { display:flex; gap:.55rem; align-items:center; justify-content:center; }
    .hc-owl-btn { font-family:var(--ff-body); font-size:.56rem; font-weight:400; letter-spacing:.18em; text-transform:uppercase; padding:.5rem 1.1rem; cursor:pointer; transition:all .3s var(--ease-out); border:1px solid; display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap; }
    .hc-owl-btn.outline { color:var(--ink-soft); border-color:rgba(74,82,53,.25); background:transparent; }
    .hc-owl-btn.outline:hover { color:var(--sage-md); border-color:rgba(74,82,53,.5); background:rgba(74,82,53,.05); }
    .hc-owl-btn.solid { color:white; border-color:var(--sage-md); background:var(--sage-md); }
    .hc-owl-btn.solid:hover { background:var(--sage-dk); border-color:var(--sage-dk); }
    .owl-hc-collection .owl-nav { margin-top:1.5rem; text-align:center; }
    .owl-hc-collection .owl-prev,
    .owl-hc-collection .owl-next { display:inline-flex !important; align-items:center; justify-content:center; width:2.6rem; height:2.6rem; border-radius:50% !important; background:var(--ivory) !important; border:1px solid var(--border) !important; color:var(--ink) !important; margin:0 .3rem; box-shadow:0 2px 12px rgba(0,0,0,.07); transition:background .25s,transform .2s,box-shadow .25s !important; }
    .owl-hc-collection .owl-prev:hover,
    .owl-hc-collection .owl-next:hover { background:white !important; transform:scale(1.08) !important; box-shadow:0 4px 18px rgba(0,0,0,.12) !important; }
    .owl-hc-collection .owl-prev span,
    .owl-hc-collection .owl-next span { display:none; }
    .owl-hc-collection .owl-dots { margin-top:.9rem; text-align:center; }
    .owl-hc-collection .owl-dot span { width:5px !important; height:5px !important; border-radius:50% !important; background:rgba(74,82,53,.2) !important; margin:0 4px !important; transition:all .35s !important; }
    .owl-hc-collection .owl-dot.active span { background:var(--sage-md) !important; transform:scale(1.8); }

    .acc-section { background:#ffffff; padding:1rem 0 2rem; }
    @media (max-width:767px) { .acc-section { padding:3.5rem 0 4rem; } }
    .acc-inner { max-width:1280px; margin:0 auto; padding:0 1.5rem; }
    .acc-bento { display:flex; flex-wrap:wrap; justify-content:center; gap:1rem; margin-top:3rem; }
    .acc-card { position:relative; overflow:hidden; background:var(--ivory); width:calc(33.333% - 0.667rem); min-width:280px; max-width:420px; cursor:url("assets/images/mouse.png") 16 16,auto; }
    @media (max-width:1024px) { .acc-card { width:calc(50% - 0.5rem); } }
    @media (max-width:767px) { .acc-card { width:100%; max-width:100%; min-width:unset; } }
    .acc-card-inner { position:relative; width:100%; overflow:hidden; aspect-ratio:1/1; }
    .acc-fill { position:absolute; inset:0; width:100%; height:100%; display:flex; align-items:center; justify-content:center; transition:transform .85s cubic-bezier(0.22,1,0.36,1); }
    .acc-card:hover .acc-fill { transform:scale(1.05); }
    .acc-fill img { position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; object-position:center; transition:transform .85s cubic-bezier(0.22,1,0.36,1); }
    .acc-card:hover .acc-fill img { transform:scale(1.06); }
    .fill-1{background:linear-gradient(145deg,#d8cfc3,#c4bab0)} .fill-2{background:linear-gradient(145deg,#c5cbbf,#b5bfae)} .fill-3{background:linear-gradient(145deg,#cfc5b8,#bfb5a8)} .fill-4{background:linear-gradient(145deg,#c0c9bb,#b2c0ac)} .fill-5{background:linear-gradient(145deg,#d4c9bc,#c4b9ac)} .fill-6{background:linear-gradient(145deg,#c8d0c4,#b8c3b5)} .fill-7{background:linear-gradient(145deg,#dacfc4,#cabfb4)} .fill-8{background:linear-gradient(145deg,#c3cbbd,#b5c2b0)}
    .acc-overlay { position:absolute; inset:0; background:linear-gradient(to top,rgba(22,28,16,.65) 0%,transparent 55%); pointer-events:none; opacity:0; transition:opacity .4s; }
    .acc-card:hover .acc-overlay { opacity:1; }
    .acc-label { position:absolute; bottom:0; left:0; right:0; padding:1.4rem 1rem .95rem; display:flex; flex-direction:column; gap:.2rem; transition:padding-bottom .35s cubic-bezier(0.22,1,0.36,1); }
    .acc-card:hover .acc-label { padding-bottom:1.25rem; }
    .acc-label-tag { font-family:var(--ff-body); font-size:.5rem; font-weight:500; letter-spacing:.25em; text-transform:uppercase; color:rgba(184,150,106,.85); }
    .acc-label-name { font-family:var(--ff-display); font-size:1.05rem; font-weight:300; font-style:italic; color:rgba(255,255,255,.92); line-height:1.25; }
    .acc-label-price { font-family:var(--ff-body); font-size:.7rem; font-weight:300; color:rgba(255,255,255,.55); margin-top:.1rem; }
    .acc-cta { position:absolute; bottom:-3rem; left:0; right:0; display:flex; align-items:center; justify-content:center; padding:.75rem 1rem; background:rgba(86,94,62,.88); backdrop-filter:blur(12px); font-family:var(--ff-body); font-size:.56rem; font-weight:400; letter-spacing:.22em; text-transform:uppercase; color:white; transition:bottom .4s cubic-bezier(0.22,1,0.36,1); gap:.5rem; }
    .acc-card:hover .acc-cta { bottom:0; }
    .acc-badge { position:absolute; top:.75rem; left:.75rem; font-family:var(--ff-body); font-size:.48rem; font-weight:500; letter-spacing:.2em; text-transform:uppercase; background:rgba(22,28,16,.72); color:rgba(184,150,106,.9); padding:.28rem .7rem; z-index:3; }
    .acc-viewall { text-align:center; margin-top:2.5rem; }
    .acc-viewall a { display:inline-flex; align-items:center; gap:.5rem; font-family:var(--ff-body); font-size:.62rem; font-weight:400; letter-spacing:.22em; text-transform:uppercase; color:var(--sage-md); border-bottom:1px solid rgba(74,82,53,.3); padding-bottom:3px; transition:color .25s,border-color .25s; }
    .acc-viewall a:hover { color:var(--sage-dk); border-color:rgba(74,82,53,.6); }
    .acc-placeholder-icon { font-family:var(--ff-display); font-size:1.3rem; font-style:italic; font-weight:300; color:rgba(74,82,53,.28); pointer-events:none; user-select:none; text-align:center; padding:1rem; }

    .site-footer { background:var(--cream);color:var(--ink-soft);padding:5rem 1.5rem 2.5rem;border-top:1px solid var(--border); }
    .footer-inner { max-width:1200px;margin:0 auto; }
    .footer-grid { display:grid;grid-template-columns:2fr 1fr 1fr;gap:4rem;padding-bottom:3.5rem;border-bottom:1px solid var(--border); }
    @media (max-width:767px) { .footer-grid { grid-template-columns:1fr;gap:2.5rem; } }
    .footer-brand-name { font-family:var(--ff-display);font-size:2rem;font-weight:300;font-style:italic;letter-spacing:.08em;color:var(--ink);margin-bottom:1rem; }
    .footer-desc { font-size:.8rem;font-weight:300;line-height:2;max-width:280px;color:var(--muted); }
    .footer-social { display:flex;gap:.875rem;margin-top:1.75rem; }
    .footer-social a { font-size:.6rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);border:1px solid var(--border);padding:.45rem 1rem;transition:color .25s,border-color .25s; }
    .footer-social a:hover { color:var(--sage-md);border-color:rgba(74,82,53,.35); }
    .footer-col h4 { font-family:var(--ff-body);font-size:.58rem;font-weight:500;letter-spacing:.28em;text-transform:uppercase;color:var(--gold);margin-bottom:1.5rem; }
    .footer-col ul { list-style:none; }
    .footer-col li { margin-bottom:.7rem; }
    .footer-col a { font-size:.8rem;font-weight:300;color:var(--muted);transition:color .25s; }
    .footer-col a:hover { color:var(--ink); }
    .footer-bottom { padding-top:2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem; }
    .footer-bottom p { font-size:.68rem;letter-spacing:.08em;color:rgba(28,28,28,.3); }

    #search-overlay { position:fixed;inset:0;background:rgba(20,24,16,.7);backdrop-filter:blur(16px);z-index:3000;display:flex;align-items:flex-start;justify-content:center;padding:0;opacity:0;pointer-events:none;transition:opacity .35s var(--ease-out); }
    #search-overlay.open { opacity:1;pointer-events:all; }
    .search-panel { width:100%;max-width:680px;background:var(--ivory);padding:2.5rem 2.5rem 3rem;margin-top:80px;transform:translateY(-20px);transition:transform .4s var(--ease-out);max-height:calc(100vh - 100px);overflow-y:auto; }
    @media (max-width:767px) { .search-panel { margin-top:0;max-height:100vh;padding:2rem 1.5rem 2.5rem; } }
    #search-overlay.open .search-panel { transform:translateY(0); }
    .search-panel-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem; }
    .search-panel-title { font-family:var(--ff-display);font-size:1.6rem;font-weight:300;font-style:italic;color:var(--ink); }
    .search-close-btn { background:none;border:none;cursor:pointer;color:var(--muted);width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;transition:color .2s,transform .2s; }
    .search-close-btn:hover { color:var(--ink);transform:rotate(90deg); }
    .search-input-wrap { display:flex;align-items:center;gap:1rem;border-bottom:1.5px solid var(--ink);padding-bottom:.75rem;margin-bottom:.5rem; }
    .search-input-wrap input { flex:1;border:none;background:transparent;outline:none;font-family:var(--ff-body);font-size:1.05rem;font-weight:300;color:var(--ink);caret-color:var(--gold); }
    .search-input-wrap input::placeholder { color:rgba(28,28,28,.28); }
    .search-empty { text-align:center;padding:3rem 0;color:var(--muted);font-size:.85rem;font-weight:300; }
    .search-empty span { display:block;font-family:var(--ff-display);font-size:1.3rem;font-style:italic;color:rgba(28,28,28,.3);margin-bottom:.5rem; }

    .fade-up { opacity:0;transform:translateY(28px);transition:opacity .7s var(--ease-out),transform .7s var(--ease-out); }
    .fade-up.visible { opacity:1;transform:translateY(0); }

    /* ============================================================
       HERO BANNER CAROUSEL — DOTS + ARROWS
       ============================================================ */
    /* Wrapper needs relative so controls can be overlaid */
    .hero-desktop__inner,
    .hero-mobile { position: relative; }

    /* --- Dots --- */
    .owl-hero-banner .owl-dots {
      position: absolute;
      bottom: 1.4rem;
      left: 50%;
      transform: translateX(-50%);
      z-index: 20;
      display: flex;
      align-items: center;
      gap: 6px;
      line-height: 1;
      margin: 0;
    }
    .owl-hero-banner .owl-dot {
      display: inline-flex;
      align-items: center;
    }
    .owl-hero-banner .owl-dot span {
      display: block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: rgba(255,255,255,0.38);
      margin: 0;
      transition: width .35s var(--ease-out), background .35s, border-radius .35s;
    }
    .owl-hero-banner .owl-dot.active span {
      background: rgba(255,255,255,0.92);
      width: 22px;
      border-radius: 3px;
    }
    .owl-hero-banner .owl-dot:hover:not(.active) span {
      background: rgba(255,255,255,0.6);
    }

    .owl-hero-banner .owl-item img {
      transition: transform .8s ease;
    }
    .owl-hero-banner .owl-item.active img {
      animation: heroZoom 3.2s ease forwards;
    }
    @keyframes heroZoom {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.04); }
    }

    /* --- Arrows nav wrapper (overlay both sides) --- */
    .owl-hero-banner .owl-nav {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      left: 0;
      right: 0;
      width: 100%;
      pointer-events: none;
      z-index: 20;
      display: flex !important;
      justify-content: space-between;
      padding: 0 1.1rem;
      margin: 0;
    }
    @media (max-width: 767px) {
      .owl-hero-banner .owl-nav { padding: 0 0.6rem; }
    }

    /* --- Arrow buttons --- */
    .owl-hero-banner .owl-prev,
    .owl-hero-banner .owl-next {
      pointer-events: all;
      width: 3rem;
      height: 3rem;
      border-radius: 50% !important;
      background: rgba(255,255,255,0.12) !important;
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,0.22) !important;
      color: white !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      transition: background .25s, transform .2s, border-color .25s !important;
      margin: 0 !important;
      padding: 0 !important;
    }
    @media (max-width: 767px) {
      .owl-hero-banner .owl-prev,
      .owl-hero-banner .owl-next {
        width: 2.3rem;
        height: 2.3rem;
      }
    }
    .owl-hero-banner .owl-prev:hover,
    .owl-hero-banner .owl-next:hover {
      background: rgba(255,255,255,0.25) !important;
      border-color: rgba(255,255,255,0.4) !important;
      transform: scale(1.08) !important;
    }
    .owl-hero-banner .owl-prev span,
    .owl-hero-banner .owl-next span { display: none !important; }

    .owl-hero-banner .owl-prev.disabled,
    .owl-hero-banner .owl-next.disabled {
      opacity: 0.35 !important;
      pointer-events: none !important;
    }
  </style>
</head>
<body>

<header id="main-header" class="mobile-only">
  <div style="display:flex;align-items:center;justify-content:space-between;height:100%;padding:0 1rem;max-width:80rem;margin:0 auto;position:relative;">
    <button id="ham-btn" aria-label="Menu" aria-expanded="false" style="padding:0.5rem;margin-left:-0.5rem;">
      <div class="ham-icon" id="ham-icon">
        <span class="ham-line"></span>
        <span class="ham-line"></span>
        <span class="ham-line"></span>
      </div>
    </button>
    <a href="/" style="position:absolute;left:50%;transform:translateX(-50%);">
      <img src="assets/images/nafesa_black.png" alt="Nafesa" class="logo-img" width="96" height="24" loading="eager" fetchpriority="high">
    </a>
    <button id="search-btn-mobile" aria-label="Cari" style="padding:0.5rem;margin-right:-0.5rem;">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7.5"/><path d="m20.5 20.5-4.5-4.5"/></svg>
    </button>
  </div>
</header>

<nav id="mobile-menu" aria-label="Mobile navigation" role="dialog">
  <a href="#new-arrivals" class="menu-link">New Arrivals</a>
  <a href="#hijab-collection" class="menu-link">Hijab Collection</a>
  <a href="#exclusive" class="menu-link">Exclusive</a>
  <a href="#accessories" class="menu-link">Accessories</a>
  <div class="menu-social">
    <a href="https://www.instagram.com/nafesahq" target="_blank" rel="noopener">Instagram</a>
    <a href="https://www.tiktok.com/@nafesahq" target="_blank" rel="noopener">TikTok</a>
  </div>
</nav>


<header id="main-header-desktop" class="desktop-only">
  <div style="max-width:80rem;margin:0 auto;padding:0 1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;height:5rem;position:relative;">
      <a href="/">
        <img src="assets/images/nafesa_white.png" alt="Nafesa" class="hd-logo" id="hd-logo-img" width="136" height="34" loading="eager" fetchpriority="high">
      </a>
      <nav style="display:none;gap:2.5rem;position:absolute;left:50%;transform:translateX(-50%);" id="desktop-nav" aria-label="Main navigation">
        <a href="#new-arrivals">New Arrivals</a>
        <a href="#hijab-collection">Hijab Collection</a>
        <a href="#exclusive">Exclusive</a>
        <a href="#accessories">Accessories</a>
      </nav>
      <div style="display:flex;align-items:center;gap:1.25rem;">
        <button id="search-btn-desktop" class="hd-btn" aria-label="Search">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7.5"/><path d="m20.5 20.5-4.5-4.5"/></svg>
        </button>
        <button onclick="window.open('https://vt.tiktok.com/ZSxJxpPnm/?page=TikTokShop','_blank')" class="hd-shop-btn">
          Shop Now
        </button>
      </div>
    </div>
  </div>
</header>

<section class="hero-desktop desktop-only" aria-label="Hero banner">
  <div class="hero-desktop__inner">
    <div class="owl-carousel owl-hero-banner">
      <?php foreach ($banners as $b): ?>
        <div>
          <img src="<?= htmlspecialchars($b['image'] ?? $b['banner_image'] ?? '') ?>" alt="<?= htmlspecialchars($b['title'] ?? 'Banner') ?>" style="width:100%;display:block;" loading="eager">
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>


<section class="hero-mobile mobile-only" aria-label="Hero banner">
  <div class="owl-carousel owl-hero-banner">
    <?php foreach ($banners as $b): ?>
      <div>
        <img src="<?= htmlspecialchars($b['mobile_image'] ?? $b['image'] ?? $b['banner_image'] ?? '') ?>" alt="<?= htmlspecialchars($b['title'] ?? 'Banner') ?>" style="width:100%;height:auto;object-fit:cover;" loading="eager">
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section id="new-arrivals" class="new-arrivals" style="scroll-margin-top:40px;">
  <div class="carousel-container">
    <div class="section-header">
      <span class="eyebrow">Latest Drop</span>
      <h2 class="section-title">New Arrivals</h2>
      <div class="section-accent-line"><span class="section-accent-dot"></span></div>
    </div>

    <?php if (empty($new_arrivals)): ?>
      <div class="no-arrivals">
        <span>Coming Soon</span>
        New arrivals will be announced soon
      </div>
    <?php else: ?>
      <div class="owl-carousel owl-new-arrivals">
        <?php
        $tile_classes = ['tile-1','tile-2','tile-3','tile-4','tile-5','tile-6'];
        foreach ($new_arrivals as $i => $p):
            $candidateName = '';
            if (!empty($p['name'])) {
              $candidateName = (string)$p['name'];
            } elseif (!empty($p['image'])) {
              $candidateName = pathinfo((string)$p['image'], PATHINFO_FILENAME);
            }
            $candidateName = trim($candidateName);
            $thumb_webp = '';
            if ($candidateName !== '') {
              $thumb_webp = 'uploads/' . $candidateName . '.webp';
            }
            $thumb = '';
            if ($thumb_webp === '') {
              if (!empty($p['image'])) { $thumb = $p['image']; }
              $imgs = [];
              if (!empty($p['images'])) {
                  $decoded = json_decode($p['images'], true);
                  if (is_array($decoded)) $imgs = $decoded;
              }
              if (!empty($imgs)) {
                  $def = intval($p['default_image_index'] ?? 0);
                  $def = max(0, min($def, max(0, count($imgs) - 1)));
                  $thumb = $imgs[$def] ?? ($imgs[0] ?? $thumb);
              }
              $thumb = is_string($thumb) ? trim($thumb) : '';
              $thumb = str_replace('\\', '/', $thumb);
              $thumb = ltrim($thumb, '/');
              if ($thumb) {
                  if (preg_match('/\.webp$/i', $thumb)) {
                      $thumb_webp = $thumb;
                  } else {
                      $thumb_webp = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $thumb);
                      if ($thumb_webp === $thumb) $thumb_webp = '';
                  }
              }
            }
            $tile  = $tile_classes[$i % count($tile_classes)];
            $is_first = ($i === 0);
        ?>
        <div class="product-card">
          <div class="product-img-wrap <?= $tile ?>">
            <?php if ($thumb): ?>
              <picture>
                <?php if ($thumb_webp && $thumb_webp !== $thumb): ?>
                  <source srcset="<?= htmlspecialchars($thumb_webp) ?>" type="image/webp">
                <?php endif; ?>
                <img class="product-image"
                     src="<?= htmlspecialchars($thumb) ?>"
                     alt="<?= htmlspecialchars($p['title'] ?? '') ?>"
                     width="400" height="400"
                     <?= $is_first ? 'fetchpriority="high" loading="eager"' : 'loading="lazy"' ?>
                     onerror="this.parentElement.style.display='none'">
              </picture>
            <?php elseif ($thumb_webp): ?>
              <img class="product-image"
                   src="<?= htmlspecialchars($thumb_webp) ?>"
                   alt="<?= htmlspecialchars($p['title'] ?? '') ?>"
                   width="400" height="400"
                   <?= $is_first ? 'fetchpriority="high" loading="eager"' : 'loading="lazy"' ?>
                   onerror="this.style.display='none'">
            <?php endif; ?>
          </div>
          <div class="product-meta">
            <p class="product-name"><?= htmlspecialchars($p['title'] ?? '') ?></p>
            <?php if (!empty($p['price']) && floatval($p['price']) > 0): ?>
              <p class="product-price">RM <?= number_format(floatval($p['price']), 2) ?></p>
            <?php endif; ?>
            <span class="product-badge"><?= !empty($p['tag']) ? htmlspecialchars($p['tag']) : 'New Arrival' ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div id="hijab-collection">
  <div class="hc-section-header">
    <span class="eyebrow">Explore Collection</span>
    <h2 class="section-title">Hijab Collection</h2>
    <div class="section-accent-line"><span class="section-accent-dot"></span></div>
  </div>

  <div class="hc-owl-section">
    <div class="hc-owl-wrap">
      <div class="owl-carousel owl-hc-collection">
        <?php foreach ($slide_items as $si => $col):
            $col_id    = intval($col['id'] ?? 0);
            $col_title = htmlspecialchars($col['title'] ?? 'Collection');
            $img = '';
            $dbBanner = !empty($col['mobile_banner_image'])
              ? (string)$col['mobile_banner_image']
              : (!empty($col['banner_image']) ? (string)$col['banner_image'] : '');
            $dbBanner = trim(str_replace('\\', '/', $dbBanner));
            if ($dbBanner !== '') {
              $dbBanner = preg_replace('/[?#].*$/', '', $dbBanner);
              $stem = pathinfo(basename($dbBanner), PATHINFO_FILENAME);
              $stem = trim($stem);
              if ($stem !== '') { $img = 'uploads/' . $stem . '.webp'; }
            }
            if ($img === '') {
              $img = !empty($col['mobile_banner_image']) ? $col['mobile_banner_image']
                     : (!empty($col['banner_image']) ? $col['banner_image'] : '');
            }
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        ?>
        <div class="hc-owl-slide">
          <div class="hc-owl-card">
            <div class="hc-owl-img" onclick="window.location.href='<?= $basePath ?>/collection.php?id=<?= $col_id ?>'">
              <?php if ($img): ?>
                <img src="<?= htmlspecialchars($img) ?>"
                     alt="<?= $col_title ?>"
                     width="400" height="498"
                     loading="lazy"
                     onerror="this.style.display='none'">
              <?php else: ?>
                <div class="hc-owl-placeholder"><span><?= $col_title ?></span></div>
              <?php endif; ?>
              <div class="hc-owl-img-overlay"></div>
            </div>
            <div class="hc-owl-actions">
              <p class="hc-owl-title"><?= $col_title ?></p>
              <div class="hc-owl-btns">
                <button class="hc-owl-btn outline" onclick="window.location.href='<?= $basePath ?>/collection/<?= makeSlug($col['title'] ?? '') ?>'">
                  View
                  <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
                </button>
                <button class="hc-owl-btn solid"
                        onclick="window.location.href='<?= htmlspecialchars(($col['link'] ?? ''), ENT_QUOTES) ?>'">
                  Shop Now
                </button>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<section id="accessories" class="acc-section" style="scroll-margin-top:64px;">
  <div class="acc-inner">
    <div class="section-header fade-up" style="text-align:center;">
      <span class="eyebrow">Complete Your Look</span>
      <h2 class="section-title">Accessories</h2>
      <div class="section-accent-line"><span class="section-accent-dot"></span></div>
    </div>

    <div class="acc-bento fade-up">
      <?php
      $acc_count = count($accessories);
      $tile_classes = ['fill-1','fill-2','fill-3','fill-4','fill-5','fill-6','fill-7','fill-8'];
      ?>
      <?php if ($acc_count === 0): ?>
        <div style="text-align:center;margin-top:3rem;color:var(--muted);">
          <div style="font-family:var(--ff-display);font-size:1.5rem;font-style:italic;color:rgba(28,28,28,.2);">Coming Soon</div>
        </div>
      <?php else: ?>
      <?php foreach ($accessories as $i => $acc):
            $tile = $tile_classes[$i % count($tile_classes)];
            $acc_link = '';
            foreach (['accessories_link','shop_link','collection_link'] as $k) {
              if (!empty($acc[$k])) { $acc_link = (string)$acc[$k]; break; }
            }
            $acc_link = trim($acc_link);
      ?>
      <div class="acc-card fade-up">
        <div class="acc-card-inner">
          <div class="acc-fill <?= $tile ?>" <?php if ($acc_link): ?>onclick="window.location.href='<?= htmlspecialchars($acc_link, ENT_QUOTES) ?>'"<?php endif; ?>>
            <?php if (!empty($acc['accessories_image'])): ?>
              <img src="<?= htmlspecialchars($acc['accessories_image']) ?>"
                   alt="<?= htmlspecialchars($acc['accessory_name']) ?>"
                   width="420" height="420"
                   loading="lazy"
                   onerror="this.style.display='none'">
            <?php else: ?>
              <span class="acc-placeholder-icon"><?= htmlspecialchars($acc['accessory_name'] ?? '') ?></span>
            <?php endif; ?>
          </div>
          <div class="acc-overlay"></div>
          <div class="acc-label">
            <span class="acc-label-tag">Accessory</span>
            <span class="acc-label-name"><?= htmlspecialchars($acc['accessory_name']) ?></span>
            <span class="acc-label-price" style="display:none;">&nbsp;</span>
          </div>
          <div class="acc-cta" <?php if ($acc_link): ?>onclick="window.location.href='<?= htmlspecialchars($acc_link, ENT_QUOTES) ?>'"<?php endif; ?>>
            Shop Now
            <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="acc-viewall fade-up">
      <a href="#">
        View All Accessories
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
      </a>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div>
        <p class="footer-brand-name">Nafesa</p>
        <p class="footer-desc">Where Modesty meets Luxury. Each piece is a careful balance of elegance and grace, crafted for women who choose to shine with dignity.</p>
        <div class="footer-social">
          <a href="https://www.instagram.com/nafesahq" target="_blank" rel="noopener">Instagram</a>
          <a href="https://www.tiktok.com/@nafesahq" target="_blank" rel="noopener">TikTok</a>
        </div>
      </div>
      <div class="footer-col">
        <h4>Shop</h4>
        <ul>
          <li><a href="#new-arrivals">New Arrivals</a></li>
          <li><a href="#hijab-collection">Hijab Collection</a></li>
          <li><a href="#exclusive">Exclusive</a></li>
          <li><a href="#accessories">Accessories</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Info</h4>
        <ul>
          <li><a href="#">Contact Us</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
          <li><a href="#">Find Us</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> Nafesa. All rights reserved.</p>
      <p>Modest Luxury · Malaysia</p>
    </div>
  </div>
</footer>

<div id="search-overlay">
  <div class="search-panel" id="search-panel">
    <div class="search-panel-header">
      <h3 class="search-panel-title">Search Products</h3>
      <button class="search-close-btn" id="search-close">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="search-input-wrap">
      <svg width="16" height="16" fill="none" stroke="var(--muted)" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7.5"/><path d="m20.5 20.5-4.5-4.5"/></svg>
      <input type="text" id="search-input" placeholder="Search Nafesa products..." autocomplete="off" spellcheck="false">
    </div>
    <div id="search-results">
      <div class="search-empty"><span>What are you looking for?</span>Start typing to discover products</div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js" defer></script>

<script>
(function() {
  'use strict';

  function init() {
    /* Desktop nav */
    var desktopNav = document.getElementById('desktop-nav');
    if (desktopNav && window.innerWidth >= 1024) {
      desktopNav.style.display = 'flex';
    }

    /* Desktop nav hover */
    var navHeader = document.getElementById('main-header-desktop');
    var hdLogo = document.getElementById('hd-logo-img');
    if (navHeader && hdLogo) {
      var setDesktopLogo = function(active) {
        hdLogo.src = active ? 'assets/images/nafesa_black.png' : 'assets/images/nafesa_white.png';
      };
      navHeader.addEventListener('mouseenter', function() {
        navHeader.classList.add('nav-active');
        setDesktopLogo(true);
      });
      navHeader.addEventListener('mouseleave', function() {
        navHeader.classList.remove('nav-active');
        if (window.scrollY <= 80) setDesktopLogo(false);
      });
      var nav = navHeader.querySelector('nav');
      if (nav) {
        nav.addEventListener('mouseenter', function() { navHeader.classList.add('nav-active'); setDesktopLogo(true); });
        nav.addEventListener('mouseleave', function() { navHeader.classList.remove('nav-active'); if (window.scrollY <= 80) setDesktopLogo(false); });
      }
    }

    /* Mobile menu */
    var hamBtn = document.getElementById('ham-btn');
    var hamIcon = document.getElementById('ham-icon');
    var mobileMenu = document.getElementById('mobile-menu');
    var menuOpen = false;
    if (hamBtn && mobileMenu) {
      hamBtn.addEventListener('click', function() {
        menuOpen = !menuOpen;
        if (hamIcon) hamIcon.classList.toggle('ham-open', menuOpen);
        mobileMenu.classList.toggle('open', menuOpen);
        hamBtn.setAttribute('aria-expanded', menuOpen);
        document.body.style.overflow = menuOpen ? 'hidden' : '';
      });
      mobileMenu.querySelectorAll('.menu-link').forEach(function(a) {
        a.addEventListener('click', function() { if (menuOpen && hamBtn) hamBtn.click(); });
      });
    }

    /* Sticky header */
    var hd = document.getElementById('main-header-desktop');
    var mhd = document.getElementById('main-header');
    var hdL = document.getElementById('hd-logo-img');
    window.addEventListener('scroll', function() {
      var y = window.scrollY;
      if (hd) hd.classList.toggle('sticky', y > 80);
      if (mhd) mhd.classList.toggle('scrolled', y > 20);
      if (hdL) {
        hdL.src = (y > 80) ? 'assets/images/nafesa_black.png' : 'assets/images/nafesa_white.png';
      }
    }, { passive: true });

    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
    window.addEventListener('beforeunload', function() {
      window.scrollTo(0, 0);
    });

    /* Fade up animation */
    var fadeEls = document.querySelectorAll('.fade-up');
    if (window.IntersectionObserver) {
      var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
          if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
        });
      }, { threshold: 0.1 });
      fadeEls.forEach(function(el) { obs.observe(el); });
    } else {
      fadeEls.forEach(function(el) { el.classList.add('visible'); });
    }

    /* Search */
    var PRODUCTS = <?= $search_json ?>;
    var overlay  = document.getElementById('search-overlay');
    var panel    = document.getElementById('search-panel');
    var input    = document.getElementById('search-input');
    var results  = document.getElementById('search-results');
    var closeBtn = document.getElementById('search-close');
    var openBtns = [document.getElementById('search-btn-mobile'), document.getElementById('search-btn-desktop')];
    function openSearch() {
      if (overlay) overlay.classList.add('open');
      document.body.style.overflow = 'hidden';
      if (input) setTimeout(function(){ input.focus(); }, 50);
    }
    function closeSearch() {
      if (overlay) overlay.classList.remove('open');
      document.body.style.overflow = '';
    }
    openBtns.forEach(function(b) { if (b) b.addEventListener('click', openSearch); });
    if (closeBtn) closeBtn.addEventListener('click', closeSearch);
    if (overlay) overlay.addEventListener('click', function(e) { if (panel && !panel.contains(e.target)) closeSearch(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) closeSearch(); });
    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function doSearch(q) {
      if (!results) return;
      var term = q.trim().toLowerCase();
      if (!term) { results.innerHTML = '<div class="search-empty"><span>What are you looking for?</span>Start typing to discover products</div>'; return; }
      var hits = PRODUCTS.filter(function(p) { return p.name.toLowerCase().includes(term); }).slice(0, 8);
      if (!hits.length) { results.innerHTML = '<div class="search-empty"><span>No results found</span>Try a different keyword</div>'; return; }
      results.innerHTML = hits.map(function(p) {
        return '<a href="' + esc(p.section) + '" onclick="document.getElementById(\'search-overlay\').classList.remove(\'open\');document.body.style.overflow=\'\'" style="display:flex;align-items:center;gap:1rem;padding:.875rem 0;border-bottom:1px solid rgba(28,28,28,.06);cursor:pointer;transition:padding-left .2s;text-decoration:none;color:inherit;" onmouseover="this.style.paddingLeft=\'.5rem\'" onmouseout="this.style.paddingLeft=\'0\'">'
          + (p.thumb ? '<img src="' + esc(p.thumb) + '" style="width:40px;height:40px;object-fit:cover;flex-shrink:0;" loading="lazy" onerror="this.style.display=\'none\'">' : '')
          + '<div style="flex:1;"><p style="font-family:var(--ff-display);font-size:1rem;font-weight:400;color:var(--ink);">' + esc(p.name) + '</p><p style="font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);margin-top:.2rem;">' + esc(p.type) + '</p></div>'
          + '<svg width="14" height="14" fill="none" stroke="rgba(28,28,28,.25)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></a>';
      }).join('');
    }
    var debounce;
    if (input) input.addEventListener('input', function() { clearTimeout(debounce); debounce = setTimeout(function(){ doSearch(input.value); }, 200); });

    /* Load more function */
    window.exclLoadMore = function(gridId, btn, wrapId) {
      var grid = document.getElementById(gridId);
      if (!grid) return;
      var hidden = grid.querySelectorAll('.hidden-design');
      var count = 0;
      hidden.forEach(function(el) { if (count < 4) { el.classList.remove('hidden-design'); count++; } });
      if (grid.querySelectorAll('.hidden-design').length === 0) {
        var wrap = document.getElementById(wrapId);
        if (wrap) wrap.style.display = 'none';
      }
    };

    /* Init Owl Carousel */
    function initOwl() {
      if (typeof $ === 'undefined' || typeof $.fn.owlCarousel === 'undefined') {
        setTimeout(initOwl, 100);
        return;
      }

      /* ── Hero Banner ── */
      if ($('.owl-hero-banner').length) {
        $('.owl-hero-banner').owlCarousel({
          loop: true,
          autoplay: true,
          autoplayTimeout: 3000,
          autoplayHoverPause: false,
          dots: true,
          nav: true,
          navText: [
            '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>',
            '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>'
          ],
          smartSpeed: 450,
          items: 1
        });
      }

      /* ── New Arrivals ── */
      if ($('.owl-new-arrivals').length) {
        $('.owl-new-arrivals').owlCarousel({
          loop: true,
          autoplay: true,
          autoplayTimeout: 2800,
          autoplayHoverPause: true,
          dots: true,
          nav: false,
          smartSpeed: 500,
          responsive: {
            0:    { items: 2, margin: 10 },
            768:  { items: 3, margin: 14 },
            1024: { items: 4, margin: 16 }
          }
        });
      }

      /* ── Hijab Collection ── */
      if ($('.owl-hc-collection').length) {
        $('.owl-hc-collection').owlCarousel({
          loop: true,
          autoplay: false,
          dots: true,
          nav: true,
          navText: [
            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>',
            '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>'
          ],
          smartSpeed: 650,
          stagePadding: 30,
          responsive: {
            0:    { items: 1.3, stagePadding: 35, margin: 12 },
            480:  { items: 2.5, stagePadding: 30, margin: 14 },
            768:  { items: 3,   stagePadding: 25, margin: 16 },
            1200: { items: 3.5, stagePadding: 20, margin: 18 }
          }
        });
      }
    }
    initOwl();
  }

  if (document.readyState === 'complete') {
    init();
  } else {
    window.addEventListener('load', init);
  }
})();
</script>
</body>
</html>
<?php
ob_end_flush();
?>