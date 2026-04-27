<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();

// 使用统一的函数获取随机物品（避免重复代码）
$selected_item = getRandomItem();
$item_name = $selected_item['name'];
$item_unit = $selected_item['unit'];
$item_emoji = $selected_item['emoji'];

// 随机颜色数组（用于动画特效）
$color_palettes = [
    ['#667eea', '#764ba2', '#f093fb'], // 紫色渐变
    ['#f093fb', '#f5576c', '#4facfe'], // 粉红到蓝色
    ['#43e97b', '#38f9d7', '#fa709a'], // 绿色到粉红
    ['#fa709a', '#fee140', '#30cfd0'], // 粉红到黄色到青色
    ['#30cfd0', '#330867', '#ff6a88'], // 青色到紫色到粉红
    ['#ff6a88', '#ffc796', '#4facfe'], // 粉红到橙色到蓝色
    ['#4facfe', '#00f2fe', '#43e97b'], // 蓝色到青色到绿色
    ['#43e97b', '#38f9d7', '#667eea'], // 绿色到青色到紫色
    ['#667eea', '#764ba2', '#f093fb', '#f5576c'], // 四色渐变
    ['#ff9a9e', '#fecfef', '#fecfef', '#ffc796'], // 粉红渐变
    ['#a8edea', '#fed6e3', '#ffecd2'], // 青色到粉红到黄色
    ['#ffecd2', '#fcb69f', '#ff8a80'], // 黄色到橙色到红色
    ['#ff8a80', '#ea4c89', '#8e2de2'], // 红色到粉红到紫色
    ['#8e2de2', '#4a00e0', '#00c9ff'], // 紫色到蓝色到青色
    ['#00c9ff', '#92fe9d', '#ffeaa7'], // 青色到绿色到黄色
];

// 随机选择一个颜色方案
$selected_colors = $color_palettes[array_rand($color_palettes)];

// 动画效果类型（每次随机选择一种）
$animation_types = ['bounce', 'wave', 'rotate', 'scale', 'glow', 'shake', 'pulse', 'swing', 'flip', 'zoom'];
$selected_animation = $animation_types[array_rand($animation_types)];

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_no = trim($_POST['student_no'] ?? '');
    
    if (!empty($student_no)) {
        // 检查学生是否存在
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_no = ?");
        $stmt->execute([$student_no]);
        $student = $stmt->fetch();
        
        if (!$student) {
            // 学生信息不存在，提示错误并返回登录界面
            $error = '学生信息不存在，请确认学号是否正确！';
        } else {
            // 学生存在，保存信息到session并跳转
            $student_id = $student['id'];
            $student_name = $student['name'] ?? null;
            $student_class = $student['class'] ?? null;
        
        $_SESSION['student_id'] = $student_id;
        $_SESSION['student_no'] = $student_no;
            $_SESSION['student_name'] = $student_name;
            $_SESSION['student_class'] = $student_class;
            
            // 保存登录时生成的标题信息（用于所有前台页面）
            $_SESSION['site_title'] = "刷啊刷刷" . $item_unit . $item_name;
            $_SESSION['site_emoji'] = $item_emoji;
            
        header('Location: exam_list.php');
        exit;
        }
    } else {
        $error = '请输入学号！';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>刷啊刷刷<?php echo $item_unit . $item_name; ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Microsoft YaHei', 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }
        
        /* 背景动画 */
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* 背景装饰元素 */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(255,255,255,0.05) 0%, transparent 50%);
            animation: float 20s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 45px 40px;
            border-radius: 20px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.5) inset;
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.35),
                0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }
        
        .login-header .icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .login-header .icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }
        
        h1 {
            font-size: 32px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            letter-spacing: 1px;
            font-family: 'Comic Sans MS', 'Microsoft YaHei', 'PingFang SC', cursive, sans-serif;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
        }
        .char-anim {
            display: inline-block;
            animation: charAnim<?php echo ucfirst($selected_animation); ?> 1.5s ease-in-out infinite;
            transform-origin: center bottom;
        }
        .emoji-anim {
            display: inline-block;
            font-size: 1.2em;
            animation: emojiAnim<?php echo ucfirst($selected_animation); ?> 1.5s ease-in-out infinite;
            transform-origin: center center;
        }
        
        <?php
        // 根据选择的动画类型生成对应的CSS
        $color1 = $selected_colors[0];
        $color2 = $selected_colors[1] ?? $selected_colors[0];
        $color3 = $selected_colors[2] ?? $selected_colors[0];
        
        switch ($selected_animation) {
            case 'bounce':
                echo "@keyframes charAnimBounce {
                    0%, 100% { transform: translateY(0) rotate(0deg) scale(1); color: {$color1}; }
                    25% { transform: translateY(-8px) rotate(-5deg) scale(1.1); color: {$color2}; }
                    50% { transform: translateY(-12px) rotate(5deg) scale(1.15); color: {$color3}; }
                    75% { transform: translateY(-8px) rotate(-3deg) scale(1.1); color: {$color2}; }
                }
                @keyframes emojiAnimBounce {
                    0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
                    50% { transform: translateY(-15px) rotate(10deg) scale(1.2); }
                }";
                break;
            case 'wave':
                echo "@keyframes charAnimWave {
                    0%, 100% { transform: translateY(0) rotate(0deg); color: {$color1}; }
                    25% { transform: translateY(-10px) rotate(-10deg); color: {$color2}; }
                    50% { transform: translateY(-5px) rotate(10deg); color: {$color3}; }
                    75% { transform: translateY(-10px) rotate(-5deg); color: {$color2}; }
                }
                @keyframes emojiAnimWave {
                    0%, 100% { transform: translateY(0) rotate(0deg); }
                    50% { transform: translateY(-12px) rotate(15deg); }
                }";
                break;
            case 'rotate':
                echo "@keyframes charAnimRotate {
                    0% { transform: rotate(0deg) scale(1); color: {$color1}; }
                    25% { transform: rotate(90deg) scale(1.1); color: {$color2}; }
                    50% { transform: rotate(180deg) scale(1.2); color: {$color3}; }
                    75% { transform: rotate(270deg) scale(1.1); color: {$color2}; }
                    100% { transform: rotate(360deg) scale(1); color: {$color1}; }
                }
                @keyframes emojiAnimRotate {
                    0% { transform: rotate(0deg) scale(1); }
                    100% { transform: rotate(360deg) scale(1.2); }
                }";
                break;
            case 'scale':
                echo "@keyframes charAnimScale {
                    0%, 100% { transform: scale(1) rotate(0deg); color: {$color1}; }
                    25% { transform: scale(1.2) rotate(5deg); color: {$color2}; }
                    50% { transform: scale(1.3) rotate(-5deg); color: {$color3}; }
                    75% { transform: scale(1.1) rotate(3deg); color: {$color2}; }
                }
                @keyframes emojiAnimScale {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.3); }
                }";
                break;
            case 'glow':
                echo "@keyframes charAnimGlow {
                    0%, 100% { transform: scale(1); color: {$color1}; text-shadow: 0 0 5px {$color1}, 0 0 10px {$color1}; }
                    50% { transform: scale(1.15); color: {$color3}; text-shadow: 0 0 15px {$color3}, 0 0 25px {$color3}, 0 0 35px {$color3}; }
                }
                @keyframes emojiAnimGlow {
                    0%, 100% { transform: scale(1); filter: drop-shadow(0 0 5px {$color1}); }
                    50% { transform: scale(1.3); filter: drop-shadow(0 0 15px {$color3}); }
                }";
                break;
            case 'shake':
                echo "@keyframes charAnimShake {
                    0%, 100% { transform: translateX(0) translateY(0) rotate(0deg); color: {$color1}; }
                    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px) translateY(-5px) rotate(-5deg); color: {$color2}; }
                    20%, 40%, 60%, 80% { transform: translateX(5px) translateY(5px) rotate(5deg); color: {$color3}; }
                }
                @keyframes emojiAnimShake {
                    0%, 100% { transform: translateX(0) rotate(0deg); }
                    25% { transform: translateX(-8px) rotate(-10deg); }
                    75% { transform: translateX(8px) rotate(10deg); }
                }";
                break;
            case 'pulse':
                echo "@keyframes charAnimPulse {
                    0%, 100% { transform: scale(1); color: {$color1}; opacity: 1; }
                    50% { transform: scale(1.2); color: {$color3}; opacity: 0.8; }
                }
                @keyframes emojiAnimPulse {
                    0%, 100% { transform: scale(1); opacity: 1; }
                    50% { transform: scale(1.4); opacity: 0.9; }
                }";
                break;
            case 'swing':
                echo "@keyframes charAnimSwing {
                    0%, 100% { transform: rotate(0deg) translateY(0); color: {$color1}; }
                    25% { transform: rotate(15deg) translateY(-5px); color: {$color2}; }
                    50% { transform: rotate(0deg) translateY(-10px); color: {$color3}; }
                    75% { transform: rotate(-15deg) translateY(-5px); color: {$color2}; }
                }
                @keyframes emojiAnimSwing {
                    0%, 100% { transform: rotate(0deg) translateY(0); }
                    50% { transform: rotate(20deg) translateY(-12px); }
                }";
                break;
            case 'flip':
                echo "@keyframes charAnimFlip {
                    0% { transform: rotateY(0deg) scale(1); color: {$color1}; }
                    50% { transform: rotateY(180deg) scale(1.2); color: {$color3}; }
                    100% { transform: rotateY(360deg) scale(1); color: {$color1}; }
                }
                @keyframes emojiAnimFlip {
                    0% { transform: rotateY(0deg) scale(1); }
                    50% { transform: rotateY(180deg) scale(1.3); }
                    100% { transform: rotateY(360deg) scale(1); }
                }";
                break;
            case 'zoom':
                echo "@keyframes charAnimZoom {
                    0%, 100% { transform: scale(1) translateZ(0); color: {$color1}; }
                    25% { transform: scale(1.15) translateZ(10px); color: {$color2}; }
                    50% { transform: scale(1.3) translateZ(20px); color: {$color3}; }
                    75% { transform: scale(1.15) translateZ(10px); color: {$color2}; }
                }
                @keyframes emojiAnimZoom {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.4); }
                }";
                break;
        }
        ?>
        
        .login-subtitle {
            color: #7f8c8d;
            font-size: 14px;
            font-weight: 400;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            color: #34495e;
            font-weight: 500;
            font-size: 14px;
            letter-spacing: 0.3px;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #fafafa;
            color: #2c3e50;
            font-family: inherit;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        input[type="text"]::placeholder {
            color: #bdc3c7;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.4);
        }
        
        .btn span {
            position: relative;
            z-index: 1;
        }
        
        .error {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 20px;
            padding: 14px 18px;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
            border-radius: 12px;
            font-size: 14px;
            border: 1px solid rgba(231, 76, 60, 0.2);
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        /* 响应式设计 */
        @media (max-width: 480px) {
            .login-container {
                padding: 35px 25px;
                margin: 20px;
                border-radius: 16px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .login-header .icon {
                width: 80px;
                height: 80px;
            }
        }
    </style>
    <script>
        // 幽默警告消息数组（超搞笑玩梗版）
        const funnyWarnings = [
            { emoji: '😏', text: '嘿嘿，想复制？这波操作有点小寄' },
            { emoji: '🤭', text: '偷偷摸摸的，是不是想搞事情？' },
            { emoji: '😎', text: '别白费力气了，这题得用脑子，不是Ctrl+C' },
            { emoji: '🙈', text: '我看不见，你也别想复制！懂得都懂' },
            { emoji: '🦸', text: '系统保护已启动，复制请求已拦截！' },
            { emoji: '🔒', text: '内容已加密，复制无效，这波属于是白给' },
            { emoji: '🎭', text: '此路不通，请走正门！别整这些花活儿' },
            { emoji: '🚫', text: '禁止操作！专心学习才是王道！别摆烂' },
            { emoji: '💪', text: '靠实力刷题，不靠复制！卷起来！' },
            { emoji: '🎯', text: '想作弊？系统第一个不答应！这波寄了' },
            { emoji: '😤', text: '哼！想复制？门都没有！别想了' },
            { emoji: '🤖', text: 'AI监控中，禁止复制操作！已被标记' },
            { emoji: '🛡️', text: '防护盾已开启，复制被拦截！这波稳了' },
            { emoji: '⚡', text: '电击警告！禁止复制！再试就寄了' },
            { emoji: '🎪', text: '这里是学习马戏团，不是复制工厂！' },
            { emoji: '🐱', text: '小猫说：不可以复制哦~要用脑子' },
            { emoji: '🦉', text: '猫头鹰盯着你呢，别想复制！' },
            { emoji: '🌙', text: '月亮代表系统，禁止复制！别整活儿' },
            { emoji: '⭐', text: '星星在看着你，老实刷题吧！别摆烂' },
            { emoji: '🔥', text: '系统很生气，后果很严重！这波要寄' },
            { emoji: '🦀', text: '螃蟹都横着走了，你还想复制？' },
            { emoji: '🐌', text: '蜗牛都比你快，快用脑子刷题！' },
            { emoji: '🦖', text: '恐龙都灭绝了，你还在想复制？' }
        ];
        
        // 显示幽默警告
        function showFunnyWarning() {
            const warning = funnyWarnings[Math.floor(Math.random() * funnyWarnings.length)];
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
                border: 3px solid #ffc107;
                border-radius: 20px;
                padding: 30px 40px;
                box-shadow: 0 10px 40px rgba(255, 193, 7, 0.5);
                z-index: 99999;
                text-align: center;
                font-size: 20px;
                font-weight: 600;
                color: #856404;
                animation: popIn 0.3s ease, fadeOut 0.3s ease 2s forwards;
                min-width: 300px;
            `;
            toast.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 15px;">${warning.emoji}</div>
                <div>${warning.text}</div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 2300);
        }
        
        // 添加动画样式
        const style = document.createElement('style');
        style.textContent = `
            @keyframes popIn {
                0% { transform: translate(-50%, -50%) scale(0.5); opacity: 0; }
                50% { transform: translate(-50%, -50%) scale(1.1); }
                100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                to { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            }
        `;
        document.head.appendChild(style);
        
        // 禁止复制功能
        document.addEventListener('DOMContentLoaded', function() {
            // 禁用右键菜单
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                showFunnyWarning();
                return false;
            });
            
            // 禁用复制快捷键 (Ctrl+C, Ctrl+A, Ctrl+V, Ctrl+X, Ctrl+S)
            document.addEventListener('keydown', function(e) {
                // Ctrl+C, Ctrl+A, Ctrl+V, Ctrl+X, Ctrl+S
                if (e.ctrlKey && (e.keyCode === 67 || e.keyCode === 65 || e.keyCode === 86 || e.keyCode === 88 || e.keyCode === 83)) {
                    e.preventDefault();
                    showFunnyWarning();
                    return false;
                }
                // F12 (开发者工具)
                if (e.keyCode === 123) {
                    e.preventDefault();
                    showFunnyWarning();
                    return false;
                }
                // Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U (查看源代码)
                if (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) {
                    e.preventDefault();
                    showFunnyWarning();
                    return false;
                }
                if (e.ctrlKey && e.keyCode === 85) {
                    e.preventDefault();
                    showFunnyWarning();
                    return false;
                }
            });
            
            // 禁用文本选择
            document.onselectstart = function() {
                showFunnyWarning();
                return false;
            };
            
            // 禁用拖拽
            document.ondragstart = function() {
                showFunnyWarning();
                return false;
            };
        });
        
        // 每分钟自动更新标题（仅在未登录时）
        <?php if (!isset($_SESSION['student_id'])): ?>
        (function() {
            // 物品列表数据
            const items = <?php echo json_encode($random_items, JSON_UNESCAPED_UNICODE); ?>;
            
            // 颜色方案
            const colorPalettes = <?php echo json_encode($color_palettes); ?>;
            
            // 动画类型
            const animationTypes = <?php echo json_encode($animation_types); ?>;
            
            // 生成动画CSS
            function generateAnimationCSS(animType, colors) {
                const color1 = colors[0];
                const color2 = colors[1] || colors[0];
                const color3 = colors[2] || colors[0];
                
                const animations = {
                    bounce: `
                        @keyframes charAnimBounce {
                            0%, 100% { transform: translateY(0) rotate(0deg) scale(1); color: ${color1}; }
                            25% { transform: translateY(-8px) rotate(-5deg) scale(1.1); color: ${color2}; }
                            50% { transform: translateY(-12px) rotate(5deg) scale(1.15); color: ${color3}; }
                            75% { transform: translateY(-8px) rotate(-3deg) scale(1.1); color: ${color2}; }
                        }
                        @keyframes emojiAnimBounce {
                            0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
                            50% { transform: translateY(-15px) rotate(10deg) scale(1.2); }
                        }`,
                    wave: `
                        @keyframes charAnimWave {
                            0%, 100% { transform: translateY(0) rotate(0deg); color: ${color1}; }
                            25% { transform: translateY(-10px) rotate(-10deg); color: ${color2}; }
                            50% { transform: translateY(-5px) rotate(10deg); color: ${color3}; }
                            75% { transform: translateY(-10px) rotate(-5deg); color: ${color2}; }
                        }
                        @keyframes emojiAnimWave {
                            0%, 100% { transform: translateY(0) rotate(0deg); }
                            50% { transform: translateY(-12px) rotate(15deg); }
                        }`,
                    rotate: `
                        @keyframes charAnimRotate {
                            0% { transform: rotate(0deg) scale(1); color: ${color1}; }
                            25% { transform: rotate(90deg) scale(1.1); color: ${color2}; }
                            50% { transform: rotate(180deg) scale(1.2); color: ${color3}; }
                            75% { transform: rotate(270deg) scale(1.1); color: ${color2}; }
                            100% { transform: rotate(360deg) scale(1); color: ${color1}; }
                        }
                        @keyframes emojiAnimRotate {
                            0% { transform: rotate(0deg) scale(1); }
                            100% { transform: rotate(360deg) scale(1.2); }
                        }`,
                    scale: `
                        @keyframes charAnimScale {
                            0%, 100% { transform: scale(1) rotate(0deg); color: ${color1}; }
                            25% { transform: scale(1.2) rotate(5deg); color: ${color2}; }
                            50% { transform: scale(1.3) rotate(-5deg); color: ${color3}; }
                            75% { transform: scale(1.1) rotate(3deg); color: ${color2}; }
                        }
                        @keyframes emojiAnimScale {
                            0%, 100% { transform: scale(1); }
                            50% { transform: scale(1.3); }
                        }`,
                    glow: `
                        @keyframes charAnimGlow {
                            0%, 100% { transform: scale(1); color: ${color1}; text-shadow: 0 0 5px ${color1}, 0 0 10px ${color1}; }
                            50% { transform: scale(1.15); color: ${color3}; text-shadow: 0 0 15px ${color3}, 0 0 25px ${color3}, 0 0 35px ${color3}; }
                        }
                        @keyframes emojiAnimGlow {
                            0%, 100% { transform: scale(1); filter: drop-shadow(0 0 5px ${color1}); }
                            50% { transform: scale(1.3); filter: drop-shadow(0 0 15px ${color3}); }
                        }`,
                    shake: `
                        @keyframes charAnimShake {
                            0%, 100% { transform: translateX(0) translateY(0) rotate(0deg); color: ${color1}; }
                            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px) translateY(-5px) rotate(-5deg); color: ${color2}; }
                            20%, 40%, 60%, 80% { transform: translateX(5px) translateY(5px) rotate(5deg); color: ${color3}; }
                        }
                        @keyframes emojiAnimShake {
                            0%, 100% { transform: translateX(0) rotate(0deg); }
                            25% { transform: translateX(-8px) rotate(-10deg); }
                            75% { transform: translateX(8px) rotate(10deg); }
                        }`,
                    pulse: `
                        @keyframes charAnimPulse {
                            0%, 100% { transform: scale(1); color: ${color1}; opacity: 1; }
                            50% { transform: scale(1.2); color: ${color3}; opacity: 0.8; }
                        }
                        @keyframes emojiAnimPulse {
                            0%, 100% { transform: scale(1); opacity: 1; }
                            50% { transform: scale(1.4); opacity: 0.9; }
                        }`,
                    swing: `
                        @keyframes charAnimSwing {
                            0%, 100% { transform: rotate(0deg) translateY(0); color: ${color1}; }
                            25% { transform: rotate(15deg) translateY(-5px); color: ${color2}; }
                            50% { transform: rotate(0deg) translateY(-10px); color: ${color3}; }
                            75% { transform: rotate(-15deg) translateY(-5px); color: ${color2}; }
                        }
                        @keyframes emojiAnimSwing {
                            0%, 100% { transform: rotate(0deg) translateY(0); }
                            50% { transform: rotate(20deg) translateY(-12px); }
                        }`,
                    flip: `
                        @keyframes charAnimFlip {
                            0% { transform: rotateY(0deg) scale(1); color: ${color1}; }
                            50% { transform: rotateY(180deg) scale(1.2); color: ${color3}; }
                            100% { transform: rotateY(360deg) scale(1); color: ${color1}; }
                        }
                        @keyframes emojiAnimFlip {
                            0% { transform: rotateY(0deg) scale(1); }
                            50% { transform: rotateY(180deg) scale(1.3); }
                            100% { transform: rotateY(360deg) scale(1); }
                        }`,
                    zoom: `
                        @keyframes charAnimZoom {
                            0%, 100% { transform: scale(1) translateZ(0); color: ${color1}; }
                            25% { transform: scale(1.15) translateZ(10px); color: ${color2}; }
                            50% { transform: scale(1.3) translateZ(20px); color: ${color3}; }
                            75% { transform: scale(1.15) translateZ(10px); color: ${color2}; }
                        }
                        @keyframes emojiAnimZoom {
                            0%, 100% { transform: scale(1); }
                            50% { transform: scale(1.4); }
                        }`
                };
                
                return animations[animType] || animations.bounce;
            }
            
            // 更新标题
            function updateTitle() {
                // 随机选择物品
                const item = items[Math.floor(Math.random() * items.length)];
                const text = "刷啊刷刷" + item.unit + item.name;
                
                // 随机选择颜色方案
                const colors = colorPalettes[Math.floor(Math.random() * colorPalettes.length)];
                
                // 随机选择动画类型
                const animType = animationTypes[Math.floor(Math.random() * animationTypes.length)];
                
                // 更新页面标题
                document.title = "刷啊刷刷" + item.unit + item.name;
                
                // 更新alt属性
                const logoImg = document.querySelector('.icon img');
                if (logoImg) {
                    logoImg.alt = text;
                }
                
                // 更新h1内容
                const h1 = document.querySelector('.login-header h1');
                if (h1) {
                    const chars = Array.from(text);
                    let html = '';
                    let delay = 0;
                    chars.forEach(char => {
                        html += `<span class="char-anim" style="animation-delay: ${delay}s;">${char}</span>`;
                        delay += 0.1;
                    });
                    html += `<span class="emoji-anim" style="animation-delay: ${delay}s; margin-left: 8px;">${item.emoji}</span>`;
                    h1.innerHTML = html;
                }
                
                // 更新动画CSS
                const styleId = 'dynamic-animation-style';
                let styleEl = document.getElementById(styleId);
                if (!styleEl) {
                    styleEl = document.createElement('style');
                    styleEl.id = styleId;
                    document.head.appendChild(styleEl);
                }
                
                const css = `
                    .char-anim {
                        display: inline-block;
                        animation: charAnim${animType.charAt(0).toUpperCase() + animType.slice(1)} 1.5s ease-in-out infinite;
                        transform-origin: center bottom;
                    }
                    .emoji-anim {
                        display: inline-block;
                        font-size: 1.2em;
                        animation: emojiAnim${animType.charAt(0).toUpperCase() + animType.slice(1)} 1.5s ease-in-out infinite;
                        transform-origin: center center;
                    }
                    ${generateAnimationCSS(animType, colors)}
                `;
                styleEl.textContent = css;
            }
            
            // 立即执行一次（在页面加载后）
            setTimeout(updateTitle, 100);
            
            // 每60秒执行一次
            setInterval(updateTitle, 60000);
        })();
        <?php endif; ?>
    </script>
    <?php include 'inc/inactivity_reminder.inc.php'; ?>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="icon">
                <img src="images/logo-detailed.svg" alt="刷啊刷刷<?php echo $item_unit . $item_name; ?>">
            </div>
            <h1>
                <?php
                $text = "刷啊刷刷" . $item_unit . $item_name;
                // 使用preg_split分割UTF-8字符（兼容PHP 7.0+）
                $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
                $delay = 0;
                foreach ($chars as $char) {
                    echo '<span class="char-anim" style="animation-delay: ' . $delay . 's;">' . escape($char) . '</span>';
                    $delay += 0.1;
                }
                // 添加emoji，使用与文字相同的动画延迟
                echo '<span class="emoji-anim" style="animation-delay: ' . $delay . 's; margin-left: 8px;">' . $item_emoji . '</span>';
                ?>
            </h1>
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="student_no">请输入学号</label>
                <input type="text" id="student_no" name="student_no" required placeholder="请输入您的学号">
            </div>
            <button type="submit" class="btn">
                <span>开始刷题 →</span>
            </button>
        </form>
    </div>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

