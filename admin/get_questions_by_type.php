<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($subject_id > 0) {
    try {
        // 按题型分组获取题目
        $stmt = $pdo->prepare("SELECT question_type, COUNT(*) as count, GROUP_CONCAT(id ORDER BY id SEPARATOR ',') as question_ids 
                               FROM questions 
                               WHERE subject_id = ? 
                               GROUP BY question_type 
                               ORDER BY question_type");
        $stmt->execute([$subject_id]);
        $type_groups = $stmt->fetchAll();
        
        // 格式化数据
        $result = [];
        foreach ($type_groups as $group) {
            $question_ids = !empty($group['question_ids']) ? explode(',', $group['question_ids']) : [];
            $result[] = [
                'type' => $group['question_type'],
                'count' => intval($group['count']),
                'question_ids' => array_map('intval', $question_ids)
            ];
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => '数据库查询失败'], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
}

