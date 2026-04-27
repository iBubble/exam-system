<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();
ensureAdminLogTable($pdo);

// 分页参数
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$username = trim($_GET['username'] ?? '');
$result = trim($_GET['result'] ?? '');
$keyword = trim($_GET['keyword'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($username !== '') {
    $where[] = "username LIKE ?";
    $params[] = "%{$username}%";
}
if ($result !== '') {
    $where[] = "result = ?";
    $params[] = $result;
}
if ($keyword !== '') {
    $where[] = "(action LIKE ? OR detail LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}
if ($date_from !== '') {
    $where[] = "created_at >= ?";
    $params[] = $date_from . " 00:00:00";
}
if ($date_to !== '') {
    $where[] = "created_at <= ?";
    $params[] = $date_to . " 23:59:59";
}

$where_sql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// 总数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_logs {$where_sql}");
$stmt->execute($params);
$total_records = (int)$stmt->fetchColumn();

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, ceil($total_records / $per_page));
    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }
    $offset = ($current_page - 1) * $per_page;
}

// 数据
$sql = "SELECT * FROM admin_logs {$where_sql} ORDER BY id DESC";
if ($per_page > 0) {
    $sql .= " LIMIT :limit OFFSET :offset";
}
$stmt = $pdo->prepare($sql);
foreach ($params as $idx => $val) {
    $stmt->bindValue($idx + 1, $val);
}
if ($per_page > 0) {
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$stmt->execute();
$logs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>操作日志 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
    <style>
        /* 紧凑表格样式 */
        .table-container table {
            font-size: 13px;
        }
        
        .table-container table th,
        .table-container table td {
            padding: 6px 8px;
            line-height: 1.3;
            vertical-align: middle;
        }
        
        .table-container table th {
            padding: 8px 8px;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .table-container table td {
            font-size: 13px;
        }
        
        /* 时间列紧凑显示 */
        .table-container table td:first-child {
            white-space: nowrap;
        }
        
        .table-container {
            padding: 16px;
        }
        
        /* 减少表格行间距 */
        .table-container table tbody tr {
            height: auto;
        }
        
        /* 优化边框 */
        .table-container table th,
        .table-container table td {
            border-bottom: 1px solid #e0e0e0;
        }
        
        /* 结果列样式 */
        .table-container table td:nth-child(4) {
            font-weight: 500;
        }
        
        /* 分页按钮优化 */
        .table-container .btn {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
        }
        
        /* 分页导航样式 */
        .pagination-info {
            color: #666;
            font-size: 14px;
        }
        .pagination {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .pagination .btn {
            min-width: auto;
            padding: 8px 12px;
            font-size: 14px;
        }
        .pagination .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .pagination .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .pagination .ellipsis {
            padding: 8px 4px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>操作日志
            <?php if ($per_page > 0): ?>
                （共<?php echo $total_records; ?>条，第<?php echo $current_page; ?>/<?php echo $total_pages; ?>页）
            <?php else: ?>
                （共<?php echo $total_records; ?>条，全部显示）
            <?php endif; ?>
        </h2>
        <div class="table-container">
            <h2>筛选</h2>
            <form method="GET" style="margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
                <div class="form-group" style="margin: 0;">
                    <label>用户名</label>
                    <input type="text" name="username" value="<?php echo escape($username); ?>">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>结果</label>
                    <select name="result">
                        <option value="">全部</option>
                        <option value="success" <?php echo $result === 'success' ? 'selected' : ''; ?>>success</option>
                        <option value="failed" <?php echo $result === 'failed' ? 'selected' : ''; ?>>failed</option>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>关键词（动作/详情）</label>
                    <input type="text" name="keyword" value="<?php echo escape($keyword); ?>">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>开始日期</label>
                    <input type="date" name="date_from" value="<?php echo escape($date_from); ?>">
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>结束日期</label>
                    <input type="date" name="date_to" value="<?php echo escape($date_to); ?>">
                </div>
                <div style="display: flex; align-items: flex-end; gap: 8px;">
                    <button type="submit" class="btn btn-primary">搜索</button>
                    <a class="btn btn-secondary" href="logs.php">重置</a>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
                <h2 style="margin: 0; padding: 0; border: none;">日志列表</h2>
                <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                    <label style="margin: 0; font-size: 14px;">每页显示：</label>
                    <select name="per_page" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                        <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                    </select>
                    <input type="hidden" name="page" value="1">
                    <?php if (!empty($username)): ?><input type="hidden" name="username" value="<?php echo escape($username); ?>"><?php endif; ?>
                    <?php if (!empty($result)): ?><input type="hidden" name="result" value="<?php echo escape($result); ?>"><?php endif; ?>
                    <?php if (!empty($keyword)): ?><input type="hidden" name="keyword" value="<?php echo escape($keyword); ?>"><?php endif; ?>
                    <?php if (!empty($date_from)): ?><input type="hidden" name="date_from" value="<?php echo escape($date_from); ?>"><?php endif; ?>
                    <?php if (!empty($date_to)): ?><input type="hidden" name="date_to" value="<?php echo escape($date_to); ?>"><?php endif; ?>
                </form>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width: 120px;">时间</th>
                            <th style="min-width: 120px;">用户</th>
                            <th style="min-width: 180px;">动作</th>
                            <th style="min-width: 90px;">结果</th>
                            <th style="min-width: 130px;">IP</th>
                            <th style="min-width: 240px;">详情</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" style="text-align:center;">暂无数据</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="white-space: nowrap;">
                                        <?php 
                                        if (!empty($log['created_at'])) {
                                            $date = strtotime($log['created_at']);
                                            echo date('m-d H:i:s', $date);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo escape($log['username'] ?: ('ID:' . ($log['admin_id'] ?? '-'))); ?></td>
                                    <td><?php echo escape($log['action']); ?></td>
                                    <td><?php echo escape($log['result']); ?></td>
                                    <td><?php echo escape($log['ip']); ?></td>
                                    <td><?php echo nl2br(escape($log['detail'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 分页导航 -->
            <?php if ($per_page > 0 && $total_pages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
                <div class="pagination-info">
                    显示第 <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_records); ?> 条，共 <?php echo $total_records; ?> 条
                </div>
                <div class="pagination">
                    <?php
                    // 构建URL参数
                    $url_params = [];
                    if ($per_page > 0) $url_params[] = 'per_page=' . $per_page;
                    if (!empty($username)) $url_params[] = 'username=' . urlencode($username);
                    if (!empty($result)) $url_params[] = 'result=' . urlencode($result);
                    if (!empty($keyword)) $url_params[] = 'keyword=' . urlencode($keyword);
                    if (!empty($date_from)) $url_params[] = 'date_from=' . urlencode($date_from);
                    if (!empty($date_to)) $url_params[] = 'date_to=' . urlencode($date_to);
                    $url_suffix = !empty($url_params) ? '&' . implode('&', $url_params) : '';
                    ?>
                    
                    <!-- 上一页 -->
                    <?php if ($current_page > 1): ?>
                        <a href="?page=<?php echo $current_page - 1; ?><?php echo $url_suffix; ?>" class="btn">上一页</a>
                    <?php else: ?>
                        <span class="btn" style="opacity: 0.5; cursor: not-allowed;">上一页</span>
                    <?php endif; ?>
                    
                    <!-- 页码 -->
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1): ?>
                        <a href="?page=1<?php echo $url_suffix; ?>" class="btn">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="btn btn-primary" style="cursor: default;"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?><?php echo $url_suffix; ?>" class="btn"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="ellipsis">...</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $total_pages; ?><?php echo $url_suffix; ?>" class="btn"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <!-- 下一页 -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=<?php echo $current_page + 1; ?><?php echo $url_suffix; ?>" class="btn">下一页</a>
                    <?php else: ?>
                        <span class="btn" style="opacity: 0.5; cursor: not-allowed;">下一页</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

