<?php
require 'inc/db.inc.php';

$stmt = $pdo->query("SELECT er.id, er.student_id FROM exam_records er WHERE er.status = 'completed'");
$repair_count = 0;
$fixed_total = 0;

while ($row = $stmt->fetch()) {
    $exam_record_id = $row['id'];
    $student_id = $row['student_id'];
    
    // 获取所有的考试题目
    $stmtQ = $pdo->prepare("SELECT question_id FROM exam_questions WHERE exam_record_id = ?");
    $stmtQ->execute([$exam_record_id]);
    $all_questions = $stmtQ->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($all_questions)) continue;
    
    // 获取已有的回答
    $stmtA = $pdo->prepare("SELECT question_id FROM answer_records WHERE exam_record_id = ?");
    $stmtA->execute([$exam_record_id]);
    $answered_questions = $stmtA->fetchAll(PDO::FETCH_COLUMN);
    
    $missing_questions = array_diff($all_questions, $answered_questions);
    
    if (!empty($missing_questions)) {
        $repair_count++;
        foreach ($missing_questions as $qid) {
            $stmtInsert = $pdo->prepare("INSERT INTO answer_records (exam_record_id, question_id, student_answer, is_correct, score) VALUES (?, ?, '', 0, 0)");
            $stmtInsert->execute([$exam_record_id, $qid]);
            $fixed_total++;
        }
    }
}

echo "Successfully repaired $repair_count records, added $fixed_total missing answers.\n";
