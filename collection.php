<?php
/**
 * ============================================================
 * NAFESA — COLLECTION PAGE
 * nafesa.my/collection/alhambra  →  collection.php?slug=alhambra
 * localhost/path/collection/alhambra  →  collection.php?slug=alhambra
 * ============================================================
 */
include 'config/db.php';

 // ── DEBUG MODE: set true sementara nak check, false untuk production ──
define('DEBUG_MODE', false);

if (!function_exists('makeSlug')) {
    function makeSlug($str) {
        return strtolower(preg_replace('/\s+/', '', trim($str)));
    }
}

$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$basePath  = rtrim($scriptDir, '/');
$uploadsBase = rtrim(dirname($basePath), '/');

// Get slug from query string (passed by .htaccess rewrite)
$slug = isset($_GET['slug']) ? strtolower(trim(urldecode($_GET['slug']))) : '';

if (DEBUG_MODE) {
    echo '<pre style="background:#1e1e1e;color:#90ee90;padding:1rem;font-size:13px;z-index:9999;position:fixed;top:0;left:0;right:0;overflow:auto;max-height:50vh;">';
    echo "SLUG received: " . htmlspecialchars($slug) . "\n";
    echo "basePath: " . htmlspecialchars($basePath) . "\n";
}

if (!$slug) { header('Location: ' . $basePath . '/'); exit; }

// Find matching section by slug
$sections_result = $conn->query("SELECT * FROM collection_hijab_sections WHERE active = 1 ORDER BY sort_order ASC");
$section = null;
$all_sections_debug = [];
if ($sections_result) {
    while ($row = $sections_result->fetch_assoc()) {
        $computed_slug = makeSlug($row['title']);
        if (DEBUG_MODE) {
            $all_sections_debug[] = "id={$row['id']} title=\"{$row['title']}\" → slug=\"{$computed_slug}\" " . ($computed_slug === $slug ? '✓ MATCH' : '');
        }
        if ($computed_slug === $slug) {
            $section = $row;
        }
    }
}

if (DEBUG_MODE) {
    echo "All sections in DB:\n";
    foreach ($all_sections_debug as $s) echo "  $s\n";
    if ($section) {
        echo "\nMatched section id=" . $section['id'] . " title=\"" . $section['title'] . "\"\n";
    } else {
        echo "\nNO MATCH FOUND for slug: $slug\n";
    }
}

if (!$section) { header('Location: ' . $basePath . '/'); exit; }

$sec_id = intval($section['id']);

// ── FETCH PRODUCTS ──
$stmt = $conn->prepare("SELECT * FROM collection_hijab_products WHERE section_id = ? AND active = 1 ORDER BY sort_order ASC, id ASC");
$stmt->bind_param('i', $sec_id);
$stmt->execute();
$res      = $stmt->get_result();
$products = [];
while ($p = $res->fetch_assoc()) $products[] = $p;
$total = count($products);

if (DEBUG_MODE) {
    echo "\nProducts found for section_id=$sec_id: $total\n";
    foreach ($products as $p) {
        echo "  id={$p['id']} title=\"{$p['title']}\" image=\"{$p['image']}\" active={$p['active']}\n";
    }
    echo '</pre>';
}

// Build back URL to homepage collection section
$back_url = $basePath . '/#hijab-collection';

// ── HELPER: resolve image path ──
function resolveImg($path) {
    global $basePath;
    if (empty($path)) return '';

    $path = trim(str_replace('\\', '/', (string)$path));
    $path = preg_replace('/[?#].*$/', '', $path);
    $path = preg_replace('#^https?://[^/]+/#', '', $path);
    $path = ltrim($path, '/');
    $path = preg_replace('#^(\.\./)+#', '', $path);
    $path = preg_replace('#^\./+#', '', $path);
    $path = preg_replace('#^collection/+uploads/#', 'uploads/', $path); //page collection.php
    $path = preg_replace('#^uploads/#', 'uploads/', $path);
    $path = preg_replace('#^collection/#', '', $path);

    $uploadsPos = strpos($path, 'uploads/');
    if ($uploadsPos !== false) {
        $path = substr($path, $uploadsPos);
    }

    if (strpos($path, '/') === false) {
        $path = 'uploads/' . $path;
    }

    $filename = basename($path);
    if ($filename === '') return '';

    return $basePath . '/uploads/' . $filename;
}

// ── Cek sama ada mobile banner wujud dalam DB (sebelum resolveImg normalize) ──
$has_mobile_banner = !empty($section['mobile_banner_image']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($section['title']) ?> Collection — Nafesa</title>
  <meta name="description" content="Terokai <?= htmlspecialchars($section['title']) ?> daripada Nafesa — koleksi hijab premium Malaysia dengan gaya modest luxury, kemasan elegan dan kualiti mewah.">
  <meta name="keywords" content="Nafesa, <?= htmlspecialchars($section['title']) ?>, hijab Malaysia, tudung Malaysia, koleksi premium, modest luxury">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="theme-color" content="#565E3E">
  <link rel="canonical" href="https://nafesa.my/collection/<?= htmlspecialchars($slug) ?>">
  <meta property="og:site_name" content="Nafesa">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://nafesa.my/collection/<?= htmlspecialchars($slug) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($section['title']) ?> Collection — Nafesa">
  <meta property="og:description" content="Terokai <?= htmlspecialchars($section['title']) ?> daripada Nafesa — koleksi hijab premium Malaysia dengan gaya modest luxury.">
  <?php if (!empty($section['banner_image'])): ?>
  <meta property="og:image" content="https://nafesa.my/<?= htmlspecialchars(resolveImg($section['banner_image'])) ?>">
  <?php endif; ?>
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($section['title']) ?> Collection — Nafesa">
  <meta name="twitter:description" content="Terokai <?= htmlspecialchars($section['title']) ?> daripada Nafesa — koleksi hijab premium Malaysia dengan gaya modest luxury.">
  <?php if (!empty($section['banner_image'])): ?>
  <meta name="twitter:image" content="https://nafesa.my/<?= htmlspecialchars(resolveImg($section['banner_image'])) ?>">
  <?php endif; ?>
  <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png" sizes="32x32">
  <link rel="icon" type="image/png" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png" sizes="96x96">
  <link rel="apple-touch-icon" href="<?= $basePath ?>/assets/images/icon_nafesa_gold.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --sage-md:#565E3E; --gold:#b8966a; --gold-lt:#cead87;
      --cream:#f5f1eb; --ivory:#faf7f3; --forest:#1e2417;
      --ink:#1c1c1c; --muted:#8a8a8a; --border:rgba(74,82,53,0.1);
      --ff-display:'Cormorant Garamond',Georgia,serif;
      --ff-body:'DM Sans',sans-serif;
      --ease-out:cubic-bezier(0.22,1,0.36,1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html{scroll-behavior:smooth}
    body{font-family:var(--ff-body);font-weight:300;color:var(--ink);background:var(--ivory);overflow-x:hidden}
    img{display:block;max-width:100%;height:auto}
    a{text-decoration:none;color:inherit}

    /* ── HEADER ── */
    #site-header{position:fixed;top:0;left:0;right:0;height:64px;z-index:1000;background:transparent;transition:background 0.45s,box-shadow 0.45s}
    #site-header.scrolled{background:rgba(250,247,243,0.96);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);box-shadow:0 1px 0 var(--border)}
    .logo-img{height:24px;width:auto;object-fit:contain;filter:brightness(0) invert(1);transition:filter 0.35s}
    #site-header.scrolled .logo-img{filter:none}
    .back-btn{display:inline-flex;align-items:center;gap:0.5rem;font-size:0.62rem;letter-spacing:0.18em;text-transform:uppercase;color:rgba(255,255,255,0.8);background:none;border:none;cursor:pointer;transition:color 0.25s;padding:0.5rem 0}
    .back-btn:hover{color:white}
    #site-header.scrolled .back-btn{color:var(--muted)}
    #site-header.scrolled .back-btn:hover{color:var(--sage-md)}
    .home-link{font-size:0.6rem;letter-spacing:0.2em;text-transform:uppercase;color:rgba(255,255,255,0.7);transition:color 0.25s}
    .home-link:hover{color:white}
    #site-header.scrolled .home-link{color:var(--muted)}
    #site-header.scrolled .home-link:hover{color:var(--sage-md)}

    /* ── HERO — Desktop ── */
    .collection-hero{position:relative;width:100%;height:100vh;min-height:560px;overflow:hidden;background:var(--forest)}

    /* ── HERO — Mobile: ratio 800×995 ── */
    @media(max-width:767px){
      .collection-hero{
        /* Ikut nisbah 800:995 = ~0.8040 — hero akan auto-tinggi ikut lebar skrin */
        height:auto;
        aspect-ratio:800/995;
        min-height:unset;
        /* Pastikan teks di bawah tetap nampak */
        padding-bottom:0;
      }
    }

    .hero-banner-wrap{position:absolute;inset:0;width:100%;height:100%}

    /* Desktop — Ken Burns scale */
    .hero-banner-wrap img{
      position:absolute;inset:0;
      width:100%;height:100%;
      object-fit:cover;object-position:center top;
      transform:scale(1.04);
      transition:transform 6s ease-out;
      display:block;
    }
    .hero-banner-wrap img.loaded{transform:scale(1)}

    /* Mobile — no Ken Burns, full cover, object-position top supaya muka nampak */
    @media(max-width:767px){
      .hero-banner-wrap img{
        object-position:center top;
        transform:none !important;
        transition:none;
      }
    }

    .hero-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,0.35) 0%,transparent 25%),linear-gradient(to top,rgba(14,17,10,0.78) 0%,rgba(14,17,10,0.2) 45%,transparent 70%)}
    .hero-placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1e2417 0%,#2e3520 40%,#3d4a29 70%,#1e2417 100%)}
    .hero-placeholder span{font-family:var(--ff-display);font-size:clamp(3rem,8vw,7rem);font-weight:300;font-style:italic;color:rgba(255,255,255,0.06);letter-spacing:0.06em}

    .hero-text{position:absolute;bottom:0;left:0;right:0;padding:4rem 4rem 3.5rem;display:flex;align-items:flex-end;justify-content:space-between;gap:2rem}
    @media(max-width:767px){.hero-text{padding:2rem 1.5rem 1.75rem;flex-direction:column;align-items:flex-start;gap:1rem}}

    .hero-eyebrow{display:block;font-size:0.58rem;font-weight:500;letter-spacing:0.4em;text-transform:uppercase;color:var(--gold);margin-bottom:0.75rem}
    .hero-title{font-family:var(--ff-display);font-size:clamp(2.4rem,8vw,6.5rem);font-weight:300;font-style:italic;color:white;line-height:0.95;letter-spacing:-0.01em}

    .hero-meta{display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;padding-bottom:0.5rem}
    @media(max-width:767px){.hero-meta{align-items:flex-start;flex-direction:row;align-items:center;gap:1rem}}
    .hero-count{font-size:0.65rem;letter-spacing:0.22em;text-transform:uppercase;color:rgba(255,255,255,0.45)}
    .scroll-hint{display:flex;flex-direction:column;align-items:center;gap:0.5rem}
    @media(max-width:767px){.scroll-hint{display:none}}
    .scroll-hint span{font-size:0.52rem;letter-spacing:0.28em;text-transform:uppercase;color:rgba(255,255,255,0.3);writing-mode:vertical-rl}
    .scroll-line{width:1px;height:40px;background:linear-gradient(to bottom,rgba(255,255,255,0.3),transparent);animation:scrollPulse 2s ease-in-out infinite}
    @keyframes scrollPulse{0%,100%{opacity:0.3;transform:scaleY(1)}50%{opacity:0.7;transform:scaleY(1.2)}}

    /* ── BREADCRUMB ── */
    .breadcrumb{background:var(--cream);border-bottom:1px solid var(--border);padding:1rem 2rem;display:flex;align-items:center;gap:0.5rem;font-size:0.62rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--muted)}
    .breadcrumb a{color:var(--muted);transition:color 0.2s}
    .breadcrumb a:hover{color:var(--sage-md)}
    .breadcrumb .sep{opacity:0.3}
    .breadcrumb .current{color:var(--ink)}
    @media(max-width:767px){.breadcrumb{padding:0.875rem 1.25rem}}

    /* ── PRODUCTS ── */
    .products-section{padding:5rem 0 8rem;background:var(--ivory)}
    .section-header{text-align:center;margin-bottom:3.5rem;padding:0 1.5rem}
    .eyebrow{display:block;font-size:0.58rem;font-weight:500;letter-spacing:0.38em;text-transform:uppercase;color:var(--gold);margin-bottom:0.875rem}
    .section-title{font-family:var(--ff-display);font-size:clamp(2.2rem,4vw,3.4rem);font-weight:300;font-style:italic;color:var(--ink);line-height:1.1}
    .section-accent-line{display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:1.5rem}
    .section-accent-line::before,.section-accent-line::after{content:'';height:1px;width:50px;opacity:0.4;background:linear-gradient(to right,transparent,var(--gold))}
    .section-accent-line::after{background:linear-gradient(to left,transparent,var(--gold))}
    .section-accent-dot{width:4px;height:4px;border-radius:50%;background:var(--gold);opacity:0.5}
    .products-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;max-width:1200px;margin:0 auto;padding:0 2rem}
    @media(max-width:1023px){.products-grid{grid-template-columns:repeat(2,1fr);gap:1rem;padding:0 1.25rem}}
    @media(max-width:599px){.products-grid{grid-template-columns:repeat(2,1fr);gap:0.75rem;padding:0 1rem}}
    .product-card{position:relative;cursor:pointer;background:var(--cream);outline:none}
    .product-card:focus-visible{outline:2px solid var(--gold);outline-offset:4px}

    /* Product image — square 1×1 */
    .product-img-wrap{position:relative;overflow:hidden;aspect-ratio:1/1;background:var(--cream)}
    .product-img-wrap img{width:100%;height:100%;object-fit:cover;object-position:center top;transition:transform 0.85s var(--ease-out);display:block}
    .product-card:hover .product-img-wrap img{transform:scale(1.07)}
    .product-img-wrap::after{content:'';position:absolute;inset:0;background:linear-gradient(to top,rgba(30,36,23,0.65) 0%,transparent 45%);opacity:0;transition:opacity 0.4s;pointer-events:none}
    .product-card:hover .product-img-wrap::after{opacity:1}
    @media(max-width:767px){.product-img-wrap::after{opacity:1;background:linear-gradient(to top,rgba(30,36,23,0.55) 0%,transparent 35%)}}

    .zoom-hint{position:absolute;top:0.75rem;right:0.75rem;width:2rem;height:2rem;background:rgba(255,255,255,0.12);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transform:scale(0.8);transition:opacity 0.3s,transform 0.3s;pointer-events:none;z-index:2}
    .product-card:hover .zoom-hint{opacity:1;transform:scale(1)}
    @media(max-width:767px){.zoom-hint{display:none}}
    .hover-name{position:absolute;bottom:0;left:0;right:0;padding:1rem 1rem 1.1rem;font-family:var(--ff-display);font-size:0.95rem;font-style:italic;font-weight:300;color:white;text-align:center;opacity:0;z-index:2;transition:opacity 0.4s}
    .product-card:hover .hover-name{opacity:1}
    @media(max-width:767px){.hover-name{opacity:1;font-size:0.82rem;padding:0.6rem 0.75rem 0.75rem}}

    /* Placeholder tile for products without image */
    .img-placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;}
    .tile-1{background:linear-gradient(135deg,#c8bfb0,#e0d5c5)}
    .tile-2{background:linear-gradient(135deg,#b5c4a8,#d0dcca)}
    .tile-3{background:linear-gradient(135deg,#c9b99a,#e0cfb5)}
    .tile-4{background:linear-gradient(135deg,#a8b5a0,#c5d0bc)}
    .tile-5{background:linear-gradient(135deg,#bfb0a5,#d8ccbf)}
    .tile-6{background:linear-gradient(135deg,#b2c0ad,#cad5c4)}

    .product-meta{padding:0.9rem 0.5rem 0.75rem;text-align:center}
    .product-name{font-family:var(--ff-display);font-size:1rem;font-weight:400;letter-spacing:0.02em;color:var(--ink);line-height:1.3}
    .product-badge{display:inline-block;font-size:0.58rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gold);margin-top:0.3rem}
    .fade-up{opacity:0;transform:translateY(22px);transition:opacity 0.65s var(--ease-out),transform 0.65s var(--ease-out)}
    .fade-up.visible{opacity:1;transform:translateY(0)}
    .fade-up:nth-child(3n+2){transition-delay:0.08s}
    .fade-up:nth-child(3n+3){transition-delay:0.16s}
    .empty-state{text-align:center;padding:5rem 2rem;color:var(--muted)}
    .empty-icon{font-family:var(--ff-display);font-size:3rem;font-style:italic;color:rgba(28,28,28,0.1);margin-bottom:1rem;display:block}

    /* ── LIGHTBOX ── */
    #lightbox-overlay{position:fixed;inset:0;background:rgba(14,17,10,0.93);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);z-index:5000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity 0.4s var(--ease-out);padding:1.5rem}
    #lightbox-overlay.open{opacity:1;pointer-events:all}
    .lightbox-inner{position:relative;max-width:700px;width:100%;transform:scale(0.9) translateY(16px);transition:transform 0.45s var(--ease-out)}
    #lightbox-overlay.open .lightbox-inner{transform:scale(1) translateY(0)}
    .lightbox-img-wrap{position:relative;overflow:hidden;background:var(--forest);line-height:0}
    #lightbox-img{width:100%;max-height:78vh;object-fit:contain;display:block;transition:opacity 0.25s}
    .lightbox-caption{padding:1.1rem 1.5rem;background:rgba(30,36,23,0.6);border-top:1px solid rgba(184,150,106,0.15);display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .lightbox-caption-left{flex:1;min-width:0}
    .lightbox-name{font-family:var(--ff-display);font-size:1.1rem;font-weight:300;font-style:italic;color:rgba(255,255,255,0.88);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .lightbox-type{font-size:0.56rem;letter-spacing:0.24em;text-transform:uppercase;color:var(--gold);margin-top:0.25rem;opacity:0.8}
    .lightbox-counter{font-size:0.62rem;letter-spacing:0.14em;color:rgba(255,255,255,0.3);white-space:nowrap;flex-shrink:0}
    .lightbox-close{position:absolute;top:-3rem;right:0;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);cursor:pointer;color:rgba(255,255,255,0.6);width:2.2rem;height:2.2rem;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:color 0.2s,background 0.2s,transform 0.3s}
    .lightbox-close:hover{color:white;background:rgba(255,255,255,0.12);transform:rotate(90deg)}
    .lb-nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.55);width:2.75rem;height:2.75rem;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.25s,color 0.25s,transform 0.25s;z-index:10}
    .lb-nav:hover{background:rgba(255,255,255,0.14);color:white;transform:translateY(-50%) scale(1.08)}
    .lb-nav.lb-prev{left:-4rem}.lb-nav.lb-next{right:-4rem}
    .lb-loader{position:absolute;bottom:0;left:0;height:2px;width:0%;background:linear-gradient(to right,var(--gold),var(--gold-lt));pointer-events:none}
    .lb-loader.loading{width:60%;transition:width 0.6s ease}.lb-loader.done{width:100%;transition:width 0.2s ease}
    @media(max-width:767px){#lightbox-overlay{padding:1rem}.lb-nav.lb-prev{left:0.5rem}.lb-nav.lb-next{right:0.5rem}.lightbox-close{top:-2.75rem;background:rgba(0,0,0,0.45);z-index:20}#lightbox-img{max-height:65vh}}

    /* ── FOOTER ── */
    .site-footer{background:var(--cream);color:#3a3a3a;padding:4rem 1.5rem 2.5rem;border-top:1px solid var(--border)}
    .footer-inner{max-width:1200px;margin:0 auto}
    .footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:4rem;padding-bottom:3rem;border-bottom:1px solid var(--border)}
    @media(max-width:767px){.footer-grid{grid-template-columns:1fr;gap:2.5rem}}
    .footer-brand-name{font-family:var(--ff-display);font-size:2rem;font-weight:300;font-style:italic;letter-spacing:0.08em;color:var(--ink);margin-bottom:1rem}
    .footer-desc{font-size:0.8rem;font-weight:300;line-height:2;max-width:280px;color:var(--muted)}
    .footer-social{display:flex;gap:0.875rem;margin-top:1.75rem}
    .footer-social a{font-size:0.6rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--muted);border:1px solid var(--border);padding:0.45rem 1rem;transition:color 0.25s,border-color 0.25s}
    .footer-social a:hover{color:var(--sage-md);border-color:rgba(74,82,53,0.35)}
    .footer-col h4{font-size:0.58rem;font-weight:500;letter-spacing:0.28em;text-transform:uppercase;color:var(--gold);margin-bottom:1.5rem}
    .footer-col ul{list-style:none}.footer-col li{margin-bottom:0.7rem}
    .footer-col a{font-size:0.8rem;font-weight:300;color:var(--muted);transition:color 0.25s}
    .footer-col a:hover{color:var(--ink)}
    .footer-bottom{padding-top:2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem}
    .footer-bottom p{font-size:0.68rem;letter-spacing:0.08em;color:rgba(28,28,28,0.3)}
  </style>
</head>
<body>

<!-- HEADER -->
<header id="site-header">
  <div class="flex items-center justify-between h-full px-6 max-w-7xl mx-auto relative" style="height:64px;">
    <button class="back-btn" onclick="history.length > 1 ? history.back() : window.location.href='<?= $basePath ?>/'">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7-7"/></svg>
      Back
    </button>
    <a href="<?= $basePath ?>/" class="absolute left-1/2 -translate-x-1/2">
      <img src="<?= $basePath ?>/assets/images/nafesa_black.png" alt="Nafesa" class="logo-img" id="hdr-logo">
    </a>
    <a href="<?= $basePath ?>/" class="home-link">Home</a>
  </div>
</header>

<!-- HERO -->
<section class="collection-hero">
  <div class="hero-banner-wrap">
    <?php
    $hero_img     = resolveImg($section['banner_image'] ?? '');
    $hero_mob_img = $has_mobile_banner ? resolveImg($section['mobile_banner_image']) : $hero_img;
    ?>
    <?php if ($hero_img): ?>
    <picture>
      <?php if ($has_mobile_banner): ?>
      <source media="(max-width:767px)" srcset="<?= htmlspecialchars($hero_mob_img) ?>">
      <?php endif; ?>
      <img src="<?= htmlspecialchars($hero_img) ?>"
           alt="Nafesa <?= htmlspecialchars($section['title']) ?> Collection"
           id="hero-img"
           onerror="this.closest('.hero-banner-wrap').style.display='none'">
    </picture>
    <?php endif; ?>
  </div>
  <?php if (!$hero_img): ?>
  <div class="hero-placeholder">
    <span><?= htmlspecialchars($section['title']) ?></span>
  </div>
  <?php endif; ?>

  </div>
</section>



<!-- PRODUCTS -->
<main class="products-section">
  <div class="section-header">
    <span class="eyebrow">Hijab Collection</span>
    <h2 class="section-title"><?= htmlspecialchars($section['title']) ?></h2>
    <div class="section-accent-line"><span class="section-accent-dot"></span></div>
  </div>

  <?php if (empty($products)): ?>
    <div class="empty-state">
      <span class="empty-icon">~ Coming Soon ~</span>
      <p style="font-size:.8rem;color:var(--muted)">Products will be available soon. Follow us on Instagram for updates.</p>
    </div>
  <?php else: ?>
  <div class="products-grid">
    <?php
    $tile_classes = ['tile-1','tile-2','tile-3','tile-4','tile-5','tile-6'];
    foreach ($products as $i => $p):
      $img_src = resolveImg($p['image'] ?? '');

      if (!empty($img_src)) {
          $img_src = str_replace('\\', '/', $img_src);
          $img_src = preg_replace('#^collection/+#', '', $img_src);
          if (str_starts_with($img_src, 'uploads/')) {
              $img_src = '/' . $img_src;
          } elseif (!str_starts_with($img_src, '/')) {
              $img_src = '/' . ltrim($img_src, '/');
          }
      }

      $tile = $tile_classes[$i % count($tile_classes)];
    ?>
    <div class="product-card fade-up"
         data-src="<?= htmlspecialchars($img_src) ?>"
         data-name="<?= htmlspecialchars($p['title'] ?? '') ?>"
         tabindex="0" role="button"
         aria-label="View <?= htmlspecialchars($p['title'] ?? '') ?>">
      <div class="product-img-wrap">
        <?php if ($img_src): ?>
          <img src="<?= htmlspecialchars($img_src) ?>"
               alt="<?= htmlspecialchars($p['alt_text'] ?? $p['title'] ?? '') ?> — Nafesa"
               loading="<?= $i < 6 ? 'eager' : 'lazy' ?>">
          <div class="img-placeholder <?= $tile ?>" style="display:none;">
            <span style="font-family:var(--ff-display);font-size:0.9rem;font-style:italic;color:rgba(74,82,53,0.5);"><?= htmlspecialchars($p['title'] ?? '') ?></span>
          </div>
        <?php else: ?>
          <div class="img-placeholder <?= $tile ?>">
            <span style="font-family:var(--ff-display);font-size:0.9rem;font-style:italic;color:rgba(74,82,53,0.5);"><?= htmlspecialchars($p['title'] ?? '') ?></span>
          </div>
        <?php endif; ?>
        <div class="zoom-hint" aria-hidden="true">
          <svg width="12" height="12" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4M11 8v6M8 11h6"/></svg>
        </div>
      </div>
      <div class="product-meta">
        <p class="product-name"><?= htmlspecialchars($p['title'] ?? '') ?></p>
        <span class="product-badge"><?= htmlspecialchars($section['title']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>

<!-- FOOTER -->
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
          <li><a href="<?= $basePath ?>/#new-arrivals">New Arrivals</a></li>
          <li><a href="<?= $back_url ?>">Hijab Collection</a></li>
          <li><a href="<?= $basePath ?>/#exclusive">Exclusive</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Info</h4>
        <ul>
          <li><a href="#">Contact Us</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> Nafesa. All rights reserved.</p>
      <p>Modest Luxury · Malaysia</p>
    </div>
  </div>
</footer>

<!-- LIGHTBOX -->
<div id="lightbox-overlay" role="dialog" aria-modal="true" aria-label="View Product Image">
  <div class="lightbox-inner">
    <button class="lightbox-close" id="lb-close" aria-label="Close">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
    <div class="lightbox-img-wrap">
      <img id="lightbox-img" src="" alt="" loading="eager">
      <div class="lb-loader" id="lb-loader"></div>
      <button class="lb-nav lb-prev" id="lb-prev" aria-label="Previous"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
      <button class="lb-nav lb-next" id="lb-next" aria-label="Next"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></button>
    </div>
    <div class="lightbox-caption">
      <div class="lightbox-caption-left">
        <p class="lightbox-name" id="lb-name"></p>
        <p class="lightbox-type"><?= htmlspecialchars($section['title']) ?> · Nafesa</p>
      </div>
      <span class="lightbox-counter" id="lb-counter"></span>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';

  // Header scroll + logo swap
  const header  = document.getElementById('site-header');
  const hdrLogo = document.getElementById('hdr-logo');
  window.addEventListener('scroll', () => {
    const scrolled = window.scrollY > 80;
    header.classList.toggle('scrolled', scrolled);
    if (hdrLogo) {
      hdrLogo.src = scrolled
        ? '<?= $basePath ?>/assets/images/nafesa_black.png'
        : '<?= $basePath ?>/assets/images/nafesa_white.png';
    }
  }, { passive: true });

  // Hero Ken Burns (desktop only — mobile uses transform:none via CSS)
  const heroImg = document.getElementById('hero-img');
  if (heroImg) {
    heroImg.complete ? heroImg.classList.add('loaded') : heroImg.addEventListener('load', () => heroImg.classList.add('loaded'));
  }

  // Fade-up observer
  const fadeEls = document.querySelectorAll('.fade-up');
  if (window.IntersectionObserver) {
    const obs = new IntersectionObserver(entries => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.08 });
    fadeEls.forEach(el => obs.observe(el));
  } else {
    fadeEls.forEach(el => el.classList.add('visible'));
  }

  // Lightbox
  const overlay   = document.getElementById('lightbox-overlay');
  const lbImg     = document.getElementById('lightbox-img');
  const lbName    = document.getElementById('lb-name');
  const lbClose   = document.getElementById('lb-close');
  const lbPrev    = document.getElementById('lb-prev');
  const lbNext    = document.getElementById('lb-next');
  const lbCounter = document.getElementById('lb-counter');
  const lbLoader  = document.getElementById('lb-loader');
  let items = [], current = 0;

  function collectItems() {
    items = [];
    document.querySelectorAll('.product-card[data-src]').forEach(el => {
      if (el.dataset.src && el.dataset.src.trim() !== '') {
        items.push({ src: el.dataset.src, name: el.dataset.name || '', el });
      }
    });
  }

  function showImage(idx) {
    if (!items.length) return;
    current = (idx + items.length) % items.length;
    const item = items[current];
    if (lbLoader) lbLoader.className = 'lb-loader loading';
    lbImg.style.opacity = '0';
    setTimeout(() => {
      const tmp = new Image();
      tmp.onload = () => {
        lbImg.src = item.src; lbImg.alt = item.name; lbImg.style.opacity = '1';
        if (lbLoader) { lbLoader.className = 'lb-loader done'; setTimeout(() => lbLoader.className = 'lb-loader', 300); }
      };
      tmp.onerror = () => { lbImg.src = item.src; lbImg.style.opacity = '1'; if (lbLoader) lbLoader.className = 'lb-loader'; };
      tmp.src = item.src;
      if (lbName) lbName.textContent = item.name;
      if (lbCounter) lbCounter.textContent = items.length > 1 ? `${current + 1} / ${items.length}` : '';
    }, 180);
    lbPrev.style.display = lbNext.style.display = items.length > 1 ? 'flex' : 'none';
  }

  function openLightbox(el) {
    if (!el.dataset.src || !el.dataset.src.trim()) return;
    collectItems();
    if (!items.length) return;
    const idx = items.findIndex(i => i.el === el);
    showImage(idx >= 0 ? idx : 0);
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox() { overlay.classList.remove('open'); document.body.style.overflow = ''; }

  document.querySelectorAll('.product-card[data-src]').forEach(el => {
    el.addEventListener('click', () => openLightbox(el));
    el.addEventListener('keypress', e => { if (e.key === 'Enter' || e.key === ' ') openLightbox(el); });
  });

  lbPrev.addEventListener('click', e => { e.stopPropagation(); showImage(current - 1); });
  lbNext.addEventListener('click', e => { e.stopPropagation(); showImage(current + 1); });
  lbClose.addEventListener('click', closeLightbox);
  overlay.addEventListener('click', e => { if (e.target === overlay) closeLightbox(); });

  document.addEventListener('keydown', e => {
    if (!overlay.classList.contains('open')) return;
    if (e.key === 'Escape')    { e.preventDefault(); closeLightbox(); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); showImage(current - 1); }
    if (e.key === 'ArrowRight'){ e.preventDefault(); showImage(current + 1); }
  });

  let tX = 0, tY = 0;
  overlay.addEventListener('touchstart', e => { tX = e.touches[0].clientX; tY = e.touches[0].clientY; }, { passive: true });
  overlay.addEventListener('touchend', e => {
    const dx = tX - e.changedTouches[0].clientX, dy = Math.abs(tY - e.changedTouches[0].clientY);
    if (Math.abs(dx) > 50 && dy < 80) showImage(current + (dx > 0 ? 1 : -1));
  });

  // Force uploads URLs root-relative
  const fixUploadsUrl = (value) => {
    if (!value || typeof value !== 'string') return value;
    if (value.startsWith('/uploads/')) return value;
    if (value.startsWith('uploads/')) return '/' + value;
    return value;
  };

  document.querySelectorAll('img[src^="uploads/"]').forEach(img => {
    img.setAttribute('src', fixUploadsUrl(img.getAttribute('src')));
  });
  document.querySelectorAll('source[srcset^="uploads/"]').forEach(s => {
    const srcset = s.getAttribute('srcset');
    if (srcset) s.setAttribute('srcset', fixUploadsUrl(srcset));
  });
  document.querySelectorAll('.product-card[data-src]').forEach(card => {
    const ds = card.getAttribute('data-src');
    if (ds) card.setAttribute('data-src', fixUploadsUrl(ds));
  });

  // Fallback: show placeholder if product image fails to load
  document.querySelectorAll('.product-img-wrap img').forEach(img => {
    img.addEventListener('error', function() {
      this.style.display = 'none';
      const placeholder = this.nextElementSibling;
      if (placeholder && placeholder.classList.contains('img-placeholder')) {
        placeholder.style.display = 'flex';
      }
    });
  });

})();
</script>
</body>
</html>
