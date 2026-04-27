<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

$message = '';
$message_type = '';

// 获取所有科目
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY id DESC");
$subjects = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    // 如果Excel中包含目录字段，则不需要手动选择科目
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
                
            } catch (Exception $e) {
                $message = 'Excel文件解析失败：' . $e->getMessage();
                $message_type = 'error';
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
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
    <title>导入题库 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>导入题库</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo escape($message); ?></div>
        <?php endif; ?>
        
        <div class="table-container">
            <h2>Excel文件格式说明</h2>
            <p>Excel文件第一行必须包含以下字段名（字段名必须完全匹配，顺序不限）：</p>
            <ul style="line-height: 2;">
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
            <p style="margin-top: 10px; color: #666;">
                <strong>说明：</strong>系统会根据第一行的字段名自动匹配，Excel中可以包含其他不需要导入的列，这些列会被忽略。
            </p>
        </div>
        
        <div class="table-container">
            <h2>上传Excel文件</h2>
            <form method="POST" enctype="multipart/form-data" style="margin-top: 20px;" id="importForm">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="use_excel_subject" value="1" id="use_excel_subject">
                        使用Excel中的"目录"字段作为科目（如果勾选，将根据Excel第一列的目录名称自动创建或匹配科目）
                    </label>
                </div>
                <div class="form-group" id="subject_select_group">
                    <label>选择科目 *（如果未勾选使用Excel目录字段）</label>
                    <select name="subject_id" id="subject_id_select">
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
                <button type="submit" class="btn btn-primary">导入题库</button>
            </form>
        </div>
        
        <script>
            document.getElementById('use_excel_subject').addEventListener('change', function() {
                const subjectSelect = document.getElementById('subject_id_select');
                const subjectGroup = document.getElementById('subject_select_group');
                if (this.checked) {
                    subjectSelect.removeAttribute('required');
                    subjectGroup.style.display = 'none';
                } else {
                    subjectSelect.setAttribute('required', 'required');
                    subjectGroup.style.display = 'block';
                }
            });
            
            // 表单提交验证
            document.getElementById('importForm').addEventListener('submit', function(e) {
                const useExcel = document.getElementById('use_excel_subject').checked;
                const subjectId = document.getElementById('subject_id_select').value;
                
                if (!useExcel && !subjectId) {
                    e.preventDefault();
                    alert('请选择科目或勾选"使用Excel中的目录字段"！');
                    return false;
                }
            });
        </script>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

