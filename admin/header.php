<?php
startAdminSession();
if (!isset($_SESSION['admin_username'])) {
    header('Location: login.php');
    exit;
}
?>
<header class="admin-header">
    <div class="header-content">
        <h1><?php echo getRandomTitle(); ?> - 后台管理</h1>
        <div class="user-info">
            <span>欢迎，<?php echo escape($_SESSION['admin_username']); ?></span>
            <a href="change_password.php">修改密码</a>
            <a href="logout.php" class="logout-btn">退出</a>
        </div>
    </div>
    <nav class="admin-nav">
        <a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">首页</a>
        <a href="subjects.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'subjects.php' ? 'active' : ''; ?>">科目管理</a>
        <a href="questions.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'questions.php' ? 'active' : ''; ?>">题库管理</a>
        <a href="papers.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'papers.php' ? 'active' : ''; ?>">考试管理</a>
        <a href="student_manage.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'student_manage.php' ? 'active' : ''; ?>">学生管理</a>
        <a href="students.php" class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['students.php', 'student_records.php', 'question_analysis.php']) ? 'active' : ''; ?>">刷题记录</a>
        <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">参数设置</a>
        <a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'logs.php' ? 'active' : ''; ?>">操作日志</a>
        <a href="help.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'help.php' ? 'active' : ''; ?>">使用说明</a>
    </nav>
</header>

