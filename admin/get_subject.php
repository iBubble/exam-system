<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少科目ID']);
    exit;
}

$id = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$id]);
$subject = $stmt->fetch();

if ($subject) {
    echo json_encode(['success' => true, 'subject' => $subject]);
} else {
    echo json_encode(['success' => false, 'message' => '科目不存在']);
}
?>

