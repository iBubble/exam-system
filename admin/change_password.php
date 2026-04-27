<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$message = '';
$message_type = '';

// 修改密码
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $message = '请填写所有字段！';
        $message_type = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = '新密码和确认密码不一致！';
        $message_type = 'error';
    } elseif (strlen($new_password) < 6) {
        $message = '新密码长度至少6位！';
        $message_type = 'error';
    } else {
        // 验证旧密码
        $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            $message = '无法找到管理员信息！';
            $message_type = 'error';
        } elseif (!password_verify($old_password, $admin['password'])) {
            $message = '旧密码错误！';
            $message_type = 'error';
        } else {
            // 更新密码
            try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // 确保使用正确的管理员ID
                $admin_id = $_SESSION['admin_id'] ?? $admin['id'] ?? null;
                if (!$admin_id) {
                    $message = '无法获取管理员ID！';
                    $message_type = 'error';
                } else {
                    // 直接执行更新（使用强制更新，通过SET id=id确保即使值相同也更新）
                    $stmt = $pdo->prepare("UPDATE admins SET password = ?, id = id WHERE id = ?");
                    $result = $stmt->execute([$hashed_password, $admin_id]);
                    
                    // 检查执行结果
                    $error_info = $stmt->errorInfo();
                    $affected_rows = $stmt->rowCount();
                    
                    if (!$result || $error_info[0] != '00000') {
                        $message = '密码修改失败：' . ($error_info[2] ?? '数据库错误');
                        $message_type = 'error';
                    } else {
                        // 等待一下确保数据库更新完成
                        usleep(200000); // 0.2秒
                        
                        // 使用新的查询对象，确保读取最新数据（不使用同一个stmt对象）
                        $verify_stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
                        $verify_stmt->execute([$admin_id]);
                        $updated_admin = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($updated_admin && isset($updated_admin['password'])) {
                            // 验证新密码
                            $new_password_verified = password_verify($new_password, $updated_admin['password']);
                            $old_password_verified = password_verify($old_password, $updated_admin['password']);
                            
                            if ($new_password_verified) {
                                $message = '密码修改成功！请使用新密码登录。';
                                $message_type = 'success';
                            } elseif ($old_password_verified) {
                                // 如果还是旧密码，说明更新没有生效
                                // 尝试强制更新（即使值相同也更新，通过SET id=id强制触发更新）
                                try {
                                    $force_stmt = $pdo->prepare("UPDATE admins SET password = ?, id = id WHERE id = ?");
                                    $force_result = $force_stmt->execute([$hashed_password, $admin_id]);
                                    
                                    if ($force_result) {
                                        // 再次验证
                                        usleep(100000);
                                        $verify_stmt2 = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
                                        $verify_stmt2->execute([$admin_id]);
                                        $updated_admin2 = $verify_stmt2->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($updated_admin2 && password_verify($new_password, $updated_admin2['password'])) {
                                            $message = '密码修改成功！请使用新密码登录。';
                $message_type = 'success';
            } else {
                                            $message = '密码修改失败：数据库更新未生效（受影响行数：' . $affected_rows . '）。可能原因：1) 数据库权限问题；2) 触发器或约束阻止更新。请检查数据库或联系管理员！';
                                            $message_type = 'error';
                                        }
                                    } else {
                                        $message = '密码修改失败：数据库更新未生效（受影响行数：' . $affected_rows . '）。可能原因：1) 数据库权限问题；2) 触发器或约束阻止更新。请检查数据库或联系管理员！';
                                        $message_type = 'error';
                                    }
                                } catch (PDOException $e) {
                                    $message = '密码修改失败：数据库更新未生效。错误：' . $e->getMessage();
                                    $message_type = 'error';
                                }
                            } else {
                                $message = '密码修改失败：密码验证异常，新旧密码都无法验证。请重试！';
                                $message_type = 'error';
                            }
                        } else {
                            $message = '密码修改失败：无法验证更新结果！';
                            $message_type = 'error';
                        }
                    }
                }
            } catch (PDOException $e) {
                $message = '密码修改失败：' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>修改密码</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        <div class="table-container">
            <form method="POST" style="margin-top: 20px;">
                <div class="form-group">
                    <label>旧密码 *</label>
                    <input type="password" name="old_password" required>
                </div>
                <div class="form-group">
                    <label>新密码 *</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="至少6位">
                </div>
                <div class="form-group">
                    <label>确认新密码 *</label>
                    <input type="password" name="confirm_password" required minlength="6" placeholder="再次输入新密码">
                </div>
                <button type="submit" class="btn btn-primary">修改密码</button>
                <a href="index.php" class="btn btn-warning">返回</a>
            </form>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

