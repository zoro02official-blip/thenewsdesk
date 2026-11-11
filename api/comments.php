<?php
// ═══════════════════════════════════════════════════════════
//  The News Desk — Comments API
//  GET  /api/comments.php?article_id=X  → all comments for article
//  POST /api/comments.php               → {article_id, author, text}
// ═══════════════════════════════════════════════════════════

session_start();
require_once '../config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

if ($method === 'GET') {
    $articleId = (int)($_GET['article_id'] ?? 0);
    if (!$articleId) { http_response_code(400); echo json_encode(['error' => 'article_id required']); exit; }

    $stmt = $db->prepare('SELECT id, article_id, author, text, date FROM comments WHERE article_id = ? ORDER BY date ASC');
    $stmt->execute([$articleId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $articleId = (int)($body['article_id'] ?? 0);
    $author    = trim($body['author'] ?? '') ?: 'Anonymous';
    $text      = trim($body['text']   ?? '');

    if (!$articleId || !$text) {
        http_response_code(400);
        echo json_encode(['error' => 'article_id and text are required']);
        exit;
    }
    if (mb_strlen($text) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Comment too long (max 2000 chars)']);
        exit;
    }

    // Check article exists
    $check = $db->prepare('SELECT id FROM articles WHERE id = ? AND published = 1');
    $check->execute([$articleId]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Article not found']); exit; }

    $stmt = $db->prepare('INSERT INTO comments (article_id, author, text) VALUES (?, ?, ?)');
    $stmt->execute([$articleId, $author, $text]);

    $newId   = (int)$db->lastInsertId();
    $fetchSt = $db->prepare('SELECT id, article_id, author, text, date FROM comments WHERE id = ?');
    $fetchSt->execute([$newId]);
    echo json_encode(['success' => true, 'comment' => $fetchSt->fetch()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
