<?php
// ═══════════════════════════════════════════════════════════
//  The News Desk — Auth API
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

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user']    = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        echo json_encode(['success' => true, 'user' => $user['username']]);
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
