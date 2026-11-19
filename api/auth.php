<?php
// ═══════════════════════════════════════════════════════════
//  The Hermes News — Auth API
//  POST  {action:'login',  username, password}  → {success, user}
//  POST  {action:'logout'}                      → {success}
//  GET                                           → {loggedIn, user}
// ═══════════════════════════════════════════════════════════

session_start();
require_once '../config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
// Accept both JSON body and form-encoded POST (Hostinger blocks JSON POST via mod_security)
$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?? [];
if (empty($body) && !empty($_POST)) {
    $body = $_POST; // fallback: form-encoded
}
$action = $body['action'] ?? $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password required']);
        exit;
    }

    $authenticated = false;
    $authedUser    = '';

    // 1. Try database first
    $db = getDB();
    if ($db !== null) {
        try {
            $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if ($row && password_verify($password, $row['password'])) {
                $authenticated = true;
                $authedUser    = $row['username'];
                $_SESSION['user_id'] = $row['id'];
            }
        } catch (\Exception $e) { /* fall through to config fallback */ }
    }

    // 2. Fallback: hardcoded admin from config (works even if DB is down)
    if (!$authenticated && defined('ADMIN_USER') && defined('ADMIN_PASS_HASH')) {
        if ($username === ADMIN_USER && password_verify($password, ADMIN_PASS_HASH)) {
            $authenticated = true;
            $authedUser    = ADMIN_USER;
        }
    }

    if ($authenticated) {
        $_SESSION['user'] = $authedUser;
        echo json_encode(['success' => true, 'user' => $authedUser]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid username or password']);
    }
    exit;
}

if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// GET — return current session state
echo json_encode([
    'loggedIn' => !empty($_SESSION['user']),
    'user'     => $_SESSION['user'] ?? null,
]);
