<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$message = '';
$message_type = '';

// 保存设置
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $settings = [
        'questions_per_paper' => intval($_POST['questions_per_paper'] ?? 50),
        'auto_generate_paper' => intval($_POST['auto_generate_paper'] ?? 1),
        'show_answer_after_submit' => intval($_POST['show_answer_after_submit'] ?? 1),
        'allow_review' => intval($_POST['allow_review'] ?? 1),
        'inactivity_reminder_minutes' => intval($_POST['inactivity_reminder_minutes'] ?? 5),
        'stats_refresh_interval_seconds' => intval($_POST['stats_refresh_interval_seconds'] ?? 10)
    ];
    
    $pdo->beginTransaction();
    try {
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) 
                                   VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        $pdo->commit();
        $message = '设置保存成功！';
        $message_type = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '设置保存失败！';
        $message_type = 'error';
    }
}

// 获取当前设置
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$current_settings = [];
while ($row = $stmt->fetch()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>参数设置 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>参数设置</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        <div class="table-container">
            <form method="POST">
                <div class="form-group">
                    <label>每套试卷题目数量</label>
                    <input type="number" name="questions_per_paper" 
                           value="<?php echo $current_settings['questions_per_paper'] ?? 50; ?>" required>
                </div>
                <div class="form-group">
                    <label>是否自动组卷</label>
                    <select name="auto_generate_paper">
                        <option value="1" <?php echo ($current_settings['auto_generate_paper'] ?? 1) == 1 ? 'selected' : ''; ?>>是</option>
                        <option value="0" <?php echo ($current_settings['auto_generate_paper'] ?? 1) == 0 ? 'selected' : ''; ?>>否</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>提交后是否显示答案</label>
                    <select name="show_answer_after_submit">
                        <option value="1" <?php echo ($current_settings['show_answer_after_submit'] ?? 1) == 1 ? 'selected' : ''; ?>>是</option>
                        <option value="0" <?php echo ($current_settings['show_answer_after_submit'] ?? 1) == 0 ? 'selected' : ''; ?>>否</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>是否允许回顾</label>
                    <select name="allow_review">
                        <option value="1" <?php echo ($current_settings['allow_review'] ?? 1) == 1 ? 'selected' : ''; ?>>是</option>
                        <option value="0" <?php echo ($current_settings['allow_review'] ?? 1) == 0 ? 'selected' : ''; ?>>否</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>学生发呆提醒时间（分钟）</label>
                    <input type="number" name="inactivity_reminder_minutes" 
                           value="<?php echo $current_settings['inactivity_reminder_minutes'] ?? 5; ?>" 
                           min="1" max="60" required>
                    <small style="display: block; margin-top: 5px; color: #666;">设置学生无操作多少分钟后弹出提醒，范围：1-60分钟</small>
                </div>
                <div class="form-group">
                    <label>首页统计刷新时间（秒）</label>
                    <input type="number" name="stats_refresh_interval_seconds" 
                           value="<?php echo $current_settings['stats_refresh_interval_seconds'] ?? 10; ?>" 
                           min="0" max="3600" required>
                    <small style="display: block; margin-top: 5px; color: #666;">设置首页统计数据自动刷新的间隔时间，范围：0-3600秒（0表示不刷新）</small>
                </div>
                <button type="submit" class="btn btn-primary">保存设置</button>
            </form>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

