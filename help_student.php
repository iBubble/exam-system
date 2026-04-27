<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
checkStudentLogin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生使用说明 - <?php echo escape(getSiteTitle()); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .help-section {
            margin-bottom: 24px;
            background: #ffffff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .help-section h3 {
            font-size: 18px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .help-section h3 span.icon {
            font-size: 22px;
        }
        .help-section p {
            margin-bottom: 6px;
            color: #555;
        }
        .help-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
            margin-top: 8px;
        }
        .help-card {
            border-radius: 10px;
            padding: 12px 14px;
            background: linear-gradient(135deg, #fdfbff 0%, #eef2ff 100%);
            border: 1px solid #e0e7ff;
            font-size: 13px;
        }
        .help-card h4 {
            font-size: 14px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .help-card p {
            margin: 0;
            color: #555;
            line-height: 1.6;
        }
        .flowchart {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #f9fafb;
            border: 1px dashed #cbd5e1;
            font-size: 13px;
        }
        .flow-steps {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }
        .flow-step {
            padding: 5px 10px;
            border-radius: 999px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            white-space: nowrap;
        }
        .flow-arrow {
            font-size: 16px;
            color: #94a3b8;
        }
        @media (max-width: 600px) {
            .flow-step {
                white-space: normal;
            }
        }
    </style>
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
        <h2 style="margin-bottom: 16px;">学生使用说明</h2>

        <div class="help-section">
            <h3><span class="icon">🚪</span>一、登录与进入考试</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4><span>1️⃣</span> 账号登录</h4>
                    <p>使用<span style="font-weight:600;">学号</span>登录系统。登录后右上角显示学号/姓名/班级信息。若学号不存在，会提示"学生信息不存在"并返回登录页。</p>
                </div>
                <div class="help-card">
                    <h4><span>2️⃣</span> 动态标题</h4>
                    <p>登录后，页面标题会显示为"刷啊刷刷个XXX"（如：刷啊刷刷个大西瓜🍉），每次登录随机生成，直到退出登录前保持一致。未登录时首页标题每分钟自动变化。</p>
                </div>
                <div class="help-card">
                    <h4><span>3️⃣</span> 试卷列表</h4>
                    <p>列表只显示与你班级匹配的试卷。每个试卷卡片中，科目显示在最顶部，考试标题在下一行，还包含总分、时长、可用状态等信息。未开始、已结束或暂停的试卷无法进入。</p>
                </div>
                <div class="help-card">
                    <h4><span>4️⃣</span> 进入考试</h4>
                    <p>点击试卷进入说明页，确认考试信息后点击"开始考试"按钮，系统开始计时。</p>
                </div>
            </div>
            <div class="flowchart">
                <div style="margin-bottom: 4px; font-weight: 600; color: #334155;">流程</div>
                <div class="flow-steps">
                    <div class="flow-step">登录</div><span class="flow-arrow">→</span>
                    <div class="flow-step">选试卷</div><span class="flow-arrow">→</span>
                    <div class="flow-step">查看说明</div><span class="flow-arrow">→</span>
                    <div class="flow-step">开始考试</div>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">📝</span>二、答题与交卷</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4><span>1️⃣</span> 题目顺序</h4>
                    <p>按题型顺序展示，可上下滚动查看；题型顺序由老师设定。系统会优先抽取你未做过的题目，提升刷题覆盖率。</p>
                </div>
                <div class="help-card">
                    <h4><span>2️⃣</span> 防复制&幽默提示</h4>
                    <p>右键、复制、F12等操作会弹出超搞笑的玩梗提醒（如"这波操作有点小寄"、"Ctrl+C已禁用，请使用Ctrl+大脑模式"等），输入框内正常操作不受影响。</p>
                </div>
                <div class="help-card">
                    <h4><span>3️⃣</span> 错误提示</h4>
                    <p>答错题目时会显示幽默诙谐的错误提示（如"这波属于是大脑短路了一下"、"再错下去绩点要表演社会性滑坡了"等），让学习更有趣。</p>
                </div>
                <div class="help-card">
                    <h4><span>4️⃣</span> 无活动提醒</h4>
                    <p>如果长时间无操作（时间由管理员在后台设置，默认5分钟），页面会弹出提醒动画，需要点击"我知道了"按钮才能关闭，确保你专注学习。</p>
                </div>
                <div class="help-card">
                    <h4><span>5️⃣</span> 离开提醒</h4>
                    <p>未提交就关闭/跳转会先弹窗确认；若确认离开，系统会先帮你提交再跳转，避免丢失答题进度。</p>
                </div>
                <div class="help-card">
                    <h4><span>6️⃣</span> 交卷</h4>
                    <p>到时自动交卷；也可手动点击"提交试卷"立即交卷。交卷后立即显示成绩和答案解析。</p>
                </div>
            </div>
            <div class="flowchart">
                <div style="margin-bottom: 4px; font-weight: 600; color: #334155;">注意</div>
                <div class="flow-steps">
                    <div class="flow-step">避免刷新/关闭</div><span class="flow-arrow">→</span>
                    <div class="flow-step">需要离开时先确认</div><span class="flow-arrow">→</span>
                    <div class="flow-step">确认会先自动提交</div>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">📊</span>三、成绩、错题与覆盖率</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>考试结果 <span>✅</span></h4>
                    <p>交卷后查看得分、正确率、用时等详情。</p>
                </div>
                <div class="help-card">
                    <h4>我的记录 <span>🗂️</span></h4>
                    <p>历次考试记录汇总，支持打开详情。</p>
                </div>
                <div class="help-card">
                    <h4>错题本 <span>📕</span></h4>
                    <p>按科目筛选自己的错题，便于针对性复习。</p>
                </div>
                <div class="help-card">
                    <h4>覆盖率 <span>📚</span></h4>
                    <p>在“我的考试记录”顶部可查看各科目刷题覆盖率，显示已刷题数/总题数，并按百分比展示不同颜色和图标（100% 有绿色🏆）。</p>
                </div>
            </div>
            <div class="flowchart">
                <div style="margin-bottom: 4px; font-weight: 600; color: #334155;">复盘路径</div>
                <div class="flow-steps">
                    <div class="flow-step">考试结果</div><span class="flow-arrow">→</span>
                    <div class="flow-step">我的记录</div><span class="flow-arrow">→</span>
                    <div class="flow-step">错题本/覆盖率</div>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">🔒</span>四、考试规范与小提示</h3>
            <ul style="margin-left: 18px; color: #555; font-size: 13px;">
                <li style="margin: 4px 0;">防复制、防调试已开启，系统会弹出幽默提醒，专注作答最佳。</li>
                <li style="margin: 4px 0;">长时间无操作会弹出提醒动画，需要手动确认关闭，确保学习专注度。</li>
                <li style="margin: 4px 0;">确认离开会先自动提交，避免误关丢失答题进度。</li>
                <li style="margin: 4px 0;">建议使用最新版 Chrome / Edge 浏览器，网络不稳时尽量不要频繁刷新。</li>
                <li style="margin: 4px 0;">系统会优先抽取未做过的题目，多刷题可以提升覆盖率，查漏补缺。</li>
            </ul>
        </div>
    </div>
    <?php include 'inc/footer.php'; ?>
</body>
</html>


