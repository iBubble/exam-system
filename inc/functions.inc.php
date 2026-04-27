<?php
// 通用函数文件

// 启动学生会话（前台使用）
function startStudentSession() {
    $target_session_name = 'student_session';
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        // 如果session已经启动，检查session名称是否正确
        if (session_name() === $target_session_name) {
            // 已经是正确的session，不需要做任何事
            return;
        } else {
            // 关闭当前的session（可能是不正确的session）
            session_write_close();
        }
    }
    
    // 设置学生session名称（必须在session_start之前）
    session_name($target_session_name);
    // 设置会话安全参数
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    // 设置session超时时间为60分钟（3600秒）
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_lifetime', 3600);
    session_start();
}

// 启动管理员会话（后台使用）
function startAdminSession() {
    $target_session_name = 'admin_session';
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        // 如果session已经启动，检查session名称是否正确
        if (session_name() === $target_session_name) {
            // 已经是正确的session，不需要做任何事
            return;
        } else {
            // 关闭当前的session（可能是不正确的session）
            session_write_close();
        }
    }
    
    // 设置管理员session名称（必须在session_start之前）
    session_name($target_session_name);
    // 设置会话安全参数
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    // 设置session超时时间为60分钟（3600秒）
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_lifetime', 3600);
    session_start();
}

// 检查管理员登录
function checkAdminLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: /admin/login.php');
        exit;
    }
}

// 检查学生登录
function checkStudentLogin() {
    if (!isset($_SESSION['student_id'])) {
        header('Location: /index.php');
        exit;
    }
}

// 格式化日期时间
function formatDateTime($datetime) {
    if (empty($datetime)) {
        return '-';
    }
    $timestamp = strtotime($datetime);
    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '-';
}

// 安全输出（防止XSS）
function escape($string) {
    if ($string === null) {
        return '';
    }
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// 安全整数转换
function safeInt($value, $default = 0) {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => $default, 'min_range' => 0]]);
}

// 安全字符串过滤
function safeString($value, $maxLength = 0) {
    $value = trim((string)$value);
    if ($maxLength > 0 && mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return $value;
}

// 获取客户端IP（用于操作日志）
function getClientIp(): string {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = explode(',', $_SERVER[$key])[0];
            return trim($ip);
        }
    }
    return 'UNKNOWN';
}

// 确保管理员日志表存在
function ensureAdminLogTable(PDO $pdo) {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `admin_id` INT(11) DEFAULT NULL,
            `username` VARCHAR(100) DEFAULT '',
            `action` VARCHAR(255) NOT NULL,
            `detail` TEXT,
            `ip` VARCHAR(64) DEFAULT '',
            `result` VARCHAR(50) DEFAULT '',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin_id` (`admin_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $checked = true;
}

// 记录管理员操作日志
function logAdminAction(PDO $pdo, string $action, string $result = 'success', string $detail = ''): void {
    ensureAdminLogTable($pdo);
    $admin_id = $_SESSION['admin_id'] ?? null;
    $username = $_SESSION['admin_username'] ?? '';
    $ip = getClientIp();
    $action = mb_substr($action, 0, 250, 'UTF-8');
    $detail = mb_substr($detail, 0, 2000, 'UTF-8');
    $result = mb_substr($result, 0, 50, 'UTF-8');
    $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, username, action, detail, ip, result) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$admin_id, $username, $action, $detail, $ip, $result]);
}

// 确保试卷表存在起止时间与暂停字段（若缺失则自动补充，兼容旧库）
function ensurePaperScheduleColumns(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $columns = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM papers");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[$col['Field']] = true;
        }

        if (!isset($columns['start_time'])) {
            $pdo->exec("ALTER TABLE papers ADD COLUMN start_time DATETIME NULL DEFAULT NULL AFTER duration");
        }
        if (!isset($columns['end_time'])) {
            $pdo->exec("ALTER TABLE papers ADD COLUMN end_time DATETIME NULL DEFAULT NULL AFTER start_time");
        }
        if (!isset($columns['is_paused'])) {
            $pdo->exec("ALTER TABLE papers ADD COLUMN is_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER end_time");
        }
    } catch (Exception $e) {
        // 静默处理，避免无权限或已存在时报错影响业务
    }
    $checked = true;
}

// 判断试卷是否处于可用状态（未暂停且在起止时间范围内）
function getPaperActiveState(array $paper): array {
    $now = new DateTimeImmutable('now');
    $is_paused = isset($paper['is_paused']) ? (int)$paper['is_paused'] : 0;
    if ($is_paused === 1) {
        return ['active' => false, 'reason' => '已暂停'];
    }
    if (!empty($paper['start_time'])) {
        try {
            $start = new DateTimeImmutable($paper['start_time']);
            if ($now < $start) {
                return ['active' => false, 'reason' => '未开始'];
            }
        } catch (Exception $e) {
            // 忽略格式异常，默认放行
        }
    }
    if (!empty($paper['end_time'])) {
        try {
            $end = new DateTimeImmutable($paper['end_time']);
            if ($now > $end) {
                return ['active' => false, 'reason' => '已结束'];
            }
        } catch (Exception $e) {
            // 忽略格式异常，默认放行
        }
    }
    return ['active' => true, 'reason' => ''];
}

// 获取当前可用科目ID列表（存在开启中的试卷）
function getActiveSubjectIds(PDO $pdo): array {
    ensurePaperScheduleColumns($pdo);
    $stmt = $pdo->prepare("
        SELECT DISTINCT subject_id 
        FROM papers 
        WHERE (is_paused = 0 OR is_paused IS NULL)
          AND (start_time IS NULL OR start_time <= NOW())
          AND (end_time IS NULL OR end_time >= NOW())
    ");
    $stmt->execute();
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// 获取当前页面的随机物品（确保标题和emoji使用同一个物品）
function getCurrentPageRandomItem() {
    static $item = null;
    if ($item === null) {
        $item = getRandomItem();
    }
    return $item;
}

// 获取网站标题和emoji（用于前台页面，每次页面加载时随机生成，像后台一样）
function getSiteTitle() {
    $item = getCurrentPageRandomItem();
    return '刷啊刷刷' . $item['unit'] . $item['name'];
}

function getSiteEmoji() {
    $item = getCurrentPageRandomItem();
    return $item['emoji'];
}

// 获取随机物品数据（统一数据源，避免重复）
function getRandomItem() {
    static $random_items = null;
    
    if ($random_items === null) {
        $random_items = [
            ['name' => '大南瓜', 'unit' => '个', 'emoji' => '🎃'],
            ['name' => '小西瓜', 'unit' => '个', 'emoji' => '🍉'],
            ['name' => '大苹果', 'unit' => '个', 'emoji' => '🍎'],
            ['name' => '小橘子', 'unit' => '个', 'emoji' => '🍊'],
            ['name' => '大草莓', 'unit' => '颗', 'emoji' => '🍓'],
            ['name' => '小番茄', 'unit' => '个', 'emoji' => '🍅'],
            ['name' => '大香蕉', 'unit' => '根', 'emoji' => '🍌'],
            ['name' => '小葡萄', 'unit' => '串', 'emoji' => '🍇'],
            ['name' => '大桃子', 'unit' => '个', 'emoji' => '🍑'],
            ['name' => '小樱桃', 'unit' => '颗', 'emoji' => '🍒'],
            ['name' => '大橙子', 'unit' => '个', 'emoji' => '🍊'],
            ['name' => '小柠檬', 'unit' => '个', 'emoji' => '🍋'],
            ['name' => '大芒果', 'unit' => '个', 'emoji' => '🥭'],
            ['name' => '小菠萝', 'unit' => '个', 'emoji' => '🍍'],
            ['name' => '大榴莲', 'unit' => '个', 'emoji' => '🫐'],
            ['name' => '小椰子', 'unit' => '个', 'emoji' => '🥥'],
            ['name' => '大白菜', 'unit' => '棵', 'emoji' => '🥬'],
            ['name' => '小萝卜', 'unit' => '根', 'emoji' => '🥕'],
            ['name' => '大土豆', 'unit' => '个', 'emoji' => '🥔'],
            ['name' => '小洋葱', 'unit' => '个', 'emoji' => '🧅'],
            ['name' => '大茄子', 'unit' => '根', 'emoji' => '🍆'],
            ['name' => '小辣椒', 'unit' => '个', 'emoji' => '🌶️'],
            ['name' => '大黄瓜', 'unit' => '根', 'emoji' => '🥒'],
            ['name' => '小豆芽', 'unit' => '把', 'emoji' => '🌱'],
            ['name' => '大蘑菇', 'unit' => '朵', 'emoji' => '🍄'],
            ['name' => '小玉米', 'unit' => '根', 'emoji' => '🌽'],
            ['name' => '大花生', 'unit' => '颗', 'emoji' => '🥜'],
            ['name' => '小豌豆', 'unit' => '颗', 'emoji' => '🫛'],
            ['name' => '大冬瓜', 'unit' => '个', 'emoji' => '🥬'],
            ['name' => '小丝瓜', 'unit' => '根', 'emoji' => '🥒'],
            ['name' => '大熊猫', 'unit' => '只', 'emoji' => '🐼'],
            ['name' => '小猫咪', 'unit' => '只', 'emoji' => '🐱'],
            ['name' => '大狗狗', 'unit' => '只', 'emoji' => '🐶'],
            ['name' => '小兔子', 'unit' => '只', 'emoji' => '🐰'],
            ['name' => '大老虎', 'unit' => '只', 'emoji' => '🐯'],
            ['name' => '小狮子', 'unit' => '只', 'emoji' => '🦁'],
            ['name' => '小企鹅', 'unit' => '只', 'emoji' => '🐧'],
            ['name' => '大鲸鱼', 'unit' => '条', 'emoji' => '🐋'],
            ['name' => '小金鱼', 'unit' => '条', 'emoji' => '🐠'],
            ['name' => '大鲨鱼', 'unit' => '条', 'emoji' => '🦈'],
            ['name' => '小海豚', 'unit' => '只', 'emoji' => '🐬'],
            ['name' => '大章鱼', 'unit' => '只', 'emoji' => '🐙'],
            ['name' => '小螃蟹', 'unit' => '只', 'emoji' => '🦀'],
            ['name' => '大龙虾', 'unit' => '只', 'emoji' => '🦞'],
            ['name' => '小海星', 'unit' => '只', 'emoji' => '⭐'],
            ['name' => '大蝴蝶', 'unit' => '只', 'emoji' => '🦋'],
            ['name' => '小蜜蜂', 'unit' => '只', 'emoji' => '🐝'],
            ['name' => '大蜻蜓', 'unit' => '只', 'emoji' => '🪰'],
            ['name' => '小蚂蚁', 'unit' => '只', 'emoji' => '🐜'],
            ['name' => '大蜘蛛', 'unit' => '只', 'emoji' => '🕷️'],
            ['name' => '小蜗牛', 'unit' => '只', 'emoji' => '🐌'],
            ['name' => '大恐龙', 'unit' => '只', 'emoji' => '🦕'],
            ['name' => '小恐龙', 'unit' => '只', 'emoji' => '🦖'],
            ['name' => '大飞机', 'unit' => '架', 'emoji' => '✈️'],
            ['name' => '小汽车', 'unit' => '辆', 'emoji' => '🚗'],
            ['name' => '大火车', 'unit' => '列', 'emoji' => '🚂'],
            ['name' => '小自行车', 'unit' => '辆', 'emoji' => '🚲'],
            ['name' => '大轮船', 'unit' => '艘', 'emoji' => '🚢'],
            ['name' => '小游艇', 'unit' => '艘', 'emoji' => '⛵'],
            ['name' => '大火箭', 'unit' => '枚', 'emoji' => '🚀'],
            ['name' => '小卫星', 'unit' => '颗', 'emoji' => '🛰️'],
            ['name' => '大星星', 'unit' => '颗', 'emoji' => '⭐'],
            ['name' => '小月亮', 'unit' => '轮', 'emoji' => '🌙'],
            ['name' => '大太阳', 'unit' => '个', 'emoji' => '☀️'],
            ['name' => '小云朵', 'unit' => '朵', 'emoji' => '☁️'],
            ['name' => '大彩虹', 'unit' => '道', 'emoji' => '🌈'],
            ['name' => '小雪花', 'unit' => '片', 'emoji' => '❄️'],
            ['name' => '大雪花', 'unit' => '片', 'emoji' => '❄️'],
            ['name' => '小石头', 'unit' => '块', 'emoji' => '🪨'],
            ['name' => '大石头', 'unit' => '块', 'emoji' => '🪨'],
            ['name' => '小贝壳', 'unit' => '个', 'emoji' => '🐚'],
            ['name' => '大贝壳', 'unit' => '个', 'emoji' => '🐚'],
            ['name' => '小珍珠', 'unit' => '颗', 'emoji' => '💎'],
            ['name' => '大钻石', 'unit' => '颗', 'emoji' => '💎'],
            ['name' => '小金币', 'unit' => '枚', 'emoji' => '🪙'],
            ['name' => '大金币', 'unit' => '枚', 'emoji' => '🪙'],
            ['name' => '小蛋糕', 'unit' => '块', 'emoji' => '🎂'],
            ['name' => '大蛋糕', 'unit' => '块', 'emoji' => '🎂'],
            ['name' => '小饼干', 'unit' => '块', 'emoji' => '🍪'],
            ['name' => '大面包', 'unit' => '个', 'emoji' => '🍞'],
            ['name' => '小糖果', 'unit' => '颗', 'emoji' => '🍬'],
            ['name' => '大糖果', 'unit' => '颗', 'emoji' => '🍭'],
            ['name' => '小冰淇淋', 'unit' => '个', 'emoji' => '🍦'],
            ['name' => '大冰淇淋', 'unit' => '个', 'emoji' => '🍨'],
            ['name' => '小汉堡', 'unit' => '个', 'emoji' => '🍔'],
            ['name' => '大汉堡', 'unit' => '个', 'emoji' => '🍔'],
            ['name' => '小披萨', 'unit' => '块', 'emoji' => '🍕'],
            ['name' => '大披萨', 'unit' => '块', 'emoji' => '🍕'],
            ['name' => '小热狗', 'unit' => '根', 'emoji' => '🌭'],
            ['name' => '大热狗', 'unit' => '根', 'emoji' => '🌭'],
        ];
    }
    
    return $random_items[array_rand($random_items)];
}

// 生成随机标题（和前台index.php一样的逻辑）
function getRandomTitle() {
    $item = getRandomItem();
    return '刷啊刷刷' . $item['unit'] . $item['name'];
}
?>

