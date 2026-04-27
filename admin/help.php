<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>使用说明 - 管理后台</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
    <style>
        .help-section {
            margin-bottom: 32px;
            background: #ffffff;
            border-radius: 10px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .help-section h3 {
            font-size: 18px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .help-section h3 span.icon {
            font-size: 22px;
        }
        .help-section p {
            margin-bottom: 8px;
            color: #555;
        }
        .help-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin-top: 12px;
        }
        .help-card {
            border-radius: 10px;
            padding: 14px 16px;
            background: linear-gradient(135deg, #f8fafc 0%, #edf2ff 100%);
            border: 1px solid #e0e7ff;
        }
        .help-card h4 {
            font-size: 15px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .help-card p {
            font-size: 13px;
            margin: 0;
            color: #555;
        }
        .flowchart {
            margin-top: 14px;
            padding: 14px 16px;
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
            padding: 6px 10px;
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
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>管理后台使用说明（教师 / 管理员端）</h2>

        <div class="help-section">
            <h3><span class="icon">🔑</span>一、登录与导航</h3>
            <p>导航顺序：<strong>首页、科目管理、题库管理、考试管理、学生管理、刷题记录、参数设置、操作日志、使用说明</strong>。</p>
            <p>首次登录若修改了密码，请妥善保存，避免与默认密码混淆。</p>
            <p><strong>注意：</strong>所有添加和编辑操作都采用弹出窗口（模态框）方式，操作更便捷，无需页面跳转。</p>
        </div>

        <div class="help-section">
            <h3><span class="icon">📚</span>二、科目与题库</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>科目管理 <span>📖</span></h4>
                    <p>点击"添加科目"按钮或"编辑"按钮，在弹出窗口中新增/修改科目信息。支持删除科目，为题库和试卷做分类。</p>
                </div>
                <div class="help-card">
                    <h4>导入题库 <span>📥</span></h4>
                    <p>在"题库管理"页面点击"导入题库"按钮，在弹出窗口中上传Excel文件（xls/xlsx/csv）批量导入试题。支持使用Excel中的"目录"字段自动创建或匹配科目。</p>
                </div>
                <div class="help-card">
                    <h4>题库管理 <span>❓</span></h4>
                    <p>点击"添加题目"或"编辑"按钮，在弹出窗口中添加/编辑题目，设置题型、分值、解析等。支持按科目筛选和关键词搜索，分页显示（默认每页50条，可选择20/50/100/全部）。</p>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">📝</span>三、考试管理（原组卷管理）</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>创建/编辑考试 <span>🧩</span></h4>
                    <p>点击"创建考试"或"编辑"按钮，在弹出窗口中设置考试信息：选择科目、设置考试名称、总分、时长、说明；按题型设定数量/分值，题型顺序可通过拖动调整。</p>
                </div>
                <div class="help-card">
                    <h4>抽题与随机 <span>🎯</span></h4>
                    <p>考试时按科目随机抽题，并优先抽取学生未做过的题，提升覆盖率；“题目乱序”功能已去除。</p>
                </div>
                <div class="help-card">
                    <h4>开放时间与暂停 <span>⏱️</span></h4>
                    <p>支持设置开始/结束时间及暂停状态。未开始、已结束或暂停的试卷，学生端无法进入考试、查看详情或错题本。</p>
                </div>
                <div class="help-card">
                    <h4>分配班级 <span>🏫</span></h4>
                    <p>试卷可多选班级，班级内学生登录后仅看到匹配的试卷。不选择班级则所有学生可见。</p>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">👥</span>四、学生管理</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>导入学生 <span>📂</span></h4>
                    <p>支持上传 csv/xls/xlsx 文件批量导入学生。遇到重复学号会自动覆盖姓名/班级信息。导入前可用小样本测试文件确认格式。</p>
                </div>
                <div class="help-card">
                    <h4>添加/编辑学生 <span>➕</span></h4>
                    <p>点击"添加学生"或"编辑"按钮（编辑按钮在删除按钮前），在弹出窗口中手工新增或修改学生信息，包括学号、姓名、班级。</p>
                </div>
                <div class="help-card">
                    <h4>批量操作 <span>🛠️</span></h4>
                    <p>支持批量删除、批量修改班级（可新增班级名）。勾选学生后点击相应按钮即可批量操作。</p>
                </div>
                <div class="help-card">
                    <h4>搜索筛选 <span>🔍</span></h4>
                    <p>支持按学号、姓名、班级搜索，也可通过班级下拉菜单快速筛选。所有操作都会记录到操作日志中。</p>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">📊</span>五、刷题记录与覆盖率</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>学生刷题列表 <span>📈</span></h4>
                    <p>位置：顶部导航「刷题记录」→"学生刷题列表"。每行是「学生-科目」，显示已刷题数、题目总数、覆盖率。</p>
                    <p>支持：按科目筛选；表头点击排序（默认覆盖率倒序）；分页显示（默认每页50条，可选择20/50/100/全部）。覆盖率只统计当前题库中存在的题目，避免超过100%。</p>
                </div>
                <div class="help-card">
                    <h4>学生刷题记录 <span>🗂️</span></h4>
                    <p>查看单个学生的考试记录，并在顶部看到该生各科目的覆盖率（已刷题数/题目总数）。</p>
                </div>
                <div class="help-card">
                    <h4>错题分析 <span>🔍</span></h4>
                    <p>可在错题相关页面/分析中按科目查看薄弱题型，为教学提供参考。</p>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">⚙️</span>五、参数设置</h3>
            <div class="help-grid">
                <div class="help-card">
                    <h4>系统参数 <span>🔧</span></h4>
                    <p>可设置每套试卷题目数量、是否自动组卷、提交后是否显示答案、是否允许回顾等基础参数。</p>
                </div>
                <div class="help-card">
                    <h4>学生发呆提醒时间 <span>⏰</span></h4>
                    <p>设置学生无操作多少分钟后弹出提醒动画（范围：1-60分钟，默认5分钟）。学生需要手动点击"我知道了"才能关闭提醒，确保学习专注度。</p>
                </div>
            </div>
        </div>

        <div class="help-section">
            <h3><span class="icon">🧾</span>六、操作日志</h3>
            <p>位置：导航「操作日志」。系统会自动记录所有管理员操作，包括：</p>
            <ul style="margin-left: 18px; color: #555; font-size: 13px; margin-top: 8px;">
                <li style="margin: 4px 0;">科目管理：创建、更新、删除科目</li>
                <li style="margin: 4px 0;">题库管理：添加、更新、删除题目，导入题库</li>
                <li style="margin: 4px 0;">考试管理：创建、更新、删除考试</li>
                <li style="margin: 4px 0;">学生管理：添加、导入、删除学生，批量操作</li>
                <li style="margin: 4px 0;">其他系统操作</li>
            </ul>
            <p style="margin-top: 8px;">默认按时间倒序显示，支持筛选用户名、结果（success/failed）、关键词（动作/详情）、起止日期，分页显示（默认每页50条，可选择20/50/100/全部）。</p>
        </div>

        <div class="help-section">
            <h3><span class="icon">💡</span>七、运维与提示</h3>
            <ul style="margin-left: 18px; color: #555; font-size: 13px;">
                <li style="margin: 4px 0;">浏览器建议：Chrome / Edge 最新版，确保最佳体验。</li>
                <li style="margin: 4px 0;">若学生看不到试卷：检查学生班级、试卷是否勾选班级、试卷是否在开放时间内。</li>
                <li style="margin: 4px 0;">导入前可用小样本测试文件确认格式后再批量导入，避免格式错误。</li>
                <li style="margin: 4px 0;">所有添加和编辑操作都采用弹出窗口方式，操作更便捷，无需页面跳转。</li>
                <li style="margin: 4px 0;">系统会优先抽取学生未做过的题目，提升刷题覆盖率，帮助学生查漏补缺。</li>
                <li style="margin: 4px 0;">学生长时间无操作会弹出提醒动画，提醒时间可在"参数设置"中调整。</li>
                <li style="margin: 4px 0;">所有列表页面支持分页显示，默认每页50条，可在页面右上角选择每页显示数量。</li>
                <li style="margin: 4px 0;">前后台Session独立，前台学生退出不会影响后台管理员登录状态。</li>
                <li style="margin: 4px 0;">覆盖率计算已优化，只统计当前题库中存在的题目，避免因题目删除导致覆盖率超过100%。</li>
            </ul>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>


