<?php
/**
 * 无活动提醒功能
 * 当用户无活动时，在页面正中显示随机消息和动画提醒
 * 提醒时间由后台参数设置中的"学生发呆提醒时间"决定
 */

// 确保数据库连接存在
if (!isset($pdo)) {
    require_once __DIR__ . '/db.inc.php';
}

// 从数据库获取提醒时间（分钟），默认为5分钟
$reminder_minutes = 5;
if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'inactivity_reminder_minutes'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $reminder_minutes = intval($result['setting_value']);
            // 确保值在合理范围内（1-60分钟）
            if ($reminder_minutes < 1) $reminder_minutes = 1;
            if ($reminder_minutes > 60) $reminder_minutes = 60;
        }
    } catch (Exception $e) {
        // 如果查询失败，使用默认值5分钟
        $reminder_minutes = 5;
    }
}

// 转换为毫秒
$reminder_milliseconds = $reminder_minutes * 60 * 1000;
?>
<script>
    // 无活动提醒功能（所有前台页面）
    (function() {
        let inactivityTimer = null;
        const INACTIVITY_TIME = <?php echo $reminder_milliseconds; ?>; // <?php echo $reminder_minutes; ?>分钟（毫秒）
        let isShowingReminder = false;
        
        // 幽默提醒消息数组（超搞笑玩梗版）
        const inactivityReminders = [
            { emoji: '😴', text: '兄弟，你是在思考人生还是睡着了？', color: '#667eea' },
            { emoji: '🤔', text: '系统检测到：你的大脑CPU已停止运行', color: '#764ba2' },
            { emoji: '👀', text: '别装了，我知道你在摸鱼！', color: '#f093fb' },
            { emoji: '🎯', text: '卷王模式已关闭，请重新启动！', color: '#f5576c' },
            { emoji: '⚡', text: '警告：检测到静止状态，疑似挂机！', color: '#4facfe' },
            { emoji: '🚀', text: '时间都去哪了？题目还在等你呢！', color: '#43e9b' },
            { emoji: '💪', text: '别摆烂了，起来卷！', color: '#38f9d7' },
            { emoji: '🎪', text: '系统：你不动，我不动，题目很尴尬', color: '#fa709a' },
            { emoji: '🌟', text: '再不动，你的绩点要开始摆烂了', color: '#fee140' },
            { emoji: '🔥', text: '系统很担心你，是不是网卡了？', color: '#ff6a88' },
            { emoji: '🎨', text: '页面都等累了，你还在等什么？', color: '#ffc796' },
            { emoji: '🌈', text: '别让时间白白溜走，题目会伤心的', color: '#30cfd0' },
            { emoji: '🎭', text: '系统在呼唤你：兄弟，该回来了！', color: '#330867' },
            { emoji: '🦄', text: '再不动，你的学习进度要寄了', color: '#8e2de2' },
            { emoji: '🎈', text: '别发呆，卷起来！卷王从不休息！', color: '#4a00e0' },
            { emoji: '🎊', text: '题目：我等你等得好辛苦', color: '#00c9ff' },
            { emoji: '🎁', text: '时间不等人，但题目在等你', color: '#92fe9d' },
            { emoji: '🎉', text: '别停下，胜利就在前方！冲鸭！', color: '#ffeaa7' },
            { emoji: '🎯', text: '专注刷题，成就更好的自己！奥利给！', color: '#ff8a80' },
            { emoji: '💡', text: '灵感来了？快回来继续卷！', color: '#ea4c89' },
            { emoji: '🌙', text: '月亮都看不下去了：你倒是动一下啊', color: '#8e2de2' },
            { emoji: '⭐', text: '星星在为你加油：别摆烂，继续卷！', color: '#4a00e0' },
            { emoji: '☀️', text: '阳光正好，正是卷题的好时候！', color: '#00c9ff' },
            { emoji: '🌊', text: '像海浪一样，永不停歇地卷！', color: '#92fe9d' },
            { emoji: '🌺', text: '花开正当时，刷题正当时！', color: '#ffeaa7' },
            { emoji: '🦖', text: '恐龙都灭绝了，你还在发呆？', color: '#ff6a88' },
            { emoji: '🐌', text: '蜗牛都比你快，快动起来！', color: '#f5576c' },
            { emoji: '🦀', text: '螃蟹都横着走了，你还在静止？', color: '#4facfe' },
            { emoji: '🐢', text: '乌龟都比你积极，快回来刷题！', color: '#43e97b' },
            { emoji: '🦉', text: '猫头鹰都醒了，你还在睡？', color: '#764ba2' }
        ];
        
        // 随机动画类型
        const animationTypes = ['bounce', 'wave', 'rotate', 'scale', 'glow', 'shake', 'pulse', 'swing', 'flip', 'zoom'];
        
        // 生成随机动画CSS
        function generateRandomAnimation(animType) {
            const animations = {
                bounce: 'reminderBounce',
                wave: 'reminderWave',
                rotate: 'reminderRotate',
                scale: 'reminderScale',
                glow: 'reminderGlow',
                shake: 'reminderShake',
                pulse: 'reminderPulse',
                swing: 'reminderSwing',
                flip: 'reminderFlip',
                zoom: 'reminderZoom'
            };
            return animations[animType] || 'reminderBounce';
        }
        
        // 关闭提醒
        function closeReminder(reminderEl) {
            if (reminderEl && reminderEl.parentNode) {
                reminderEl.style.animation = 'reminderFadeOut 0.5s ease forwards';
                setTimeout(() => {
                    if (reminderEl.parentNode) {
                        reminderEl.remove();
                    }
                    isShowingReminder = false;
                    resetTimer();
                }, 500);
            }
        }
        
        // 显示提醒
        function showInactivityReminder() {
            if (isShowingReminder) return;
            isShowingReminder = true;
            
            // 随机选择提醒消息
            const reminder = inactivityReminders[Math.floor(Math.random() * inactivityReminders.length)];
            // 随机选择动画类型
            const animType = animationTypes[Math.floor(Math.random() * animationTypes.length)];
            const animName = generateRandomAnimation(animType);
            
            // 创建提醒元素
            const reminderEl = document.createElement('div');
            reminderEl.id = 'inactivity-reminder';
            reminderEl.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: linear-gradient(135deg, ${reminder.color}15 0%, ${reminder.color}30 100%);
                border: 4px solid ${reminder.color};
                border-radius: 30px;
                padding: 40px 60px;
                box-shadow: 0 20px 60px ${reminder.color}50, 0 0 40px ${reminder.color}30;
                z-index: 999999;
                text-align: center;
                font-size: 28px;
                font-weight: 700;
                color: ${reminder.color};
                animation: reminderPopIn 0.5s ease;
                min-width: 400px;
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            `;
            
            // 创建确认按钮
            const confirmBtn = document.createElement('button');
            confirmBtn.textContent = '我知道了';
            confirmBtn.style.cssText = `
                margin-top: 25px;
                padding: 12px 40px;
                font-size: 18px;
                font-weight: 600;
                color: white;
                background: linear-gradient(135deg, ${reminder.color} 0%, ${reminder.color}dd 100%);
                border: none;
                border-radius: 25px;
                cursor: pointer;
                box-shadow: 0 4px 15px ${reminder.color}50;
                transition: all 0.3s ease;
                outline: none;
            `;
            
            // 按钮悬停效果
            confirmBtn.onmouseenter = function() {
                this.style.transform = 'scale(1.05)';
                this.style.boxShadow = `0 6px 20px ${reminder.color}70`;
            };
            confirmBtn.onmouseleave = function() {
                this.style.transform = 'scale(1)';
                this.style.boxShadow = `0 4px 15px ${reminder.color}50`;
            };
            
            // 点击确认按钮关闭提醒
            confirmBtn.onclick = function() {
                closeReminder(reminderEl);
            };
            
            reminderEl.innerHTML = `
                <div style="font-size: 80px; margin-bottom: 20px; animation: ${animName} 1s ease-in-out infinite;">${reminder.emoji}</div>
                <div style="font-size: 24px; line-height: 1.5; margin-bottom: 10px;">${reminder.text}</div>
            `;
            
            reminderEl.appendChild(confirmBtn);
            
            // 添加动画样式
            if (!document.getElementById('inactivity-reminder-style')) {
                const style = document.createElement('style');
                style.id = 'inactivity-reminder-style';
                style.textContent = `
                    @keyframes reminderPopIn {
                        0% { transform: translate(-50%, -50%) scale(0.3); opacity: 0; }
                        50% { transform: translate(-50%, -50%) scale(1.15); }
                        100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
                    }
                    @keyframes reminderBounce {
                        0%, 100% { transform: translateY(0) scale(1); }
                        50% { transform: translateY(-15px) scale(1.1); }
                    }
                    @keyframes reminderWave {
                        0%, 100% { transform: translateY(0) rotate(0deg); }
                        25% { transform: translateY(-10px) rotate(-10deg); }
                        50% { transform: translateY(-5px) rotate(10deg); }
                        75% { transform: translateY(-10px) rotate(-5deg); }
                    }
                    @keyframes reminderRotate {
                        0% { transform: rotate(0deg) scale(1); }
                        100% { transform: rotate(360deg) scale(1.2); }
                    }
                    @keyframes reminderScale {
                        0%, 100% { transform: scale(1); }
                        50% { transform: scale(1.3); }
                    }
                    @keyframes reminderGlow {
                        0%, 100% { transform: scale(1); filter: drop-shadow(0 0 5px currentColor); }
                        50% { transform: scale(1.2); filter: drop-shadow(0 0 20px currentColor); }
                    }
                    @keyframes reminderShake {
                        0%, 100% { transform: translateX(0) rotate(0deg); }
                        25% { transform: translateX(-8px) rotate(-10deg); }
                        75% { transform: translateX(8px) rotate(10deg); }
                    }
                    @keyframes reminderPulse {
                        0%, 100% { transform: scale(1); opacity: 1; }
                        50% { transform: scale(1.4); opacity: 0.8; }
                    }
                    @keyframes reminderSwing {
                        0%, 100% { transform: rotate(0deg) translateY(0); }
                        50% { transform: rotate(20deg) translateY(-12px); }
                    }
                    @keyframes reminderFlip {
                        0% { transform: rotateY(0deg) scale(1); }
                        50% { transform: rotateY(180deg) scale(1.3); }
                        100% { transform: rotateY(360deg) scale(1); }
                    }
                    @keyframes reminderZoom {
                        0%, 100% { transform: scale(1); }
                        50% { transform: scale(1.4); }
                    }
                    @keyframes reminderFadeOut {
                        from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                        to { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(reminderEl);
        }
        
        // 重置定时器
        function resetTimer() {
            if (inactivityTimer) {
                clearTimeout(inactivityTimer);
            }
            inactivityTimer = setTimeout(showInactivityReminder, INACTIVITY_TIME);
        }
        
        // 将提醒函数暴露到全局，方便在浏览器控制台手动测试
        if (typeof window !== 'undefined') {
            window.showInactivityReminder = showInactivityReminder;
        }
        
        // 用户活动事件
        const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'keydown'];
        
        activityEvents.forEach(event => {
            document.addEventListener(event, () => {
                if (!isShowingReminder) {
                    resetTimer();
                }
            }, { passive: true });
        });
        
        // 初始化定时器
        resetTimer();
    })();
</script>

