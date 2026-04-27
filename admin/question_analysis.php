<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// 获取学生信息
$student_info = null;
if ($student_id > 0) {
    // 优化：只选择需要的字段
    $stmt = $pdo->prepare("SELECT id, student_no, name, class FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch();
}

// 获取该学生的答题分析数据
$analysis_data = [];
if ($student_info) {
    // 按题目类型统计
    $sql = "SELECT q.question_type,
            COUNT(DISTINCT ar.question_id) as total_questions,
            SUM(CASE WHEN ar.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
            SUM(ar.score) as total_score,
            SUM(eq.score) as total_possible_score
            FROM answer_records ar
            JOIN exam_questions eq ON ar.exam_record_id = eq.exam_record_id AND ar.question_id = eq.question_id
            JOIN questions q ON ar.question_id = q.id
            JOIN exam_records er ON ar.exam_record_id = er.id
            WHERE er.student_id = ? AND er.status = 'completed'
            GROUP BY q.question_type
            ORDER BY q.question_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $type_stats = $stmt->fetchAll();
    
    // 按知识点统计
    $sql = "SELECT q.knowledge_point,
            COUNT(DISTINCT ar.question_id) as total_questions,
            SUM(CASE WHEN ar.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
            SUM(ar.score) as total_score,
            SUM(eq.score) as total_possible_score
            FROM answer_records ar
            JOIN exam_questions eq ON ar.exam_record_id = eq.exam_record_id AND ar.question_id = eq.question_id
            JOIN questions q ON ar.question_id = q.id
            JOIN exam_records er ON ar.exam_record_id = er.id
            WHERE er.student_id = ? AND er.status = 'completed' AND q.knowledge_point IS NOT NULL AND q.knowledge_point != ''
            GROUP BY q.knowledge_point
            ORDER BY correct_count ASC, total_questions DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $knowledge_stats = $stmt->fetchAll();
    
    // 错题最多的题目
    $sql = "SELECT q.*, COUNT(wq.wrong_times) as wrong_count
            FROM wrong_questions wq
            JOIN questions q ON wq.question_id = q.id
            WHERE wq.student_id = ?
            GROUP BY q.id
            ORDER BY wrong_count DESC, wq.last_wrong_time DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $wrong_questions = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生答题分析 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>学生答题分析</h2>
        
        <?php if (!$student_info): ?>
            <div class="table-container">
                <p style="text-align: center; color: #999;">请先选择学生</p>
                <p style="text-align: center;">
                    <a href="students.php" class="btn btn-primary">返回学生列表</a>
                </p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <h2>学生信息</h2>
                <p><strong>学号：</strong><?php echo escape($student_info['student_no']); ?></p>
                <p><strong>姓名：</strong><?php echo escape($student_info['name'] ?? '-'); ?></p>
                <p style="margin-top: 15px;">
                    <a href="students.php" class="btn btn-warning">返回学生列表</a>
                    <a href="student_records.php?student_id=<?php echo $student_id; ?>" class="btn btn-primary">查看刷题记录</a>
                </p>
            </div>
            
            <?php if (!empty($type_stats)): ?>
            <div class="table-container">
                <h2>按题型分析</h2>
                <table>
                    <thead>
                        <tr>
                            <th>题目类型</th>
                            <th>总题数</th>
                            <th>正确数</th>
                            <th>正确率</th>
                            <th>得分</th>
                            <th>总分</th>
                            <th>得分率</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($type_stats as $stat): ?>
                            <?php 
                            $correct_rate = $stat['total_questions'] > 0 ? ($stat['correct_count'] / $stat['total_questions'] * 100) : 0;
                            $score_rate = $stat['total_possible_score'] > 0 ? ($stat['total_score'] / $stat['total_possible_score'] * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo escape($stat['question_type']); ?></td>
                                <td><?php echo $stat['total_questions']; ?></td>
                                <td><?php echo $stat['correct_count']; ?></td>
                                <td><?php echo number_format($correct_rate, 1); ?>%</td>
                                <td><?php echo number_format($stat['total_score'], 1); ?></td>
                                <td><?php echo number_format($stat['total_possible_score'], 1); ?></td>
                                <td>
                                    <?php 
                                    $color = $score_rate >= 80 ? '#27ae60' : ($score_rate >= 60 ? '#f39c12' : '#e74c3c');
                                    ?>
                                    <span style="color: <?php echo $color; ?>;">
                                        <?php echo number_format($score_rate, 1); ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($knowledge_stats)): ?>
            <div class="table-container">
                <h2>按知识点分析（薄弱知识点TOP20）</h2>
                <table>
                    <thead>
                        <tr>
                            <th>知识点</th>
                            <th>总题数</th>
                            <th>正确数</th>
                            <th>正确率</th>
                            <th>得分</th>
                            <th>总分</th>
                            <th>得分率</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($knowledge_stats as $stat): ?>
                            <?php 
                            $correct_rate = $stat['total_questions'] > 0 ? ($stat['correct_count'] / $stat['total_questions'] * 100) : 0;
                            $score_rate = $stat['total_possible_score'] > 0 ? ($stat['total_score'] / $stat['total_possible_score'] * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo escape($stat['knowledge_point']); ?></td>
                                <td><?php echo $stat['total_questions']; ?></td>
                                <td><?php echo $stat['correct_count']; ?></td>
                                <td><?php echo number_format($correct_rate, 1); ?>%</td>
                                <td><?php echo number_format($stat['total_score'], 1); ?></td>
                                <td><?php echo number_format($stat['total_possible_score'], 1); ?></td>
                                <td>
                                    <?php 
                                    $color = $score_rate >= 80 ? '#27ae60' : ($score_rate >= 60 ? '#f39c12' : '#e74c3c');
                                    ?>
                                    <span style="color: <?php echo $color; ?>;">
                                        <?php echo number_format($score_rate, 1); ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($wrong_questions)): ?>
            <div class="table-container">
                <h2>错题排行（错误次数最多的题目TOP10）</h2>
                <table>
                    <thead>
                        <tr>
                            <th>题目ID</th>
                            <th>类型</th>
                            <th>题干</th>
                            <th>知识点</th>
                            <th>错误次数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wrong_questions as $q): ?>
                            <tr>
                                <td><?php echo $q['id']; ?></td>
                                <td><?php echo escape($q['question_type']); ?></td>
                                <td style="max-width: 400px; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo escape(mb_substr($q['question_text'], 0, 100)); ?>...
                                </td>
                                <td><?php echo escape($q['knowledge_point'] ?? ''); ?></td>
                                <td><?php echo $q['wrong_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

