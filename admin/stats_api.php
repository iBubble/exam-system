<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json');

// 获取统计信息（与 index.php 中的逻辑保持一致）
$stats = [];
// 使用子查询一次性获取部分统计信息
$stmt = $pdo->query("SELECT 
    (SELECT COUNT(*) FROM subjects) as subjects,
    (SELECT COUNT(*) FROM questions) as questions,
    (SELECT COUNT(*) FROM papers) as papers,
    (SELECT COUNT(*) FROM students) as students,
    (SELECT COUNT(DISTINCT class) FROM students WHERE class IS NOT NULL AND class != '') as classes,
    (SELECT COUNT(DISTINCT student_id) FROM exam_records WHERE status = 'completed') as completed_students");
$stats_row = $stmt->fetch();
$stats['subjects'] = (int)$stats_row['subjects'];
$stats['questions'] = (int)$stats_row['questions'];
$stats['papers'] = (int)$stats_row['papers'];
$stats['students'] = (int)$stats_row['students'];
$stats['classes'] = (int)$stats_row['classes'];
$stats['completed_students'] = (int)$stats_row['completed_students'];

// 刷题次数（完成的考试记录总数）
$stats['exams'] = (int)$pdo->query("SELECT COUNT(*) FROM exam_records WHERE status = 'completed'")->fetchColumn();

// 平均覆盖率：有完成考试记录的学生数 / 学生总数
$coverage_rate = ($stats['students'] > 0)
    ? round(($stats['completed_students'] / $stats['students']) * 100, 2)
    : 0;

// 获取各科目题目数量分布
$stmt = $pdo->query("SELECT s.name, COUNT(q.id) as count 
                     FROM subjects s 
                     LEFT JOIN questions q ON s.id = q.subject_id 
                     GROUP BY s.id, s.name 
                     ORDER BY count DESC");
$subject_question_data = $stmt->fetchAll();

// 获取题目类型分布
$stmt = $pdo->query("SELECT question_type, COUNT(*) as count 
                     FROM questions 
                     GROUP BY question_type 
                     ORDER BY count DESC");
$question_type_data = $stmt->fetchAll();

// 获取最近30天的考试记录趋势
$stmt = $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as count 
                     FROM exam_records 
                     WHERE status = 'completed' 
                     AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY DATE(created_at) 
                     ORDER BY date ASC");
$exam_trend_data = $stmt->fetchAll();

// 获取刷题次数Top10的学生
$stmt = $pdo->query("SELECT 
                        s.name,
                        s.class,
                        COUNT(er.id) as exam_count
                     FROM students s
                     INNER JOIN exam_records er ON s.id = er.student_id
                     WHERE er.status = 'completed'
                     GROUP BY s.id, s.name, s.class
                     ORDER BY exam_count DESC
                     LIMIT 10");
$top_students_data = $stmt->fetchAll();

// 获取试卷使用情况（前10个最常用的试卷）
$stmt = $pdo->query("SELECT p.title, COUNT(er.id) as exam_count 
                     FROM papers p 
                     LEFT JOIN exam_records er ON p.id = er.paper_id 
                     WHERE er.status = 'completed'
                     GROUP BY p.id, p.title 
                     ORDER BY exam_count DESC 
                     LIMIT 10");
$paper_usage_data = $stmt->fetchAll();

// 获取平均分和通过率
$stmt = $pdo->query("SELECT 
                        AVG(score) as avg_score,
                        COUNT(*) as total,
                        SUM(CASE WHEN score >= 60 THEN 1 ELSE 0 END) as passed
                     FROM exam_records 
                     WHERE status = 'completed' AND score IS NOT NULL");
$performance_stats = $stmt->fetch();
$pass_rate = $performance_stats['total'] > 0 ? round(($performance_stats['passed'] / $performance_stats['total']) * 100, 2) : 0;
$avg_score = $performance_stats['avg_score'] ? round($performance_stats['avg_score'], 2) : 0;

// 返回JSON数据
echo json_encode([
    'success' => true,
    'stats' => [
        'subjects' => $stats['subjects'],
        'questions' => $stats['questions'],
        'papers' => $stats['papers'],
        'students' => $stats['students'],
        'classes' => $stats['classes'],
        'exams' => $stats['exams'],
        'avg_score' => $avg_score,
        'coverage_rate' => $coverage_rate
    ],
    'charts' => [
        'subject_question_data' => $subject_question_data,
        'question_type_data' => $question_type_data,
        'exam_trend_data' => $exam_trend_data,
        'top_students_data' => $top_students_data,
        'paper_usage_data' => $paper_usage_data
    ]
]);
?>

