<?php
// ═══════════════════════════════════════════════════════════
//  The Hermes News — Database Config
// ═══════════════════════════════════════════════════════════
ini_set('display_errors', '0');
error_reporting(0);

define('DB_HOST', 'localhost');
define('DB_NAME', 'u458421599_thehermesnews');
define('DB_USER', 'u458421599_thehermesnews');
define('DB_PASS', 'Zoroxproxy01');

// ── Site URL (no trailing slash) ──────────────────────────
define('SITE_URL', 'https://thehermesnews.com');

// ── Fallback admin (works even when DB is unavailable) ────
// Change ADMIN_PASS_HASH by running: php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
define('ADMIN_USER',      'zoroxproxy');
define('ADMIN_PASS_HASH', '$2y$10$laxaXmAag3DN7j3TOl649uGpncAqicuxo19Z9EC5YUMfYJZavwQ9i'); // khatarnakbhai

// ── Upload limits ─────────────────────────────────────────
define('MAX_IMAGE_MB', 10);
define('MAX_VIDEO_MB', 100);

// ── Allowed file types ────────────────────────────────────
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/webp','image/gif']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4','video/webm','video/quicktime']);

// ─────────────────────────────────────────────────────────
function getDB(): ?PDO {
    static $pdo = null;
    static $failed = false;
    if ($failed) return null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            $failed = true;
            return null;
        }
    }
    return $pdo;
}

function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
}

function setCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: '.SITE_URL);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
}
