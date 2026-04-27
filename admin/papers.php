<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();
ensurePaperScheduleColumns($pdo);

$message = '';
$message_type = '';

    // 创建考试
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $total_score = intval($_POST['total_score'] ?? 100);
    $duration = intval($_POST['duration'] ?? 60);
    $start_time_raw = safeString($_POST['start_time'] ?? '');
    $end_time_raw = safeString($_POST['end_time'] ?? '');
    $start_time = null;
    $end_time = null;
    if (!empty($start_time_raw)) {
        $ts = strtotime($start_time_raw);
        if ($ts !== false) {
            $start_time = date('Y-m-d H:i:s', $ts);
        }
    }
    if (!empty($end_time_raw)) {
        $ts = strtotime($end_time_raw);
        if ($ts !== false) {
            $end_time = date('Y-m-d H:i:s', $ts);
        }
    }
    $shuffle_questions = 0; // 题目乱序功能已移除，答题页面已随机抽选
    $question_config = $_POST['question_config'] ?? []; // 格式: {type: {count: 10, score: 20}}
    $question_scores = $_POST['question_scores'] ?? []; // 格式: {type: 20}
    
    // 获取题型顺序（如果提交了顺序）
    $type_order = $_POST['type_order'] ?? [];
    
    // 过滤掉数量为0的题型，并合并count和score
    $filtered_config = [];
    
    // 如果提供了顺序，按照顺序处理
    if (!empty($type_order) && is_array($type_order)) {
        foreach ($type_order as $type) {
            $count = intval($question_config[$type] ?? 0);
            $score = intval($question_scores[$type] ?? 0);
            if ($count > 0) {
                $filtered_config[$type] = [
                    'count' => $count,
                    'score' => $score > 0 ? $score : 0
                ];
            }
        }
    } else {
        // 如果没有顺序，按原逻辑处理
    foreach ($question_config as $type => $count) {
        $count = intval($count);
        $score = intval($question_scores[$type] ?? 0);
        if ($count > 0) {
            $filtered_config[$type] = [
                'count' => $count,
                    'score' => $score > 0 ? $score : 0
            ];
            }
        }
    }
    
    if ($subject_id > 0 && !empty($title) && !empty($filtered_config)) {
        try {
            // 将题型配置存储为JSON
            $question_config_json = json_encode($filtered_config, JSON_UNESCAPED_UNICODE);
            
            $stmt = $pdo->prepare("INSERT INTO papers (subject_id, title, description, total_score, duration, start_time, end_time, is_paused, shuffle_questions, question_config, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$subject_id, $title, $description, $total_score, $duration, $start_time, $end_time, $shuffle_questions, $question_config_json, $_SESSION['admin_id']]);
            $paper_id = $pdo->lastInsertId();
            logAdminAction($pdo, '创建考试', 'success', "ID={$paper_id}, 标题={$title}");
            
            // 保存试卷班级关联
            $selected_classes = $_POST['classes'] ?? [];
            if (!empty($selected_classes) && is_array($selected_classes)) {
                $stmt = $pdo->prepare("INSERT INTO paper_classes (paper_id, class) VALUES (?, ?)");
                foreach ($selected_classes as $class) {
                    $class = trim($class);
                    if (!empty($class)) {
                        $stmt->execute([$paper_id, $class]);
                    }
                }
            }
            
            // 计算总题目数
            $total_questions = 0;
            $total_config_score = 0;
            foreach ($filtered_config as $config) {
                $total_questions += $config['count'];
                $total_config_score += $config['score'];
            }
            
            $message = '考试创建成功！已配置 ' . count($filtered_config) . ' 种题型，共 ' . $total_questions . ' 道题目';
            if ($total_config_score > 0) {
                $message .= '，题型总分：' . $total_config_score . ' 分';
            }
            $message_type = 'success';
        } catch (Exception $e) {
            $message = '考试创建失败：' . $e->getMessage();
            $message_type = 'error';
            logAdminAction($pdo, '创建考试', 'failed', $e->getMessage());
        }
    } else {
        $message = '请填写完整信息并至少选择一种题型！';
        $message_type = 'error';
    }
}

// 编辑考试
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $total_score = intval($_POST['total_score'] ?? 100);
    $duration = intval($_POST['duration'] ?? 60);
    $start_time_raw = safeString($_POST['start_time'] ?? '');
    $end_time_raw = safeString($_POST['end_time'] ?? '');
    $start_time = null;
    $end_time = null;
    if (!empty($start_time_raw)) {
        $ts = strtotime($start_time_raw);
        if ($ts !== false) {
            $start_time = date('Y-m-d H:i:s', $ts);
        }
    }
    if (!empty($end_time_raw)) {
        $ts = strtotime($end_time_raw);
        if ($ts !== false) {
            $end_time = date('Y-m-d H:i:s', $ts);
        }
    }
    $shuffle_questions = 0; // 题目乱序功能已移除，答题页面已随机抽选
    $question_config = $_POST['question_config'] ?? [];
    $question_scores = $_POST['question_scores'] ?? [];
    
    // 获取题型顺序（如果提交了顺序）
    $type_order_raw = $_POST['type_order'] ?? [];
    $type_order = [];
    if (!empty($type_order_raw)) {
        if (is_array($type_order_raw)) {
            $type_order = array_filter(array_map('trim', $type_order_raw));
        } else {
            $type_order = explode(',', $type_order_raw);
        }
    }
    
    // 过滤掉数量为0的题型，并合并count和score
    $filtered_config = [];
    
    // 如果提供了顺序，按照顺序处理
    if (!empty($type_order) && is_array($type_order)) {
        foreach ($type_order as $type) {
            $type = trim($type);
            if (empty($type)) continue;
            $count = intval($question_config[$type] ?? 0);
            $score = intval($question_scores[$type] ?? 0);
            if ($count > 0) {
                $filtered_config[$type] = [
                    'count' => $count,
                    'score' => $score > 0 ? $score : 0
                ];
            }
        }
    }
    // 添加未在顺序中的题型（兼容旧数据）
    foreach ($question_config as $type => $count) {
        if (!isset($filtered_config[$type])) {
        $count = intval($count);
        $score = intval($question_scores[$type] ?? 0);
        if ($count > 0) {
            $filtered_config[$type] = [
                'count' => $count,
                'score' => $score > 0 ? $score : 0
            ];
            }
        }
    }
    
    if ($id > 0 && $subject_id > 0 && !empty($title) && !empty($filtered_config)) {
        try {
            $current_paused = $edit_paper['is_paused'] ?? 0;
            $question_config_json = json_encode($filtered_config, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("UPDATE papers SET subject_id = ?, title = ?, description = ?, 
                                   total_score = ?, duration = ?, start_time = ?, end_time = ?, is_paused = ?, shuffle_questions = ?, question_config = ? 
                                   WHERE id = ?");
            $stmt->execute([$subject_id, $title, $description, $total_score, $duration, $start_time, $end_time, $current_paused, $shuffle_questions, $question_config_json, $id]);
            
            // 更新考试班级关联
            // 先删除旧的关联
            $stmt = $pdo->prepare("DELETE FROM paper_classes WHERE paper_id = ?");
            $stmt->execute([$id]);
            
            // 添加新的关联
            $selected_classes = $_POST['classes'] ?? [];
            if (!empty($selected_classes) && is_array($selected_classes)) {
                $stmt = $pdo->prepare("INSERT INTO paper_classes (paper_id, class) VALUES (?, ?)");
                foreach ($selected_classes as $class) {
                    $class = trim($class);
                    if (!empty($class)) {
                        $stmt->execute([$id, $class]);
                    }
                }
            }
            
            $message = '考试更新成功！';
            $message_type = 'success';
            logAdminAction($pdo, '更新考试', 'success', "ID={$id}, 标题={$title}");
        } catch (Exception $e) {
            $message = '考试更新失败：' . $e->getMessage();
            $message_type = 'error';
            logAdminAction($pdo, '更新考试', 'failed', $e->getMessage());
        }
    } else {
        $message = '请填写完整信息并至少选择一种题型！';
        $message_type = 'error';
    }
}

// 删除试卷
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 删除试卷班级关联
        $pdo->prepare("DELETE FROM paper_classes WHERE paper_id = ?")->execute([$id]);
        // 删除试卷
        $pdo->prepare("DELETE FROM papers WHERE id = ?")->execute([$id]);
        $message = '试卷删除成功！';
        $message_type = 'success';
        logAdminAction($pdo, '删除试卷', 'success', "ID={$id}");
    } catch (Exception $e) {
        $message = '删除失败！';
        $message_type = 'error';
        logAdminAction($pdo, '删除试卷', 'failed', $e->getMessage());
    }
}

// 暂停/恢复试卷
if (isset($_GET['action']) && in_array($_GET['action'], ['pause', 'resume']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $flag = $_GET['action'] === 'pause' ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE papers SET is_paused = ?, updated_at = NOW() WHERE id = ?");
    if ($stmt->execute([$flag, $id])) {
        $message = $flag ? '试卷已暂停' : '试卷已恢复';
        $message_type = 'success';
        logAdminAction($pdo, $flag ? '暂停试卷' : '恢复试卷', 'success', "ID={$id}");
    } else {
        $message = '操作失败，请稍后重试';
        $message_type = 'error';
        logAdminAction($pdo, $flag ? '暂停试卷' : '恢复试卷', 'failed', "ID={$id}");
    }
}

// 获取所有科目
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY id DESC");
$subjects = $stmt->fetchAll();

// 获取所有班级列表
$stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != '' ORDER BY class");
$all_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 分页参数
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 获取总记录数
$count_sql = "SELECT COUNT(DISTINCT p.id) as total 
              FROM papers p 
              LEFT JOIN subjects s ON p.subject_id = s.id 
              LEFT JOIN paper_classes pc ON p.id = pc.paper_id";
$count_stmt = $pdo->query($count_sql);
$total_records = $count_stmt->fetch()['total'];

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;
}

// 获取试卷列表（含班级列表）
$sql = "SELECT p.*, s.name as subject_name, GROUP_CONCAT(pc.class ORDER BY pc.class SEPARATOR ',') AS class_list 
        FROM papers p 
        LEFT JOIN subjects s ON p.subject_id = s.id 
        LEFT JOIN paper_classes pc ON p.id = pc.paper_id
        GROUP BY p.id
        ORDER BY p.id DESC";
if ($per_page > 0) {
    $sql .= " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
}
$stmt = $pdo->query($sql);
$papers = $stmt->fetchAll();
foreach ($papers as &$paperItem) {
    $paperItem['classes'] = [];
    if (!empty($paperItem['class_list'])) {
        $paperItem['classes'] = array_filter(array_map('trim', explode(',', $paperItem['class_list'])));
    }
    $paperItem['is_paused'] = isset($paperItem['is_paused']) ? intval($paperItem['is_paused']) : 0;
}
unset($paperItem);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>考试管理 - 后台管理</title>
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
        .table-container table td:nth-child(1),
        .table-container table td:nth-child(5) {
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
            max-width: 1000px;
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
        
        /* 时间输入框 spinner 样式 - 确保时分秒显示上下箭头 */
        input[type="datetime-local"] {
            -webkit-appearance: none;
            -moz-appearance: textfield;
        }
        
        /* WebKit 浏览器（Chrome, Safari, Edge）显示时间部分的 spinner */
        input[type="datetime-local"]::-webkit-datetime-edit-hour-field,
        input[type="datetime-local"]::-webkit-datetime-edit-minute-field,
        input[type="datetime-local"]::-webkit-datetime-edit-second-field {
            -webkit-appearance: menulist;
            padding: 0 2px;
        }
        
        /* 确保 spinner 按钮可见 */
        input[type="datetime-local"]::-webkit-inner-spin-button {
            opacity: 1;
            height: 20px;
            width: 16px;
            cursor: pointer;
            margin-left: 2px;
        }
        
        /* 日历图标样式 */
        input[type="datetime-local"]::-webkit-calendar-picker-indicator {
            opacity: 1;
            cursor: pointer;
            margin-left: 5px;
        }
        
        /* Firefox 浏览器显示 spinner */
        input[type="datetime-local"] {
            -moz-appearance: textfield;
        }
        
        /* 确保输入框有足够空间显示 spinner */
        #modalStartTime,
        #modalEndTime {
            padding-right: 30px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>考试管理</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
                <h2 style="margin: 0; padding: 0; border: none;">
                    考试列表
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
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">➕ 创建考试</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>考试名称</th>
                        <th>科目</th>
                        <th>班级</th>
                        <th>总分</th>
                        <th>时长</th>
                        <th>开始时间</th>
                        <th>结束时间</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($papers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">暂无试卷</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($papers as $paper): ?>
                            <tr>
                                <td><?php echo $paper['id']; ?></td>
                                <td><?php echo escape($paper['title']); ?></td>
                                <td><?php echo escape($paper['subject_name'] ?? ''); ?></td>
                                <td>
                                    <?php if (empty($paper['classes'])): ?>
                                        <span style="color: #999;">全部班级</span>
                                    <?php else: ?>
                                        <?php foreach ($paper['classes'] as $class): ?>
                                            <span style="display: inline-block; padding: 2px 8px; background: #667eea; color: white; border-radius: 4px; font-size: 12px; margin: 2px;"><?php echo escape($class); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $paper['total_score']; ?></td>
                                <td><?php echo $paper['duration']; ?>分钟</td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($paper['start_time'])) {
                                        $date = strtotime($paper['start_time']);
                                        echo date('m-d H:i', $date);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($paper['end_time'])) {
                                        $date = strtotime($paper['end_time']);
                                        echo date('m-d H:i', $date);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php $state = getPaperActiveState($paper); ?>
                                    <?php if ($state['active']): ?>
                                        <span style="color: #27ae60;">进行中</span>
                                    <?php else: ?>
                                        <span style="color: #e67e22;"><?php echo escape($state['reason'] ?: '不可用'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($paper['created_at'])) {
                                        $date = strtotime($paper['created_at']);
                                        echo date('m-d H:i', $date);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-primary" onclick="openEditModal(<?php echo $paper['id']; ?>)">编辑</button>
                                        <?php
                                        // 构建URL，保留分页参数
                                        $action_url_suffix = '';
                                        if ($per_page > 0) $action_url_suffix .= '&per_page=' . $per_page;
                                        $action_url_suffix .= '&page=' . $current_page;
                                        ?>
                                        <?php if (isset($paper['is_paused']) && (int)$paper['is_paused'] === 1): ?>
                                            <a href="?action=resume&id=<?php echo $paper['id']; ?><?php echo $action_url_suffix; ?>" 
                                               class="btn btn-warning" 
                                               onclick="return confirm('恢复后学生可以按时间窗口进入考试，确认恢复吗？');">恢复</a>
                                        <?php else: ?>
                                            <a href="?action=pause&id=<?php echo $paper['id']; ?><?php echo $action_url_suffix; ?>" 
                                               class="btn btn-secondary" 
                                               onclick="return confirm('暂停后学生无法进入或查看该考试，确认暂停吗？');">暂停</a>
                                        <?php endif; ?>
                                        <a href="?action=delete&id=<?php echo $paper['id']; ?><?php echo $action_url_suffix; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('确定要删除这个试卷吗？')">删除</a>
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
    
    <!-- 创建/编辑考试模态框 -->
    <div id="paperModal" class="modal-overlay" onclick="if(event.target === this) closePaperModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">创建考试</h2>
                <button type="button" class="modal-close" onclick="closePaperModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="paperForm">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="paperId" value="">
                    <div class="form-row">
                        <div class="form-group">
                            <label>选择科目 *</label>
                            <select name="subject_id" id="modalSubjectId" required>
                                <option value="">请选择科目</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo escape($subject['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>考试名称 *</label>
                            <input type="text" name="title" id="modalTitleInput" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>考试描述</label>
                        <textarea name="description" id="modalDescription"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>总分 *</label>
                            <input type="number" name="total_score" id="modalTotalScore" value="100" required>
                        </div>
                        <div class="form-group">
                            <label>考试时长（分钟） *</label>
                            <input type="number" name="duration" id="modalDuration" value="60" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>开始时间（留空则不限）</label>
                            <input type="datetime-local" name="start_time" id="modalStartTime" step="1">
                        </div>
                        <div class="form-group">
                            <label>结束时间（留空则不限）</label>
                            <input type="datetime-local" name="end_time" id="modalEndTime" step="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>选择班级（可多选，不选则所有班级可见）</label>
                        <div id="modalClassesContainer" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">
                            <?php if (empty($all_classes)): ?>
                                <p style="color: #999; grid-column: 1 / -1;">暂无班级数据，请先在"学生管理"中导入学生名单</p>
                            <?php else: ?>
                                <?php foreach ($all_classes as $class): ?>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;" 
                                           onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='transparent'">
                                        <input type="checkbox" name="classes[]" value="<?php echo escape($class); ?>" class="class-checkbox">
                                        <span><?php echo escape($class); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p style="color: #666; font-size: 12px; margin-top: 5px;">提示：选择班级后，只有这些班级的学生可以看到此试卷</p>
                    </div>
                    <div class="form-group">
                        <label>按题型选择题目数量 *</label>
                        <div id="modalQuestionTypeSelection" style="margin-top: 10px;" class="sortable-container">
                            <p style="color: #666;">请先选择科目</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="submitBtn">创建考试</button>
                        <button type="button" class="btn btn-warning" onclick="closePaperModal()">取消</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // 打开添加模态框
        function openAddModal() {
            document.getElementById('modalTitle').textContent = '创建考试';
            document.getElementById('formAction').value = 'create';
            document.getElementById('paperId').value = '';
            document.getElementById('paperForm').reset();
            document.getElementById('modalTotalScore').value = '100';
            document.getElementById('modalDuration').value = '60';
            document.getElementById('modalQuestionTypeSelection').innerHTML = '<p style="color: #666;">请先选择科目</p>';
            document.getElementById('submitBtn').textContent = '创建考试';
            // 清除所有班级复选框
            document.querySelectorAll('.class-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('paperModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // 打开编辑模态框
        function openEditModal(paperId) {
            fetch('get_paper.php?id=' + paperId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const p = data.paper;
                        document.getElementById('modalTitle').textContent = '编辑考试';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('paperId').value = p.id;
                        document.getElementById('modalSubjectId').value = p.subject_id;
                        document.getElementById('modalTitleInput').value = p.title || '';
                        document.getElementById('modalDescription').value = p.description || '';
                        document.getElementById('modalTotalScore').value = p.total_score || '100';
                        document.getElementById('modalDuration').value = p.duration || '60';
                        document.getElementById('modalStartTime').value = p.start_time ? p.start_time.substring(0, 16) : '';
                        document.getElementById('modalEndTime').value = p.end_time ? p.end_time.substring(0, 16) : '';
                        document.getElementById('submitBtn').textContent = '更新考试';
                        
                        // 设置班级复选框
                        document.querySelectorAll('.class-checkbox').forEach(cb => {
                            cb.checked = p.classes && p.classes.includes(cb.value);
                        });
                        
                        // 加载题型配置
                        if (p.subject_id) {
                            loadQuestionTypes(p.subject_id, p.question_config || {}, p.type_order || []);
                        } else {
                            document.getElementById('modalQuestionTypeSelection').innerHTML = '<p style="color: #666;">请先选择科目</p>';
                        }
                        
                        document.getElementById('paperModal').classList.add('active');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('获取试卷信息失败：' + (data.message || '未知错误'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取试卷信息失败，请刷新页面重试');
                });
        }
        
        // 关闭模态框
        function closePaperModal() {
            document.getElementById('paperModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // ESC键关闭模态框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaperModal();
            }
        });
        
        // 模态框中的科目选择变化事件
        document.getElementById('modalSubjectId').addEventListener('change', function() {
            loadQuestionTypes(this.value, {}, []);
        });
        
        function loadQuestionTypes(subjectId, editConfig = {}, editTypeOrder = []) {
            const typeSelection = document.getElementById('modalQuestionTypeSelection');
            
            if (subjectId) {
                fetch(`get_questions_by_type.php?subject_id=${subjectId}`)
                    .then(response => response.json())
                    .then(data => {
                        typeSelection.innerHTML = '';
                        if (data.length > 0) {
                            // 如果有编辑时的顺序，按照顺序排序
                            if (editTypeOrder && editTypeOrder.length > 0) {
                                const orderMap = {};
                                editTypeOrder.forEach((type, index) => {
                                    orderMap[type] = index;
                                });
                                data.sort((a, b) => {
                                    const orderA = orderMap[a.type] !== undefined ? orderMap[a.type] : 999;
                                    const orderB = orderMap[b.type] !== undefined ? orderMap[b.type] : 999;
                                    return orderA - orderB;
                                });
                            }
                            
                            data.forEach(group => {
                                const div = document.createElement('div');
                                div.className = 'question-type-item';
                                div.setAttribute('data-type', group.type);
                                div.style.marginBottom = '15px';
                                div.style.padding = '15px';
                                div.style.border = '1px solid #ddd';
                                div.style.borderRadius = '4px';
                                div.style.backgroundColor = '#f9f9f9';
                                div.style.cursor = 'move';
                                div.style.position = 'relative';
                                div.draggable = true;
                                
                                // 添加拖拽手柄图标
                                const dragHandle = document.createElement('span');
                                dragHandle.innerHTML = '☰';
                                dragHandle.style.cssText = 'position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 20px; color: #999; cursor: move; user-select: none;';
                                dragHandle.style.pointerEvents = 'none';
                                div.appendChild(dragHandle);
                                
                                const contentDiv = document.createElement('div');
                                contentDiv.style.marginLeft = '30px';
                                
                                const label = document.createElement('label');
                                label.style.fontWeight = 'bold';
                                label.style.display = 'block';
                                label.style.marginBottom = '8px';
                                label.textContent = group.type + '（共' + group.count + '题）';
                                contentDiv.appendChild(label);
                                
                                const configRow = document.createElement('div');
                                configRow.style.display = 'flex';
                                configRow.style.alignItems = 'center';
                                configRow.style.gap = '15px';
                                
                                const countLabel = document.createElement('label');
                                countLabel.textContent = '题目数量：';
                                countLabel.style.minWidth = '80px';
                                
                                const select = document.createElement('select');
                                select.name = 'question_config[' + group.type + ']';
                                select.style.width = '100px';
                                select.style.padding = '5px';
                                select.innerHTML = '<option value="0">不选择</option>';
                                
                                // 获取编辑时的值（兼容旧格式）
                                let selectedCount = 0;
                                if (editConfig[group.type]) {
                                    if (typeof editConfig[group.type] === 'object') {
                                        selectedCount = editConfig[group.type].count || 0;
                                    } else {
                                        selectedCount = editConfig[group.type];
                                    }
                                }
                                
                                for (let i = 1; i <= group.count; i++) {
                                    const option = document.createElement('option');
                                    option.value = i;
                                    option.textContent = i;
                                    if (i == selectedCount) {
                                        option.selected = true;
                                    }
                                    select.appendChild(option);
                                }
                                
                                const scoreLabel = document.createElement('label');
                                scoreLabel.textContent = '该题型总分：';
                                scoreLabel.style.minWidth = '100px';
                                
                                const scoreInput = document.createElement('input');
                                scoreInput.type = 'number';
                                scoreInput.name = 'question_scores[' + group.type + ']';
                                scoreInput.style.width = '100px';
                                scoreInput.style.padding = '5px';
                                scoreInput.min = '0';
                                scoreInput.placeholder = '0为平均分配';
                                
                                // 获取编辑时的分数值
                                if (editConfig[group.type] && typeof editConfig[group.type] === 'object') {
                                    scoreInput.value = editConfig[group.type].score || 0;
                                }
                                
                                configRow.appendChild(countLabel);
                                configRow.appendChild(select);
                                configRow.appendChild(scoreLabel);
                                configRow.appendChild(scoreInput);
                                
                                contentDiv.appendChild(configRow);
                                div.appendChild(contentDiv);
                                typeSelection.appendChild(div);
                                
                                // 拖拽事件
                                div.addEventListener('dragstart', function(e) {
                                    e.dataTransfer.effectAllowed = 'move';
                                    e.dataTransfer.setData('text/plain', group.type);
                                    this.classList.add('dragging');
                                });
                                
                                div.addEventListener('dragend', function(e) {
                                    this.classList.remove('dragging');
                                    // 重置所有元素的样式
                                    document.querySelectorAll('.question-type-item').forEach(item => {
                                        item.style.borderColor = '#ddd';
                                        item.style.backgroundColor = '#f9f9f9';
                                    });
                                });
                                
                                div.addEventListener('dragover', function(e) {
                                    e.preventDefault();
                                    e.dataTransfer.dropEffect = 'move';
                                    const dragging = document.querySelector('.dragging');
                                    if (!dragging || dragging === this) return;
                                    
                                    const afterElement = getDragAfterElement(typeSelection, e.clientY);
                                    if (afterElement == null) {
                                        typeSelection.appendChild(dragging);
                                    } else {
                                        typeSelection.insertBefore(dragging, afterElement);
                                    }
                                });
                                
                                div.addEventListener('dragenter', function(e) {
                                    e.preventDefault();
                                    if (!this.classList.contains('dragging')) {
                                        this.style.borderColor = '#667eea';
                                        this.style.backgroundColor = '#f0f4ff';
                                    }
                                });
                                
                                div.addEventListener('dragleave', function(e) {
                                    if (!this.classList.contains('dragging')) {
                                        this.style.borderColor = '#ddd';
                                        this.style.backgroundColor = '#f9f9f9';
                                    }
                                });
                                
                                div.addEventListener('drop', function(e) {
                                    e.preventDefault();
                                    if (!this.classList.contains('dragging')) {
                                        this.style.borderColor = '#ddd';
                                        this.style.backgroundColor = '#f9f9f9';
                                    }
                                });
                            });
                        } else {
                            typeSelection.innerHTML = '<p style="color: #e74c3c;">该科目暂无题目</p>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        typeSelection.innerHTML = '<p style="color: #e74c3c;">加载失败</p>';
                    });
            } else {
                typeSelection.innerHTML = '<p style="color: #666;">请先选择科目</p>';
            }
        }
        
        
        // 拖拽辅助函数
        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.question-type-item:not(.dragging)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        
        // 在表单提交前，收集题型顺序
        document.getElementById('paperForm').addEventListener('submit', function(e) {
            const typeOrder = [];
            const items = document.querySelectorAll('#modalQuestionTypeSelection .question-type-item');
            items.forEach(item => {
                const type = item.getAttribute('data-type');
                if (type) {
                    typeOrder.push(type);
                }
            });
            
            // 移除旧的顺序输入框
            const oldInputs = this.querySelectorAll('input[name^="type_order"]');
            oldInputs.forEach(input => input.remove());
            
            // 创建隐藏输入框保存顺序（使用数组格式）
            typeOrder.forEach((type) => {
                const orderInput = document.createElement('input');
                orderInput.type = 'hidden';
                orderInput.name = 'type_order[]';
                orderInput.value = type;
                this.appendChild(orderInput);
            });
        });
    </script>
    <style>
        .question-type-item.dragging {
            opacity: 0.5;
        }
        .question-type-item:hover {
            border-color: #667eea !important;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
        }
    </style>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

