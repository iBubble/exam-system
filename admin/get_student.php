<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少学生ID']);
    exit;
}

$id = intval($_GET['id']);
// 优化：只选择需要的字段
$stmt = $pdo->prepare("SELECT id, student_no, name, class, created_at FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();

if ($student) {
    echo json_encode(['success' => true, 'student' => $student]);
} else {
    echo json_encode(['success' => false, 'message' => '学生不存在']);
}
?>

