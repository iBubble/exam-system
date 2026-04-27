<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$message = '';
$message_type = '';

// 添加科目
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (name, description) VALUES (?, ?)");
            if ($stmt->execute([$name, $description])) {
                $subject_id = $pdo->lastInsertId();
                $message = '科目添加成功！';
                $message_type = 'success';
                logAdminAction($pdo, '创建科目', 'success', "ID={$subject_id}, 名称={$name}");
            } else {
                $message = '科目添加失败！';
                $message_type = 'error';
                logAdminAction($pdo, '创建科目', 'failed', "名称={$name}");
            }
        } catch (PDOException $e) {
            $message = '科目添加失败！';
            $message_type = 'error';
            logAdminAction($pdo, '创建科目', 'failed', "名称={$name}, 错误: " . $e->getMessage());
        }
    }
}

// 编辑科目
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if ($id > 0 && !empty($name)) {
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET name = ?, description = ? WHERE id = ?");
            if ($stmt->execute([$name, $description, $id])) {
                $message = '科目更新成功！';
                $message_type = 'success';
                logAdminAction($pdo, '更新科目', 'success', "ID={$id}, 名称={$name}");
            } else {
                $message = '科目更新失败！';
                $message_type = 'error';
                logAdminAction($pdo, '更新科目', 'failed', "ID={$id}, 名称={$name}");
            }
        } catch (PDOException $e) {
            $message = '科目更新失败！';
            $message_type = 'error';
            logAdminAction($pdo, '更新科目', 'failed', "ID={$id}, 名称={$name}, 错误: " . $e->getMessage());
        }
    }
}

// 删除科目
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 先获取科目名称用于日志
        $stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
        $stmt->execute([$id]);
        $subject = $stmt->fetch();
        $subject_name = $subject['name'] ?? '未知';
        
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = '科目删除成功！';
            $message_type = 'success';
            logAdminAction($pdo, '删除科目', 'success', "ID={$id}, 名称={$subject_name}");
        } else {
            $message = '科目删除失败！';
            $message_type = 'error';
            logAdminAction($pdo, '删除科目', 'failed', "ID={$id}, 名称={$subject_name}");
        }
    } catch (PDOException $e) {
        $message = '科目删除失败！';
        $message_type = 'error';
        logAdminAction($pdo, '删除科目', 'failed', "ID={$id}, 错误: " . $e->getMessage());
    }
}

// 分页参数
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 获取总记录数
$count_stmt = $pdo->query("SELECT COUNT(*) as total FROM subjects");
$total_records = $count_stmt->fetch()['total'];

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;
}

// 获取科目列表
$sql = "SELECT * FROM subjects ORDER BY id DESC";
if ($per_page > 0) {
    $sql .= " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
}
$stmt = $pdo->query($sql);
$subjects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>科目管理 - 后台管理</title>
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
        
        /* 数字列右对齐，更紧凑 */
        .table-container table td:nth-child(1) {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        
        /* 操作列保持左对齐 */
        .table-container table td:last-child {
            text-align: left;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
        }
        
        .action-buttons .btn {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            white-space: nowrap;
            line-height: 1.2;
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
        
        /* 模态框样式 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .modal-header {
            padding: 20px 25px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
        }
        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 24px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        .modal-body {
            padding: 25px;
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
        <h2>科目管理</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
                <h2 style="margin: 0; padding: 0; border: none;">
                    科目列表
                    <?php if ($per_page > 0): ?>
                        （共<?php echo $total_records; ?>条，第<?php echo $current_page; ?>/<?php echo $total_pages; ?>页）
                    <?php else: ?>
                        （共<?php echo $total_records; ?>条，全部显示）
                    <?php endif; ?>
                </h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                        <label style="margin: 0; font-size: 14px;">每页显示：</label>
                        <select name="per_page" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px;">
                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                            <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                        </select>
                        <input type="hidden" name="page" value="1">
                    </form>
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">➕ 添加科目</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>科目名称</th>
                        <th>描述</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subjects)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">暂无科目</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><?php echo $subject['id']; ?></td>
                                <td><?php echo escape($subject['name']); ?></td>
                                <td><?php echo escape($subject['description'] ?? ''); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($subject['created_at'])) {
                                        $date = strtotime($subject['created_at']);
                                        echo date('m-d H:i', $date);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-primary" onclick="openEditModal(<?php echo $subject['id']; ?>)">编辑</button>
                                        <?php
                                        // 构建删除URL，保留分页参数
                                        $delete_url = '?action=delete&id=' . $subject['id'];
                                        if ($per_page > 0) $delete_url .= '&per_page=' . $per_page;
                                        $delete_url .= '&page=' . $current_page;
                                        ?>
                                        <a href="<?php echo $delete_url; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('确定要删除这个科目吗？')">删除</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
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
    
    <!-- 添加/编辑科目模态框 -->
    <div id="subjectModal" class="modal-overlay" onclick="if(event.target === this) closeSubjectModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">添加科目</h2>
                <button type="button" class="modal-close" onclick="closeSubjectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="subjectForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="subjectId" value="">
                    <div class="form-group">
                        <label>科目名称 *</label>
                        <input type="text" name="name" id="formName" required>
                    </div>
                    <div class="form-group">
                        <label>科目描述</label>
                        <textarea name="description" id="formDescription"></textarea>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="submitBtn">添加科目</button>
                        <button type="button" class="btn btn-warning" onclick="closeSubjectModal()">取消</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // 打开添加模态框
        function openAddModal() {
            document.getElementById('modalTitle').textContent = '添加科目';
            document.getElementById('formAction').value = 'add';
            document.getElementById('subjectId').value = '';
            document.getElementById('subjectForm').reset();
            document.getElementById('submitBtn').textContent = '添加科目';
            document.getElementById('subjectModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // 打开编辑模态框
        function openEditModal(subjectId) {
            fetch('get_subject.php?id=' + subjectId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const s = data.subject;
                        document.getElementById('modalTitle').textContent = '编辑科目';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('subjectId').value = s.id;
                        document.getElementById('formName').value = s.name || '';
                        document.getElementById('formDescription').value = s.description || '';
                        document.getElementById('submitBtn').textContent = '更新科目';
                        document.getElementById('subjectModal').classList.add('active');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('获取科目信息失败：' + (data.message || '未知错误'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取科目信息失败，请刷新页面重试');
                });
        }
        
        // 关闭模态框
        function closeSubjectModal() {
            document.getElementById('subjectModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // ESC键关闭模态框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSubjectModal();
            }
        });
    </script>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

