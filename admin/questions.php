<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$message = '';
$message_type = '';

// 删除题目
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
    if ($stmt->execute([$id])) {
        $message = '题目删除成功！';
        $message_type = 'success';
        logAdminAction($pdo, '删除题目', 'success', "ID={$id}");
    }
}

// 批量删除题目
if (isset($_POST['action']) && $_POST['action'] == 'batch_delete' && isset($_POST['question_ids'])) {
    $question_ids = array_filter(array_map('intval', explode(',', $_POST['question_ids'])));
    if (!empty($question_ids)) {
        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($placeholders)");
        if ($stmt->execute($question_ids)) {
            // 构建重定向URL，保留筛选条件和分页参数
            $redirect_url = 'questions.php?msg=batch_delete_success&count=' . count($question_ids);
            logAdminAction($pdo, '批量删除题目', 'success', 'IDs=' . implode(',', $question_ids));
            if (isset($_POST['subject_id']) && intval($_POST['subject_id']) > 0) {
                $redirect_url .= '&subject_id=' . intval($_POST['subject_id']);
            }
            if (isset($_POST['keyword']) && !empty(trim($_POST['keyword']))) {
                $redirect_url .= '&keyword=' . urlencode(trim($_POST['keyword']));
            }
            if (isset($_POST['per_page']) && intval($_POST['per_page']) >= 0) {
                $redirect_url .= '&per_page=' . intval($_POST['per_page']);
            }
            if (isset($_POST['page']) && intval($_POST['page']) > 0) {
                $redirect_url .= '&page=' . intval($_POST['page']);
            }
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $message = '批量删除失败！';
            $message_type = 'error';
        }
    }
}

// 添加题目
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $question_type = trim($_POST['question_type'] ?? '');
    $question_text = trim($_POST['question_text'] ?? '');
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_answer = trim($_POST['correct_answer'] ?? '');
    $answer_analysis = trim($_POST['answer_analysis'] ?? '');
    $knowledge_point = trim($_POST['knowledge_point'] ?? '');
    
    if ($subject_id > 0 && !empty($question_type) && !empty($question_text) && !empty($correct_answer)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO questions (subject_id, question_type, question_text, 
                               option_a, option_b, option_c, option_d, 
                               correct_answer, answer_analysis, knowledge_point) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$subject_id, $question_type, $question_text, $option_a, $option_b, $option_c, $option_d, 
                            $correct_answer, $answer_analysis, $knowledge_point])) {
                $question_id = $pdo->lastInsertId();
                $message = '题目添加成功！';
                $message_type = 'success';
                logAdminAction($pdo, '添加题目', 'success', "ID={$question_id}, 科目ID={$subject_id}, 类型={$question_type}");
            } else {
                $message = '题目添加失败！';
                $message_type = 'error';
                logAdminAction($pdo, '添加题目', 'failed', "科目ID={$subject_id}, 类型={$question_type}");
            }
        } catch (PDOException $e) {
            $message = '题目添加失败：' . $e->getMessage();
            $message_type = 'error';
            logAdminAction($pdo, '添加题目', 'failed', "科目ID={$subject_id}, 错误: " . $e->getMessage());
        }
    } else {
        $message = '请填写完整信息！';
        $message_type = 'error';
        logAdminAction($pdo, '添加题目', 'failed', '参数不足');
    }
}

// 编辑题目
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $question_type = trim($_POST['question_type'] ?? '');
    $question_text = trim($_POST['question_text'] ?? '');
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_answer = trim($_POST['correct_answer'] ?? '');
    $answer_analysis = trim($_POST['answer_analysis'] ?? '');
    $knowledge_point = trim($_POST['knowledge_point'] ?? '');
    
    if ($id > 0 && $subject_id > 0 && !empty($question_type) && !empty($question_text) && !empty($correct_answer)) {
        $stmt = $pdo->prepare("UPDATE questions SET subject_id = ?, question_type = ?, question_text = ?, 
                               option_a = ?, option_b = ?, option_c = ?, option_d = ?, 
                               correct_answer = ?, answer_analysis = ?, knowledge_point = ? 
                               WHERE id = ?");
        if ($stmt->execute([$subject_id, $question_type, $question_text, $option_a, $option_b, $option_c, $option_d, 
                            $correct_answer, $answer_analysis, $knowledge_point, $id])) {
            $message = '题目更新成功！';
            $message_type = 'success';
            logAdminAction($pdo, '更新题目', 'success', "ID={$id}");
        } else {
            $message = '题目更新失败！';
            $message_type = 'error';
            logAdminAction($pdo, '更新题目', 'failed', "ID={$id}");
        }
    } else {
        $message = '请填写完整信息！';
        $message_type = 'error';
        logAdminAction($pdo, '更新题目', 'failed', '参数不足');
    }
}

// 导入题库
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'import' && isset($_FILES['excel_file'])) {
    $use_excel_subject = isset($_POST['use_excel_subject']) && $_POST['use_excel_subject'] == '1';
    $subject_id = !$use_excel_subject ? intval($_POST['subject_id'] ?? 0) : 0;
    
    if (!$use_excel_subject && $subject_id <= 0) {
        $message = '请选择科目或选择使用Excel中的目录字段！';
        $message_type = 'error';
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != UPLOAD_ERR_OK) {
        $message = '文件上传失败！';
        $message_type = 'error';
    } else {
        $file = $_FILES['excel_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
            $message = '请上传Excel文件（.xls, .xlsx, .csv）！';
            $message_type = 'error';
        } else {
            // 使用PhpSpreadsheet库处理Excel文件
            require_once '../vendor/autoload.php';
            
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_path = $upload_dir . uniqid() . '_' . $file['name'];
            move_uploaded_file($file['tmp_name'], $file_path);
            
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
                $spreadsheet = $reader->load($file_path);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                if (count($rows) < 2) {
                    throw new Exception('Excel文件至少需要包含表头和数据行');
                }
                
                // 读取第一行作为表头，找到各字段的列索引
                $header = $rows[0];
                $column_map = [];
                
                // 字段名映射（Excel字段名 => 数据库字段名）
                $field_mapping = [
                    '目录' => 'subject_name',
                    '题目类型' => 'question_type',
                    '大题题干' => 'question_text',
                    '选项A' => 'option_a',
                    '选项B' => 'option_b',
                    '选项C' => 'option_c',
                    '选项D' => 'option_d',
                    '正确答案' => 'correct_answer',
                    '答案解析' => 'answer_analysis',
                    '知识点' => 'knowledge_point'
                ];
                
                // 查找每个字段在表头中的位置
                foreach ($header as $col_index => $header_name) {
                    $header_name = trim($header_name ?? '');
                    foreach ($field_mapping as $excel_field => $db_field) {
                        if ($header_name == $excel_field) {
                            $column_map[$db_field] = $col_index;
                            break;
                        }
                    }
                }
                
                // 检查必需字段是否存在
                $required_fields = ['question_type', 'question_text', 'correct_answer'];
                $missing_fields = [];
                foreach ($required_fields as $field) {
                    if (!isset($column_map[$field])) {
                        $missing_fields[] = array_search($field, $field_mapping);
                    }
                }
                
                if (!empty($missing_fields)) {
                    throw new Exception('缺少必需字段：' . implode('、', $missing_fields));
                }
                
                // 从第二行开始读取数据
                $success_count = 0;
                $error_count = 0;
                
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    
                    // 检查行是否为空
                    if (empty(array_filter($row))) {
                        continue;
                    }
                    
                    // 根据字段名读取数据
                    $subject_name = isset($column_map['subject_name']) ? trim($row[$column_map['subject_name']] ?? '') : '';
                    $question_type = isset($column_map['question_type']) ? trim($row[$column_map['question_type']] ?? '') : '';
                    $question_text = isset($column_map['question_text']) ? trim($row[$column_map['question_text']] ?? '') : '';
                    $option_a = isset($column_map['option_a']) ? trim($row[$column_map['option_a']] ?? '') : '';
                    $option_b = isset($column_map['option_b']) ? trim($row[$column_map['option_b']] ?? '') : '';
                    $option_c = isset($column_map['option_c']) ? trim($row[$column_map['option_c']] ?? '') : '';
                    $option_d = isset($column_map['option_d']) ? trim($row[$column_map['option_d']] ?? '') : '';
                    $correct_answer = isset($column_map['correct_answer']) ? trim($row[$column_map['correct_answer']] ?? '') : '';
                    $answer_analysis = isset($column_map['answer_analysis']) ? trim($row[$column_map['answer_analysis']] ?? '') : '';
                    $knowledge_point = isset($column_map['knowledge_point']) ? trim($row[$column_map['knowledge_point']] ?? '') : '';
                    
                    // 清理HTML实体和多余空格（如 &nbsp;）
                    $clean_fields = [&$question_text, &$option_a, &$option_b, &$option_c, &$option_d, &$correct_answer, &$answer_analysis];
                    foreach ($clean_fields as &$field) {
                        $field = str_replace(['&nbsp;', '&amp;nbsp;'], ' ', $field);
                        $field = str_replace("\xC2\xA0", ' ', $field);  // UTF-8 不间断空格
                        $field = preg_replace('/\s{2,}/', ' ', $field); // 连续空格合并
                        $field = trim($field);
                    }
                    unset($field);
                    
                    // 填空题/简答题特殊处理：正确答案为空时，从选项A中提取答案
                    if (empty($correct_answer) && !empty($option_a) && in_array($question_type, ['填空题', '简答题'])) {
                        $correct_answer = $option_a;
                        // 填空题/简答题不需要选项，清空选项字段
                        $option_a = '';
                        $option_b = '';
                        $option_c = '';
                        $option_d = '';
                    }
                    
                    // 处理科目ID
                    $current_subject_id = $subject_id;
                    if ($use_excel_subject && !empty($subject_name)) {
                        // 根据Excel中的目录（科目名称）查找或创建科目
                        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE name = ?");
                        $stmt->execute([$subject_name]);
                        $existing_subject = $stmt->fetch();
                        
                        if ($existing_subject) {
                            $current_subject_id = $existing_subject['id'];
                        } else {
                            // 如果科目不存在，创建新科目
                            $stmt = $pdo->prepare("INSERT INTO subjects (name) VALUES (?)");
                            $stmt->execute([$subject_name]);
                            $current_subject_id = $pdo->lastInsertId();
                            logAdminAction($pdo, '创建科目', 'success', "名称={$subject_name}（导入时自动创建）");
                        }
                    }
                    
                    if (!empty($question_text) && !empty($correct_answer) && $current_subject_id > 0) {
                        $stmt = $pdo->prepare("INSERT INTO questions 
                            (subject_id, question_type, question_text, option_a, option_b, option_c, option_d, 
                             correct_answer, answer_analysis, knowledge_point) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        
                        if ($stmt->execute([
                            $current_subject_id, $question_type, $question_text, 
                            $option_a, $option_b, $option_c, $option_d,
                            $correct_answer, $answer_analysis, $knowledge_point
                        ])) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $error_count++;
                    }
                }
                
                unlink($file_path); // 删除临时文件
                
                $message = "导入完成！成功：{$success_count} 条，失败：{$error_count} 条";
                $message_type = $error_count > 0 ? 'error' : 'success';
                logAdminAction($pdo, '导入题库', 'success', "成功={$success_count}, 失败={$error_count}");
                
            } catch (Exception $e) {
                $message = 'Excel文件解析失败：' . $e->getMessage();
                $message_type = 'error';
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                logAdminAction($pdo, '导入题库', 'failed', $e->getMessage());
            }
        }
    }
}

// 处理批量删除成功消息
if (isset($_GET['msg']) && $_GET['msg'] == 'batch_delete_success' && isset($_GET['count'])) {
    $message = '成功删除 ' . intval($_GET['count']) . ' 道题目！';
    $message_type = 'success';
}

// 获取筛选条件
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

// 分页参数
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 构建查询
$where = [];
$params = [];

if ($subject_id > 0) {
    $where[] = "q.subject_id = ?";
    $params[] = $subject_id;
}

if (!empty($keyword)) {
    $where[] = "(q.question_text LIKE ? OR q.knowledge_point LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// 获取所有科目（优化：只获取需要的字段）
$stmt = $pdo->query("SELECT id, name FROM subjects ORDER BY id DESC");
$subjects = $stmt->fetchAll();

// 获取总记录数
$count_sql = "SELECT COUNT(*) as total FROM questions q $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;
}

// 获取题目列表
$sql = "SELECT q.*, s.name as subject_name FROM questions q 
        LEFT JOIN subjects s ON q.subject_id = s.id 
        $where_sql 
        ORDER BY q.id DESC";
if ($per_page > 0) {
    $sql .= " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>题库管理 - 后台管理</title>
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
        .table-container table td:nth-child(2) {
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
            max-width: 900px;
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
        <h2>题库管理</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        
        <div class="table-container">
            <h2>筛选条件</h2>
            <form method="GET" style="margin-top: 20px;">
                <div class="form-row" style="align-items: center; gap: 12px;">
                    <div class="form-group">
                        <label>科目</label>
                        <select name="subject_id">
                            <option value="">全部科目</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" 
                                    <?php echo $subject_id == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($subject['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>关键词搜索</label>
                        <input type="text" name="keyword" value="<?php echo escape($keyword); ?>" placeholder="题干或知识点">
                    </div>
                    <div class="form-group">
                        <label>每页显示</label>
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                            <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                        </select>
                    </div>
                    <input type="hidden" name="page" value="1">
                    <div class="form-group" style="margin-bottom: 0; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" style="margin: 0;">搜索</button>
                        <a href="questions.php" class="btn btn-warning" style="margin: 0;">重置</a>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
                <h2 style="margin: 0; padding: 0; border: none;">
                    题目列表
                    <?php if ($per_page > 0): ?>
                        （共<?php echo $total_records; ?>条，第<?php echo $current_page; ?>/<?php echo $total_pages; ?>页）
                    <?php else: ?>
                        （共<?php echo $total_records; ?>条，全部显示）
                    <?php endif; ?>
                </h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">➕ 添加题目</button>
                    <button type="button" class="btn btn-success" onclick="openImportModal()">📥 导入题库</button>
                    <button type="button" id="batchDeleteBtn" class="btn btn-danger" style="display: none;" onclick="batchDelete()">
                        批量删除 (<span id="selectedCount">0</span>)
                    </button>
                </div>
            </div>
            <form id="batchDeleteForm" method="POST" action="questions.php" style="display: none;">
                <input type="hidden" name="action" value="batch_delete">
                <input type="hidden" name="question_ids" id="questionIds" value="">
                <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                <input type="hidden" name="keyword" value="<?php echo escape($keyword); ?>">
                <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
                <input type="hidden" name="page" value="<?php echo $current_page; ?>">
            </form>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                        </th>
                        <th>ID</th>
                        <th>科目</th>
                        <th>类型</th>
                        <th>题干</th>
                        <th>知识点</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($questions)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">暂无题目</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($questions as $q): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="question-checkbox" value="<?php echo $q['id']; ?>" onchange="updateSelectedCount()">
                                </td>
                                <td><?php echo $q['id']; ?></td>
                                <td><?php echo escape($q['subject_name'] ?? ''); ?></td>
                                <td><?php echo escape($q['question_type']); ?></td>
                                <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo escape(mb_substr($q['question_text'], 0, 50)); ?>...
                                </td>
                                <td><?php echo escape($q['knowledge_point'] ?? ''); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-primary" onclick="openEditModal(<?php echo $q['id']; ?>)">编辑</button>
                                        <?php
                                        // 构建删除URL，保留所有查询参数
                                        $delete_url = '?action=delete&id=' . $q['id'];
                                        if ($subject_id > 0) $delete_url .= '&subject_id=' . $subject_id;
                                        if (!empty($keyword)) $delete_url .= '&keyword=' . urlencode($keyword);
                                        if ($per_page > 0) $delete_url .= '&per_page=' . $per_page;
                                        $delete_url .= '&page=' . $current_page;
                                        ?>
                                        <a href="<?php echo $delete_url; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('确定要删除这道题吗？')">删除</a>
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
                    if ($subject_id > 0) $url_params[] = 'subject_id=' . $subject_id;
                    if (!empty($keyword)) $url_params[] = 'keyword=' . urlencode($keyword);
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
        
        <!-- 添加/编辑题目模态框 -->
        <div id="questionModal" class="modal-overlay" onclick="if(event.target === this) closeModal()">
            <div class="modal-content" onclick="event.stopPropagation()">
                <div class="modal-header">
                    <h2 id="modalTitle">添加题目</h2>
                    <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="questionForm" method="POST">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="questionId" value="">
                        <div class="form-row">
                            <div class="form-group">
                                <label>科目 *</label>
                                <select name="subject_id" id="formSubjectId" required>
                                    <option value="">请选择科目</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>">
                                            <?php echo escape($subject['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>题目类型 *</label>
                                <select name="question_type" id="formQuestionType" required>
                                    <option value="">请选择类型</option>
                                    <option value="单选题">单选题</option>
                                    <option value="多选题">多选题</option>
                                    <option value="判断题">判断题</option>
                                    <option value="填空题">填空题</option>
                                    <option value="名词解释">名词解释</option>
                                    <option value="简答题">简答题</option>
                                    <option value="实操论述题">实操论述题</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>题干 *</label>
                            <textarea name="question_text" id="formQuestionText" required style="min-height: 80px;"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>选项A</label>
                                <textarea name="option_a" id="formOptionA" style="min-height: 60px;"></textarea>
                            </div>
                            <div class="form-group">
                                <label>选项B</label>
                                <textarea name="option_b" id="formOptionB" style="min-height: 60px;"></textarea>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>选项C</label>
                                <textarea name="option_c" id="formOptionC" style="min-height: 60px;"></textarea>
                            </div>
                            <div class="form-group">
                                <label>选项D</label>
                                <textarea name="option_d" id="formOptionD" style="min-height: 60px;"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>正确答案 *</label>
                            <textarea name="correct_answer" id="formCorrectAnswer" required style="min-height: 80px;"></textarea>
                        </div>
                        <div class="form-group">
                            <label>答案解析</label>
                            <textarea name="answer_analysis" id="formAnswerAnalysis" style="min-height: 100px;"></textarea>
                        </div>
                        <div class="form-group">
                            <label>知识点</label>
                            <input type="text" name="knowledge_point" id="formKnowledgePoint">
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary" id="submitBtn">添加题目</button>
                            <button type="button" class="btn btn-warning" onclick="closeModal()">取消</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- 导入题库模态框 -->
        <div id="importModal" class="modal-overlay" onclick="if(event.target === this) closeImportModal()">
            <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 800px;">
                <div class="modal-header">
                    <h2>导入题库</h2>
                    <button type="button" class="modal-close" onclick="closeImportModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <h3 style="margin-top: 0; margin-bottom: 10px; font-size: 16px;">Excel文件格式说明</h3>
                        <p style="margin-bottom: 10px;">Excel文件第一行必须包含以下字段名（字段名必须完全匹配，顺序不限）：</p>
                        <ul style="line-height: 2; margin-bottom: 0;">
                            <li><strong>目录</strong>（科目名称，如果选择"使用Excel中的目录字段"，则根据此字段自动创建或匹配科目）</li>
                            <li><strong>题目类型</strong>（必需）</li>
                            <li><strong>大题题干</strong>（必需）</li>
                            <li><strong>选项A</strong></li>
                            <li><strong>选项B</strong></li>
                            <li><strong>选项C</strong></li>
                            <li><strong>选项D</strong></li>
                            <li><strong>正确答案</strong>（必需）</li>
                            <li><strong>答案解析</strong></li>
                            <li><strong>知识点</strong></li>
                        </ul>
                        <p style="margin-top: 10px; margin-bottom: 0; color: #666; font-size: 12px;">
                            <strong>说明：</strong>系统会根据第一行的字段名自动匹配，Excel中可以包含其他不需要导入的列，这些列会被忽略。
                        </p>
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <input type="hidden" name="action" value="import">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="use_excel_subject" value="1" id="use_excel_subject" onchange="toggleSubjectSelect()">
                                使用Excel中的"目录"字段作为科目（如果勾选，将根据Excel第一列的目录名称自动创建或匹配科目）
                            </label>
                        </div>
                        <div class="form-group" id="subject_select_group">
                            <label>选择科目 *（如果未勾选使用Excel目录字段）</label>
                            <select name="subject_id" id="import_subject_id">
                                <option value="">请选择科目</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo escape($subject['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>选择Excel文件 *</label>
                            <input type="file" name="excel_file" accept=".xls,.xlsx,.csv" required>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">导入题库</button>
                            <button type="button" class="btn btn-warning" onclick="closeImportModal()">取消</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            // 打开添加模态框
            function openAddModal() {
                document.getElementById('modalTitle').textContent = '添加题目';
                document.getElementById('formAction').value = 'add';
                document.getElementById('questionId').value = '';
                document.getElementById('questionForm').reset();
                document.getElementById('submitBtn').textContent = '添加题目';
                document.getElementById('questionModal').classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            
            // 打开编辑模态框
            function openEditModal(questionId) {
                // 通过AJAX获取题目信息
                fetch('get_question.php?id=' + questionId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const q = data.question;
                            document.getElementById('modalTitle').textContent = '编辑题目';
                            document.getElementById('formAction').value = 'edit';
                            document.getElementById('questionId').value = q.id;
                            document.getElementById('formSubjectId').value = q.subject_id;
                            document.getElementById('formQuestionType').value = q.question_type;
                            document.getElementById('formQuestionText').value = q.question_text || '';
                            document.getElementById('formOptionA').value = q.option_a || '';
                            document.getElementById('formOptionB').value = q.option_b || '';
                            document.getElementById('formOptionC').value = q.option_c || '';
                            document.getElementById('formOptionD').value = q.option_d || '';
                            document.getElementById('formCorrectAnswer').value = q.correct_answer || '';
                            document.getElementById('formAnswerAnalysis').value = q.answer_analysis || '';
                            document.getElementById('formKnowledgePoint').value = q.knowledge_point || '';
                            document.getElementById('submitBtn').textContent = '更新题目';
                            document.getElementById('questionModal').classList.add('active');
                            document.body.style.overflow = 'hidden';
                        } else {
                            alert('获取题目信息失败：' + (data.message || '未知错误'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('获取题目信息失败，请刷新页面重试');
                    });
            }
            
            // 关闭模态框
            function closeModal() {
                document.getElementById('questionModal').classList.remove('active');
                document.body.style.overflow = '';
            }
            
            // ESC键关闭模态框
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    closeImportModal();
                }
            });
            
            // 打开导入模态框
            function openImportModal() {
                document.getElementById('importModal').classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            
            // 关闭导入模态框
            function closeImportModal() {
                document.getElementById('importModal').classList.remove('active');
                document.body.style.overflow = '';
            }
            
            // 切换科目选择显示
            function toggleSubjectSelect() {
                const useExcel = document.getElementById('use_excel_subject').checked;
                const subjectSelect = document.getElementById('import_subject_id');
                const subjectGroup = document.getElementById('subject_select_group');
                if (useExcel) {
                    subjectSelect.removeAttribute('required');
                    subjectGroup.style.display = 'none';
                } else {
                    subjectSelect.setAttribute('required', 'required');
                    subjectGroup.style.display = 'block';
                }
            }
            
            // 导入表单提交验证
            document.getElementById('importForm').addEventListener('submit', function(e) {
                const useExcel = document.getElementById('use_excel_subject').checked;
                const subjectId = document.getElementById('import_subject_id').value;
                
                if (!useExcel && !subjectId) {
                    e.preventDefault();
                    alert('请选择科目或勾选"使用Excel中的目录字段"！');
                    return false;
                }
            });
            
            function toggleSelectAll() {
                const selectAll = document.getElementById('selectAll');
                const checkboxes = document.querySelectorAll('.question-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = selectAll.checked;
                });
                updateSelectedCount();
            }
            
            function updateSelectedCount() {
                const checkboxes = document.querySelectorAll('.question-checkbox:checked');
                const count = checkboxes.length;
                const selectedCountEl = document.getElementById('selectedCount');
                const batchDeleteBtn = document.getElementById('batchDeleteBtn');
                
                selectedCountEl.textContent = count;
                batchDeleteBtn.style.display = count > 0 ? 'inline-block' : 'none';
                
                // 更新全选复选框状态
                const allCheckboxes = document.querySelectorAll('.question-checkbox');
                const selectAll = document.getElementById('selectAll');
                selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
                selectAll.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
            }
            
            function batchDelete() {
                const checkboxes = document.querySelectorAll('.question-checkbox:checked');
                if (checkboxes.length === 0) {
                    alert('请先选择要删除的题目！');
                    return;
                }
                
                if (!confirm('确定要删除选中的 ' + checkboxes.length + ' 道题目吗？此操作不可恢复！')) {
                    return;
                }
                
                const ids = Array.from(checkboxes).map(cb => cb.value);
                document.getElementById('questionIds').value = ids.join(',');
                
                // 将当前URL的筛选参数添加到表单中
                const urlParams = new URLSearchParams(window.location.search);
                const form = document.getElementById('batchDeleteForm');
                
                // 添加筛选参数到表单
                if (urlParams.has('subject_id') && urlParams.get('subject_id')) {
                    let subjectInput = form.querySelector('input[name="subject_id"]');
                    if (!subjectInput) {
                        subjectInput = document.createElement('input');
                        subjectInput.type = 'hidden';
                        subjectInput.name = 'subject_id';
                        form.appendChild(subjectInput);
                    }
                    subjectInput.value = urlParams.get('subject_id');
                }
                
                if (urlParams.has('keyword') && urlParams.get('keyword')) {
                    let keywordInput = form.querySelector('input[name="keyword"]');
                    if (!keywordInput) {
                        keywordInput = document.createElement('input');
                        keywordInput.type = 'hidden';
                        keywordInput.name = 'keyword';
                        form.appendChild(keywordInput);
                    }
                    keywordInput.value = urlParams.get('keyword');
                }
                
                form.submit();
            }
        </script>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

