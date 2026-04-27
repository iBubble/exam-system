<?php
// 数据库连接配置文件
$db_host = 'localhost';
$db_port = '3306';
$db_user = 'exam';
$db_pass = 'Gl5181081';
$db_name = 'exam';
$db_socket = '/tmp/mysql.sock';  // 指定socket路径

try {
    $pdo = new PDO(
        "mysql:unix_socket=$db_socket;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
} catch (PDOException $e) {
    // 生产环境应记录错误日志，而不是直接显示错误信息
    error_log("数据库连接失败: " . $e->getMessage());
    die("数据库连接失败，请稍后重试");
}
?>

