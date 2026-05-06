<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
checkStudentLogin();
ensurePaperScheduleColumns($pdo);

// 获取该学生的所有考试记录
$stmt = $pdo->prepare("SELECT er.*, p.title as paper_title, p.start_time, p.end_time, p.is_paused, s.name as subject_name 
                       FROM exam_records er 
                       JOIN papers p ON er.paper_id = p.id 
                       LEFT JOIN subjects s ON p.subject_id = s.id 
                       WHERE er.student_id = ? AND er.status = 'completed'
                       ORDER BY er.end_time DESC");
$stmt->execute([$_SESSION['student_id']]);
$records = $stmt->fetchAll();
foreach ($records as &$recordItem) {
    $state = getPaperActiveState($recordItem);
    $recordItem['paper_active'] = $state['active'];
    $recordItem['paper_state_reason'] = $state['reason'];
}
unset($recordItem);
$msg = $_GET['msg'] ?? '';
$reason = $_GET['reason'] ?? '';

// 统计各科目覆盖率：已刷到的不同题数 / 该科目题库总题数
$coverage = [];
// 题库总数
$stmtTotal = $pdo->query("SELECT subject_id, COUNT(DISTINCT id) AS total_count FROM questions GROUP BY subject_id");
$total_map = [];
foreach ($stmtTotal->fetchAll() as $row) {
    $sid = (int)$row['subject_id'];
    if ($sid > 0) {
        $total_map[$sid] = (int)$row['total_count'];
    }
}
// 已刷到的不同题（该学生，已完成考试，只统计当前题库中存在的属于该科目的题目）
$stmtSeen = $pdo->prepare("
    SELECT p.subject_id, sub.name AS subject_name, COUNT(DISTINCT eq.question_id) AS seen_count
    FROM exam_records er
    JOIN exam_questions eq ON eq.exam_record_id = er.id
    JOIN papers p ON er.paper_id = p.id
    JOIN questions q ON eq.question_id = q.id AND q.subject_id = p.subject_id
    LEFT JOIN subjects sub ON p.subject_id = sub.id
    WHERE er.student_id = ? AND er.status = 'completed'
    GROUP BY p.subject_id, sub.name
");
$stmtSeen->execute([$_SESSION['student_id']]);
foreach ($stmtSeen->fetchAll() as $row) {
    $sid = (int)$row['subject_id'];
    $seen = (int)$row['seen_count'];
    $total = $total_map[$sid] ?? 0;
    if ($total <= 0) continue;
    $rate = $seen > 0 ? round($seen / $total * 100, 1) : 0;
    $coverage[] = [
        'subject_id' => $sid,
        'subject_name' => $row['subject_name'] ?? ('科目ID ' . $sid),
        'seen_count' => $seen,
        'total_count' => $total,
        'rate' => $rate,
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的记录 - <?php echo escape(getSiteTitle()); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <script>
        const funnyWarnings = [
            { emoji: '😏', text: '嘿嘿，想复制？没门！' },
            { emoji: '🤭', text: '偷偷摸摸的想干嘛呢？' },
            { emoji: '😎', text: '别白费力气了，专心刷题吧！' },
            { emoji: '🙈', text: '我看不见，你也别想复制！' },
            { emoji: '🦸', text: '系统保护已启动，禁止复制！' },
            { emoji: '🔒', text: '内容已加密，复制无效哦~' },
            { emoji: '🎭', text: '此路不通，请走正门！' },
            { emoji: '🚫', text: '禁止操作！专心学习才是王道！' },
            { emoji: '💪', text: '靠实力刷题，不靠复制！' },
            { emoji: '🎯', text: '想作弊？系统第一个不答应！' },
            { emoji: '😤', text: '哼！想复制？门都没有！' },
            { emoji: '🤖', text: 'AI监控中，禁止复制操作！' },
            { emoji: '🛡️', text: '防护盾已开启，复制被拦截！' },
            { emoji: '⚡', text: '电击警告！禁止复制！' },
            { emoji: '🎪', text: '这里是学习马戏团，不是复制工厂！' },
            { emoji: '🐱', text: '小猫说：不可以复制哦~' },
            { emoji: '🦉', text: '猫头鹰盯着你呢，别想复制！' },
            { emoji: '🌙', text: '月亮代表系统，禁止复制！' },
            { emoji: '⭐', text: '星星在看着你，老实刷题吧！' },
            { emoji: '🔥', text: '系统很生气，后果很严重！' }
        ];
        function showFunnyWarning() {
            const warning = funnyWarnings[Math.floor(Math.random() * funnyWarnings.length)];
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%); border: 3px solid #ffc107; border-radius: 20px; padding: 30px 40px; box-shadow: 0 10px 40px rgba(255, 193, 7, 0.5); z-index: 99999; text-align: center; font-size: 20px; font-weight: 600; color: #856404; animation: popIn 0.3s ease, fadeOut 0.3s ease 2s forwards; min-width: 300px;';
            toast.innerHTML = '<div style="font-size: 48px; margin-bottom: 15px;">' + warning.emoji + '</div><div>' + warning.text + '</div>';
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 2300);
        }
        const style = document.createElement('style');
        style.textContent = '@keyframes popIn { 0% { transform: translate(-50%, -50%) scale(0.5); opacity: 0; } 50% { transform: translate(-50%, -50%) scale(1.1); } 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; } } @keyframes fadeOut { from { opacity: 1; transform: translate(-50%, -50%) scale(1); } to { opacity: 0; transform: translate(-50%, -50%) scale(0.8); } }';
        document.head.appendChild(style);
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('contextmenu', function(e) { e.preventDefault(); showFunnyWarning(); return false; });
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && (e.keyCode === 67 || e.keyCode === 65 || e.keyCode === 86 || e.keyCode === 88 || e.keyCode === 83)) {
                    e.preventDefault(); showFunnyWarning(); return false;
                }
                if (e.keyCode === 123 || (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) || (e.ctrlKey && e.keyCode === 85)) {
                    e.preventDefault(); showFunnyWarning(); return false;
                }
            });
            document.onselectstart = function() { showFunnyWarning(); return false; };
            document.ondragstart = function() { showFunnyWarning(); return false; };
        });
    </script>
    <script>
        <?php include 'inc/inactivity_reminder.inc.php'; ?>
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <h1>
                <img src="/favicon.svg" alt="<?php echo escape(getSiteTitle()); ?>" class="logo-img" style="width: 40px; height: 40px; display: block;">
                <?php echo escape(getSiteTitle()); ?><?php echo getSiteEmoji(); ?>
            </h1>
            <div class="user-info">
                <span>
                    学号：<?php echo escape($_SESSION['student_no']); ?>
                    <?php if (!empty($_SESSION['student_name'])): ?>
                        | 姓名：<?php echo escape($_SESSION['student_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['student_class'])): ?>
                        | 班级：<?php echo escape($_SESSION['student_class']); ?>
                    <?php endif; ?>
                </span>
                <a href="exam_list.php">考试</a>
                <a href="records.php">我的记录</a>
                <a href="wrong_questions.php">错题本</a>
                <a href="logout.php">退出</a>
                <a href="help_student.php">使用说明</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <h2 style="margin: 0;">我的考试记录</h2>
            <?php if ($msg === 'paper_inactive'): ?>
                <div class="alert alert-warning" style="margin: 0; padding: 10px 15px; border-radius: 6px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;">
                    当前试卷不可用<?php echo $reason ? '：' . escape($reason) : ''; ?>，无法查看详情。
                </div>
            <?php endif; ?>
            <?php if (!empty($records)): 
                $total_exams = count($records);
                $total_score = 0;
                $total_possible = 0;
                $avg_score = 0;
                foreach ($records as $record) {
                    $total_score += $record['score'];
                    $total_possible += $record['total_score'];
                }
                if ($total_possible > 0) {
                    $avg_score = ($total_score / $total_possible) * 100;
                }
            ?>
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="display: inline-flex; align-items: center; padding: 10px 20px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); border-radius: 12px; border: 2px solid rgba(102, 126, 234, 0.2);">
                        <span style="font-size: 24px; margin-right: 10px;">📊</span>
                        <span style="font-size: 18px; font-weight: 600; color: #667eea;">共 <?php echo $total_exams; ?> 次考试</span>
                    </div>
                    <div style="display: inline-flex; align-items: center; padding: 10px 20px; background: linear-gradient(135deg, rgba(39, 174, 96, 0.1) 0%, rgba(34, 153, 84, 0.1) 100%); border-radius: 12px; border: 2px solid rgba(39, 174, 96, 0.2);">
                        <span style="font-size: 24px; margin-right: 10px;">⭐</span>
                        <span style="font-size: 18px; font-weight: 600; color: #27ae60;">平均正确率 <?php echo number_format($avg_score, 1); ?>%</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($coverage)): ?>
        <div class="paper-card" style="margin-bottom: 25px; padding: 18px 20px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <span style="font-size: 22px;">📚</span>
                <div style="font-size: 18px; font-weight: 700; color: #2c3e50;">各科目刷题覆盖率</div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                <?php foreach ($coverage as $cov): 
                    $rate = $cov['rate'];
                    // 覆盖率梯度与图标
                    if ($rate >= 100) { 
                        $color = 'linear-gradient(135deg,#16a34a,#22c55e)'; // 绿色
                        $icon  = '🏆'; // 满贯
                    } elseif ($rate >= 90) { 
                        $color = 'linear-gradient(135deg,#22c55e,#4ade80)'; // 亮绿
                        $icon  = '🥇'; 
                    } elseif ($rate >= 70) { 
                        $color = 'linear-gradient(135deg,#2563eb,#3b82f6)'; // 蓝色
                        $icon  = '🚀'; 
                    } elseif ($rate >= 40) { 
                        $color = 'linear-gradient(135deg,#f59e0b,#fbbf24)'; // 橙色
                        $icon  = '📘'; 
                    } else { 
                        $color = 'linear-gradient(135deg,#ef4444,#f97316)'; // 红橙
                        $icon  = '💡'; // 继续努力
                    }
                ?>
                    <div style="padding: 14px 16px; border-radius: 12px; background: <?php echo $color; ?>; color: white; box-shadow: 0 6px 16px rgba(0,0,0,0.12); display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 26px; line-height: 1;"><?php echo $icon; ?></div>
                        <div style="flex: 1;">
                            <div style="font-size: 15px; font-weight: 700; margin-bottom: 4px;"><?php echo escape($cov['subject_name']); ?></div>
                            <div style="font-size: 13px; opacity: 0.9;">
                                覆盖率：<strong><?php echo number_format($rate, 1); ?>%</strong>
                                <span style="margin-left: 6px; opacity:0.9;">(<?php echo $cov['seen_count']; ?>/<?php echo $cov['total_count']; ?>)</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (empty($records)): ?>
            <div class="paper-card" style="text-align: center; padding: 80px 20px; max-width: 500px; margin: 0 auto;">
                <div style="font-size: 80px; margin-bottom: 25px; animation: float 3s ease-in-out infinite;">📝</div>
                <p style="font-size: 20px; color: #2c3e50; font-weight: 600; margin-bottom: 10px;">暂无考试记录</p>
                <p style="font-size: 14px; color: #7f8c8d; margin-bottom: 30px;">开始你的第一次考试吧！</p>
                <a href="exam_list.php" class="btn btn-primary" style="padding: 14px 28px;">
                    <span>开始刷题 →</span>
                </a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">试卷名称</th>
                                <th style="min-width: 120px;">科目</th>
                                <th style="min-width: 100px;">得分</th>
                                <th style="min-width: 80px;">总分</th>
                                <th style="min-width: 100px;">正确率</th>
                                <th style="min-width: 100px;">用时</th>
                                <th style="min-width: 160px;">完成时间</th>
                                <th style="min-width: 100px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $index => $record): 
                                $duration_value = $record['duration'] ?? null;
                                if ($duration_value === null || $duration_value === '') {
                                    if (!empty($record['start_time']) && !empty($record['end_time'])) {
                                        $start = strtotime($record['start_time']);
                                        $end = strtotime($record['end_time']);
                                        if ($start !== false && $end !== false && $end > $start) {
                                            $duration_value = $end - $start;
                                        }
                                    }
                                }
                                $duration = intval($duration_value ?? 0);
                                $score_percent = $record['total_score'] > 0 ? ($record['score'] / $record['total_score'] * 100) : 0;
                                $score_color = $score_percent >= 80 ? '#27ae60' : ($score_percent >= 60 ? '#3498db' : ($score_percent >= 40 ? '#f39c12' : '#e74c3c'));
                            ?>
                                <tr style="animation: fadeIn 0.5s ease <?php echo $index * 0.05; ?>s both;">
                                    <td style="font-weight: 600; color: #2c3e50;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); color: #667eea; border-radius: 8px; font-weight: 700; font-size: 14px;">
                                                <?php echo $index + 1; ?>
                                            </span>
                                            <?php echo escape($record['paper_title']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: rgba(102, 126, 234, 0.1); color: #667eea; border-radius: 8px; font-weight: 500; font-size: 13px;">
                                            <?php echo escape($record['subject_name'] ?? '未分类'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <strong style="color: <?php echo $score_color; ?>; font-size: 18px; font-weight: 700;">
                                                <?php echo number_format($record['score'], 2); ?>
                                            </strong>
                                        </div>
                                    </td>
                                    <td style="color: #7f8c8d; font-weight: 500;">
                                        <?php echo $record['total_score']; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="flex: 1; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; max-width: 80px;">
                                                <div style="height: 100%; width: <?php echo min($score_percent, 100); ?>%; background: linear-gradient(90deg, <?php echo $score_color; ?> 0%, <?php echo $score_color; ?>dd 100%); border-radius: 4px; transition: width 0.3s ease;"></div>
                                            </div>
                                            <span style="color: <?php echo $score_color; ?>; font-weight: 600; font-size: 14px; min-width: 45px;">
                                                <?php echo number_format($score_percent, 1); ?>%
                                            </span>
                                        </div>
                                    </td>
                                    <td style="color: #555; font-size: 13px;">
                                        <?php 
                                        if ($duration > 0) {
                                            $minutes = floor($duration / 60);
                                            $seconds = $duration % 60;
                                            echo '<span style="display: inline-flex; align-items: center; gap: 4px;"><span>⏱</span><span>' . $minutes . '分' . $seconds . '秒</span></span>';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td style="color: #7f8c8d; font-size: 13px;">
                                        <?php 
                                        $completion_time = !empty($record['end_time']) ? $record['end_time'] : ($record['created_at'] ?? '');
                                        if (!empty($completion_time)) {
                                            echo date('Y-m-d H:i:s', strtotime($completion_time));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['paper_active'])): ?>
                                        <a href="exam_result.php?exam_record_id=<?php echo $record['id']; ?>" class="btn btn-primary" style="font-size: 13px; padding: 8px 16px; white-space: nowrap;">
                                            <span>查看详情</span>
                                        </a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary" style="font-size: 13px; padding: 8px 16px; white-space: nowrap; cursor: not-allowed; opacity: 0.7;" title="试卷<?php echo escape($record['paper_state_reason'] ?: '不可用'); ?>">
                                                <span><?php echo escape($record['paper_state_reason'] ?: '不可用'); ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <style>
                @keyframes float {
                    0%, 100% { transform: translateY(0px); }
                    50% { transform: translateY(-10px); }
                }
                @keyframes fadeIn {
                    from {
                        opacity: 0;
                        transform: translateX(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateX(0);
                    }
                }
            </style>
        <?php endif; ?>
    </div>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

