<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
checkStudentLogin();
ensurePaperScheduleColumns($pdo);

$paper_id = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : 0;

if ($paper_id <= 0) {
    header('Location: exam_list.php');
    exit;
}

// 获取试卷信息
$stmt = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                       LEFT JOIN subjects s ON p.subject_id = s.id 
                       WHERE p.id = ?");
$stmt->execute([$paper_id]);
$paper = $stmt->fetch();

if (!$paper) {
    header('Location: exam_list.php');
    exit;
}

// 检查学生是否有权限访问该试卷
$student_class = $_SESSION['student_class'] ?? null;
if (!checkStudentPaperAccess($pdo, $paper_id, $student_class)) {
    header('Location: exam_list.php?msg=paper_inactive&reason=' . urlencode('您所在的班级无权参加此考试'));
    exit;
}

$state = getPaperActiveState($paper);
if (!$state['active']) {
    $reason = urlencode($state['reason'] ?? '');
    header('Location: exam_list.php?msg=paper_inactive' . ($reason ? '&reason=' . $reason : ''));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>开始考试 - <?php echo escape($paper['title']); ?> - <?php echo escape(getSiteTitle()); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/style.css">
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
        <div class="paper-card" style="max-width: 700px; margin: 0 auto; text-align: center;">
            <!-- 试卷图标 -->
            <div style="width: 100px; height: 100px; margin: 0 auto 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 24px; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);">
                <span style="font-size: 50px;">📝</span>
            </div>
            
            <h2 style="margin-bottom: 10px; border: none; padding: 0; font-size: 28px; color: #2c3e50;">
                <?php echo escape($paper['title']); ?>
            </h2>
            
            <?php if ($paper['subject_name']): ?>
                <div style="display: inline-flex; align-items: center; padding: 8px 20px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); border-radius: 20px; border: 2px solid rgba(102, 126, 234, 0.2); margin-bottom: 30px;">
                    <span style="color: #667eea; font-weight: 600; font-size: 15px;"><?php echo escape($paper['subject_name']); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- 考试信息卡片 -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 35px;">
                <div style="padding: 25px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 16px; border: 2px solid rgba(102, 126, 234, 0.1);">
                    <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 10px; font-weight: 500;">总分</div>
                    <div style="font-size: 36px; font-weight: 700; color: #667eea; line-height: 1;">
                        <?php echo $paper['total_score']; ?>
                        <span style="font-size: 18px; color: #7f8c8d; font-weight: 400;">分</span>
                    </div>
                </div>
                <div style="padding: 25px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 16px; border: 2px solid rgba(118, 75, 162, 0.1);">
                    <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 10px; font-weight: 500;">考试时长</div>
                    <div style="font-size: 36px; font-weight: 700; color: #764ba2; line-height: 1;">
                        <?php echo $paper['duration']; ?>
                        <span style="font-size: 18px; color: #7f8c8d; font-weight: 400;">分钟</span>
                    </div>
                </div>
            </div>
            
            <?php if ($paper['description']): ?>
                <div style="padding: 20px; background: #f8f9fa; border-radius: 12px; margin-bottom: 35px; text-align: left;">
                    <div style="font-size: 14px; color: #667eea; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <span>📋</span>
                        <span>试卷说明</span>
                    </div>
                    <p style="font-size: 14px; color: #555; line-height: 1.8; margin: 0;">
                        <?php echo nl2br(escape($paper['description'])); ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <!-- 考试提示 -->
            <div style="padding: 20px; background: linear-gradient(135deg, rgba(52, 152, 219, 0.1) 0%, rgba(41, 128, 185, 0.1) 100%); border-radius: 12px; border-left: 4px solid #3498db; margin-bottom: 35px; text-align: left;">
                <div style="font-size: 14px; color: #2c3e50; line-height: 1.8;">
                    <p style="margin: 0 0 8px 0; font-weight: 600; color: #3498db; display: flex; align-items: center; gap: 8px;">
                        <span>💡</span>
                        <span>考试提示</span>
                    </p>
                    <ul style="margin: 0; padding-left: 20px; color: #555;">
                        <li>考试开始后，系统将自动计时</li>
                        <li>答题过程中可以随时保存答案</li>
                        <li>时间结束后系统将自动提交</li>
                        <li>请确保网络连接稳定</li>
                    </ul>
                </div>
            </div>
            
            <!-- 开始按钮 -->
            <form method="POST" action="exam.php?paper_id=<?php echo $paper_id; ?>">
                <input type="hidden" name="action" value="start">
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 18px; font-size: 18px; font-weight: 600; position: relative; overflow: hidden;">
                    <span style="position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 10px;">
                        <span>🚀</span>
                        <span>开始答题</span>
                        <span>→</span>
                    </span>
                </button>
            </form>
            
            <!-- 返回链接 -->
            <div style="margin-top: 25px;">
                <a href="exam_list.php" style="color: #7f8c8d; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px; transition: color 0.3s;" onmouseover="this.style.color='#667eea'" onmouseout="this.style.color='#7f8c8d'">
                    <span>←</span>
                    <span>返回试卷列表</span>
                </a>
            </div>
        </div>
    </div>
    
    <style>
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .paper-card {
            animation: fadeIn 0.6s ease;
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

