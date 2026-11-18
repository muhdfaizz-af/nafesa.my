<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '105M');
ini_set('max_execution_time', '300');
ini_set('max_input_time', '300');

session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}
include '../config/db.php';

// ============================================================
// IMAGE HELPERS
// ============================================================
function compressAndSave($tmp_path, $destination, $max_width = 2560, $quality = 88) {
    $info = @getimagesize($tmp_path);
    if (!$info) return false;
    $mime = $info['mime'];
    $src  = null;
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') $src = @imagecreatefromjpeg($tmp_path);
    elseif ($mime === 'image/png')  $src = @imagecreatefrompng($tmp_path);
    elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($tmp_path);
    elseif ($mime === 'image/gif')  $src = @imagecreatefromgif($tmp_path);
    if (!$src) return move_uploaded_file($tmp_path, $destination) || @rename($tmp_path, $destination);
    $orig_w = imagesx($src);
    $orig_h = imagesy($src);
    if ($orig_w > $max_width) {
        $new_w = $max_width;
        $new_h = intval($orig_h * ($max_width / $orig_w));
        $dst   = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($src);
        $src = $dst;
    }
    $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    if ($mime === 'image/png' || $ext === 'png') $result = imagepng($src, $destination, min(9, intval((100 - $quality) / 10)));
    elseif ($mime === 'image/webp' || $ext === 'webp') $result = imagewebp($src, $destination, $quality);
    else $result = imagejpeg($src, $destination, $quality);
    imagedestroy($src);
    return $result;
}

function handleImageUpload($file_key, $target_dir, $filename) {
    if (!isset($_FILES[$file_key])) return ['ok' => false, 'error' => 'No file field found.'];
    $err = $_FILES[$file_key]['error'];
    if ($err === UPLOAD_ERR_OK) {
        $tmp = $_FILES[$file_key]['tmp_name'];
        if (!@getimagesize($tmp)) return ['ok' => false, 'error' => 'Not a valid image.'];
        $destination = $target_dir . $filename;
        if (compressAndSave($tmp, $destination)) return ['ok' => true, 'path' => 'uploads/' . $filename];
        return ['ok' => false, 'error' => 'Failed to save image. Check uploads folder permissions.'];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['ok' => false, 'error' => 'File too large.'];
    if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'no_file'];
    return ['ok' => false, 'error' => 'Upload error code: ' . $err];
}

function uploadMultipleImages($file_key, $target_dir) {
    $saved = [];
    if (empty($_FILES[$file_key]['name'][0])) return $saved;
    for ($i = 0; $i < count($_FILES[$file_key]['name']); $i++) {
        if ($_FILES[$file_key]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $_FILES['__tmp__'] = [
            'tmp_name' => $_FILES[$file_key]['tmp_name'][$i],
            'error'    => $_FILES[$file_key]['error'][$i],
            'name'     => $_FILES[$file_key]['name'][$i],
        ];
        $dest = time() . '_' . mt_rand(1000,9999) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES[$file_key]['name'][$i]));
        $up = handleImageUpload('__tmp__', $target_dir, $dest);
        if ($up['ok']) $saved[] = $up['path'];
        unset($_FILES['__tmp__']);
    }
    return $saved;
}

// ============================================================
// DB HELPERS
// ============================================================
function safeQuery($conn, $sql) {
    $result = $conn->query($sql);
    $rows = [];
    if ($result && $result !== true) while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function esc($conn, $val) { return $conn->real_escape_string($val); }

function ensureTables($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS collection_hijab_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        banner_image VARCHAR(500) DEFAULT '',
        mobile_banner_image VARCHAR(500) DEFAULT '',
        sort_order INT DEFAULT 0,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    foreach (['description' => "TEXT NULL", 'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", 'is_exclusive' => "TINYINT(1) DEFAULT 0"] as $col => $def) {
        $c = $conn->query("SHOW COLUMNS FROM collection_hijab_sections LIKE '$col'");
        if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE collection_hijab_sections ADD COLUMN $col $def");
    }
    // Exclusive banner table (web + mobile banner for Exclusive section)
    $conn->query("CREATE TABLE IF NOT EXISTS exclusive_banner (id INT AUTO_INCREMENT PRIMARY KEY, image VARCHAR(500) DEFAULT '', mobile_image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("INSERT INTO exclusive_banner (id) VALUES (1) ON DUPLICATE KEY UPDATE id=id");
    $conn->query("CREATE TABLE IF NOT EXISTS designs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        collection_id INT DEFAULT NULL,
        description TEXT NULL,
        design_image VARCHAR(500) DEFAULT '',
        sort_order INT DEFAULT 0,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    foreach (['title' => "VARCHAR(255) NOT NULL", 'collection_id' => "INT DEFAULT NULL", 'description' => "TEXT NULL", 'design_image' => "VARCHAR(500) DEFAULT ''", 'sort_order' => "INT DEFAULT 0", 'active' => "TINYINT(1) DEFAULT 1"] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM designs LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE designs ADD COLUMN $col $def");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        category VARCHAR(100) DEFAULT '',
        tag VARCHAR(255) DEFAULT '',
        image VARCHAR(500) DEFAULT '',
        images JSON,
        default_image_index INT DEFAULT 0,
        collection_id INT DEFAULT NULL,
        design_id INT DEFAULT NULL,
        sort_order INT DEFAULT 0,
        new_arrival TINYINT(1) DEFAULT 0,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    foreach (['images' => "JSON", 'default_image_index' => "INT DEFAULT 0", 'collection_id' => "INT DEFAULT NULL", 'design_id' => "INT DEFAULT NULL", 'sort_order' => "INT DEFAULT 0", 'new_arrival' => "TINYINT(1) DEFAULT 0", 'active' => "TINYINT(1) DEFAULT 1"] as $col => $def) {
        $c = $conn->query("SHOW COLUMNS FROM products LIKE '$col'");
        if ($c && $c->num_rows === 0) $conn->query("ALTER TABLE products ADD COLUMN $col $def");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS site_settings (id INT AUTO_INCREMENT PRIMARY KEY, site_status TINYINT(1) NOT NULL DEFAULT 1)");
    $conn->query("INSERT INTO site_settings (id, site_status) VALUES (1, 1) ON DUPLICATE KEY UPDATE id=id");
    $conn->query("CREATE TABLE IF NOT EXISTS banners (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) DEFAULT '', image VARCHAR(500) DEFAULT '', mobile_image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS collection_exclusive (id INT AUTO_INCREMENT PRIMARY KEY, image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS exclusive_collaborations (id INT AUTO_INCREMENT PRIMARY KEY, image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', link VARCHAR(500) DEFAULT '', sort_order INT DEFAULT 0, active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS collection_hijab_products (id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, title VARCHAR(255) DEFAULT '', image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', link VARCHAR(500) DEFAULT '', sort_order INT DEFAULT 0, active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS collection_hijab_banner (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) DEFAULT '', image VARCHAR(500) DEFAULT '', mobile_image VARCHAR(500) DEFAULT '', alt_text VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
}
ensureTables($conn);

$target_dir = dirname(__DIR__) . '/uploads/';
if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

$section = isset($_GET['section']) ? $_GET['section'] : 'products';
$is_ajax = isset($_GET['ajax']);

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_collection_section') {
        $title        = esc($conn, trim($_POST['title'] ?? ''));
        $desc         = esc($conn, trim($_POST['description'] ?? ''));
        $sort_order   = intval($_POST['sort_order'] ?? 0);
        $is_exclusive = isset($_POST['is_exclusive']) ? 1 : 0;
        if (!empty($title)) {
            $conn->query("INSERT INTO collection_hijab_sections (title, description, sort_order, is_exclusive) VALUES ('$title','$desc',$sort_order,$is_exclusive)");
            $new_id = $conn->insert_id;
            $wb = handleImageUpload('banner_image', $target_dir, time().'_web_'.basename($_FILES['banner_image']['name'] ?? 'b.jpg'));
            $mb = handleImageUpload('mobile_banner_image', $target_dir, time().'_mob_'.basename($_FILES['mobile_banner_image']['name'] ?? 'mb.jpg'));
            $parts = [];
            if ($wb['ok']) $parts[] = "banner_image='".esc($conn,$wb['path'])."'";
            if ($mb['ok']) $parts[] = "mobile_banner_image='".esc($conn,$mb['path'])."'";
            if ($parts) $conn->query("UPDATE collection_hijab_sections SET ".implode(',',$parts)." WHERE id=$new_id");
        }
    }

    if ($action === 'edit_collection_section') {
        $id           = intval($_POST['id']);
        $title        = esc($conn, trim($_POST['title'] ?? ''));
        $desc         = esc($conn, trim($_POST['description'] ?? ''));
        $sort_order   = intval($_POST['sort_order'] ?? 0);
        $is_exclusive = isset($_POST['is_exclusive']) ? 1 : 0;
        $parts = ["title='$title'", "description='$desc'", "sort_order=$sort_order", "is_exclusive=$is_exclusive"];
        $wb = handleImageUpload('banner_image', $target_dir, time().'_web_'.basename($_FILES['banner_image']['name'] ?? 'b.jpg'));
        $mb = handleImageUpload('mobile_banner_image', $target_dir, time().'_mob_'.basename($_FILES['mobile_banner_image']['name'] ?? 'mb.jpg'));
        if ($wb['ok']) $parts[] = "banner_image='".esc($conn,$wb['path'])."'";
        if ($mb['ok']) $parts[] = "mobile_banner_image='".esc($conn,$mb['path'])."'";
        $conn->query("UPDATE collection_hijab_sections SET ".implode(',',$parts)." WHERE id=$id");
    }

    if ($action === 'update_exclusive_banner') {
        $alt   = esc($conn, trim($_POST['alt_text'] ?? ''));
        $parts = ["alt_text='$alt'"];
        $wb = handleImageUpload('image', $target_dir, time().'_excl_web_'.basename($_FILES['image']['name'] ?? 'b.jpg'));
        $mb = handleImageUpload('mobile_image', $target_dir, time().'_excl_mob_'.basename($_FILES['mobile_image']['name'] ?? 'mb.jpg'));
        if ($wb['ok']) $parts[] = "image='".esc($conn,$wb['path'])."'";
        if ($mb['ok']) $parts[] = "mobile_image='".esc($conn,$mb['path'])."'";
        $conn->query("UPDATE exclusive_banner SET ".implode(',',$parts)." WHERE id=1");
    }

    if ($action === 'add_design') {
        $title      = esc($conn, trim($_POST['title'] ?? ''));
        $desc       = esc($conn, trim($_POST['description'] ?? ''));
        $col_id     = !empty($_POST['collection_id']) ? intval($_POST['collection_id']) : 'NULL';
        $sort_order = intval($_POST['sort_order'] ?? 0);
        if (!empty($title)) {
            $conn->query("INSERT INTO designs (title,description,collection_id,sort_order) VALUES ('$title','$desc',$col_id,$sort_order)");
            $new_des_id = $conn->insert_id;
            $di = handleImageUpload('design_image', $target_dir, time().'_design_'.basename($_FILES['design_image']['name'] ?? 'd.jpg'));
            if ($di['ok']) $conn->query("UPDATE designs SET design_image='".esc($conn,$di['path'])."' WHERE id=$new_des_id");
        }
    }

    if ($action === 'edit_design') {
        $id         = intval($_POST['id']);
        $title      = esc($conn, trim($_POST['title'] ?? ''));
        $desc       = esc($conn, trim($_POST['description'] ?? ''));
        $col_id     = !empty($_POST['collection_id']) ? intval($_POST['collection_id']) : 'NULL';
        $sort_order = intval($_POST['sort_order'] ?? 0);
        $parts = ["title='$title'", "description='$desc'", "collection_id=$col_id", "sort_order=$sort_order"];
        $di = handleImageUpload('design_image', $target_dir, time().'_design_'.basename($_FILES['design_image']['name'] ?? 'd.jpg'));
        if ($di['ok']) $parts[] = "design_image='".esc($conn,$di['path'])."'";
        $conn->query("UPDATE designs SET ".implode(',',$parts)." WHERE id=$id");
    }

    if ($action === 'add_product') {
        $name        = esc($conn, trim($_POST['name'] ?? ''));
        $desc        = esc($conn, trim($_POST['description'] ?? ''));
        $category    = esc($conn, trim($_POST['category'] ?? ''));
        $tag         = esc($conn, trim($_POST['tag'] ?? ''));
        $col_id      = !empty($_POST['collection_id']) ? intval($_POST['collection_id']) : 'NULL';
        $des_id      = !empty($_POST['design_id']) ? intval($_POST['design_id']) : 'NULL';
        $sort_order  = intval($_POST['sort_order'] ?? 0);
        $new_arrival = isset($_POST['new_arrival']) ? 1 : 0;
        $default_idx = intval($_POST['default_image_index'] ?? 0);
        $saved = uploadMultipleImages('images', $target_dir);
        if (!empty($saved)) {
            $default_idx = max(0, min($default_idx, count($saved) - 1));
            $img0       = esc($conn, $saved[$default_idx]);
            $imgs_json  = esc($conn, json_encode(array_values($saved)));
            $conn->query("INSERT INTO products (name,description,image,images,default_image_index,category,tag,collection_id,design_id,sort_order,new_arrival) VALUES ('$name','$desc','$img0','$imgs_json',$default_idx,'$category','$tag',$col_id,$des_id,$sort_order,$new_arrival)");
        } else {
            if (!empty($name)) $conn->query("INSERT INTO products (name,description,category,tag,collection_id,design_id,sort_order,new_arrival) VALUES ('$name','$desc','$category','$tag',$col_id,$des_id,$sort_order,$new_arrival)");
        }
    }

    if ($action === 'edit_product') {
        $id          = intval($_POST['id']);
        $name        = esc($conn, trim($_POST['name'] ?? ''));
        $desc        = esc($conn, trim($_POST['description'] ?? ''));
        $category    = esc($conn, trim($_POST['category'] ?? ''));
        $tag         = esc($conn, trim($_POST['tag'] ?? ''));
        $col_id      = !empty($_POST['collection_id']) ? intval($_POST['collection_id']) : 'NULL';
        $des_id      = !empty($_POST['design_id']) ? intval($_POST['design_id']) : 'NULL';
        $sort_order  = intval($_POST['sort_order'] ?? 0);
        $new_arrival = isset($_POST['new_arrival']) ? 1 : 0;
        $default_idx = intval($_POST['default_image_index'] ?? 0);
        $parts = ["name='$name'","description='$desc'","category='$category'","tag='$tag'","collection_id=$col_id","design_id=$des_id","sort_order=$sort_order","new_arrival=$new_arrival","default_image_index=$default_idx"];
        $saved = uploadMultipleImages('images', $target_dir);
        if (!empty($saved)) {
            $default_idx = max(0, min($default_idx, count($saved) - 1));
            $img0 = esc($conn, $saved[$default_idx]);
            $imgs_json = esc($conn, json_encode(array_values($saved)));
            $parts[] = "image='$img0'";
            $parts[] = "images='$imgs_json'";
        } else {
            $existing = safeQuery($conn, "SELECT images FROM products WHERE id=$id LIMIT 1");
            if (!empty($existing[0]['images'])) {
                $ex_imgs = json_decode($existing[0]['images'], true) ?: [];
                if (!empty($ex_imgs)) {
                    $default_idx = max(0, min($default_idx, count($ex_imgs) - 1));
                    $parts[] = "image='".esc($conn, $ex_imgs[$default_idx])."'";
                    $parts[] = "default_image_index=$default_idx";
                }
            }
        }
        $conn->query("UPDATE products SET ".implode(',',$parts)." WHERE id=$id");
    }

    if ($action === 'add_banner') {
        $title      = esc($conn, trim($_POST['title'] ?? ''));
        $alt        = esc($conn, trim($_POST['alt_text'] ?? ''));
        $sort_order = intval($_POST['sort_order'] ?? 0);
        $wb = handleImageUpload('image', $target_dir, time().'_web_'.basename($_FILES['image']['name'] ?? 'b.jpg'));
        $mb = handleImageUpload('mobile_image', $target_dir, time().'_mob_'.basename($_FILES['mobile_image']['name'] ?? 'mb.jpg'));
        if ($wb['ok'] && $mb['ok']) $conn->query("INSERT INTO banners (title,image,mobile_image,alt_text,sort_order) VALUES ('$title','".esc($conn,$wb['path'])."','".esc($conn,$mb['path'])."','$alt',$sort_order)");
    }

    if ($action === 'update_site_status') {
        $conn->query("UPDATE site_settings SET site_status=".intval($_POST['site_status'])." WHERE id=1");
    }

    if ($action === 'add_collection_exclusive') {
        $alt = esc($conn, trim($_POST['alt_text'] ?? ''));
        $sort = intval($_POST['sort_order'] ?? 0);
        $up = handleImageUpload('image', $target_dir, time().'_excl_'.basename($_FILES['image']['name'] ?? 'e.jpg'));
        if ($up['ok']) $conn->query("INSERT INTO collection_exclusive (image,alt_text,sort_order) VALUES ('".esc($conn,$up['path'])."','$alt',$sort)");
    }

    if ($action === 'add_collaboration') {
        $alt  = esc($conn, trim($_POST['alt_text'] ?? ''));
        $link = esc($conn, trim($_POST['link'] ?? ''));
        $sort = intval($_POST['sort_order'] ?? 0);
        $up = handleImageUpload('image', $target_dir, time().'_collab_'.basename($_FILES['image']['name'] ?? 'c.jpg'));
        if ($up['ok']) $conn->query("INSERT INTO exclusive_collaborations (image,alt_text,link,sort_order) VALUES ('".esc($conn,$up['path'])."','$alt','$link',$sort)");
    }

    // Always respond JSON when ajax_action param present
    if (isset($_GET['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode($conn->error ? ['ok' => false, 'error' => $conn->error] : ['ok' => true]);
        exit();
    }
}

// ---- DELETE handlers (GET) ----
if (isset($_GET['delete']))               { $conn->query("UPDATE products SET active=0 WHERE id=".intval($_GET['delete'])); }
if (isset($_GET['delete_design']))        { $conn->query("UPDATE designs SET active=0 WHERE id=".intval($_GET['delete_design'])); }
if (isset($_GET['delete_section']))       { $conn->query("UPDATE collection_hijab_sections SET active=0 WHERE id=".intval($_GET['delete_section'])); }
if (isset($_GET['delete_banner']))        { $conn->query("DELETE FROM banners WHERE id=".intval($_GET['delete_banner'])); }
if (isset($_GET['delete_collection']))    { $conn->query("DELETE FROM collection_exclusive WHERE id=".intval($_GET['delete_collection'])); }
if (isset($_GET['delete_collaboration'])) { $conn->query("DELETE FROM exclusive_collaborations WHERE id=".intval($_GET['delete_collaboration'])); }

// JSON response for DELETE ajax
if ($is_ajax && (isset($_GET['delete']) || isset($_GET['delete_design']) || isset($_GET['delete_section']) ||
    isset($_GET['delete_banner']) || isset($_GET['delete_collection']) || isset($_GET['delete_collaboration']))) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit();
}

// ============================================================
// AJAX: JSON data for modals
// ============================================================
if ($is_ajax && isset($_GET['get_collection'])) {
    $row = safeQuery($conn, "SELECT * FROM collection_hijab_sections WHERE id=".intval($_GET['get_collection'])." LIMIT 1");
    header('Content-Type: application/json');
    echo json_encode($row[0] ?? null);
    exit();
}
if ($is_ajax && isset($_GET['get_design'])) {
    $row = safeQuery($conn, "SELECT * FROM designs WHERE id=".intval($_GET['get_design'])." LIMIT 1");
    header('Content-Type: application/json');
    echo json_encode($row[0] ?? null);
    exit();
}
if ($is_ajax && isset($_GET['get_product'])) {
    $row = safeQuery($conn, "SELECT * FROM products WHERE id=".intval($_GET['get_product'])." LIMIT 1");
    header('Content-Type: application/json');
    echo json_encode($row[0] ?? null);
    exit();
}

// ============================================================
// FETCH DATA
// ============================================================
$collections     = safeQuery($conn, "SELECT * FROM collection_hijab_sections WHERE active=1 ORDER BY sort_order ASC");
$designs         = safeQuery($conn, "SELECT d.*, c.title AS collection_title FROM designs d LEFT JOIN collection_hijab_sections c ON d.collection_id=c.id WHERE d.active=1 ORDER BY d.sort_order ASC");
$products        = safeQuery($conn, "SELECT p.*, c.title AS col_title, d.title AS des_title FROM products p LEFT JOIN collection_hijab_sections c ON p.collection_id=c.id LEFT JOIN designs d ON p.design_id=d.id WHERE p.active=1 ORDER BY p.sort_order ASC");
$banners         = safeQuery($conn, "SELECT * FROM banners ORDER BY sort_order ASC");
$coll_excl       = safeQuery($conn, "SELECT * FROM collection_exclusive WHERE active=1 ORDER BY sort_order ASC");
$collabs         = safeQuery($conn, "SELECT * FROM exclusive_collaborations WHERE active=1 ORDER BY sort_order ASC");
$site_status_r   = safeQuery($conn, "SELECT site_status FROM site_settings WHERE id=1");
$site_status     = !empty($site_status_r) ? intval($site_status_r[0]['site_status']) : 1;
$exclusive_banner_row = safeQuery($conn, "SELECT * FROM exclusive_banner WHERE id=1 LIMIT 1");
$exclusive_banner     = $exclusive_banner_row[0] ?? ['image'=>'','mobile_image'=>'','alt_text'=>''];
// Collections that are ticked exclusive (for exclusive grid)
$excl_collections = safeQuery($conn, "SELECT * FROM collection_hijab_sections WHERE active=1 AND is_exclusive=1 ORDER BY sort_order ASC");

// AJAX: return section HTML only
if ($is_ajax) {
    ob_start();
    renderSection($section, $conn, $collections, $designs, $products, $banners, $coll_excl, $collabs, $site_status, $exclusive_banner, $excl_collections);
    $html = ob_get_clean();
    echo '<div id="main-content" class="flex-1 p-6">' . $html . '</div>';
    exit();
}

// ============================================================
// RENDER SECTION
// ============================================================
function renderSection($section, $conn, $collections, $designs, $products, $banners, $coll_excl, $collabs, $site_status, $exclusive_banner=[], $excl_collections=[]) {
    switch ($section) {
        case 'collections':          renderCollections($conn, $collections, $exclusive_banner, $excl_collections); break;
        case 'designs':              renderDesigns($conn, $designs, $collections); break;
        case 'products':             renderProducts($conn, $products, $collections, $designs); break;
        case 'banners':              renderBanners($conn, $banners); break;
        case 'collection-exclusive': renderCollections($conn, $collections, $exclusive_banner, $excl_collections); break;
        case 'collaborations':       renderCollaborations($conn, $collabs); break;
        case 'site-settings':        renderSiteSettings($site_status); break;
        default:                     renderProducts($conn, $products, $collections, $designs);
    }
}

// ============================================================
// COLLECTIONS SECTION
// ============================================================
function renderCollections($conn, $collections, $exclusive_banner=[], $excl_collections=[]) { ?>
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Collections</h2>
        <p class="text-gray-500 text-sm">Manage collection sections untuk hijab</p>
    </div>
    <button data-open-modal="modal-add-collection" class="btn-primary flex items-center gap-2">
        <i class="fas fa-plus"></i> Tambah Collection
    </button>
</div>

<div class="card rounded-2xl overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">ID</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Banner</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Nama Collection</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Deskripsi</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Seq</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Exclusive</th>
                <th class="text-right p-4 text-sm font-semibold text-gray-600">Tindakan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($collections)): ?>
                <tr><td colspan="7" class="p-8 text-center text-gray-400"><i class="fas fa-inbox text-4xl mb-3 block"></i>Tiada collection lagi</td></tr>
            <?php else: foreach ($collections as $c): ?>
            <tr class="border-b hover:bg-gray-50 cursor-pointer expandable-row" data-detail="detail-<?= $c['id'] ?>">
                <td class="p-4 text-gray-500 text-sm">#<?= $c['id'] ?></td>
                <td class="p-4">
                    <?php if (!empty($c['banner_image'])): ?>
                        <img src="../<?= htmlspecialchars($c['banner_image']) ?>" class="w-16 h-10 object-cover rounded">
                    <?php else: ?>
                        <div class="w-16 h-10 bg-gray-100 rounded flex items-center justify-center"><i class="fas fa-image text-gray-300"></i></div>
                    <?php endif; ?>
                </td>
                <td class="p-4 font-semibold text-gray-800"><?= htmlspecialchars($c['title']) ?></td>
                <td class="p-4 text-gray-500 text-sm"><?= htmlspecialchars(mb_strimwidth($c['description'] ?? '', 0, 60, '...')) ?></td>
                <td class="p-4 text-gray-500"><?= intval($c['sort_order']) ?></td>
                <td class="p-4">
                    <?php if (!empty($c['is_exclusive'])): ?>
                        <span class="badge-green">✦ Ya</span>
                    <?php else: ?>
                        <span class="text-gray-300 text-sm">—</span>
                    <?php endif; ?>
                </td>
                <td class="p-4 text-right">
                    <button data-edit-collection="<?= $c['id'] ?>" class="btn-icon-edit mr-2" title="Edit"><i class="fas fa-edit"></i></button>
                    <a href="?section=collections&delete_section=<?= $c['id'] ?>" data-section="collections" class="btn-icon-delete delete-link" title="Padam"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <tr class="detail-row hidden bg-blue-50" id="detail-<?= $c['id'] ?>">
                <td colspan="7" class="p-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-1">WEB BANNER</p>
                            <?php if (!empty($c['banner_image'])): ?>
                                <img src="../<?= htmlspecialchars($c['banner_image']) ?>" class="w-full h-28 object-cover rounded">
                            <?php else: ?><div class="w-full h-28 bg-gray-100 rounded flex items-center justify-center text-gray-400">Tiada banner</div><?php endif; ?>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-1">MOBILE BANNER</p>
                            <?php if (!empty($c['mobile_banner_image'])): ?>
                                <img src="../<?= htmlspecialchars($c['mobile_banner_image']) ?>" class="w-full h-28 object-cover rounded">
                            <?php else: ?><div class="w-full h-28 bg-gray-100 rounded flex items-center justify-center text-gray-400">Tiada banner</div><?php endif; ?>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-1">MAKLUMAT</p>
                            <p class="text-sm text-gray-700"><strong>Nama:</strong> <?= htmlspecialchars($c['title']) ?></p>
                            <p class="text-sm text-gray-700"><strong>Seq:</strong> <?= intval($c['sort_order']) ?></p>
                            <p class="text-sm text-gray-700 mt-1"><?= htmlspecialchars($c['description'] ?? '') ?></p>
                            <p class="text-xs text-gray-400 mt-2">Dicipta: <?= $c['created_at'] ?></p>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ADD Collection Modal -->
<div id="modal-add-collection" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Tambah Collection Baru</h3>
            <button data-close-modal="modal-add-collection" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="collections" data-msg="Collection berjaya ditambah!">
            <input type="hidden" name="action" value="add_collection_section">
            <div><label class="form-label">Nama Collection *</label><input type="text" name="title" class="form-input" placeholder="cth: Spring Collection 2025" required></div>
            <div><label class="form-label">Deskripsi</label><textarea name="description" class="form-input" rows="3" placeholder="Penerangan collection..."></textarea></div>
            <div><label class="form-label">Sequence (urutan paparan)</label><input type="number" name="sort_order" class="form-input" value="0" min="0"></div>
            <div><label class="form-label">Banner Web (1920×1080) — Pilihan</label><input type="file" name="banner_image" accept="image/*" class="form-input"></div>
            <div><label class="form-label">Banner Mobile (800×995) — Pilihan</label><input type="file" name="mobile_banner_image" accept="image/*" class="form-input"></div>
            <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_exclusive" value="1" class="w-5 h-5 rounded accent-amber-500">
                    <div>
                        <span class="font-semibold text-gray-700 text-sm">✦ Exclusive Collection</span>
                        <p class="text-xs text-gray-500 mt-0.5">Tandakan untuk paparkan collection ini di bahagian Exclusive pada laman web</p>
                    </div>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-add-collection" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan Collection</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT Collection Modal -->
<div id="modal-edit-collection" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Edit Collection</h3>
            <button data-close-modal="modal-edit-collection" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="collections" data-msg="Collection berjaya dikemaskini!">
            <input type="hidden" name="action" value="edit_collection_section">
            <input type="hidden" name="id" id="edit-collection-id">
            <div><label class="form-label">Nama Collection *</label><input type="text" name="title" id="edit-collection-title" class="form-input" required></div>
            <div><label class="form-label">Deskripsi</label><textarea name="description" id="edit-collection-desc" class="form-input" rows="3"></textarea></div>
            <div><label class="form-label">Sequence</label><input type="number" name="sort_order" id="edit-collection-sort" class="form-input" min="0"></div>
            <div>
                <label class="form-label">Banner Web baru (kosongkan jika tidak tukar)</label>
                <input type="file" name="banner_image" accept="image/*" class="form-input">
                <div id="edit-collection-web-preview" class="mt-2"></div>
            </div>
            <div>
                <label class="form-label">Banner Mobile baru (kosongkan jika tidak tukar)</label>
                <input type="file" name="mobile_banner_image" accept="image/*" class="form-input">
                <div id="edit-collection-mob-preview" class="mt-2"></div>
            </div>
            <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_exclusive" id="edit-collection-exclusive" value="1" class="w-5 h-5 rounded accent-amber-500">
                    <div>
                        <span class="font-semibold text-gray-700 text-sm">✦ Exclusive Collection</span>
                        <p class="text-xs text-gray-500 mt-0.5">Paparkan di bahagian Exclusive pada laman web</p>
                    </div>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-edit-collection" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Kemaskini</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     EXCLUSIVE BANNER — upload sekali dengan Collections
═══════════════════════════════════════════════════════ -->
<div class="mt-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-lg font-bold text-gray-900">✦ Exclusive — Banner &amp; Collections</h3>
            <p class="text-gray-500 text-sm">Banner exclusive dan senarai collections yang ditandakan exclusive</p>
        </div>
        <button data-open-modal="modal-excl-banner" class="btn-primary flex items-center gap-2">
            <i class="fas fa-image"></i> Kemaskini Banner Exclusive
        </button>
    </div>

    <!-- Banner preview -->
    <div class="card rounded-2xl p-5 mb-5">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-2">Web Banner</p>
                <?php if (!empty($exclusive_banner['image'])): ?>
                    <img src="../<?= htmlspecialchars($exclusive_banner['image']) ?>" class="w-full h-32 object-cover rounded-xl border border-gray-100">
                <?php else: ?>
                    <div class="w-full h-32 bg-gray-50 border border-dashed border-gray-200 rounded-xl flex items-center justify-center text-gray-400 text-sm">Tiada banner web lagi</div>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-2">Mobile Banner</p>
                <?php if (!empty($exclusive_banner['mobile_image'])): ?>
                    <img src="../<?= htmlspecialchars($exclusive_banner['mobile_image']) ?>" class="w-full h-32 object-cover rounded-xl border border-gray-100">
                <?php else: ?>
                    <div class="w-full h-32 bg-gray-50 border border-dashed border-gray-200 rounded-xl flex items-center justify-center text-gray-400 text-sm">Tiada banner mobile lagi</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Exclusive collections grid -->
    <div class="card rounded-2xl p-5">
        <p class="text-sm font-semibold text-gray-700 mb-1">Collections Ditanda ✦ Exclusive</p>
        <p class="text-xs text-gray-400 mb-4">Collections di bawah ini akan muncul dalam grid Exclusive di laman web. Untuk tambah/buang, edit collection dan tukar tick Exclusive.</p>
        <?php if (empty($excl_collections)): ?>
            <div class="py-8 text-center text-gray-400 border border-dashed border-gray-200 rounded-xl">
                <i class="fas fa-star text-2xl mb-2 block text-gray-300"></i>
                <p class="text-sm">Tiada collection ditanda exclusive lagi.</p>
                <p class="text-xs mt-1">Edit mana-mana collection di atas → tick <strong>✦ Exclusive Collection</strong>.</p>
            </div>
        <?php else: ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <?php foreach ($excl_collections as $ec):
                $ec_img = !empty($ec['mobile_banner_image']) ? $ec['mobile_banner_image']
                        : (!empty($ec['banner_image']) ? $ec['banner_image'] : '');
            ?>
            <div class="rounded-xl overflow-hidden border border-amber-100 bg-amber-50">
                <?php if ($ec_img): ?>
                    <img src="../<?= htmlspecialchars($ec_img) ?>" class="w-full h-36 object-cover">
                <?php else: ?>
                    <div class="w-full h-36 bg-amber-100 flex items-center justify-center"><i class="fas fa-image text-amber-300 text-2xl"></i></div>
                <?php endif; ?>
                <div class="p-2">
                    <p class="font-semibold text-xs text-gray-800 truncate"><?= htmlspecialchars($ec['title']) ?></p>
                    <span class="text-xs text-amber-600 font-semibold">✦ Exclusive</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Exclusive Banner -->
<div id="modal-excl-banner" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Kemaskini Banner Exclusive</h3>
            <button data-close-modal="modal-excl-banner" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="collections" data-msg="Banner Exclusive dikemaskini!">
            <input type="hidden" name="action" value="update_exclusive_banner">
            <div><label class="form-label">Alt Text</label><input type="text" name="alt_text" class="form-input" value="<?= htmlspecialchars($exclusive_banner['alt_text'] ?? '') ?>"></div>
            <div>
                <label class="form-label">Gambar Web (1920×800) — kosong jika tidak tukar</label>
                <?php if (!empty($exclusive_banner['image'])): ?><img src="../<?= htmlspecialchars($exclusive_banner['image']) ?>" class="h-20 object-cover rounded-lg mb-2 border border-gray-100"><?php endif; ?>
                <input type="file" name="image" accept="image/*" class="form-input">
            </div>
            <div>
                <label class="form-label">Gambar Mobile (800×995) — kosong jika tidak tukar</label>
                <?php if (!empty($exclusive_banner['mobile_image'])): ?><img src="../<?= htmlspecialchars($exclusive_banner['mobile_image']) ?>" class="h-20 object-cover rounded-lg mb-2 border border-gray-100"><?php endif; ?>
                <input type="file" name="mobile_image" accept="image/*" class="form-input">
            </div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-excl-banner" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan Banner</button>
            </div>
        </form>
    </div>
</div>
<?php }

// ============================================================
// DESIGNS SECTION
// ============================================================
function renderDesigns($conn, $designs, $collections) { ?>
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Designs</h2>
        <p class="text-gray-500 text-sm">Urus design dalam collection</p>
    </div>
    <button data-open-modal="modal-add-design" class="btn-primary flex items-center gap-2">
        <i class="fas fa-plus"></i> Tambah Design
    </button>
</div>

<div class="card rounded-2xl overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">ID</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Gambar</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Nama Design</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Collection</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Deskripsi</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Seq</th>
                <th class="text-right p-4 text-sm font-semibold text-gray-600">Tindakan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($designs)): ?>
                <tr><td colspan="7" class="p-8 text-center text-gray-400"><i class="fas fa-palette text-4xl mb-3 block"></i>Tiada design lagi</td></tr>
            <?php else: foreach ($designs as $d): ?>
            <tr class="border-b hover:bg-gray-50 cursor-pointer expandable-row" data-detail="design-detail-<?= $d['id'] ?>">
                <td class="p-4 text-gray-500 text-sm">#<?= $d['id'] ?></td>
                <td class="p-3">
                    <?php if (!empty($d['design_image'])): ?>
                        <img src="../<?= htmlspecialchars($d['design_image']) ?>" class="w-14 h-14 object-cover rounded-lg">
                    <?php else: ?>
                        <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center"><i class="fas fa-image text-gray-300 text-xl"></i></div>
                    <?php endif; ?>
                </td>
                <td class="p-4 font-semibold text-gray-800"><?= htmlspecialchars($d['title']) ?></td>
                <td class="p-4">
                    <?php if (!empty($d['collection_title'])): ?>
                        <span class="badge-blue"><?= htmlspecialchars($d['collection_title']) ?></span>
                    <?php else: ?>
                        <span class="text-gray-400 text-sm">—</span>
                    <?php endif; ?>
                </td>
                <td class="p-4 text-gray-500 text-sm"><?= htmlspecialchars(mb_strimwidth($d['description'] ?? '', 0, 60, '...')) ?></td>
                <td class="p-4 text-gray-500"><?= intval($d['sort_order']) ?></td>
                <td class="p-4 text-right">
                    <button data-edit-design="<?= $d['id'] ?>" class="btn-icon-edit mr-2" title="Edit"><i class="fas fa-edit"></i></button>
                    <a href="?section=designs&delete_design=<?= $d['id'] ?>" data-section="designs" class="btn-icon-delete delete-link" title="Padam"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <tr class="detail-row hidden bg-blue-50" id="design-detail-<?= $d['id'] ?>">
                <td colspan="7" class="p-4">
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-2">GAMBAR DESIGN</p>
                            <?php if (!empty($d['design_image'])): ?>
                                <img src="../<?= htmlspecialchars($d['design_image']) ?>" class="w-full h-40 object-cover rounded-lg">
                            <?php else: ?>
                                <div class="w-full h-40 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 text-sm">Tiada gambar</div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-1">MAKLUMAT DESIGN</p>
                            <p class="text-sm text-gray-700"><strong>Nama:</strong> <?= htmlspecialchars($d['title']) ?></p>
                            <p class="text-sm text-gray-700"><strong>Collection:</strong> <?= htmlspecialchars($d['collection_title'] ?? '—') ?></p>
                            <p class="text-sm text-gray-700"><strong>Sequence:</strong> <?= intval($d['sort_order']) ?></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 mb-1">DESKRIPSI</p>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($d['description'] ?? '—') ?></p>
                            <p class="text-xs text-gray-400 mt-2">Dicipta: <?= $d['created_at'] ?></p>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ADD Design Modal -->
<div id="modal-add-design" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Tambah Design Baru</h3>
            <button data-close-modal="modal-add-design" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="designs" data-msg="Design berjaya ditambah!">
            <input type="hidden" name="action" value="add_design">
            <div><label class="form-label">Nama Design *</label><input type="text" name="title" class="form-input" placeholder="cth: Segiempat Classic" required></div>
            <div><label class="form-label">Deskripsi</label><textarea name="description" class="form-input" rows="3" placeholder="Penerangan design..."></textarea></div>
            <div>
                <label class="form-label">Pilih Collection</label>
                <select name="collection_id" class="form-input">
                    <option value="">— Tiada —</option>
                    <?php foreach ($collections as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label">Sequence (urutan)</label><input type="number" name="sort_order" class="form-input" value="0" min="0"></div>
            <div>
                <label class="form-label">Gambar Design</label>
                <input type="file" name="design_image" accept="image/*" class="form-input img-preview-input" data-preview="add-design-img-preview">
                <div id="add-design-img-preview" class="mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-add-design" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan Design</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT Design Modal -->
<div id="modal-edit-design" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Edit Design</h3>
            <button data-close-modal="modal-edit-design" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="designs" data-msg="Design berjaya dikemaskini!">
            <input type="hidden" name="action" value="edit_design">
            <input type="hidden" name="id" id="edit-design-id">
            <div><label class="form-label">Nama Design *</label><input type="text" name="title" id="edit-design-title" class="form-input" required></div>
            <div><label class="form-label">Deskripsi</label><textarea name="description" id="edit-design-desc" class="form-input" rows="3"></textarea></div>
            <div>
                <label class="form-label">Pilih Collection</label>
                <select name="collection_id" id="edit-design-collection" class="form-input">
                    <option value="">— Tiada —</option>
                    <?php foreach ($collections as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label">Sequence</label><input type="number" name="sort_order" id="edit-design-sort" class="form-input" min="0"></div>
            <div>
                <label class="form-label">Gambar Design (kosongkan jika tidak tukar)</label>
                <div id="edit-design-img-current" class="mb-2"></div>
                <input type="file" name="design_image" accept="image/*" class="form-input img-preview-input" data-preview="edit-design-img-preview">
                <div id="edit-design-img-preview" class="mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-edit-design" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Kemaskini</button>
            </div>
        </form>
    </div>
</div>
<?php }

// ============================================================
// PRODUCTS SECTION
// ============================================================
function renderProducts($conn, $products, $collections, $designs) {
    $total   = count($products);
    $new_arr = count(array_filter($products, fn($p) => !empty($p['new_arrival'])));
    $cats    = array_unique(array_column($products, 'category'));
    ?>
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Products</h2>
        <p class="text-gray-500 text-sm">Senarai semua produk</p>
    </div>
    <button data-open-modal="modal-add-product" class="btn-primary flex items-center gap-2">
        <i class="fas fa-plus"></i> Tambah Produk
    </button>
</div>

<!-- Stats bar -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="card rounded-xl p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center"><i class="fas fa-box text-red-600"></i></div>
        <div><p class="text-2xl font-bold"><?= $total ?></p><p class="text-xs text-gray-500">Jumlah Produk</p></div>
    </div>
    <div class="card rounded-xl p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center"><i class="fas fa-star text-green-600"></i></div>
        <div><p class="text-2xl font-bold"><?= $new_arr ?></p><p class="text-xs text-gray-500">New Arrivals</p></div>
    </div>
    <div class="card rounded-xl p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center"><i class="fas fa-layer-group text-blue-600"></i></div>
        <div><p class="text-2xl font-bold"><?= count($cats) ?></p><p class="text-xs text-gray-500">Kategori</p></div>
    </div>
    <div class="card rounded-xl p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center"><i class="fas fa-images text-purple-600"></i></div>
        <div><p class="text-2xl font-bold"><?= count($collections) ?></p><p class="text-xs text-gray-500">Collections</p></div>
    </div>
</div>

<!-- Filter bar -->
<div class="card rounded-xl p-4 mb-4 flex flex-wrap gap-3 items-center">
    <input type="text" id="product-search" placeholder="Cari nama produk..." class="form-input flex-1 min-w-48">
    <select id="filter-category" class="form-input w-auto">
        <option value="">Semua Kategori</option>
        <option value="Hijabs">Hijabs</option>
        <option value="Ready to Wear">Ready to Wear</option>
        <option value="Accessories">Accessories</option>
        <option value="New Arrivals">New Arrivals</option>
        <option value="Sale">Sale</option>
    </select>
    <select id="filter-collection" class="form-input w-auto">
        <option value="">Semua Collection</option>
        <?php foreach ($collections as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
        <input type="checkbox" id="filter-new-arrival"> New Arrival sahaja
    </label>
</div>

<div class="card rounded-2xl overflow-hidden">
    <table class="w-full" id="product-table">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Gambar</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Nama Produk</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Kategori</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Collection / Design</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Tag</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Seq</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">New</th>
                <th class="text-right p-4 text-sm font-semibold text-gray-600">Tindakan</th>
            </tr>
        </thead>
        <tbody id="product-tbody">
            <?php if (empty($products)): ?>
                <tr><td colspan="8" class="p-8 text-center text-gray-400"><i class="fas fa-box-open text-4xl mb-3 block"></i>Tiada produk lagi</td></tr>
            <?php else: foreach ($products as $p):
                $imgs = [];
                if (!empty($p['images'])) $imgs = json_decode($p['images'], true) ?: [];
                if (empty($imgs) && !empty($p['image'])) $imgs[] = $p['image'];
                $def_idx = intval($p['default_image_index'] ?? 0);
                $def_idx = max(0, min($def_idx, max(0, count($imgs)-1)));
                $thumb = $imgs[$def_idx] ?? ($imgs[0] ?? '');
            ?>
            <tr class="border-b hover:bg-gray-50 cursor-pointer expandable-row"
                data-detail="prod-detail-<?= $p['id'] ?>"
                data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                data-category="<?= htmlspecialchars($p['category'] ?? '') ?>"
                data-collection="<?= intval($p['collection_id'] ?? 0) ?>"
                data-new="<?= intval($p['new_arrival'] ?? 0) ?>">
                <td class="p-3">
                    <?php if ($thumb): ?>
                        <img src="../<?= htmlspecialchars($thumb) ?>" class="w-14 h-14 object-cover rounded-lg">
                    <?php else: ?>
                        <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center"><i class="fas fa-image text-gray-300 text-xl"></i></div>
                    <?php endif; ?>
                </td>
                <td class="p-4">
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($p['name']) ?></p>
                    <?php if (count($imgs) > 1): ?><p class="text-xs text-gray-400"><?= count($imgs) ?> gambar</p><?php endif; ?>
                </td>
                <td class="p-4"><span class="badge-gray"><?= htmlspecialchars($p['category'] ?? '—') ?></span></td>
                <td class="p-4 text-sm">
                    <?php if (!empty($p['col_title'])): ?><span class="badge-blue block mb-1"><?= htmlspecialchars($p['col_title']) ?></span><?php endif; ?>
                    <?php if (!empty($p['des_title'])): ?><span class="badge-purple"><?= htmlspecialchars($p['des_title']) ?></span><?php endif; ?>
                </td>
                <td class="p-4 text-gray-500 text-sm"><?= htmlspecialchars($p['tag'] ?? '—') ?></td>
                <td class="p-4 text-gray-500"><?= intval($p['sort_order'] ?? 0) ?></td>
                <td class="p-4">
                    <?php if (!empty($p['new_arrival'])): ?>
                        <span class="badge-green">✦ New</span>
                    <?php else: ?>
                        <span class="text-gray-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="p-4 text-right">
                    <button data-edit-product="<?= $p['id'] ?>" class="btn-icon-edit mr-2" title="Edit"><i class="fas fa-edit"></i></button>
                    <a href="?section=products&delete=<?= $p['id'] ?>" data-section="products" class="btn-icon-delete delete-link" title="Padam"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <tr class="detail-row hidden bg-orange-50" id="prod-detail-<?= $p['id'] ?>">
                <td colspan="8" class="p-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($imgs as $i => $im): ?>
                            <div class="relative">
                                <img src="../<?= htmlspecialchars($im) ?>" class="w-full h-28 object-cover rounded-lg <?= $i === $def_idx ? 'ring-2 ring-red-500' : '' ?>">
                                <?php if ($i === $def_idx): ?>
                                    <span class="absolute top-1 left-1 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded font-bold">✓ Default</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($p['description'])): ?>
                        <p class="text-sm text-gray-600 mt-3"><?= htmlspecialchars($p['description']) ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-2">Dicipta: <?= $p['created_at'] ?></p>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ADD Product Modal -->
<div id="modal-add-product" class="modal-overlay hidden">
    <div class="modal-box modal-box-lg">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Tambah Produk Baru</h3>
            <button data-close-modal="modal-add-product" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body ajax-form" data-section="products" data-msg="Produk berjaya ditambah!">
            <input type="hidden" name="action" value="add_product">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="form-label">Nama Produk *</label><input type="text" name="name" class="form-input" placeholder="cth: Bawal Silk Premium" required></div>
                <div class="col-span-2"><label class="form-label">Deskripsi</label><textarea name="description" class="form-input" rows="3" placeholder="Penerangan produk..."></textarea></div>
                <div>
                    <label class="form-label">Kategori *</label>
                    <select name="category" class="form-input" required>
                        <option value="">Pilih Kategori</option>
                        <option value="Hijabs">Hijabs</option>
                        <option value="Ready to Wear">Ready to Wear</option>
                        <option value="Accessories">Accessories</option>
                        <option value="New Arrivals">New Arrivals</option>
                        <option value="Sale">Sale</option>
                    </select>
                </div>
                <div><label class="form-label">Tag</label><input type="text" name="tag" class="form-input" placeholder="cth: bestseller, limited"></div>
                <div>
                    <label class="form-label">Collection</label>
                    <select name="collection_id" class="form-input">
                        <option value="">— Tiada —</option>
                        <?php foreach ($collections as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Design</label>
                    <select name="design_id" class="form-input">
                        <option value="">— Tiada —</option>
                        <?php foreach ($designs as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?> <?= !empty($d['collection_title']) ? '('.$d['collection_title'].')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="form-label">Sequence (urutan)</label><input type="number" name="sort_order" class="form-input" value="0" min="0"></div>
                <div class="flex items-end pb-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="new_arrival" value="1" class="w-5 h-5 rounded accent-red-600">
                        <span class="font-semibold text-gray-700">✦ New Arrival</span>
                    </label>
                </div>
                <div class="col-span-2">
                    <label class="form-label">Gambar Produk — boleh pilih lebih 1. Klik gambar untuk set sebagai default (thumbnail utama)</label>
                    <input type="file" name="images[]" accept="image/*" multiple class="form-input multi-img-input"
                           data-preview="add-product-preview" data-idx="add-default-idx">
                    <input type="hidden" name="default_image_index" id="add-default-idx" value="0">
                    <p class="text-xs text-gray-400 mt-1">Format: JPG, PNG, WEBP. Klik gambar untuk tukar default.</p>
                    <div id="add-product-preview" class="grid grid-cols-4 gap-2 mt-3"></div>
                </div>
            </div>
            <div class="modal-footer mt-4">
                <button type="button" data-close-modal="modal-add-product" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan Produk</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT Product Modal -->
<div id="modal-edit-product" class="modal-overlay hidden">
    <div class="modal-box modal-box-lg">
        <div class="modal-header">
            <h3 class="font-bold text-lg">Edit Produk</h3>
            <button data-close-modal="modal-edit-product" class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-body ajax-form" data-section="products" data-msg="Produk berjaya dikemaskini!">
            <input type="hidden" name="action" value="edit_product">
            <input type="hidden" name="id" id="edit-product-id">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="form-label">Nama Produk *</label><input type="text" name="name" id="edit-product-name" class="form-input" required></div>
                <div class="col-span-2"><label class="form-label">Deskripsi</label><textarea name="description" id="edit-product-desc" class="form-input" rows="3"></textarea></div>
                <div>
                    <label class="form-label">Kategori</label>
                    <select name="category" id="edit-product-category" class="form-input">
                        <option value="">Pilih Kategori</option>
                        <option value="Hijabs">Hijabs</option>
                        <option value="Ready to Wear">Ready to Wear</option>
                        <option value="Accessories">Accessories</option>
                        <option value="New Arrivals">New Arrivals</option>
                        <option value="Sale">Sale</option>
                    </select>
                </div>
                <div><label class="form-label">Tag</label><input type="text" name="tag" id="edit-product-tag" class="form-input"></div>
                <div>
                    <label class="form-label">Collection</label>
                    <select name="collection_id" id="edit-product-collection" class="form-input">
                        <option value="">— Tiada —</option>
                        <?php foreach ($collections as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Design</label>
                    <select name="design_id" id="edit-product-design" class="form-input">
                        <option value="">— Tiada —</option>
                        <?php foreach ($designs as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="form-label">Sequence</label><input type="number" name="sort_order" id="edit-product-sort" class="form-input" min="0"></div>
                <div class="flex items-end pb-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="new_arrival" id="edit-product-new-arrival" value="1" class="w-5 h-5 rounded accent-red-600">
                        <span class="font-semibold text-gray-700">✦ New Arrival</span>
                    </label>
                </div>
                <div class="col-span-2">
                    <label class="form-label">Gambar Semasa — klik untuk set default (✓ = thumbnail utama)</label>
                    <input type="hidden" name="default_image_index" id="edit-default-idx" value="0">
                    <div id="edit-product-current-images" class="grid grid-cols-4 gap-2 mb-3"></div>
                    <label class="form-label">Upload Gambar Baru (akan gantikan semua gambar lama)</label>
                    <input type="file" name="images[]" accept="image/*" multiple class="form-input multi-img-input"
                           data-preview="edit-product-new-preview" data-idx="edit-default-idx">
                    <div id="edit-product-new-preview" class="grid grid-cols-4 gap-2 mt-2"></div>
                </div>
            </div>
            <div class="modal-footer mt-4">
                <button type="button" data-close-modal="modal-edit-product" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Kemaskini Produk</button>
            </div>
        </form>
    </div>
</div>
<?php }

// ============================================================
// BANNERS SECTION
// ============================================================
function renderBanners($conn, $banners) { ?>
<div class="flex items-center justify-between mb-6">
    <div><h2 class="text-2xl font-bold">Banners</h2><p class="text-gray-500 text-sm">Homepage carousel banners</p></div>
    <button data-open-modal="modal-add-banner" class="btn-primary flex items-center gap-2"><i class="fas fa-plus"></i> Tambah Banner</button>
</div>
<div class="card rounded-2xl overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Web</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Mobile</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Tajuk</th>
                <th class="text-left p-4 text-sm font-semibold text-gray-600">Seq</th>
                <th class="text-right p-4 text-sm font-semibold text-gray-600">Tindakan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banners)): ?>
                <tr><td colspan="5" class="p-8 text-center text-gray-400">Tiada banner</td></tr>
            <?php else: foreach ($banners as $b): ?>
            <tr class="border-b hover:bg-gray-50">
                <td class="p-3"><img src="../<?= htmlspecialchars($b['image']) ?>" class="w-24 h-14 object-cover rounded"></td>
                <td class="p-3"><img src="../<?= htmlspecialchars($b['mobile_image']) ?>" class="w-12 h-14 object-cover rounded"></td>
                <td class="p-4 font-semibold"><?= htmlspecialchars($b['title']) ?></td>
                <td class="p-4 text-gray-500"><?= intval($b['sort_order']) ?></td>
                <td class="p-4 text-right">
                    <a href="?section=banners&delete_banner=<?= $b['id'] ?>" data-section="banners" class="btn-icon-delete delete-link"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<div id="modal-add-banner" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header"><h3 class="font-bold text-lg">Tambah Banner</h3><button data-close-modal="modal-add-banner" class="modal-close"><i class="fas fa-times"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="banners" data-msg="Banner berjaya ditambah!">
            <input type="hidden" name="action" value="add_banner">
            <div><label class="form-label">Tajuk *</label><input type="text" name="title" class="form-input" required></div>
            <div><label class="form-label">Alt Text</label><input type="text" name="alt_text" class="form-input"></div>
            <div><label class="form-label">Sequence</label><input type="number" name="sort_order" class="form-input" value="0"></div>
            <div><label class="form-label">Gambar Web (1920×800) *</label><input type="file" name="image" accept="image/*" class="form-input" required></div>
            <div><label class="form-label">Gambar Mobile (800×995) *</label><input type="file" name="mobile_image" accept="image/*" class="form-input" required></div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-add-banner" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php }


// ============================================================
// COLLABORATIONS
// ============================================================
function renderCollaborations($conn, $collabs) { ?>
<div class="flex items-center justify-between mb-6">
    <div><h2 class="text-2xl font-bold">Collaborations</h2></div>
    <button data-open-modal="modal-add-collab" class="btn-primary flex items-center gap-2"><i class="fas fa-plus"></i> Tambah</button>
</div>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
    <?php foreach ($collabs as $row): ?>
    <div class="card rounded-xl overflow-hidden">
        <img src="../<?= htmlspecialchars($row['image']) ?>" class="w-full h-48 object-cover">
        <div class="p-3">
            <a href="<?= htmlspecialchars($row['link']) ?>" target="_blank" class="text-blue-500 text-xs break-all"><?= htmlspecialchars($row['link'] ?: '—') ?></a>
            <p class="text-xs text-gray-500">Sort: <?= intval($row['sort_order']) ?></p>
            <a href="?section=collaborations&delete_collaboration=<?= $row['id'] ?>" data-section="collaborations" class="delete-link text-red-500 text-sm mt-1 block">Padam</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div id="modal-add-collab" class="modal-overlay hidden">
    <div class="modal-box">
        <div class="modal-header"><h3 class="font-bold text-lg">Tambah Collaboration</h3><button data-close-modal="modal-add-collab" class="modal-close"><i class="fas fa-times"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="modal-body space-y-4 ajax-form" data-section="collaborations" data-msg="Kolaborasi berjaya ditambah!">
            <input type="hidden" name="action" value="add_collaboration">
            <div><label class="form-label">Alt Text</label><input type="text" name="alt_text" class="form-input"></div>
            <div><label class="form-label">Link</label><input type="url" name="link" class="form-input"></div>
            <div><label class="form-label">Sequence</label><input type="number" name="sort_order" class="form-input" value="0"></div>
            <div><label class="form-label">Gambar *</label><input type="file" name="image" accept="image/*" class="form-input" required></div>
            <div class="modal-footer">
                <button type="button" data-close-modal="modal-add-collab" class="btn-secondary">Batal</button>
                <button type="submit" class="btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php }

// ============================================================
// SITE SETTINGS
// ============================================================
function renderSiteSettings($site_status) { ?>
<div class="card rounded-2xl p-6 max-w-xl">
    <h2 class="text-xl font-bold mb-4">Status Website</h2>
    <form method="POST" class="ajax-form" data-section="site-settings" data-msg="Status laman dikemaskini!">
        <input type="hidden" name="action" value="update_site_status">
        <div class="flex gap-6 mb-6">
            <label class="flex items-center gap-3 cursor-pointer p-4 rounded-xl border-2 <?= $site_status==1 ? 'border-green-400 bg-green-50' : 'border-gray-200' ?>">
                <input type="radio" name="site_status" value="1" <?= $site_status==1 ? 'checked' : '' ?>>
                <span class="font-semibold text-green-700">🟢 LIVE</span>
            </label>
            <label class="flex items-center gap-3 cursor-pointer p-4 rounded-xl border-2 <?= $site_status==0 ? 'border-red-400 bg-red-50' : 'border-gray-200' ?>">
                <input type="radio" name="site_status" value="0" <?= $site_status==0 ? 'checked' : '' ?>>
                <span class="font-semibold text-red-700">🔴 MAINTENANCE</span>
            </label>
        </div>
        <button type="submit" class="btn-primary">Kemaskini Status</button>
    </form>
</div>
<?php }
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal — Nafesa CMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: Inter, system-ui, sans-serif; background: #f1f5f9; }
        .sidebar { background: linear-gradient(180deg,#1e293b 0%,#334155 100%); }
        .card { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.07); }
        .nav-item { transition: all .2s; }
        .nav-item:hover { background: rgba(255,255,255,.08); }
        .nav-item.active { background: linear-gradient(90deg,#dc2626,#ef4444); border-right: 3px solid #fff; }

        .btn-primary { background:#dc2626; color:#fff; padding:.5rem 1.25rem; border-radius:.75rem; font-weight:600; transition:.15s; cursor:pointer; border:none; }
        .btn-primary:hover { background:#b91c1c; }
        .btn-secondary { background:#f1f5f9; color:#374151; padding:.5rem 1.25rem; border-radius:.75rem; font-weight:600; transition:.15s; cursor:pointer; border:none; }
        .btn-secondary:hover { background:#e2e8f0; }
        .btn-icon-edit { background:#eff6ff; color:#2563eb; width:32px; height:32px; border-radius:.5rem; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; border:none; cursor:pointer; }
        .btn-icon-edit:hover { background:#dbeafe; }
        .btn-icon-delete { background:#fef2f2; color:#dc2626; width:32px; height:32px; border-radius:.5rem; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; }
        .btn-icon-delete:hover { background:#fee2e2; }

        .form-label { font-size:.8rem; font-weight:600; color:#374151; display:block; margin-bottom:.35rem; }
        .form-input { width:100%; border:1px solid #d1d5db; border-radius:.5rem; padding:.55rem .75rem; font-size:.9rem; outline:none; transition:.15s; }
        .form-input:focus { border-color:#dc2626; box-shadow:0 0 0 2px rgba(220,38,38,.12); }

        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:100; display:flex; align-items:center; justify-content:center; padding:1rem; }
        .modal-overlay.hidden { display:none; }
        .modal-box { background:#fff; border-radius:1.25rem; width:100%; max-width:520px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        .modal-box-lg { max-width:700px; }
        .modal-header { display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid #f1f5f9; }
        .modal-body { padding:1.5rem; }
        .modal-footer { display:flex; gap:.75rem; justify-content:flex-end; padding-top:1rem; border-top:1px solid #f1f5f9; margin-top:1rem; }
        .modal-close { color:#6b7280; background:none; border:none; font-size:1.1rem; cursor:pointer; padding:.25rem .5rem; border-radius:.4rem; }
        .modal-close:hover { background:#f1f5f9; }

        .badge-blue   { display:inline-block; background:#dbeafe; color:#1d4ed8; font-size:.7rem; font-weight:600; padding:.2rem .55rem; border-radius:.4rem; }
        .badge-gray   { display:inline-block; background:#f1f5f9; color:#374151; font-size:.75rem; font-weight:600; padding:.2rem .55rem; border-radius:.4rem; }
        .badge-green  { display:inline-block; background:#dcfce7; color:#15803d; font-size:.75rem; font-weight:700; padding:.2rem .55rem; border-radius:.4rem; }
        .badge-purple { display:inline-block; background:#ede9fe; color:#6d28d9; font-size:.7rem; font-weight:600; padding:.2rem .55rem; border-radius:.4rem; }

        #toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; display:flex; flex-direction:column; gap:.5rem; pointer-events:none; }
        .toast { padding:.75rem 1.25rem; border-radius:.75rem; font-size:.875rem; font-weight:600; color:#fff; opacity:0; transform:translateY(1rem); transition:all .3s ease; pointer-events:none; max-width:320px; box-shadow:0 4px 20px rgba(0,0,0,.18); }
        .toast.show { opacity:1; transform:translateY(0); }
        .toast.success { background:#16a34a; }
        .toast.error   { background:#dc2626; }
        .toast.info    { background:#2563eb; }
        .section-fading { opacity:.4; pointer-events:none; transition:opacity .15s; }
    </style>
</head>
<body>

<div class="md:hidden fixed top-4 left-4 z-50">
    <button id="mobile-menu-btn" class="bg-slate-800 text-white p-3 rounded-xl shadow-lg"><i class="fas fa-bars"></i></button>
</div>

<div id="sidebar" class="sidebar fixed left-0 top-0 h-full w-64 z-40 transition-transform duration-300 -translate-x-full md:translate-x-0">
    <div class="p-6 border-b border-slate-600">
        <div class="flex items-center space-x-3">
            <img src="../assets/images/nafesa_green.png" alt="Nafesa" class="w-10 h-10" onerror="this.style.display='none'">
            <div><h2 class="text-white font-bold text-lg">Admin Portal</h2><p class="text-slate-300 text-xs">Content Management</p></div>
        </div>
    </div>
    <nav class="p-4">
        <ul class="space-y-1">
            <li><a href="#" data-section="products"              class="nav-item flex items-center space-x-3 text-white p-3 rounded-lg <?= $section=='products'?'active':'' ?>"><i class="fas fa-box w-5"></i><span>Products</span></a></li>
            <li><a href="#" data-section="collections"           class="nav-item flex items-center space-x-3 text-slate-300 hover:text-white p-3 rounded-lg <?= $section=='collections'?'active':'' ?>"><i class="fas fa-layer-group w-5"></i><span>Collections</span></a></li>
            <li><a href="#" data-section="designs"               class="nav-item flex items-center space-x-3 text-slate-300 hover:text-white p-3 rounded-lg <?= $section=='designs'?'active':'' ?>"><i class="fas fa-palette w-5"></i><span>Designs</span></a></li>
            <li><a href="#" data-section="banners"               class="nav-item flex items-center space-x-3 text-slate-300 hover:text-white p-3 rounded-lg <?= $section=='banners'?'active':'' ?>"><i class="fas fa-images w-5"></i><span>Banners</span></a></li>
            <li><a href="#" data-section="collaborations"        class="nav-item flex items-center space-x-3 text-slate-300 hover:text-white p-3 rounded-lg <?= $section=='collaborations'?'active':'' ?>"><i class="fas fa-handshake w-5"></i><span>Collaborations</span></a></li>
            <li><a href="#" data-section="site-settings"         class="nav-item flex items-center space-x-3 text-slate-300 hover:text-white p-3 rounded-lg <?= $section=='site-settings'?'active':'' ?>"><i class="fas fa-cog w-5"></i><span>Site Settings</span></a></li>
        </ul>
    </nav>
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-slate-600">
        <div class="flex items-center space-x-3 text-white mb-3">
            <div class="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center"><i class="fas fa-user text-sm"></i></div>
            <div><p class="text-sm font-medium">Faizzuddin</p><p class="text-xs text-slate-300">Admin</p></div>
        </div>
        <a href="logout.php" class="block w-full bg-red-600 hover:bg-red-700 text-white text-center py-2 rounded-lg text-sm">
            <i class="fas fa-sign-out-alt mr-1"></i>Logout
        </a>
    </div>
</div>

<div class="md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm border-b px-6 py-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900" id="page-title">
                <?php $titles = ['products'=>'Products','collections'=>'Collections','designs'=>'Designs','banners'=>'Banners','collaborations'=>'Collaborations','site-settings'=>'Site Settings']; echo $titles[$section] ?? 'Dashboard'; ?>
            </h1>
            <p class="text-gray-500 text-sm">Nafesa CMS</p>
        </div>
        <div class="w-9 h-9 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center">
            <i class="fas fa-user text-white text-sm"></i>
        </div>
    </header>
    <div id="main-content" class="flex-1 p-6">
        <?php renderSection($section, $conn, $collections, $designs, $products, $banners, $coll_excl, $collabs, $site_status, $exclusive_banner, $excl_collections); ?>
    </div>
</div>

<div id="toast-container"></div>

<script>
// ================================================================
// CORE UTILITIES
// ================================================================
var _currentSection = '<?= htmlspecialchars($section) ?>';
var _pageTitles = {
    products:'Products', collections:'Collections', designs:'Designs',
    banners:'Banners', collaborations:'Collaborations', 'site-settings':'Site Settings'
};

function toast(msg, type) {
    var c = document.getElementById('toast-container');
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'success');
    t.textContent = msg;
    c.appendChild(t);
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ t.classList.add('show'); }); });
    setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 350); }, 3200);
}

// ================================================================
// MODAL SYSTEM — event delegation, works after AJAX reload
// ================================================================
document.addEventListener('click', function(e) {
    // Open modal via data-open-modal
    var opener = e.target.closest('[data-open-modal]');
    if (opener) {
        e.preventDefault();
        e.stopPropagation();
        var id = opener.getAttribute('data-open-modal');
        var el = document.getElementById(id);
        if (el) el.classList.remove('hidden');
        return;
    }

    // Close modal via data-close-modal
    var closer = e.target.closest('[data-close-modal]');
    if (closer) {
        e.preventDefault();
        var id = closer.getAttribute('data-close-modal');
        var el = document.getElementById(id);
        if (el) el.classList.add('hidden');
        return;
    }

    // Close modal by clicking backdrop
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.add('hidden');
        return;
    }

    // Edit Collection button
    var ec = e.target.closest('[data-edit-collection]');
    if (ec) {
        e.preventDefault();
        e.stopPropagation();
        openEditCollectionModal(parseInt(ec.getAttribute('data-edit-collection')));
        return;
    }

    // Edit Design button
    var ed = e.target.closest('[data-edit-design]');
    if (ed) {
        e.preventDefault();
        e.stopPropagation();
        openEditDesignModal(parseInt(ed.getAttribute('data-edit-design')));
        return;
    }

    // Edit Product button
    var ep = e.target.closest('[data-edit-product]');
    if (ep) {
        e.preventDefault();
        e.stopPropagation();
        openEditProductModal(parseInt(ep.getAttribute('data-edit-product')));
        return;
    }

    // Expandable row toggle (don't fire if clicking action buttons)
    var row = e.target.closest('.expandable-row');
    if (row && !e.target.closest('a,button')) {
        var detailId = row.getAttribute('data-detail');
        if (detailId) {
            var det = document.getElementById(detailId);
            if (det) det.classList.toggle('hidden');
        }
        return;
    }

    // Delete link
    var dl = e.target.closest('.delete-link');
    if (dl) {
        e.preventDefault();
        e.stopPropagation();
        if (!confirm('Padam item ini? Tindakan ini tidak boleh dibuat alik.')) return;
        var sec = dl.getAttribute('data-section') || _currentSection;
        var url = dl.getAttribute('href') + '&ajax=1';
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.ok) { toast('Dipadam.', 'info'); loadSection(sec, true); }
                else toast('Gagal memadam.', 'error');
            })
            .catch(function(){ toast('Sambungan gagal.', 'error'); });
        return;
    }
});

// ================================================================
// FORM SUBMIT — event delegation
// ================================================================
document.addEventListener('submit', function(e) {
    var form = e.target.closest('form.ajax-form');
    if (!form) return;
    e.preventDefault();

    var btn = form.querySelector('button[type=submit]');
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Simpan...'; btn.disabled = true; }

    fetch('dashboard.php?ajax_action=1', { method: 'POST', body: new FormData(form) })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (btn) { btn.innerHTML = orig; btn.disabled = false; }
            if (data.ok) {
                // Close parent modal if any
                var overlay = form.closest('.modal-overlay');
                if (overlay) overlay.classList.add('hidden');
                // Reset form fields
                form.reset();
                // Clear image previews
                form.querySelectorAll('[id$="-preview"]').forEach(function(el){ el.innerHTML = ''; });
                toast(form.getAttribute('data-msg') || 'Berjaya disimpan!', 'success');
                loadSection(form.getAttribute('data-section') || _currentSection, true);
            } else {
                toast(data.error || 'Ralat semasa menyimpan.', 'error');
            }
        })
        .catch(function(){
            if (btn) { btn.innerHTML = orig; btn.disabled = false; }
            toast('Sambungan gagal. Cuba semula.', 'error');
        });
});

// ================================================================
// IMAGE PREVIEW — event delegation
// ================================================================
document.addEventListener('change', function(e) {
    // Single image preview
    var single = e.target.closest('.img-preview-input');
    if (single) {
        var previewId = single.getAttribute('data-preview');
        var preview = document.getElementById(previewId);
        if (!preview || !single.files || !single.files[0]) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            preview.innerHTML = '<img src="' + ev.target.result + '" class="w-full h-28 object-cover rounded-lg mt-1">';
        };
        reader.readAsDataURL(single.files[0]);
        return;
    }

    // Multi image picker
    var multi = e.target.closest('.multi-img-input');
    if (multi) {
        var previewId = multi.getAttribute('data-preview');
        var idxId     = multi.getAttribute('data-idx');
        var preview   = document.getElementById(previewId);
        var hiddenIdx = document.getElementById(idxId);
        if (!preview || !hiddenIdx) return;
        preview.innerHTML = '';
        hiddenIdx.value = 0;
        Array.from(multi.files).forEach(function(file, i) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var wrap  = document.createElement('div');
                wrap.className = 'relative cursor-pointer';
                var img   = document.createElement('img');
                img.src   = ev.target.result;
                img.className = 'w-full h-20 object-cover rounded' + (i === 0 ? ' ring-2 ring-red-500' : '');
                var badge = document.createElement('span');
                badge.className = 'absolute top-1 left-1 text-xs px-1.5 py-0.5 rounded font-bold ' + (i === 0 ? 'bg-red-500 text-white' : 'bg-black/40 text-white');
                badge.textContent = i === 0 ? '✓ Default' : (i + 1);
                wrap.appendChild(img);
                wrap.appendChild(badge);
                wrap.addEventListener('click', function() {
                    hiddenIdx.value = i;
                    preview.querySelectorAll('img').forEach(function(el, j) {
                        el.className = 'w-full h-20 object-cover rounded' + (j === i ? ' ring-2 ring-red-500' : '');
                    });
                    preview.querySelectorAll('span').forEach(function(el, j) {
                        el.className = 'absolute top-1 left-1 text-xs px-1.5 py-0.5 rounded font-bold ' + (j === i ? 'bg-red-500 text-white' : 'bg-black/40 text-white');
                        el.textContent = j === i ? '✓ Default' : (j + 1);
                    });
                });
                preview.appendChild(wrap);
            };
            reader.readAsDataURL(file);
        });
        return;
    }
});

// ================================================================
// EDIT MODALS — fetch data then populate
// ================================================================
function openEditCollectionModal(id) {
    fetch('dashboard.php?ajax=1&get_collection=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d) return;
            document.getElementById('edit-collection-id').value    = d.id;
            document.getElementById('edit-collection-title').value = d.title;
            document.getElementById('edit-collection-desc').value  = d.description || '';
            document.getElementById('edit-collection-sort').value  = d.sort_order || 0;
            document.getElementById('edit-collection-exclusive').checked = (d.is_exclusive == 1);
            var wp = document.getElementById('edit-collection-web-preview');
            var mp = document.getElementById('edit-collection-mob-preview');
            wp.innerHTML = d.banner_image
                ? '<img src="../'+d.banner_image+'" class="h-20 object-cover rounded w-full mt-1"><p class="text-xs text-gray-400">Banner semasa</p>'
                : '<p class="text-xs text-gray-400">Tiada banner</p>';
            mp.innerHTML = d.mobile_banner_image
                ? '<img src="../'+d.mobile_banner_image+'" class="h-20 object-cover rounded w-full mt-1"><p class="text-xs text-gray-400">Banner semasa</p>'
                : '<p class="text-xs text-gray-400">Tiada banner</p>';
            document.getElementById('modal-edit-collection').classList.remove('hidden');
        })
        .catch(function(){ toast('Gagal memuatkan data.', 'error'); });
}

function openEditDesignModal(id) {
    fetch('dashboard.php?ajax=1&get_design=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d) return;
            document.getElementById('edit-design-id').value    = d.id;
            document.getElementById('edit-design-title').value = d.title;
            document.getElementById('edit-design-desc').value  = d.description || '';
            document.getElementById('edit-design-sort').value  = d.sort_order || 0;
            var sel = document.getElementById('edit-design-collection');
            for (var i = 0; i < sel.options.length; i++)
                sel.options[i].selected = (sel.options[i].value == d.collection_id);
            var cur = document.getElementById('edit-design-img-current');
            cur.innerHTML = d.design_image
                ? '<img src="../'+d.design_image+'" class="h-28 object-cover rounded-lg w-full"><p class="text-xs text-gray-400 mt-1">Gambar semasa</p>'
                : '<p class="text-xs text-gray-400">Tiada gambar</p>';
            document.getElementById('edit-design-img-preview').innerHTML = '';
            document.getElementById('modal-edit-design').classList.remove('hidden');
        })
        .catch(function(){ toast('Gagal memuatkan data.', 'error'); });
}

function openEditProductModal(id) {
    fetch('dashboard.php?ajax=1&get_product=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d) return;
            document.getElementById('edit-product-id').value          = d.id;
            document.getElementById('edit-product-name').value        = d.name;
            document.getElementById('edit-product-desc').value        = d.description || '';
            document.getElementById('edit-product-tag').value         = d.tag || '';
            document.getElementById('edit-product-sort').value        = d.sort_order || 0;
            document.getElementById('edit-product-new-arrival').checked = (d.new_arrival == 1);

            var setSelect = function(selId, val) {
                var sel = document.getElementById(selId);
                for (var i = 0; i < sel.options.length; i++)
                    sel.options[i].selected = (sel.options[i].value == val);
            };
            setSelect('edit-product-category',   d.category);
            setSelect('edit-product-collection', d.collection_id);
            setSelect('edit-product-design',     d.design_id);

            // Existing images picker
            var curDiv   = document.getElementById('edit-product-current-images');
            var defIdx   = parseInt(d.default_image_index || 0);
            document.getElementById('edit-default-idx').value = defIdx;
            document.getElementById('edit-product-new-preview').innerHTML = '';
            curDiv.innerHTML = '';

            var imgs = [];
            try { imgs = JSON.parse(d.images || '[]'); } catch(e) {}
            if (!imgs.length && d.image) imgs = [d.image];

            imgs.forEach(function(im, i) {
                var wrap  = document.createElement('div');
                wrap.className = 'relative cursor-pointer';
                var img   = document.createElement('img');
                img.src   = '../' + im;
                img.className = 'w-full h-20 object-cover rounded' + (i === defIdx ? ' ring-2 ring-red-500' : '');
                var badge = document.createElement('span');
                badge.className = 'absolute top-1 left-1 text-xs px-1.5 py-0.5 rounded font-bold ' + (i === defIdx ? 'bg-red-500 text-white' : 'bg-black/40 text-white');
                badge.textContent = i === defIdx ? '✓ Default' : (i + 1);
                wrap.appendChild(img);
                wrap.appendChild(badge);
                (function(idx) {
                    wrap.addEventListener('click', function() {
                        document.getElementById('edit-default-idx').value = idx;
                        curDiv.querySelectorAll('img').forEach(function(el, j) {
                            el.className = 'w-full h-20 object-cover rounded' + (j === idx ? ' ring-2 ring-red-500' : '');
                        });
                        curDiv.querySelectorAll('span').forEach(function(el, j) {
                            el.className = 'absolute top-1 left-1 text-xs px-1.5 py-0.5 rounded font-bold ' + (j === idx ? 'bg-red-500 text-white' : 'bg-black/40 text-white');
                            el.textContent = j === idx ? '✓ Default' : (j + 1);
                        });
                    });
                })(i);
                curDiv.appendChild(wrap);
            });

            document.getElementById('modal-edit-product').classList.remove('hidden');
        })
        .catch(function(){ toast('Gagal memuatkan data.', 'error'); });
}

// ================================================================
// SECTION LOADER
// ================================================================
function loadSection(sec, silent) {
    _currentSection = sec;
    var mc = document.getElementById('main-content');
    if (!silent) mc.classList.add('section-fading');

    fetch('dashboard.php?section=' + encodeURIComponent(sec) + '&ajax=1')
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nc  = doc.getElementById('main-content');
            if (nc) mc.innerHTML = nc.innerHTML;
            mc.classList.remove('section-fading');
            document.getElementById('page-title').textContent = _pageTitles[sec] || 'Dashboard';
            updateActiveNav(sec);
            initProductFilter();
        })
        .catch(function(){
            mc.classList.remove('section-fading');
            toast('Gagal memuatkan bahagian.', 'error');
        });
}

function updateActiveNav(sec) {
    document.querySelectorAll('a[data-section]').forEach(function(a) {
        var on = a.getAttribute('data-section') === sec;
        a.classList.toggle('active', on);
        if (on) { a.classList.add('text-white'); a.classList.remove('text-slate-300'); }
        else    { a.classList.remove('text-white'); a.classList.add('text-slate-300'); }
    });
}

// Sidebar nav
document.querySelectorAll('a[data-section]').forEach(function(a) {
    a.addEventListener('click', function(e) {
        e.preventDefault();
        var sec = this.getAttribute('data-section');
        history.pushState({ section: sec }, '', '?section=' + sec);
        loadSection(sec);
    });
});
window.addEventListener('popstate', function(e) {
    if (e.state && e.state.section) loadSection(e.state.section);
});

// Mobile sidebar
document.getElementById('mobile-menu-btn').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('-translate-x-full');
});

// ================================================================
// PRODUCT FILTER
// ================================================================
function initProductFilter() {
    var ps = document.getElementById('product-search');
    if (!ps) return;

    function applyFilter() {
        var s  = (document.getElementById('product-search').value || '').toLowerCase();
        var fc = document.getElementById('filter-category');
        var fl = document.getElementById('filter-collection');
        var fn = document.getElementById('filter-new-arrival');
        var c  = fc ? fc.value : '';
        var co = fl ? fl.value : '';
        var nw = fn ? fn.checked : false;
        document.querySelectorAll('#product-tbody tr.expandable-row').forEach(function(row) {
            var show = (!s  || (row.dataset.name     || '').includes(s))
                    && (!c  || row.dataset.category  === c)
                    && (!co || row.dataset.collection === co)
                    && (!nw || row.dataset.new        === '1');
            row.style.display = show ? '' : 'none';
            var det = document.getElementById(row.getAttribute('data-detail'));
            if (det) det.style.display = show ? '' : 'none';
        });
    }

    [ps,
     document.getElementById('filter-category'),
     document.getElementById('filter-collection'),
     document.getElementById('filter-new-arrival')
    ].forEach(function(el) {
        if (!el) return;
        el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', applyFilter);
    });
}

// Init on first load
initProductFilter();
</script>
</body>
</html>