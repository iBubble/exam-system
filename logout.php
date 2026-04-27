<?php
require_once 'inc/functions.inc.php';

// 启动学生会话并清除学生相关数据
startStudentSession();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: index.php');
exit;
?>

