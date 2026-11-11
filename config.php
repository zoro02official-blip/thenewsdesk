<?php
// ═══════════════════════════════════════════════════════════
//  The News Desk — Database Config
//  Fill in these values from Hostinger hPanel → Databases
// ═══════════════════════════════════════════════════════════

define('DB_HOST', 'localhost');         // Always localhost on Hostinger shared
define('DB_NAME', 'u743676866_TheNewsDesk');
define('DB_USER', 'u743676866_thenewsdesk');
define('DB_PASS', 'Newsdesk05427k');

// ── Site URL (no trailing slash) ──────────────────────────
define('SITE_URL', 'https://thenewsdesk.space');

// ── Upload limits ─────────────────────────────────────────
define('MAX_IMAGE_MB', 10);   // Max image upload size in MB
define('MAX_VIDEO_MB', 100);  // Max video upload size in MB

// ── Allowed file types ────────────────────────────────────
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/webp','image/gif']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4','video/webm','video/quicktime']);

// ─────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
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
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed. Check config.php.']);
            exit;
        }
    }
    return $pdo;
}

function requireAuth(): void {
    // Guard against double session_start() — articles.php already calls it at the top
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
