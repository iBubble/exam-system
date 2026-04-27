<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少试卷ID']);
    exit;
}

$id = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT * FROM papers WHERE id = ?");
$stmt->execute([$id]);
$paper = $stmt->fetch();

if (!$paper) {
    echo json_encode(['success' => false, 'message' => '试卷不存在']);
    exit;
}

// 解析题型配置
$question_config = [];
$type_order = [];
if (!empty($paper['question_config'])) {
    $decoded = json_decode($paper['question_config'], true) ?: [];
    $type_order = array_keys($decoded);
    foreach ($decoded as $type => $value) {
        if (is_array($value)) {
            $question_config[$type] = $value;
        } else {
            $question_config[$type] = ['count' => intval($value), 'score' => 0];
        }
    }
}

// 获取试卷关联的班级
$stmt = $pdo->prepare("SELECT class FROM paper_classes WHERE paper_id = ?");
$stmt->execute([$id]);
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$paper['classes'] = $classes;
$paper['question_config'] = $question_config;
$paper['type_order'] = $type_order;

echo json_encode(['success' => true, 'paper' => $paper]);
?>

