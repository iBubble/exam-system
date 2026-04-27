<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
checkStudentLogin();
ensurePaperScheduleColumns($pdo);
$active_subject_ids = getActiveSubjectIds($pdo);
$msg = $_GET['msg'] ?? '';
$reason = $_GET['reason'] ?? '';

// 删除错题
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("DELETE FROM wrong_questions WHERE id = ? AND student_id = ?");
    $stmt->execute([$id, $_SESSION['student_id']]);
    header('Location: wrong_questions.php');
    exit;
}

// 获取学生已做过考试的科目列表
$stmt = $pdo->prepare("SELECT DISTINCT s.id, s.name 
                       FROM exam_records er 
                       JOIN papers p ON er.paper_id = p.id 
                       JOIN subjects s ON p.subject_id = s.id 
                       WHERE er.student_id = ? AND er.status = 'completed'
                       ORDER BY s.name");
$stmt->execute([$_SESSION['student_id']]);
$subjects = array_values(array_filter($stmt->fetchAll(), function($row) use ($active_subject_ids) {
    return in_array((int)$row['id'], $active_subject_ids, true);
}));

// 获取选中的科目ID
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($selected_subject_id > 0 && !in_array($selected_subject_id, $active_subject_ids, true)) {
    $reason = urlencode('科目未开放');
    header('Location: wrong_questions.php?msg=paper_inactive' . ($reason ? '&reason=' . $reason : ''));
    exit;
}

// 获取错题列表（根据科目筛选）
$sql = "SELECT wq.*, q.question_text, q.correct_answer, q.answer_analysis, q.option_a, q.option_b, q.option_c, q.option_d, 
        q.knowledge_point, q.subject_id, s.name as subject_name 
                       FROM wrong_questions wq 
                       JOIN questions q ON wq.question_id = q.id 
                       LEFT JOIN subjects s ON q.subject_id = s.id 
        WHERE wq.student_id = ?";
$params = [$_SESSION['student_id']];

// 如果选择了科目，添加科目筛选条件
if ($selected_subject_id > 0) {
    $sql .= " AND q.subject_id = ?";
    $params[] = $selected_subject_id;
}

// 仅展示当前处于开放/未暂停科目的错题
$should_query = true;
if (!empty($active_subject_ids)) {
    $placeholders = implode(',', array_fill(0, count($active_subject_ids), '?'));
    $sql .= " AND q.subject_id IN ($placeholders)";
    $params = array_merge($params, $active_subject_ids);
} else {
    // 若无可用科目，直接返回空结果
    $wrong_questions = [];
    $subjects = [];
    $selected_subject_id = 0;
    $should_query = false;
}

$sql .= " ORDER BY wq.last_wrong_time DESC";

if ($should_query) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
$wrong_questions = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <title>错题本 - <?php echo escape(getSiteTitle()); ?></title>
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
        
        function filterBySubject(subjectId) {
            const url = new URL(window.location.href);
            if (subjectId == '0') {
                url.searchParams.delete('subject_id');
            } else {
                url.searchParams.set('subject_id', subjectId);
            }
            window.location.href = url.toString();
        }
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
            <h2 style="margin: 0;">错题本</h2>
            <?php if ($msg === 'paper_inactive'): ?>
                <div class="alert alert-warning" style="margin: 0; padding: 10px 15px; border-radius: 6px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;">
                    当前科目未开放<?php echo $reason ? '：' . escape($reason) : ''; ?>，暂不可查看错题。
                </div>
            <?php endif; ?>
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <?php if (!empty($subjects)): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label for="subject_filter" style="font-weight: 600; color: #34495e; white-space: nowrap;">科目筛选：</label>
                        <select id="subject_filter" name="subject_id" onchange="filterBySubject(this.value)" 
                                style="padding: 8px 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px; background: white; cursor: pointer; min-width: 150px;"
                                value="<?php echo $selected_subject_id; ?>">
                            <option value="0" <?php echo $selected_subject_id == 0 ? 'selected' : ''; ?>>全部科目</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $selected_subject_id == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($subject['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <div style="display: inline-flex; align-items: center; padding: 10px 20px; background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(192, 57, 43, 0.1) 100%); border-radius: 12px; border: 2px solid rgba(231, 76, 60, 0.2);">
                <span style="font-size: 24px; margin-right: 10px;">📚</span>
                <span style="font-size: 18px; font-weight: 600; color: #e74c3c;">共 <?php echo count($wrong_questions); ?> 题</span>
                </div>
            </div>
        </div>
        
        <?php if (empty($wrong_questions)): ?>
            <div class="paper-card" style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
                <p style="font-size: 16px; color: #7f8c8d; margin-bottom: 20px;">
                    <?php if ($selected_subject_id > 0): ?>
                        该科目暂无错题，继续保持！
                    <?php else: ?>
                        暂无错题，继续保持！
                    <?php endif; ?>
                </p>
                <a href="exam_list.php" class="btn btn-primary">
                    <span>继续刷题 →</span>
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($wrong_questions as $wq): ?>
                <div class="question-card" style="border-left-color: #e74c3c; position: relative;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 10px;">
                                <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: rgba(231, 76, 60, 0.1); color: #e74c3c; border-radius: 8px; font-weight: 600; font-size: 13px;">
                                    <?php echo escape($wq['subject_name'] ?? '未分类'); ?>
                                </span>
                                <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: rgba(243, 156, 18, 0.1); color: #f39c12; border-radius: 8px; font-weight: 600; font-size: 13px;">
                                    错误 <?php echo $wq['wrong_times']; ?> 次
                                </span>
                            </div>
                            
                            <?php if ($wq['knowledge_point']): ?>
                                <p style="font-size: 14px; margin-bottom: 12px; color: #667eea; font-weight: 500;">
                                    📌 <strong>知识点：</strong><?php echo escape($wq['knowledge_point']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <a href="?action=delete&id=<?php echo $wq['id']; ?>" 
                           class="btn btn-danger" 
                           style="font-size: 13px; padding: 8px 16px;"
                           onclick="return confirm('确定要从错题本中删除这道题吗？')">
                            <span>删除</span>
                        </a>
                    </div>
                    
                    <div style="font-size: 15px; margin-bottom: 15px; line-height: 1.8; color: #34495e; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <?php echo nl2br(escape($wq['question_text'])); ?>
                    </div>
                    
                    <?php if ($wq['option_a']): ?>
                        <div style="font-size: 14px; line-height: 1.8; margin-bottom: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                            <p style="margin: 0; padding: 10px; background: white; border-radius: 8px; border: 2px solid #e0e0e0;">A. <?php echo escape($wq['option_a']); ?></p>
                            <p style="margin: 0; padding: 10px; background: white; border-radius: 8px; border: 2px solid #e0e0e0;">B. <?php echo escape($wq['option_b'] ?? ''); ?></p>
                            <p style="margin: 0; padding: 10px; background: white; border-radius: 8px; border: 2px solid #e0e0e0;">C. <?php echo escape($wq['option_c'] ?? ''); ?></p>
                            <p style="margin: 0; padding: 10px; background: white; border-radius: 8px; border: 2px solid #e0e0e0;">D. <?php echo escape($wq['option_d'] ?? ''); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="answer-detail">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                            <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: #d4edda; color: #155724; border-radius: 8px; font-weight: 600; font-size: 14px;">
                                ✓ 正确答案：<?php echo escape($wq['correct_answer']); ?>
                            </span>
                        </div>
                        <?php if ($wq['answer_analysis']): ?>
                            <div style="margin-top: 12px;">
                                <strong style="display: block; margin-bottom: 8px; color: #2c3e50;">📖 答案解析：</strong>
                                <p style="margin: 0; color: #34495e; line-height: 1.8;"><?php echo nl2br(escape($wq['answer_analysis'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <p style="margin-top: 15px; color: #7f8c8d; font-size: 13px; display: flex; align-items: center; gap: 5px;">
                        <span>🕒</span>
                        <span>最后错误时间：<?php echo $wq['last_wrong_time']; ?></span>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

