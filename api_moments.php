<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

ob_start();
header('Content-Type: application/json; charset=utf-8');

function jsonResponse(int $statusCode, array $payload): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    jsonResponse(500, ['error' => 'Server error: ' . $e->getMessage()]);
});

$host = 'localhost';
$db   = 'ruang_kenangan';
$user = 'root';
$pass = ''; // sesuaikan password MySQL kamu

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    jsonResponse(500, ['error' => 'Koneksi MySQL gagal: ' . $e->getMessage()]);
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    throw new RuntimeException('Gagal membuat folder uploads.');
}

function detectImageExtension(string $tmpName, string $originalName): ?string {
    $allowedMime = [
        'image/jpeg'  => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'   => 'png',
        'image/webp'  => 'webp',
        'image/gif'   => 'gif',
    ];

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        if ($mime && isset($allowedMime[$mime])) {
            return $allowedMime[$mime];
        }
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mapExt = [
        'jpg'  => 'jpg',
        'jpeg' => 'jpg',
        'png'  => 'png',
        'webp' => 'webp',
        'gif'  => 'gif',
    ];
    return $mapExt[$ext] ?? null;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $stmt = $pdo->query('SELECT id, uploaded_by, title, date, description, img_url, created_at FROM moments ORDER BY id DESC');
    $moments = $stmt->fetchAll();
    jsonResponse(200, $moments);
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadedBy = trim($_POST['uploaded_by'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $date       = trim($_POST['date'] ?? '');
    $desc       = trim($_POST['description'] ?? '');

    if ($title === '' || $date === '' || $desc === '') {
        jsonResponse(400, ['error' => 'Judul, tanggal, dan cerita wajib diisi.']);
    }

    if (!isset($_FILES['photo'])) {
        jsonResponse(400, ['error' => 'Foto tidak terkirim. Jika ukuran foto besar, cek post_max_size di php.ini.']);
    }

    $errCode = $_FILES['photo']['error'];
    if ($errCode !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran foto melebihi batas upload_max_filesize di php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran foto melebihi batas form.',
            UPLOAD_ERR_PARTIAL    => 'Foto hanya terunggah sebagian.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file foto yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary PHP tidak ditemukan.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP.',
        ];
        jsonResponse(400, ['error' => $uploadErrors[$errCode] ?? ('Error upload kode: ' . $errCode)]);
    }

    $tmpName      = $_FILES['photo']['tmp_name'];
    $originalName = $_FILES['photo']['name'] ?? 'foto.jpg';
    $ext          = detectImageExtension($tmpName, $originalName);

    if ($ext === null) {
        jsonResponse(400, ['error' => 'Format foto harus JPG, PNG, WEBP, atau GIF.']);
    }

    $fileName = time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destPath = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        throw new RuntimeException('Gagal memindahkan file ke folder uploads.');
    }

    $imgUrl = 'uploads/' . $fileName;
    $owner  = $uploadedBy !== '' ? $uploadedBy : 'Tamu';

    $stmt = $pdo->prepare('
        INSERT INTO moments (uploaded_by, title, date, description, img_url)
        VALUES (:uploaded_by, :title, :date, :description, :img_url)
    ');
    $stmt->execute([
        ':uploaded_by' => $owner,
        ':title'       => $title,
        ':date'        => $date,
        ':description' => $desc,
        ':img_url'     => $imgUrl,
    ]);

    $id = $pdo->lastInsertId();
    $stmtOne = $pdo->prepare('SELECT id, uploaded_by, title, date, description, img_url, created_at FROM moments WHERE id = :id');
    $stmtOne->execute([':id' => $id]);
    $newItem = $stmtOne->fetch();

    jsonResponse(201, ['success' => true, 'item' => $newItem]);
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = trim($_POST['id'] ?? '');
    if ($id === '' || !ctype_digit($id)) {
        jsonResponse(400, ['error' => 'ID tidak valid.']);
    }

    $stmtGet = $pdo->prepare('SELECT img_url FROM moments WHERE id = :id');
    $stmtGet->execute([':id' => $id]);
    $row = $stmtGet->fetch();

    if ($row && !empty($row['img_url'])) {
        $realUpload = realpath($uploadDir);
        $fullPath   = __DIR__ . '/' . ltrim($row['img_url'], '/');
        $realFile   = realpath($fullPath);
        if ($realUpload && $realFile && str_starts_with($realFile, $realUpload . DIRECTORY_SEPARATOR) && is_file($realFile)) {
            @unlink($realFile);
        }
    }

    $stmtDel = $pdo->prepare('DELETE FROM moments WHERE id = :id');
    $stmtDel->execute([':id' => $id]);

    jsonResponse(200, ['success' => true]);
}

jsonResponse(400, ['error' => 'Aksi tidak dikenal.']);