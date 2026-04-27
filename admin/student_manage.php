<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// 读取XLSX文件的函数
function readXLSX($file_path) {
    $rows = [];
    if (!class_exists('ZipArchive')) {
        throw new Exception('需要启用PHP的Zip扩展才能读取XLSX文件');
    }
    
    $zip = new ZipArchive();
    $result = $zip->open($file_path);
    if ($result !== TRUE) {
        throw new Exception('无法打开XLSX文件，错误代码：' . $result);
    }
    
    // 读取共享字符串
    $shared_strings = [];
    if (($shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($shared_strings_xml);
        if ($xml !== false) {
            $namespaces = $xml->getNamespaces(true);
            $ns = isset($namespaces['']) ? $namespaces[''] : '';
            
            if (isset($xml->si)) {
                foreach ($xml->si as $si) {
                    $text = '';
                    // 处理文本节点（可能有多个t节点，需要合并）
                    if (isset($si->t)) {
                        foreach ($si->t as $t) {
                            $text .= (string)$t;
                        }
                    } elseif (isset($si->children($ns)->t)) {
                        foreach ($si->children($ns)->t as $t) {
                            $text .= (string)$t;
                        }
                    }
                    $shared_strings[] = $text;
                }
            }
        }
    }
    
    // 获取第一个工作表名称（从workbook.xml）
    $sheet_name = 'sheet1.xml';
    if (($workbook_xml = $zip->getFromName('xl/workbook.xml')) !== false) {
        $workbook = @simplexml_load_string($workbook_xml);
        if ($workbook !== false) {
            $wb_ns = $workbook->getNamespaces(true);
            $wb_main_ns = isset($wb_ns['']) ? $wb_ns[''] : '';
            
            // 获取第一个sheet的r:id
            if (isset($workbook->sheets->sheet[0])) {
                $sheet = $workbook->sheets->sheet[0];
                $r_id = (string)$sheet['r:id'];
                
                // 从relationships中获取实际的文件名
                if (($rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
                    $rels = @simplexml_load_string($rels_xml);
                    if ($rels !== false) {
                        foreach ($rels->Relationship as $rel) {
                            if ((string)$rel['Id'] === $r_id) {
                                $target = (string)$rel['Target'];
                                $sheet_name = basename($target);
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // 读取工作表数据
    $sheet_path = 'xl/worksheets/' . $sheet_name;
    if (($sheet_xml = $zip->getFromName($sheet_path)) === false) {
        // 如果找不到，尝试默认的sheet1.xml
        $sheet_path = 'xl/worksheets/sheet1.xml';
        $sheet_xml = $zip->getFromName($sheet_path);
    }
    
    if ($sheet_xml !== false) {
        $xml = @simplexml_load_string($sheet_xml);
        if ($xml !== false) {
            $namespaces = $xml->getNamespaces(true);
            $ns = isset($namespaces['']) ? $namespaces[''] : '';
            
            if (isset($xml->sheetData)) {
                $sheet_data = $xml->sheetData;
                
                foreach ($sheet_data->row as $row) {
                    $row_data = [];
                    $row_num = isset($row['r']) ? (int)$row['r'] : 0;
                    
                    if (isset($row->c)) {
                        foreach ($row->c as $cell) {
                            $cell_ref = isset($cell['r']) ? (string)$cell['r'] : '';
                            $cell_type = isset($cell['t']) ? (string)$cell['t'] : '';
                            $cell_value = isset($cell->v) ? (string)$cell->v : '';
                            
                            // 获取列索引（A=0, B=1, ...）
                            if (!empty($cell_ref)) {
                                preg_match('/([A-Z]+)(\d+)/', $cell_ref, $matches);
                                $col_letter = $matches[1] ?? '';
                                $col_index = 0;
                                
                                if (!empty($col_letter)) {
                                    for ($i = 0; $i < strlen($col_letter); $i++) {
                                        $col_index = $col_index * 26 + (ord($col_letter[$i]) - ord('A') + 1);
                                    }
                                    $col_index--;
                                } else {
                                    // 如果没有引用，使用顺序索引
                                    $col_index = count($row_data);
                                }
                                
                                // 如果是共享字符串，从共享字符串数组中获取
                                if ($cell_type == 's' && !empty($cell_value) && isset($shared_strings[(int)$cell_value])) {
                                    $cell_value = $shared_strings[(int)$cell_value];
                                }
                                
                                // 处理内联字符串（inlineStr）
                                if ($cell_type == 'inlineStr' && isset($cell->is->t)) {
                                    $cell_value = (string)$cell->is->t;
                                }
                                
                                // 确保数组足够大
                                while (count($row_data) <= $col_index) {
                                    $row_data[] = '';
                                }
                                $row_data[$col_index] = $cell_value;
                            } else {
                                // 没有引用，按顺序添加
                                $row_data[] = $cell_value;
                            }
                        }
                    }
                    
                    // 只添加非空行
                    $has_data = false;
                    foreach ($row_data as $val) {
                        if (!empty(trim($val))) {
                            $has_data = true;
                            break;
                        }
                    }
                    
                    if ($has_data) {
                        $rows[] = $row_data;
                    }
                }
            }
        } else {
            $zip->close();
            throw new Exception('无法解析工作表XML数据');
        }
    } else {
        $zip->close();
        throw new Exception('无法找到工作表文件，尝试的文件：' . $sheet_path);
    }
    
    $zip->close();
    
    if (empty($rows)) {
        throw new Exception('XLSX文件中没有找到数据行');
    }
    
    return $rows;
}

// 读取XLS文件的函数
function readXLS($file_path) {
    // XLS格式（OLE2格式）较复杂，这里提供一个基础实现
    // 尝试使用系统命令转换（如果可用）
    if (function_exists('shell_exec')) {
        // 方法1: 尝试使用libreoffice转换
        $converted_file = dirname($file_path) . '/converted_' . basename($file_path, '.xls') . '.csv';
        $command = "libreoffice --headless --convert-to csv --outdir " . escapeshellarg(dirname($file_path)) . " " . escapeshellarg($file_path) . " 2>&1";
        @shell_exec($command);
        
        if (file_exists($converted_file)) {
            $rows = [];
            $handle = fopen($converted_file, 'r');
            if ($handle !== false) {
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            }
            @unlink($converted_file);
            if (!empty($rows)) {
                return $rows;
            }
        }
        
        // 方法2: 尝试使用unoconv转换
        $converted_file2 = $file_path . '.csv';
        $command2 = "unoconv -f csv " . escapeshellarg($file_path) . " 2>&1";
        @shell_exec($command2);
        
        if (file_exists($converted_file2)) {
            $rows = [];
            $handle = fopen($converted_file2, 'r');
            if ($handle !== false) {
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
            }
            @unlink($converted_file2);
            if (!empty($rows)) {
                return $rows;
            }
        }
    }
    
    // 如果无法转换，尝试使用简单的OLE读取（基础实现）
    // 注意：这是一个简化实现，可能无法处理所有XLS文件
    // 建议用户转换为XLSX或CSV格式以获得最佳兼容性
    throw new Exception('XLS格式文件读取失败。建议将文件另存为XLSX或CSV格式后重新上传。如果服务器安装了LibreOffice，可以自动转换XLS文件。');
}

$message = '';
$message_type = '';

// 处理Excel/CSV文件上传
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'import') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['excel_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // 支持的文件格式：csv, xls, xlsx
        if (!in_array($file_ext, ['csv', 'xls', 'xlsx'])) {
            $message = '不支持的文件格式，请上传CSV、XLS或XLSX文件！';
            $message_type = 'error';
        } else {
            $upload_path = '../uploads/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0755, true);
            }
            
            $file_name = 'student_import_' . time() . '.' . $file_ext;
            $file_path = $upload_path . $file_name;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                try {
                    // 使用PhpSpreadsheet库处理Excel文件（与导入题库页面相同的方法）
                    require_once '../vendor/autoload.php';
                    
                    $imported = 0;
                    $updated = 0;
                    $classes = [];
                    
                    // 使用PhpSpreadsheet读取Excel文件
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
                        '学号' => 'student_no',
                        'student_no' => 'student_no',
                        'studentno' => 'student_no',
                        '学号/工号' => 'student_no',
                        'student id' => 'student_no',
                        'student_id' => 'student_no',
                        '姓名' => 'name',
                        'name' => 'name',
                        '学生姓名' => 'name',
                        '姓名/name' => 'name',
                        '学生名字' => 'name',
                        '班级' => 'class',
                        'class' => 'class',
                        '班级名称' => 'class',
                        'class name' => 'class',
                        'class_name' => 'class'
                    ];
                    
                    // 查找每个字段在表头中的位置
                    foreach ($header as $col_index => $header_name) {
                        $header_name = trim($header_name ?? '');
                        if (empty($header_name)) continue;
                        
                        // 转换为小写进行匹配（不区分大小写）
                        $header_lower = mb_strtolower($header_name, 'UTF-8');
                        $header_lower = str_replace([' ', '_', '/', '-'], '', $header_lower); // 移除空格、下划线、斜杠、横线
                        
                        foreach ($field_mapping as $excel_field => $db_field) {
                            $excel_field_lower = mb_strtolower($excel_field, 'UTF-8');
                            $excel_field_lower = str_replace([' ', '_', '/', '-'], '', $excel_field_lower);
                            
                            // 精确匹配或包含匹配
                            if ($header_lower == $excel_field_lower || 
                                $header_name == $excel_field ||
                                strpos($header_lower, $excel_field_lower) !== false ||
                                strpos($excel_field_lower, $header_lower) !== false) {
                                $column_map[$db_field] = $col_index;
                                break;
                            }
                        }
                    }
                    
                    // 如果没找到列名，使用默认顺序：学号、姓名、班级
                    if (!isset($column_map['student_no'])) {
                        // 尝试通过位置推断（第一列通常是学号）
                        $column_map['student_no'] = 0;
                    }
                    if (!isset($column_map['name'])) {
                        $column_map['name'] = 1;
                    }
                    if (!isset($column_map['class'])) {
                        $column_map['class'] = 2;
                    }
                    
                    // 从第二行开始读取数据
                    $processed_rows = 0;
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // 检查行是否为空（过滤掉所有空值）
                        $row_filtered = array_filter($row, function($val) {
                            return !empty(trim((string)$val));
                        });
                        if (empty($row_filtered)) {
                            continue;
                        }
                        
                        // 根据字段名读取数据
                        $student_no = '';
                        $name = '';
                        $class = '';
                        
                        // 获取学号
                        if (isset($column_map['student_no']) && isset($row[$column_map['student_no']])) {
                            $student_no = trim((string)$row[$column_map['student_no']]);
                            // 处理数字格式的学号（Excel可能将其转换为数字）
                            if (is_numeric($student_no)) {
                                $student_no = (string)intval($student_no);
                            }
                        }
                        
                        // 获取姓名
                        if (isset($column_map['name']) && isset($row[$column_map['name']])) {
                            $name = trim((string)$row[$column_map['name']]);
                        }
                        
                        // 获取班级
                        if (isset($column_map['class']) && isset($row[$column_map['class']])) {
                            $class = trim((string)$row[$column_map['class']]);
                            // 处理数字格式的班级
                            if (is_numeric($class)) {
                                $class = (string)intval($class);
                            }
                        }
                        
                        // 跳过学号为空的记录
                        if (empty($student_no)) {
                            continue;
                        }
                        
                        $processed_rows++;
                        
                        // 收集班级信息
                        if (!empty($class) && !in_array($class, $classes)) {
                            $classes[] = $class;
                        }
                        
                        try {
                            // 检查学生是否已存在
                            $stmt = $pdo->prepare("SELECT id FROM students WHERE student_no = ?");
                            $stmt->execute([$student_no]);
                            $existing = $stmt->fetch();
                            
                            if ($existing) {
                                // 学号已存在，覆盖更新原有记录
                                $stmt = $pdo->prepare("UPDATE students SET name = ?, class = ? WHERE student_no = ?");
                                $stmt->execute([$name, $class, $student_no]);
                                $updated++;
                            } else {
                                // 学号不存在，插入新记录
                                $stmt = $pdo->prepare("INSERT INTO students (student_no, name, class) VALUES (?, ?, ?)");
                                $stmt->execute([$student_no, $name, $class]);
                                $imported++;
                            }
                        } catch (PDOException $e) {
                            // 记录错误但继续处理其他记录
                            error_log("导入学生失败 - 学号: {$student_no}, 错误: " . $e->getMessage());
                            // 不继续，而是记录错误但继续处理
                            continue;
                        }
                    }
                    
                    // 删除临时文件
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                    
                    if ($imported == 0 && $updated == 0) {
                        // 提供错误信息（生产环境简化版）
                        $error_details = [];
                        $error_details[] = "文件读取成功，共 " . count($rows) . " 行数据（包含表头）";
                        $error_details[] = "表头列：" . implode('、', array_filter(array_map('trim', $header)));
                        $error_details[] = "检测到的列映射：学号(第" . ($column_map['student_no'] + 1) . "列)、姓名(第" . ($column_map['name'] + 1) . "列)、班级(第" . ($column_map['class'] + 1) . "列)";
                        $error_details[] = "处理的数据行数：" . $processed_rows;
                        $error_details[] = "可能原因：1) 学号列为空；2) 数据行全部为空；3) 列索引不正确；4) Excel格式问题";
                        
                        $message = '导入完成，但没有处理任何数据。' . "<br>" . implode("<br>", $error_details);
                        $message_type = 'error';
                        logAdminAction($pdo, '导入学生', 'failed', "文件={$file['name']}, 未处理任何数据");
                    } else {
                        $message = "导入成功！新增 {$imported} 名学生，更新 {$updated} 名学生。";
                        if (!empty($classes)) {
                            $message .= " 发现 " . count($classes) . " 个班级：" . implode('、', array_slice($classes, 0, 10));
                            if (count($classes) > 10) {
                                $message .= ' 等';
                            }
                        }
                        $message_type = 'success';
                        logAdminAction($pdo, '导入学生', 'success', "文件={$file['name']}, 新增={$imported}, 更新={$updated}");
                    }
                } catch (Exception $e) {
                    $message = '导入失败：' . $e->getMessage();
                    $message_type = 'error';
                    logAdminAction($pdo, '导入学生', 'failed', "文件={$file['name']}, 错误: " . $e->getMessage());
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            } else {
                $message = '文件上传失败！';
                $message_type = 'error';
                logAdminAction($pdo, '导入学生', 'failed', '文件上传失败');
            }
        }
    } else {
        $message = '请选择要上传的文件！';
        $message_type = 'error';
        logAdminAction($pdo, '导入学生', 'failed', '未选择文件');
    }
    end_import:
}

// 删除学生（单个）
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 先获取学生信息用于日志
        $stmt = $pdo->prepare("SELECT student_no, name FROM students WHERE id = ?");
        $stmt->execute([$id]);
        $student = $stmt->fetch();
        $student_info = $student ? "学号={$student['student_no']}, 姓名=" . ($student['name'] ?? '未知') : "ID={$id}";
        
        $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = '学生删除成功！';
            $message_type = 'success';
            logAdminAction($pdo, '删除学生', 'success', $student_info);
        } else {
            $message = '学生删除失败！';
            $message_type = 'error';
            logAdminAction($pdo, '删除学生', 'failed', $student_info);
        }
    } catch (PDOException $e) {
        $message = '学生删除失败！';
        $message_type = 'error';
        logAdminAction($pdo, '删除学生', 'failed', "ID={$id}, 错误: " . $e->getMessage());
    }
}

// 批量删除学生
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batch_delete') {
    $ids = $_POST['ids'] ?? [];
    
    // 处理 ids 参数（应该是数组）
    if (!is_array($ids)) {
        $ids = [];
    }
    
    // 清理和验证 IDs
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    $ids = array_unique($ids);
    $ids = array_values($ids); // 重新索引数组，确保连续索引
    
    if (!empty($ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM students WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $deleted_count = $stmt->rowCount();
            $message = "成功删除 {$deleted_count} 名学生！";
            $message_type = 'success';
            logAdminAction($pdo, '批量删除学生', 'success', "数量={$deleted_count}, IDs=" . implode(',', $ids));
        } catch (PDOException $e) {
            $message = '批量删除失败！';
            $message_type = 'error';
            logAdminAction($pdo, '批量删除学生', 'failed', "IDs=" . implode(',', $ids) . ", 错误: " . $e->getMessage());
        }
    } else {
        $message = '请选择要删除的学生！';
        $message_type = 'error';
        logAdminAction($pdo, '批量删除学生', 'failed', '未选择学生');
    }
}

// 编辑学生
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_student') {
    $id = intval($_POST['id'] ?? 0);
    $student_no = trim($_POST['student_no'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $class = trim($_POST['class'] ?? '');
    
    if ($id > 0 && !empty($student_no)) {
        try {
            // 检查学号是否被其他学生使用
            $stmt = $pdo->prepare("SELECT id FROM students WHERE student_no = ? AND id != ?");
            $stmt->execute([$student_no, $id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                $message = '该学号已被其他学生使用！';
                $message_type = 'error';
                logAdminAction($pdo, '编辑学生', 'failed', "ID={$id}, 学号={$student_no}, 原因: 学号已被使用");
            } else {
                $stmt = $pdo->prepare("UPDATE students SET student_no = ?, name = ?, class = ? WHERE id = ?");
                if ($stmt->execute([$student_no, $name ?: null, $class ?: null, $id])) {
                    $message = '学生信息更新成功！';
                    $message_type = 'success';
                    logAdminAction($pdo, '编辑学生', 'success', "ID={$id}, 学号={$student_no}, 姓名=" . ($name ?: '未设置') . ", 班级=" . ($class ?: '未设置'));
                } else {
                    $message = '学生信息更新失败！';
                    $message_type = 'error';
                    logAdminAction($pdo, '编辑学生', 'failed', "ID={$id}, 学号={$student_no}");
                }
            }
        } catch (PDOException $e) {
            $message = '学生信息更新失败：' . $e->getMessage();
            $message_type = 'error';
            logAdminAction($pdo, '编辑学生', 'failed', "ID={$id}, 错误: " . $e->getMessage());
        }
    } else {
        $message = '请填写完整信息！';
        $message_type = 'error';
        logAdminAction($pdo, '编辑学生', 'failed', '参数不足');
    }
}

// 新增学生
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_student') {
    $student_no = trim($_POST['student_no'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $class = trim($_POST['class'] ?? '');
    
    if (empty($student_no)) {
        $message = '学号不能为空！';
        $message_type = 'error';
    } else {
        // 检查学号是否已存在
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_no = ?");
        $stmt->execute([$student_no]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $message = '该学号已存在！';
            $message_type = 'error';
            logAdminAction($pdo, '添加学生', 'failed', "学号={$student_no}, 原因: 学号已存在");
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO students (student_no, name, class) VALUES (?, ?, ?)");
                $stmt->execute([$student_no, $name ?: null, $class ?: null]);
                $student_id = $pdo->lastInsertId();
                $message = '学生添加成功！';
                $message_type = 'success';
                logAdminAction($pdo, '添加学生', 'success', "ID={$student_id}, 学号={$student_no}, 姓名=" . ($name ?: '未设置') . ", 班级=" . ($class ?: '未设置'));
            } catch (PDOException $e) {
                $message = '学生添加失败：' . $e->getMessage();
                $message_type = 'error';
                logAdminAction($pdo, '添加学生', 'failed', "学号={$student_no}, 错误: " . $e->getMessage());
            }
        }
    }
}

// 批量修改班级
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batch_update_class') {
    $ids = $_POST['ids'] ?? [];
    
    // 处理 ids 参数（可能是数组）
    if (!is_array($ids)) {
        $ids = [];
    }
    
    // 清理和验证 IDs
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    $ids = array_unique($ids);
    $ids = array_values($ids); // 重新索引数组，确保连续索引
    
    $new_class = trim($_POST['new_class'] ?? '');
    
    if (!empty($ids)) {
        try {
            if (empty($new_class)) {
                // 如果班级为空，表示清除班级
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE students SET class = NULL WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $updated_count = $stmt->rowCount();
                $message = "成功清除 {$updated_count} 名学生的班级信息！";
                $message_type = 'success';
                logAdminAction($pdo, '批量修改学生班级', 'success', "数量={$updated_count}, 操作=清除班级, IDs=" . implode(',', $ids));
            } else {
                // 更新班级
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$new_class], $ids);
                $stmt = $pdo->prepare("UPDATE students SET class = ? WHERE id IN ($placeholders)");
                $stmt->execute($params);
                $updated_count = $stmt->rowCount();
                $message = "成功将 {$updated_count} 名学生移动到班级「{$new_class}」！";
                $message_type = 'success';
                logAdminAction($pdo, '批量修改学生班级', 'success', "数量={$updated_count}, 班级={$new_class}, IDs=" . implode(',', $ids));
            }
        } catch (PDOException $e) {
            $message = '批量修改班级失败！';
            $message_type = 'error';
            logAdminAction($pdo, '批量修改学生班级', 'failed', "IDs=" . implode(',', $ids) . ", 错误: " . $e->getMessage());
        }
    } else {
        $message = '请选择要修改的学生！';
        $message_type = 'error';
        logAdminAction($pdo, '批量修改学生班级', 'failed', '未选择学生');
    }
}

// 获取选中的班级（用于筛选）
$selected_class = isset($_GET['class']) ? trim($_GET['class']) : '';

// 获取搜索条件
$search_student_no = isset($_GET['search_student_no']) ? trim($_GET['search_student_no']) : '';
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$search_class = isset($_GET['search_class']) ? trim($_GET['search_class']) : '';

// 获取所有班级列表（去重）
$stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != '' ORDER BY class");
$all_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 分页参数
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 获取学生列表
// 优化：只选择需要的字段，减少数据传输量
$sql = "SELECT id, student_no, name, class, created_at FROM students WHERE 1=1";
$params = [];

// 班级筛选（下拉选择）
if (!empty($selected_class)) {
    $sql .= " AND class = ?";
    $params[] = $selected_class;
}

// 搜索条件（学号）
if (!empty($search_student_no)) {
    $sql .= " AND student_no LIKE ?";
    $params[] = '%' . $search_student_no . '%';
}

// 搜索条件（姓名）
if (!empty($search_name)) {
    $sql .= " AND name LIKE ?";
    $params[] = '%' . $search_name . '%';
}

// 搜索条件（班级）
if (!empty($search_class)) {
    $sql .= " AND class LIKE ?";
    $params[] = '%' . $search_class . '%';
}

// 获取总记录数（修复：原SQL无SELECT *，直接子查询计数）
$count_sql = "SELECT COUNT(*) as total FROM (" . $sql . ") AS sub";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_records = $count_row ? (int)$count_row['total'] : 0;

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, ceil($total_records / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;
}

$sql .= " ORDER BY created_at DESC, id DESC";
if ($per_page > 0) {
    $sql .= " LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// 统计信息
$total_students = $total_records;
$class_count = count($all_classes);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生管理 - 后台管理</title>
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
        .import-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .import-section h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        .file-upload {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .file-upload input[type="file"] {
            flex: 1;
            min-width: 200px;
            padding: 8px;
            border: 2px dashed #ddd;
            border-radius: 6px;
            background: white;
        }
        .class-filter {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .class-filter select {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            min-width: 200px;
        }
        .class-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #667eea;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            margin: 2px;
        }
        .add-import-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .add-import-grid {
                grid-template-columns: 1fr;
            }
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
        <h2>学生管理</h2>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo escape($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- 新增和导入学生 -->
        <div class="add-import-grid">
            <!-- 手工新增学生 -->
            <div class="import-section">
                <h3>➕ 手工新增学生</h3>
                <button type="button" class="btn btn-primary" onclick="openAddStudentModal()" style="width: 100%; padding: 12px; font-size: 16px;">➕ 添加学生</button>
            </div>
            
            <!-- 导入学生名单 -->
            <div class="import-section">
                <h3>📥 导入学生名单</h3>
                <p style="color: #666; margin-bottom: 15px; font-size: 14px;">
                    支持csv/xls/xlsx格式文件，文件应包含：学号、姓名、班级 三列（第一行为表头）
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import">
                    <div class="file-upload">
                        <input type="file" name="excel_file" accept=".csv,.xls,.xlsx" required>
                        <button type="submit" class="btn btn-primary">上传并导入</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- 统计信息 -->
        <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
            <div style="padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; flex: 1; min-width: 150px;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">
                    <?php echo (!empty($search_student_no) || !empty($search_name) || !empty($search_class) || !empty($selected_class)) ? '搜索结果' : '总学生数'; ?>
                </div>
                <div style="font-size: 28px; font-weight: bold;"><?php echo $total_students; ?></div>
            </div>
            <div style="padding: 15px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border-radius: 8px; flex: 1; min-width: 150px;">
                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">班级数</div>
                <div style="font-size: 28px; font-weight: bold;"><?php echo $class_count; ?></div>
            </div>
        </div>
        
        <!-- 搜索和筛选 -->
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 15px;">🔍 搜索和筛选</h3>
            <form method="GET" id="searchForm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                <input type="hidden" name="page" value="1">
                <input type="hidden" name="per_page" value="<?php echo $per_page; ?>">
                <div>
                    <label for="search_student_no" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">学号</label>
                    <input type="text" 
                           id="search_student_no" 
                           name="search_student_no" 
                           value="<?php echo escape($search_student_no); ?>"
                           placeholder="输入学号搜索" 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div>
                    <label for="search_name" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">姓名</label>
                    <input type="text" 
                           id="search_name" 
                           name="search_name" 
                           value="<?php echo escape($search_name); ?>"
                           placeholder="输入姓名搜索" 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                <div>
                    <label for="search_class" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">班级</label>
                    <input type="text" 
                           id="search_class" 
                           name="search_class" 
                           list="classListForSearch"
                           value="<?php echo escape($search_class); ?>"
                           placeholder="输入班级搜索" 
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                    <datalist id="classListForSearch">
                        <?php foreach ($all_classes as $class): ?>
                            <option value="<?php echo escape($class); ?>"><?php echo escape($class); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label for="class_filter" style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">快速筛选班级</label>
                    <select id="class_filter" 
                            name="class" 
                            style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="">全部班级</option>
                        <?php foreach ($all_classes as $class): ?>
                            <option value="<?php echo escape($class); ?>" <?php echo $selected_class == $class ? 'selected' : ''; ?>>
                                <?php echo escape($class); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="padding: 8px 20px; flex: 1;">搜索</button>
                    <a href="student_manage.php" class="btn btn-secondary" style="padding: 8px 20px; text-decoration: none; display: inline-block; text-align: center;">清除</a>
                </div>
            </form>
            <?php if (!empty($search_student_no) || !empty($search_name) || !empty($search_class) || !empty($selected_class)): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-weight: 600; color: #666;">当前筛选条件：</span>
                        <?php if (!empty($search_student_no)): ?>
                            <span class="class-badge">学号：<?php echo escape($search_student_no); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($search_name)): ?>
                            <span class="class-badge">姓名：<?php echo escape($search_name); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($search_class)): ?>
                            <span class="class-badge">班级：<?php echo escape($search_class); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selected_class)): ?>
                            <span class="class-badge">快速筛选：<?php echo escape($selected_class); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 学生列表 -->
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #3498db;">
                <h2 style="margin: 0; padding: 0; border: none;">
                    学生列表
                    <?php if ($per_page > 0): ?>
                        （共<?php echo $total_records; ?>条，第<?php echo $current_page; ?>/<?php echo $total_pages; ?>页）
                    <?php else: ?>
                        （共<?php echo $total_records; ?>条，全部显示）
                    <?php endif; ?>
                </h2>
                <form method="GET" style="display: flex; gap: 8px; align-items: center; margin: 0;">
                    <label style="margin: 0; font-size: 14px;">每页显示：</label>
                    <select name="per_page" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                        <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                    </select>
                    <input type="hidden" name="page" value="1">
                    <?php if (!empty($search_student_no)): ?><input type="hidden" name="search_student_no" value="<?php echo escape($search_student_no); ?>"><?php endif; ?>
                    <?php if (!empty($search_name)): ?><input type="hidden" name="search_name" value="<?php echo escape($search_name); ?>"><?php endif; ?>
                    <?php if (!empty($search_class)): ?><input type="hidden" name="search_class" value="<?php echo escape($search_class); ?>"><?php endif; ?>
                    <?php if (!empty($selected_class)): ?><input type="hidden" name="class" value="<?php echo escape($selected_class); ?>"><?php endif; ?>
                </form>
            </div>
            <?php if (!empty($students)): ?>
                <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <button type="button" class="btn btn-secondary" onclick="selectAll()" style="padding: 8px 15px;">全选</button>
                    <button type="button" class="btn btn-secondary" onclick="deselectAll()" style="padding: 8px 15px;">取消全选</button>
                    <span style="margin: 0 10px; color: #666;">已选择 <strong id="selectedCount">0</strong> 名学生</span>
                    <div style="flex: 1; min-width: 200px;"></div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-primary" onclick="batchUpdateClass()" style="padding: 8px 15px;" id="batchUpdateClassBtn" disabled>
                            批量修改班级
                        </button>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="text" 
                                   id="batchClassInput" 
                                   list="classList" 
                                   placeholder="选择或输入班级名称" 
                                   style="padding: 8px 15px; border: 1px solid #ddd; border-radius: 6px; min-width: 200px; padding-right: 80px;">
                            <datalist id="classList">
                                <option value="__clear__">清除班级</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?php echo escape($class); ?>"><?php echo escape($class); ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <button type="button" 
                                    class="btn btn-secondary" 
                                    onclick="clearClassInput()" 
                                    style="position: absolute; right: 5px; padding: 4px 8px; font-size: 12px; border-radius: 4px;"
                                    title="清除输入">
                                清除
                            </button>
                        </div>
                        <button type="button" class="btn btn-danger" onclick="batchDelete()" style="padding: 8px 15px;" id="batchDeleteBtn" disabled>
                            批量删除
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            <form id="batchDeleteForm" method="POST" style="display: none;">
                <input type="hidden" name="action" value="batch_delete">
                <input type="hidden" name="ids[]" id="selectedIds" value="">
            </form>
            <form id="batchUpdateClassForm" method="POST" style="display: none;">
                <input type="hidden" name="action" value="batch_update_class">
                <input type="hidden" name="ids[]" id="selectedIdsForClass" value="">
                <input type="hidden" name="new_class" id="newClassValue" value="">
            </form>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">
                            <?php if (!empty($students)): ?>
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this.checked)">
                            <?php endif; ?>
                        </th>
                        <th>ID</th>
                        <th>学号</th>
                        <th>姓名</th>
                        <th>班级</th>
                        <th>添加时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">暂无学生数据</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="student-checkbox" value="<?php echo $student['id']; ?>" onchange="updateSelectedCount()">
                                </td>
                                <td><?php echo $student['id']; ?></td>
                                <td><?php echo escape($student['student_no']); ?></td>
                                <td><?php echo escape($student['name'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!empty($student['class'])): ?>
                                        <span class="class-badge"><?php echo escape($student['class']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">未分配</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($student['created_at'])) {
                                        $date = strtotime($student['created_at']);
                                        echo date('m-d H:i', $date);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-primary" onclick="openEditStudentModal(<?php echo $student['id']; ?>)">编辑</button>
                                        <?php
                                        // 构建删除URL，保留分页和筛选参数
                                        $delete_url = '?action=delete&id=' . $student['id'];
                                        if ($per_page > 0) $delete_url .= '&per_page=' . $per_page;
                                        $delete_url .= '&page=' . $current_page;
                                        if (!empty($search_student_no)) $delete_url .= '&search_student_no=' . urlencode($search_student_no);
                                        if (!empty($search_name)) $delete_url .= '&search_name=' . urlencode($search_name);
                                        if (!empty($search_class)) $delete_url .= '&search_class=' . urlencode($search_class);
                                        if (!empty($selected_class)) $delete_url .= '&class=' . urlencode($selected_class);
                                        ?>
                                        <a href="<?php echo $delete_url; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('确定要删除该学生吗？')">删除</a>
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
                    if (!empty($search_student_no)) $url_params[] = 'search_student_no=' . urlencode($search_student_no);
                    if (!empty($search_name)) $url_params[] = 'search_name=' . urlencode($search_name);
                    if (!empty($search_class)) $url_params[] = 'search_class=' . urlencode($search_class);
                    if (!empty($selected_class)) $url_params[] = 'class=' . urlencode($selected_class);
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
    
    <script>
        // 搜索表单提交（保留所有搜索条件）
        document.getElementById('searchForm')?.addEventListener('submit', function(e) {
            // 表单会自动提交，不需要额外处理
        });
        
        // 全选/取消全选
        function toggleAll(checked) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = checked;
            });
            updateSelectedCount();
        }
        
        // 全选
        function selectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = true;
            }
            updateSelectedCount();
        }
        
        // 取消全选
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
            updateSelectedCount();
        }
        
        // 更新选中数量
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const count = checkboxes.length;
            const countSpan = document.getElementById('selectedCount');
            const batchDeleteBtn = document.getElementById('batchDeleteBtn');
            const batchUpdateClassBtn = document.getElementById('batchUpdateClassBtn');
            
            if (countSpan) {
                countSpan.textContent = count;
            }
            
            if (batchDeleteBtn) {
                batchDeleteBtn.disabled = count === 0;
            }
            
            if (batchUpdateClassBtn) {
                batchUpdateClassBtn.disabled = count === 0;
            }
            
            // 更新全选复选框状态
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (selectAllCheckbox) {
                const allCheckboxes = document.querySelectorAll('.student-checkbox');
                selectAllCheckbox.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
            }
        }
        
        // 批量删除
        function batchDelete() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('请选择要删除的学生！');
                return;
            }
            
            if (!confirm(`确定要删除选中的 ${checkboxes.length} 名学生吗？此操作不可恢复！`)) {
                return;
            }
            
            const ids = Array.from(checkboxes).map(checkbox => checkbox.value);
            const form = document.getElementById('batchDeleteForm');
            
            // 清除旧的隐藏输入
            const oldInputs = form.querySelectorAll('input[name^="ids"]');
            oldInputs.forEach(input => {
                if (input.id !== 'selectedIds') {
                    input.remove();
                }
            });
            
            // 为每个 ID 创建一个隐藏输入
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            
            form.submit();
        }
        
        // 清除班级输入
        function clearClassInput() {
            const classInput = document.getElementById('batchClassInput');
            if (classInput) {
                classInput.value = '';
            }
        }
        
        // 批量修改班级
        function batchUpdateClass() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('请选择要修改的学生！');
                return;
            }
            
            const classInput = document.getElementById('batchClassInput');
            const newClass = classInput ? classInput.value.trim() : '';
            
            if (newClass === '') {
                alert('请选择或输入目标班级！');
                return;
            }
            
            const classText = newClass === '__clear__' ? '清除班级' : `移动到「${newClass}」`;
            if (!confirm(`确定要将选中的 ${checkboxes.length} 名学生${classText}吗？`)) {
                return;
            }
            
            const ids = Array.from(checkboxes).map(checkbox => checkbox.value);
            const form = document.getElementById('batchUpdateClassForm');
            
            // 清除旧的隐藏输入
            const oldInputs = form.querySelectorAll('input[name^="ids"]');
            oldInputs.forEach(input => {
                if (input.id !== 'selectedIdsForClass') {
                    input.remove();
                }
            });
            
            // 为每个 ID 创建一个隐藏输入
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            
            // 设置新班级值
            document.getElementById('newClassValue').value = newClass === '__clear__' ? '' : newClass;
            
            form.submit();
        }
        
        // 页面加载时初始化
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
        
        // 打开添加学生模态框
        function openAddStudentModal() {
            document.getElementById('studentModalTitle').textContent = '添加学生';
            document.getElementById('studentFormAction').value = 'add_student';
            document.getElementById('studentId').value = '';
            document.getElementById('studentForm').reset();
            document.getElementById('studentSubmitBtn').textContent = '添加学生';
            document.getElementById('studentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // 打开编辑学生模态框
        function openEditStudentModal(studentId) {
            fetch('get_student.php?id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const s = data.student;
                        document.getElementById('studentModalTitle').textContent = '编辑学生';
                        document.getElementById('studentFormAction').value = 'edit_student';
                        document.getElementById('studentId').value = s.id;
                        document.getElementById('studentNo').value = s.student_no || '';
                        document.getElementById('studentName').value = s.name || '';
                        document.getElementById('studentClass').value = s.class || '';
                        document.getElementById('studentSubmitBtn').textContent = '更新学生';
                        document.getElementById('studentModal').classList.add('active');
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('获取学生信息失败：' + (data.message || '未知错误'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取学生信息失败，请刷新页面重试');
                });
        }
        
        // 关闭学生模态框
        function closeStudentModal() {
            document.getElementById('studentModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // ESC键关闭模态框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStudentModal();
            }
        });
    </script>
    
    <!-- 添加/编辑学生模态框 -->
    <div id="studentModal" class="modal-overlay" onclick="if(event.target === this) closeStudentModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="studentModalTitle">添加学生</h2>
                <button type="button" class="modal-close" onclick="closeStudentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="studentForm">
                    <input type="hidden" name="action" id="studentFormAction" value="add_student">
                    <input type="hidden" name="id" id="studentId" value="">
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="studentNo" style="display: block; margin-bottom: 5px; font-weight: 600;">学号 *</label>
                        <input type="text" id="studentNo" name="student_no" required 
                               placeholder="请输入学号" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="studentName" style="display: block; margin-bottom: 5px; font-weight: 600;">姓名</label>
                        <input type="text" id="studentName" name="name" 
                               placeholder="请输入姓名（可选）" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="studentClass" style="display: block; margin-bottom: 5px; font-weight: 600;">班级</label>
                        <input type="text" id="studentClass" name="class" 
                               list="classListForModal" 
                               placeholder="选择或输入班级（可选）" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                        <datalist id="classListForModal">
                            <?php foreach ($all_classes as $class): ?>
                                <option value="<?php echo escape($class); ?>"><?php echo escape($class); ?></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="studentSubmitBtn" style="flex: 1;">添加学生</button>
                        <button type="button" class="btn btn-warning" onclick="closeStudentModal()">取消</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

