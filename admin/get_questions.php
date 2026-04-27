<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($subject_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, question_text FROM questions WHERE subject_id = ? ORDER BY id DESC LIMIT 500");
        $stmt->execute([$subject_id]);
        $questions = $stmt->fetchAll();
        echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => '数据库查询失败'], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}

