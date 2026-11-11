<?php
// ═══════════════════════════════════════════════════════════
//  The News Desk — Media Upload API
//  POST multipart/form-data with field "file"
//  Returns: {url: '/uploads/media/filename.ext'}
//
//  Accepts: JPG, PNG, WebP, GIF (images) + MP4, WebM, MOV (video)
// ═══════════════════════════════════════════════════════════

session_start();
require_once '../config.php';
setCorsHeaders();

// Only authenticated users can upload
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Login required to upload files']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file  = $_FILES['file'];
$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

if ($error !== UPLOAD_ERR_OK) {
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (upload_max_filesize in php.ini)',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded — try again',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
    ];
    http_response_code(400);
    echo json_encode(['error' => $msgs[$error] ?? 'Upload error code '.$error]);
    exit;
}

// ── Detect MIME from actual file bytes (not trusting browser) ─
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

$isImage = in_array($mimeType, ALLOWED_IMAGE_TYPES, true);
$isVideo = in_array($mimeType, ALLOWED_VIDEO_TYPES, true);

if (!$isImage && !$isVideo) {
    http_response_code(400);
    echo json_encode(['error' => "File type '{$mimeType}' not allowed. Use JPG, PNG, WebP, GIF for images or MP4/WebM for videos."]);
    exit;
}

// ── Size check ────────────────────────────────────────────
$maxBytes = $isVideo ? (MAX_VIDEO_MB * 1024 * 1024) : (MAX_IMAGE_MB * 1024 * 1024);
if ($file['size'] > $maxBytes) {
    $limit = $isVideo ? MAX_VIDEO_MB : MAX_IMAGE_MB;
    http_response_code(400);
    echo json_encode(['error' => "File too large. Maximum {$limit}MB allowed for " . ($isVideo ? 'videos' : 'images') . '.' ]);
    exit;
}

// ── Build safe filename ───────────────────────────────────
$ext = match ($mimeType) {
    'image/jpeg'       => 'jpg',
    'image/png'        => 'png',
    'image/webp'       => 'webp',
    'image/gif'        => 'gif',
    'video/mp4'        => 'mp4',
    'video/webm'       => 'webm',
    'video/quicktime'  => 'mov',
    default            => 'bin',
};

$prefix   = $isVideo ? 'vid_' : 'img_';
$filename = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$uploadDir = __DIR__ . '/../uploads/media/';
$destPath  = $uploadDir . $filename;

// ── Ensure uploads directory exists ──────────────────────
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create uploads directory. Check folder permissions in Hostinger.']);
        exit;
    }
}

// ── Move the file ─────────────────────────────────────────
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file. Check that uploads/media/ folder has write permissions (755) in Hostinger File Manager.']);
    exit;
}

// ── Return the public URL ─────────────────────────────────
$publicUrl = '/uploads/media/' . $filename;

echo json_encode([
    'success'  => true,
    'url'      => $publicUrl,
    'filename' => $filename,
    'type'     => $isVideo ? 'video' : 'image',
    'size'     => $file['size'],
]);
