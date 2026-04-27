<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// 获取统计信息（优化：合并查询，减少数据库访问次数）
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

// 刷题次数（完成的考试记录总数，确保与学生刷题列表一致）
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

// 获取首页统计刷新时间设置
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'stats_refresh_interval_seconds'");
$refresh_interval_row = $stmt->fetch();
$refresh_interval_seconds = $refresh_interval_row ? intval($refresh_interval_row['setting_value']) : 10;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - <?php echo getRandomTitle(); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=1.0">
    <!-- Chart.js - 延迟加载，避免阻塞页面渲染 -->
    <script>
        // 异步加载Chart.js，并设置加载完成回调
        (function() {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            script.async = true;
            script.defer = true;
            script.onload = function() {
                // Chart.js加载完成后触发自定义事件
                window.dispatchEvent(new Event('chartjsLoaded'));
            };
            document.head.appendChild(script);
        })();
    </script>
    <style>
        /* 统一统计报表卡片样式 */
        .stats-report-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.15),
                        0 0 20px rgba(118, 75, 162, 0.1);
            border: 2px solid rgba(102, 126, 234, 0.1);
            position: relative;
            overflow: visible;
        }
        
        .stats-report-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 30px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stats-report-title::before {
            content: '📊';
            font-size: 28px;
        }
        
        .stats-items-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 0;
            position: relative;
            padding: 0;
        }
        
        /* 创建连续的深色背景条，覆盖数字那一行 */
        .stats-items-grid::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 33px; /* 标签高度(约20px) + margin-bottom(5px) + padding-top(8px) */
            height: 48px; /* 数字容器的高度(32px) + padding(上下各8px) */
            background: linear-gradient(135deg, #3b3f58 0%, #181a27 100%);
            border-radius: 0;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5),
                        0 0 15px rgba(0, 0, 0, 0.3);
            z-index: 0;
        }
        
        .stats-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 7.5px; /* 左右各7.5px，相当于15px的gap */
            background: transparent;
            border-radius: 0;
            transition: all 0.3s ease;
            position: relative;
            border: none;
            z-index: 1;
        }
        
        /* 标签区域使用白色背景 */
        .stats-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 7.5px;
            right: 7.5px;
            height: 33px; /* 标签区域高度 */
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px 10px 0 0;
            z-index: -1;
        }
        
        .stats-item:hover::before {
            background: rgba(255, 255, 255, 1);
        }
        
        .stats-item:hover {
            transform: translateY(-2px);
        }
        
        .stats-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }
        
        .stats-item-icon {
            font-size: 20px;
            margin-bottom: 5px;
            filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.1));
            transition: transform 0.3s ease;
        }
        
        .stats-item:hover .stats-item-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .stats-item-label {
            font-size: 20px;
            margin-bottom: 5px;
            font-weight: 600;
            text-align: center;
            line-height: 1.3;
            /* 使用与翻牌数字牌子相同的深色风格 */
            color: #3b3f58;
            text-shadow: 0 1px 2px rgba(24, 26, 39, 0.2);
        }
        
        /* 翻牌式数字容器 */
        .flip-container {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            overflow: hidden;
            position: relative;
            perspective: 260px;
            background: transparent;
            padding: 8px 12px;
            margin-top: 4px;
            z-index: 2;
        }
        
        .flip-digit-wrapper {
            position: relative;
            width: 20px;
            height: 32px;
            margin: 0 1px;
            perspective: 260px;
        }
        
        .flip-digit {
            position: relative;
            width: 100%;
            height: 100%;
            transform-style: preserve-3d;
            transition: transform 0.1s linear;
        }
        
        .flip-digit.flipping {
            animation: flipAnimation 0.6s ease-in-out;
        }
        
        @keyframes flipAnimation {
            0% {
                transform: rotateX(0deg);
            }
            50% {
                transform: rotateX(90deg);
            }
            100% {
                transform: rotateX(0deg);
            }
        }
        
        .flip-digit-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
            /* 数字采用有颜色背景的翻牌效果 */
            color: #ffffff;
            background: linear-gradient(135deg, #3b3f58 0%, #181a27 100%);
            border-radius: 4px;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        
        .flip-digit-face.front {
            transform: rotateX(0deg);
        }
        
        .flip-digit-face.back {
            transform: rotateX(180deg);
        }
        
        .flip-dot {
            display: inline-block;
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
            margin: 0 2px;
            line-height: 28px;
        }
        
        .flip-suffix {
            font-size: 14px;
            font-weight: 600;
            color: #667eea;
            margin-left: 2px;
            line-height: 28px;
        }
        
        /* 响应式设计 */
        @media (max-width: 1600px) {
            .stats-items-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 12px;
            }
        }
        
        @media (max-width: 1200px) {
            .stats-items-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
            }
            
            .stats-item-label {
                font-size: 10px;
            }
            
            .flip-digit-face {
                font-size: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .stats-items-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                padding: 15px 10px;
            }
            
            .stats-item {
                padding: 10px 6px;
            }
        }
        
        /* 保留旧的stat-card样式用于兼容（如果需要） */
        .stat-card {
            padding: 24px;
            gap: 16px;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.3) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-12px) rotateX(5deg) rotateY(-5deg) scale(1.05);
            box-shadow: 0 20px 60px rgba(102, 126, 234, 0.4), 
                        0 0 40px rgba(118, 75, 162, 0.2),
                        inset 0 0 30px rgba(255, 255, 255, 0.1);
            border-color: rgba(102, 126, 234, 0.3);
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-card:hover::after {
            transform: scaleX(1);
        }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.2) rotate(10deg);
            filter: drop-shadow(0 4px 8px rgba(102, 126, 234, 0.4));
        }
        
        .stat-card:hover .stat-value {
            transform: scale(1.1);
            text-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }
        
        .stat-icon {
            font-size: 40px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
            position: relative;
            z-index: 1;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            z-index: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            font-size: 13px;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }
        
        /* 图表卡片增强样式 */
        .charts-section {
            margin-top: 20px;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-top: 30px;
        }
        
        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1),
                        0 0 0 1px rgba(102, 126, 234, 0.1);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }
        
        .chart-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .chart-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(102, 126, 234, 0.25),
                        0 0 0 1px rgba(102, 126, 234, 0.2),
                        inset 0 0 40px rgba(102, 126, 234, 0.05);
        }
        
        .chart-card:hover::before {
            transform: scaleX(1);
        }
        
        .chart-card h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 3px solid transparent;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .chart-card:hover h3 {
            border-bottom-color: #667eea;
        }
        
        .chart-container {
            position: relative;
            height: 240px;
            transition: all 0.3s ease;
        }
        
        .chart-card:hover .chart-container {
            transform: scale(1.02);
        }
        
        
        /* 动画效果 */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .stat-card {
            animation: fadeInUp 0.6s ease-out backwards;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
        .stat-card:nth-child(5) { animation-delay: 0.5s; }
        .stat-card:nth-child(6) { animation-delay: 0.6s; }
        .stat-card:nth-child(7) { animation-delay: 0.7s; }
        .stat-card:nth-child(8) { animation-delay: 0.8s; }
        
        .chart-card {
            animation: fadeInUp 0.8s ease-out backwards;
        }
        
        .chart-card:nth-child(1) { animation-delay: 0.2s; }
        .chart-card:nth-child(2) { animation-delay: 0.3s; }
        .chart-card:nth-child(3) { animation-delay: 0.4s; }
        .chart-card:nth-child(4) { animation-delay: 0.5s; }
        .chart-card:nth-child(5) { animation-delay: 0.6s; }
        
        /* 响应式设计 */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            .charts-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .chart-container {
                height: 220px;
            }
            .stat-card {
                padding: 20px;
            }
            .stat-icon {
                font-size: 36px;
            }
            .stat-value {
                font-size: 28px;
            }
        }
        
        /* 数字滚动动画 */
        .stat-value {
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <!-- 统一统计报表卡片 -->
        <div class="stats-report-card">
            <div class="stats-items-grid">
                <div class="stats-item">
                    <a href="subjects.php">
                        <div class="stats-item-label">科目</div>
                        <div class="flip-container" data-value="<?php echo $stats['subjects']; ?>" data-type="integer"></div>
                    </a>
                </div>
                <div class="stats-item">
                    <a href="papers.php">
                        <div class="stats-item-label">试卷</div>
                        <div class="flip-container" data-value="<?php echo $stats['papers']; ?>" data-type="integer"></div>
                    </a>
                </div>
                <div class="stats-item">
                    <a href="questions.php">
                        <div class="stats-item-label">题目</div>
                        <div class="flip-container" data-value="<?php echo $stats['questions']; ?>" data-type="integer"></div>
                    </a>
                </div>
                <div class="stats-item">
                    <div class="stats-item-label">班级</div>
                    <div class="flip-container" data-value="<?php echo $stats['classes']; ?>" data-type="integer"></div>
                </div>
                <div class="stats-item">
                    <a href="student_manage.php">
                        <div class="stats-item-label">学生</div>
                        <div class="flip-container" data-value="<?php echo $stats['students']; ?>" data-type="integer"></div>
                    </a>
                </div>
                <div class="stats-item">
                    <a href="students.php">
                        <div class="stats-item-label">考试次数</div>
                        <div class="flip-container" data-value="<?php echo $stats['exams']; ?>" data-type="integer"></div>
                    </a>
                </div>
                <div class="stats-item">
                    <div class="stats-item-label">平均得分</div>
                    <div class="flip-container" data-value="<?php echo $avg_score; ?>" data-type="decimal"></div>
                </div>
                <div class="stats-item">
                    <div class="stats-item-label">平均覆盖率</div>
                    <div class="flip-container" data-value="<?php echo $coverage_rate; ?>" data-type="percent"></div>
                </div>
            </div>
        </div>

        <!-- 图表分析区域 -->
        <div class="charts-section">
            <div class="charts-grid">
                <!-- 各科目题目数量分布 -->
                <div class="chart-card">
                    <h3>📚 各科目题目数量分布</h3>
                    <div class="chart-container">
                        <canvas id="subjectChart"></canvas>
                    </div>
                </div>

                <!-- 题目类型分布 -->
                <div class="chart-card">
                    <h3>📋 题目类型分布</h3>
                    <div class="chart-container">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>

                <!-- 考试记录趋势 -->
                <div class="chart-card">
                    <h3>📈 考试记录趋势（最近30天）</h3>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

                <!-- 刷题次数Top10学生 -->
                <div class="chart-card">
                    <h3>🏆 刷题次数Top10学生</h3>
                    <div class="chart-container">
                        <canvas id="topStudentsChart"></canvas>
                    </div>
                </div>

                <!-- 试卷使用情况 -->
                <div class="chart-card" style="grid-column: 1 / -1;">
                    <h3>📝 试卷使用情况（Top 10）</h3>
                    <div class="chart-container" style="height: 280px;">
                        <canvas id="paperChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
    <script>
        // 等待所有资源加载完成
        document.addEventListener('DOMContentLoaded', function() {
        
        // 翻牌式动画 - 创建数字位
        function createFlipDigits(container, targetValue, dataType) {
            let valueStr;
            if (dataType === 'decimal') {
                valueStr = parseFloat(targetValue).toFixed(2); // 如 88.23
            } else if (dataType === 'percent') {
                valueStr = parseFloat(targetValue).toFixed(2) + '%'; // 如 88.23%
            } else {
                valueStr = Math.floor(targetValue).toString();
            }
            const chars = valueStr.split('');
            
            // 清空容器
            container.innerHTML = '';
            
            // 为每个字符创建翻牌容器（包括数字、小数点和百分号）
            chars.forEach((char, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'flip-digit-wrapper';
                
                const flipDigit = document.createElement('div');
                flipDigit.className = 'flip-digit';
                flipDigit.setAttribute('data-digit-index', index);
                flipDigit.setAttribute('data-target', char);
                
                const frontFace = document.createElement('div');
                frontFace.className = 'flip-digit-face front';
                frontFace.textContent = char === '%' || char === '.' ? char : '0';
                
                const backFace = document.createElement('div');
                backFace.className = 'flip-digit-face back';
                backFace.textContent = char === '%' || char === '.' ? char : '0';
                
                flipDigit.appendChild(frontFace);
                flipDigit.appendChild(backFace);
                wrapper.appendChild(flipDigit);
                container.appendChild(wrapper);
            });
        }
        
        // 单个数字位的翻牌动画
        function animateFlipDigit(wrapperElement, targetDigit, delay = 0) {
            setTimeout(() => {
                const flipDigit = wrapperElement.querySelector('.flip-digit');
                const frontFace = flipDigit.querySelector('.front');
                const backFace = flipDigit.querySelector('.back');
                const isNumeric = /^[0-9]$/.test(targetDigit);
                
                // 非数字字符（小数点、百分号）只做一次轻微翻牌，不参与数值滚动
                if (!isNumeric) {
                    frontFace.textContent = targetDigit;
                    backFace.textContent = targetDigit;
                    flipDigit.classList.add('flipping');
                    setTimeout(() => {
                        flipDigit.classList.remove('flipping');
                    }, 400);
                    return;
                }
                
                const targetNum = parseInt(targetDigit, 10);
                
                const duration = 2000 + Math.random() * 500; // 2-2.5秒
                const startTime = Date.now();
                let currentNum = Math.floor(Math.random() * 10);
                let flipCount = 0;
                const totalFlips = 15 + Math.floor(Math.random() * 10); // 15-25次翻动
                
                function update() {
                    const now = Date.now();
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    
                    // 计算当前应该显示的数字
                    if (progress < 0.85) {
                        // 前85%的时间快速翻动
                        currentNum = Math.floor(Math.random() * 10);
                    } else {
                        // 后15%的时间逐渐接近目标
                        const remaining = targetNum - currentNum;
                        if (Math.abs(remaining) > 0.5) {
                            // 逐渐接近目标数字
                            if (remaining > 0) {
                                currentNum = Math.min(currentNum + 1, 9);
                            } else {
                                currentNum = Math.max(currentNum - 1, 0);
                            }
                        } else {
                            currentNum = targetNum;
                        }
                    }
                    
                    // 更新前后两个面的数字
                    frontFace.textContent = currentNum;
                    backFace.textContent = currentNum;
                    
                    // 触发翻牌动画
                    flipDigit.classList.remove('flipping');
                    void flipDigit.offsetWidth; // 触发重排
                    flipDigit.classList.add('flipping');
                    
                    flipCount++;
                    
                    if (progress < 1) {
                        const nextDelay = progress < 0.85 ? 80 : 150; // 前期快，后期慢
                        setTimeout(update, nextDelay);
                    } else {
                        // 确保最终停在目标数字
                        frontFace.textContent = targetNum;
                        backFace.textContent = targetNum;
                        flipDigit.classList.remove('flipping');
                    }
                }
                
                update();
            }, delay);
        }
        
        // 初始化所有翻牌数字动画
        function initFlipDigits() {
            const flipContainers = document.querySelectorAll('.flip-container[data-value]');
            
            flipContainers.forEach((container, containerIndex) => {
                const rawValue = container.getAttribute('data-value');
                const targetValue = parseFloat(rawValue);
                const dataType = container.getAttribute('data-type') || 'integer';
                
                // 创建数字位（包括小数点和百分号）
                createFlipDigits(container, targetValue, dataType);
                
                // 获取所有数字位包装器
                const wrapperElements = container.querySelectorAll('.flip-digit-wrapper');
                let valueStr;
                if (dataType === 'decimal') {
                    valueStr = parseFloat(targetValue).toFixed(2);
                } else if (dataType === 'percent') {
                    valueStr = parseFloat(targetValue).toFixed(2) + '%';
                } else {
                    valueStr = Math.floor(targetValue).toString();
                }
                const chars = valueStr.split('');
                
                // 为每个字符启动动画，带延迟效果
                wrapperElements.forEach((wrapper, index) => {
                    const ch = chars[index];
                    if (ch !== undefined) {
                        const delay = containerIndex * 80 + index * 60; // 每个容器延迟80ms，每个字符位延迟60ms
                        animateFlipDigit(wrapper, ch, delay);
                    }
                });
            });
        }
        
        // 页面加载完成后初始化翻牌动画
        setTimeout(() => {
            initFlipDigits();
        }, 500);
        
        // 等待Chart.js加载完成后再初始化图表
        function initCharts() {
            // 检查Chart对象是否存在
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js未加载，等待加载...');
                setTimeout(initCharts, 100);
                return;
            }
            
            // Chart.js 配置
            Chart.defaults.font.family = "'Microsoft YaHei', 'PingFang SC', 'Helvetica Neue', Arial, sans-serif";
            Chart.defaults.font.size = 11;
            Chart.defaults.color = '#2c3e50';
            Chart.defaults.animation.duration = 2000;
            Chart.defaults.animation.easing = 'easeOutQuart';
            
            // 初始化所有图表
            try {
                // 颜色方案
                const colors = {
                    primary: ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#30cfd0', '#a8edea', '#fed6e3'],
                    gradient: ['rgba(102, 126, 234, 0.8)', 'rgba(118, 75, 162, 0.8)', 'rgba(240, 147, 251, 0.8)', 'rgba(79, 172, 254, 0.8)']
                };
                
                // 创建渐变背景
                function createGradient(ctx, color1, color2) {
                    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
                    gradient.addColorStop(0, color1);
                    gradient.addColorStop(1, color2);
                    return gradient;
                }

                // HEX -> RGBA
                function hexToRgb(hex) {
                    const sanitized = hex.replace('#', '');
                    const bigint = parseInt(sanitized, 16);
                    const r = (bigint >> 16) & 255;
                    const g = (bigint >> 8) & 255;
                    const b = bigint & 255;
                    return { r, g, b };
                }

                // 颜色加亮
                function lighten(hex, amount = 0.15) {
                    const { r, g, b } = hexToRgb(hex);
                    const to255 = (v) => Math.min(255, Math.max(0, Math.floor(v)));
                    const nr = to255(r + (255 - r) * amount);
                    const ng = to255(g + (255 - g) * amount);
                    const nb = to255(b + (255 - b) * amount);
                    return `rgba(${nr}, ${ng}, ${nb}, 1)`;
                }

                // 生成饼/环形图渐变数组
                function buildPieGradients(ctx, baseColors) {
                    return baseColors.map((c) => {
                        const grad = ctx.createLinearGradient(0, 0, 0, 220);
                        grad.addColorStop(0, lighten(c, 0.35));
                        grad.addColorStop(0.5, c);
                        grad.addColorStop(1, lighten(c, -0.12));
                        return grad;
                    });
                }

                // 生成柱状图渐变数组（每个柱子不同颜色，使用逐渐变化的深浅色系）
                function buildBarGradients(ctx, count, isHorizontal = false) {
                    // 基础色：使用蓝紫色系
                    const baseHue = 240; // 蓝色
                    const baseSaturation = 70; // 饱和度
                    
                    // 生成逐渐变化的深浅色系
                    const gradients = [];
                    for (let i = 0; i < count; i++) {
                        // 计算亮度：数值越大颜色越深（第一个数值最大，颜色最深）
                        // 使用线性插值创建平滑的深浅变化
                        const progress = count > 1 ? i / (count - 1) : 0;
                        // 亮度范围：从30%到85%，第一个最暗（最深），最后一个最亮（最浅）
                        const lightness = 30 + (85 - 30) * progress; // 从深到浅
                        
                        // 转换为HSL并生成颜色
                        const color1 = hslToRgba(baseHue, baseSaturation, lightness);
                        const color2 = hslToRgba(baseHue, baseSaturation, Math.max(20, lightness - 15));
                        
                        // 创建带透明度的颜色
                        const color1Transparent = color1.replace('1)', '0.87)');
                        
                        const grad = isHorizontal 
                            ? ctx.createLinearGradient(0, 0, 400, 0)
                            : ctx.createLinearGradient(0, 0, 0, 400);
                        grad.addColorStop(0, color1Transparent); // 顶部/左侧，添加透明度
                        grad.addColorStop(0.5, color1);
                        grad.addColorStop(1, color2);
                        gradients.push(grad);
                    }
                    return gradients;
                }
                
                // HSL转RGBA辅助函数
                function hslToRgba(h, s, l) {
                    h = h / 360;
                    s = s / 100;
                    l = l / 100;
                    
                    let r, g, b;
                    if (s === 0) {
                        r = g = b = l;
                    } else {
                        const hue2rgb = (p, q, t) => {
                            if (t < 0) t += 1;
                            if (t > 1) t -= 1;
                            if (t < 1/6) return p + (q - p) * 6 * t;
                            if (t < 1/2) return q;
                            if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                            return p;
                        };
                        const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                        const p = 2 * l - q;
                        r = hue2rgb(p, q, h + 1/3);
                        g = hue2rgb(p, q, h);
                        b = hue2rgb(p, q, h - 1/3);
                    }
                    return `rgba(${Math.round(r * 255)}, ${Math.round(g * 255)}, ${Math.round(b * 255)}, 1)`;
                }
                
                // HSL转十六进制辅助函数
                function hslToHex(h, s, l) {
                    h = h / 360;
                    s = s / 100;
                    l = l / 100;
                    let r, g, b;
                    if (s === 0) {
                        r = g = b = l;
                    } else {
                        const hue2rgb = (p, q, t) => {
                            if (t < 0) t += 1;
                            if (t > 1) t -= 1;
                            if (t < 1/6) return p + (q - p) * 6 * t;
                            if (t < 1/2) return q;
                            if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                            return p;
                        };
                        const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                        const p = 2 * l - q;
                        r = hue2rgb(p, q, h + 1/3);
                        g = hue2rgb(p, q, h);
                        b = hue2rgb(p, q, h - 1/3);
                    }
                    const toHex = (x) => {
                        const hex = Math.round(x * 255).toString(16);
                        return hex.length === 1 ? '0' + hex : hex;
                    };
                    return '#' + toHex(r) + toHex(g) + toHex(b);
                }

                // 全局阴影插件
                const shadowPlugin = {
                    id: 'shadowPlugin',
                    beforeDatasetsDraw(chart, args, opts) {
                        chart.ctx.save();
                    },
                    beforeDatasetDraw(chart, args, opts) {
                        const { ctx } = chart;
                        const dataset = chart.data.datasets[args.index] || {};
                        const shadow = dataset.shadow;
                        if (shadow) {
                            ctx.save();
                            ctx.shadowColor = shadow.color || 'rgba(0,0,0,0.25)';
                            ctx.shadowBlur = shadow.blur ?? 20;
                            ctx.shadowOffsetX = shadow.offsetX ?? 0;
                            ctx.shadowOffsetY = shadow.offsetY ?? 10;
                        }
                    },
                    afterDatasetDraw(chart, args, opts) {
                        chart.ctx.restore();
                    },
                    afterDatasetsDraw(chart, args, opts) {
                        chart.ctx.restore();
                    }
                };
                
                // 保存图表实例到全局变量
                window.chartInstances = {};

                // 1. 各科目题目数量分布
                const subjectCtx = document.getElementById('subjectChart').getContext('2d');
        const subjectRawData = <?php echo json_encode($subject_question_data); ?>;
        // 按数值从大到小排序
        const subjectSortedData = subjectRawData.slice().sort((a, b) => b.count - a.count);
        const subjectLabels = subjectSortedData.map(item => item.name);
        const subjectCounts = subjectSortedData.map(item => item.count);
        const subjectGradients = buildPieGradients(subjectCtx, colors.primary);
        const subjectData = {
            labels: subjectLabels,
            datasets: [{
                data: subjectCounts,
                backgroundColor: subjectGradients,
                borderWidth: 3,
                borderColor: '#fff',
                hoverBorderWidth: 5,
                hoverOffset: 12,
                shadow: { color: 'rgba(0,0,0,0.25)', blur: 25, offsetY: 10 }
            }]
        };
        window.chartInstances.subjectChart = new Chart(subjectCtx, {
            type: 'pie',
            data: subjectData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 12,
                            usePointStyle: true,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (data.labels.length && data.datasets.length) {
                                    const dataset = data.datasets[0];
                                    return data.labels.map((label, i) => {
                                        const value = dataset.data[i];
                                        return {
                                            text: label + ' ' + value,
                                            fillStyle: dataset.backgroundColor[i],
                                            hidden: false,
                                            index: i
                                        };
                                    });
                                }
                                return [];
                            }
                        }
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' 题 (' + percentage + '%)';
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false
                }
            }
        });

        // 2. 题目类型分布
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        const typeRawData = <?php echo json_encode($question_type_data); ?>;
        // 按数值从大到小排序
        const typeSortedData = typeRawData.slice().sort((a, b) => b.count - a.count);
        const typeLabels = typeSortedData.map(item => item.question_type);
        const typeCounts = typeSortedData.map(item => item.count);
        const typeGradients = buildPieGradients(typeCtx, colors.gradient);
        const typeData = {
            labels: typeLabels,
            datasets: [{
                data: typeCounts,
                backgroundColor: typeGradients,
                borderWidth: 3,
                borderColor: '#fff',
                hoverBorderWidth: 6,
                hoverOffset: 10,
                shadow: { color: 'rgba(0,0,0,0.25)', blur: 22, offsetY: 8 }
            }]
        };
        window.chartInstances.typeChart = new Chart(typeCtx, {
            type: 'doughnut',
            data: typeData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 12,
                            usePointStyle: true,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                if (data.labels.length && data.datasets.length) {
                                    const dataset = data.datasets[0];
                                    return data.labels.map((label, i) => {
                                        const value = dataset.data[i];
                                        return {
                                            text: label + ' ' + value,
                                            fillStyle: dataset.backgroundColor[i],
                                            hidden: false,
                                            index: i
                                        };
                                    });
                                }
                                return [];
                            }
                        }
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' 题 (' + percentage + '%)';
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false
                }
            }
        });

        // 3. 考试记录趋势（渐变折线图）
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendLabels = <?php echo json_encode(array_column($exam_trend_data, 'date')); ?>;
        const trendCounts = <?php echo json_encode(array_column($exam_trend_data, 'count')); ?>;
        const trendGradient = createGradient(trendCtx, 'rgba(102, 126, 234, 0.4)', 'rgba(118, 75, 162, 0.1)');
        
        window.chartInstances.trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: '考试次数',
                    data: trendCounts,
                    borderColor: '#667eea',
                    backgroundColor: trendGradient,
                    borderWidth: 4,
                    fill: true,
                    tension: 0.5,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#667eea',
                    pointBorderWidth: 3,
                    pointHoverBackgroundColor: '#667eea',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3,
                    shadow: { color: 'rgba(102,126,234,0.35)', blur: 24, offsetY: 10 }
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                return '考试次数: ' + context.parsed.y + ' 次';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(102, 126, 234, 0.1)',
                            lineWidth: 1
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });

        // 4. 刷题次数Top10学生
        const topStudentsCtx = document.getElementById('topStudentsChart').getContext('2d');
        const topStudentsData = <?php echo json_encode($top_students_data); ?>;
        const topStudentsLabels = <?php echo json_encode(array_column($top_students_data, 'name')); ?>;
        const topStudentsCounts = <?php echo json_encode(array_column($top_students_data, 'exam_count')); ?>;
        const topStudentsGradients = buildBarGradients(topStudentsCtx, topStudentsCounts.length, false);
        
        // 为每个柱子生成边框颜色（使用逐渐变化的深浅色系）
        const topStudentsBorderColors = [];
        const baseHue = 240; // 蓝色
        const baseSaturation = 70;
        for (let i = 0; i < topStudentsCounts.length; i++) {
            const progress = topStudentsCounts.length > 1 ? i / (topStudentsCounts.length - 1) : 0;
            const lightness = 30 + (85 - 30) * progress; // 数值越大颜色越深（第一个最深，lightness最小）
            topStudentsBorderColors.push(hslToHex(baseHue, baseSaturation, lightness));
        }
        
        window.chartInstances.topStudentsChart = new Chart(topStudentsCtx, {
            type: 'bar',
            data: {
                labels: topStudentsLabels,
                datasets: [{
                    label: '刷题次数',
                    data: topStudentsCounts,
                    backgroundColor: topStudentsGradients,
                    borderColor: topStudentsBorderColors,
                    borderWidth: 3,
                    borderRadius: {
                        topLeft: 12,
                        topRight: 12,
                        bottomLeft: 0,
                        bottomRight: 0
                    },
                    borderSkipped: false,
                    barThickness: 50,
                    maxBarThickness: 60,
                    shadow: { color: 'rgba(0,0,0,0.35)', blur: 20, offsetY: 10 }
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.85)',
                        padding: 14,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                const index = context[0].dataIndex;
                                const student = topStudentsData[index];
                                return (student.name || '未知');
                            },
                            label: function(context) {
                                const index = context.dataIndex;
                                const student = topStudentsData[index];
                                const classText = student.class && student.class.trim() !== '' ? student.class : '未分班';
                                return [
                                    '班级: ' + classText,
                                    '刷题次数: ' + context.parsed.y + ' 次'
                                ];
                            }
                        }
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(102, 126, 234, 0.1)',
                            lineWidth: 1
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    }
                },
                interaction: {
                    intersect: false
                }
            }
        });

        // 5. 试卷使用情况（渐变水平柱状图）
        const paperCtx = document.getElementById('paperChart').getContext('2d');
        const paperLabels = <?php echo json_encode(array_column($paper_usage_data, 'title')); ?>;
        const paperCounts = <?php echo json_encode(array_column($paper_usage_data, 'exam_count')); ?>;
        const paperGradients = buildBarGradients(paperCtx, paperCounts.length, true);
        
        // 为每个柱子生成边框颜色（使用逐渐变化的深浅色系）
        const paperBorderColors = [];
        const paperBaseHue = 240; // 蓝色
        const paperBaseSaturation = 70;
        for (let i = 0; i < paperCounts.length; i++) {
            const progress = paperCounts.length > 1 ? i / (paperCounts.length - 1) : 0;
            const lightness = 30 + (85 - 30) * progress; // 数值越大颜色越深（第一个最深，lightness最小）
            paperBorderColors.push(hslToHex(paperBaseHue, paperBaseSaturation, lightness));
        }
        
        window.chartInstances.paperChart = new Chart(paperCtx, {
            type: 'bar',
            data: {
                labels: paperLabels,
                datasets: [{
                    label: '考试次数',
                    data: paperCounts,
                    backgroundColor: paperGradients,
                    borderColor: paperBorderColors,
                    borderWidth: 3,
                    borderRadius: {
                        topLeft: 0,
                        topRight: 12,
                        bottomLeft: 0,
                        bottomRight: 12
                    },
                    borderSkipped: false,
                    barThickness: 35,
                    maxBarThickness: 42,
                    shadow: { color: 'rgba(0,0,0,0.35)', blur: 20, offsetX: 10 }
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 2000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(0, 0, 0, 0.85)',
                        padding: 14,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                return '考试次数: ' + context.parsed.x + ' 次';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(102, 126, 234, 0.1)',
                            lineWidth: 1
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                weight: '500'
                            },
                            color: '#666'
                        }
                    }
                },
                interaction: {
                    intersect: false
                }
            }
        });
            } catch (error) {
                console.error('图表初始化失败:', error);
            }
        }
        
        // 监听Chart.js加载完成事件和DOMContentLoaded事件
        let chartsInitialized = false;
        function tryInitCharts() {
            if (!chartsInitialized && typeof Chart !== 'undefined' && document.readyState === 'complete') {
                chartsInitialized = true;
                initCharts();
            }
        }
        
        // 监听Chart.js加载完成
        window.addEventListener('chartjsLoaded', tryInitCharts);
        
        // 如果Chart.js已经加载（同步加载的情况）
        if (document.readyState === 'complete') {
            setTimeout(tryInitCharts, 100);
        } else {
            window.addEventListener('load', tryInitCharts);
        }
        
        // 定时刷新统计数据
        const refreshIntervalSeconds = <?php echo $refresh_interval_seconds; ?>;
        let currentStats = {
            subjects: <?php echo $stats['subjects']; ?>,
            questions: <?php echo $stats['questions']; ?>,
            papers: <?php echo $stats['papers']; ?>,
            students: <?php echo $stats['students']; ?>,
            classes: <?php echo $stats['classes']; ?>,
            exams: <?php echo $stats['exams']; ?>,
            avg_score: <?php echo $avg_score; ?>,
            coverage_rate: <?php echo $coverage_rate; ?>
        };
        
        let currentChartData = {
            subject_question_data: <?php echo json_encode($subject_question_data); ?>,
            question_type_data: <?php echo json_encode($question_type_data); ?>,
            exam_trend_data: <?php echo json_encode($exam_trend_data); ?>,
            top_students_data: <?php echo json_encode($top_students_data); ?>,
            paper_usage_data: <?php echo json_encode($paper_usage_data); ?>
        };
        
        // 更新数字显示（如果值有变化）
        function updateStatValue(container, newValue, dataType) {
            const oldValue = parseFloat(container.getAttribute('data-value'));
            if (Math.abs(oldValue - newValue) < 0.01) { // 浮点数比较，使用容差
                return false; // 没有变化
            }
            
            // 获取旧值字符串
            let oldValueStr;
            if (dataType === 'decimal') {
                oldValueStr = parseFloat(oldValue).toFixed(2);
            } else if (dataType === 'percent') {
                oldValueStr = parseFloat(oldValue).toFixed(2) + '%';
            } else {
                oldValueStr = Math.floor(oldValue).toString();
            }
            
            // 获取新值字符串
            let newValueStr;
            if (dataType === 'decimal') {
                newValueStr = parseFloat(newValue).toFixed(2);
            } else if (dataType === 'percent') {
                newValueStr = parseFloat(newValue).toFixed(2) + '%';
            } else {
                newValueStr = Math.floor(newValue).toString();
            }
            
            // 如果字符串长度不一致，需要重新创建整个结构
            if (oldValueStr.length !== newValueStr.length) {
                container.setAttribute('data-value', newValue);
                createFlipDigits(container, newValue, dataType);
                const wrapperElements = container.querySelectorAll('.flip-digit-wrapper');
                const newChars = newValueStr.split('');
                wrapperElements.forEach((wrapper, index) => {
                    const ch = newChars[index];
                    if (ch !== undefined) {
                        animateFlipDigit(wrapper, ch, index * 60);
                    }
                });
            } else {
                // 字符串长度一致，只更新变化的位置
                container.setAttribute('data-value', newValue);
                const wrapperElements = container.querySelectorAll('.flip-digit-wrapper');
                const oldChars = oldValueStr.split('');
                const newChars = newValueStr.split('');
                let hasAnyChange = false;
                
                wrapperElements.forEach((wrapper, index) => {
                    const oldCh = oldChars[index];
                    const newCh = newChars[index];
                    
                    if (oldCh !== newCh) {
                        // 只对有变化的位置进行动画
                        animateFlipDigit(wrapper, newCh, 0);
                        hasAnyChange = true;
                    } else {
                        // 没有变化的位置，直接更新显示内容（避免重新创建）
                        const flipDigit = wrapper.querySelector('.flip-digit');
                        if (flipDigit) {
                            const frontFace = flipDigit.querySelector('.front');
                            const backFace = flipDigit.querySelector('.back');
                            if (frontFace && backFace) {
                                frontFace.textContent = newCh;
                                backFace.textContent = newCh;
                            }
                        }
                    }
                });
                
                // 如果有任何变化，才添加高亮动画
                if (!hasAnyChange) {
                    return false;
                }
            }
            
            // 添加高亮动画效果
            const statsItem = container.closest('.stats-item');
            if (statsItem) {
                statsItem.style.animation = 'none';
                void statsItem.offsetWidth; // 触发重排
                statsItem.style.animation = 'pulse 0.6s ease-in-out';
            }
            
            return true; // 有变化
        }
        
        // 更新图表数据（如果数据有变化）
        function updateChart(chartInstance, newLabels, newData, chartType) {
            if (!chartInstance) return false;
            
            const oldLabels = JSON.stringify(chartInstance.data.labels);
            const oldData = JSON.stringify(chartInstance.data.datasets[0].data);
            const newLabelsStr = JSON.stringify(newLabels);
            const newDataStr = JSON.stringify(newData);
            
            if (oldLabels === newLabelsStr && oldData === newDataStr) {
                return false; // 没有变化
            }
            
            // 更新图表数据
            chartInstance.data.labels = newLabels;
            chartInstance.data.datasets[0].data = newData;
            
            // 更新动画配置（启用动画）
            chartInstance.options.animation.duration = 1000;
            chartInstance.update('active');
            
            return true; // 有变化
        }
        
        // 刷新统计数据
        function refreshStats() {
            fetch('stats_api.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        console.error('获取统计数据失败');
                        return;
                    }
                    
                    const newStats = data.stats;
                    const newChartData = data.charts;
                    let hasChanges = false;
                    
                    // 检查统计数据是否有变化
                    const statMapping = [
                        { key: 'subjects', type: 'integer', index: 0 },
                        { key: 'papers', type: 'integer', index: 1 },
                        { key: 'questions', type: 'integer', index: 2 },
                        { key: 'classes', type: 'integer', index: 3 },
                        { key: 'students', type: 'integer', index: 4 },
                        { key: 'exams', type: 'integer', index: 5 },
                        { key: 'avg_score', type: 'decimal', index: 6 },
                        { key: 'coverage_rate', type: 'percent', index: 7 }
                    ];
                    
                    statMapping.forEach(({ key, type, index }) => {
                        if (currentStats[key] !== newStats[key]) {
                            const containers = document.querySelectorAll('.stats-items-grid .flip-container[data-value]');
                            if (containers[index]) {
                                const changed = updateStatValue(containers[index], newStats[key], type);
                                if (changed) hasChanges = true;
                            }
                            currentStats[key] = newStats[key];
                        }
                    });
                    
                    // 检查图表数据是否有变化
                    if (window.chartInstances) {
                        // 1. 各科目题目数量分布（按数值从大到小排序）
                        const subjectSortedData = newChartData.subject_question_data.slice().sort((a, b) => b.count - a.count);
                        const subjectLabels = subjectSortedData.map(item => item.name);
                        const subjectData = subjectSortedData.map(item => item.count);
                        if (updateChart(window.chartInstances.subjectChart, subjectLabels, subjectData, 'pie')) {
                            hasChanges = true;
                            currentChartData.subject_question_data = newChartData.subject_question_data;
                        }
                        
                        // 2. 题目类型分布（按数值从大到小排序）
                        const typeSortedData = newChartData.question_type_data.slice().sort((a, b) => b.count - a.count);
                        const typeLabels = typeSortedData.map(item => item.question_type);
                        const typeData = typeSortedData.map(item => item.count);
                        if (updateChart(window.chartInstances.typeChart, typeLabels, typeData, 'doughnut')) {
                            hasChanges = true;
                            currentChartData.question_type_data = newChartData.question_type_data;
                        }
                        
                        // 3. 考试记录趋势（需要特殊处理，因为数据结构不同）
                        if (window.chartInstances.trendChart) {
                            const trendLabels = newChartData.exam_trend_data.map(item => item.date);
                            const trendData = newChartData.exam_trend_data.map(item => item.count);
                            const oldLabels = JSON.stringify(window.chartInstances.trendChart.data.labels);
                            const oldData = JSON.stringify(window.chartInstances.trendChart.data.datasets[0].data);
                            const newLabelsStr = JSON.stringify(trendLabels);
                            const newDataStr = JSON.stringify(trendData);
                            
                            if (oldLabels !== newLabelsStr || oldData !== newDataStr) {
                                window.chartInstances.trendChart.data.labels = trendLabels;
                                window.chartInstances.trendChart.data.datasets[0].data = trendData;
                                window.chartInstances.trendChart.options.animation.duration = 1000;
                                window.chartInstances.trendChart.update('active');
                                hasChanges = true;
                                currentChartData.exam_trend_data = newChartData.exam_trend_data;
                            }
                        }
                        
                        // 4. 刷题次数Top10学生
                        const topStudentsLabels = newChartData.top_students_data.map(item => item.name);
                        const topStudentsData = newChartData.top_students_data.map(item => item.exam_count);
                        if (updateChart(window.chartInstances.topStudentsChart, topStudentsLabels, topStudentsData, 'bar')) {
                            hasChanges = true;
                            currentChartData.top_students_data = newChartData.top_students_data;
                        }
                        
                        // 5. 试卷使用情况
                        const paperLabels = newChartData.paper_usage_data.map(item => item.title);
                        const paperData = newChartData.paper_usage_data.map(item => item.exam_count);
                        if (updateChart(window.chartInstances.paperChart, paperLabels, paperData, 'bar')) {
                            hasChanges = true;
                            currentChartData.paper_usage_data = newChartData.paper_usage_data;
                        }
                    }
                })
                .catch(error => {
                    console.error('刷新统计数据时出错:', error);
                });
        }
        
        // 设置定时器（秒转换为毫秒），等待图表初始化完成后再开始
        if (refreshIntervalSeconds > 0) {
            const refreshIntervalMs = refreshIntervalSeconds * 1000;
            // 等待图表初始化完成后再开始定时刷新
            const startRefreshTimer = function() {
                if (window.chartInstances && Object.keys(window.chartInstances).length > 0) {
                    setInterval(refreshStats, refreshIntervalMs);
                } else {
                    setTimeout(startRefreshTimer, 500);
                }
            };
            setTimeout(startRefreshTimer, 2000); // 给足够的时间让图表初始化
        }
        
        }); // DOMContentLoaded结束
    </script>
</body>
</html>

