<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
require_once 'inc/ai_scoring.inc.php';
checkStudentLogin();
ensurePaperScheduleColumns($pdo);

$paper_id = isset($_GET['paper_id']) ? intval($_GET['paper_id']) : 0;

if ($paper_id <= 0) {
    header('Location: exam_list.php');
    exit;
}

// 获取试卷信息（含科目与可用性）
$stmtPaper = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                           LEFT JOIN subjects s ON p.subject_id = s.id 
                           WHERE p.id = ?");
$stmtPaper->execute([$paper_id]);
$paper = $stmtPaper->fetch();
if (!$paper) {
    header('Location: exam_list.php');
    exit;
}
$paperState = getPaperActiveState($paper);
if (!$paperState['active']) {
    $reason = urlencode($paperState['reason'] ?? '');
    header('Location: exam_list.php?msg=paper_inactive' . ($reason ? '&reason=' . $reason : ''));
    exit;
}

// 检查是否有进行中的考试
$stmt = $pdo->prepare("SELECT * FROM exam_records WHERE student_id = ? AND paper_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
$stmt->execute([$_SESSION['student_id'], $paper_id]);
$existing_exam = $stmt->fetch();

// 开始新考试
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'start') {
    // 清除可能存在的旧session数据（确保开始新考试时没有残留数据）
    if (isset($_SESSION['exam_record_id'])) {
        $old_exam_record_id = $_SESSION['exam_record_id'];
        // 检查旧考试记录的状态
        $stmt = $pdo->prepare("SELECT status FROM exam_records WHERE id = ? AND student_id = ?");
        $stmt->execute([$old_exam_record_id, $_SESSION['student_id']]);
        $old_exam = $stmt->fetch();
    
        // 如果旧考试已完成，清除session（避免干扰新考试）
        if ($old_exam && $old_exam['status'] == 'completed') {
            unset($_SESSION['exam_record_id']);
            unset($_SESSION['exam_start_time']);
        }
    }
    
    // 解析题型配置（兼容新旧格式）
    $question_config = json_decode($paper['question_config'], true);
    if (!$question_config || empty($question_config)) {
        header('Location: exam_list.php');
        exit;
    }
    
    // 获取该学生在本试卷中已经做过的题目（用于优先抽取没见过的题，增加题目覆盖率）
    $seen_questions_by_type = [];
    $stmt = $pdo->prepare("
        SELECT q.id, q.question_type 
        FROM exam_questions eq
        JOIN exam_records er ON eq.exam_record_id = er.id
        JOIN questions q ON q.id = eq.question_id
        WHERE er.student_id = ? AND er.paper_id = ?
    ");
    $stmt->execute([$_SESSION['student_id'], $paper_id]);
    while ($row = $stmt->fetch()) {
        $type = $row['question_type'];
        if (!isset($seen_questions_by_type[$type])) {
            $seen_questions_by_type[$type] = [];
        }
        $seen_questions_by_type[$type][] = (int)$row['id'];
    }
    
    // 根据题型配置从题库中随机抽取题目（优先抽取学生没见过的题）
    $selected_questions = [];
    $subject_id = $paper['subject_id'];
    $total_questions = 0;
    $type_question_map = []; // 记录每个题型对应的题目，用于计算分值
    
    foreach ($question_config as $type => $config) {
        // 兼容旧格式：如果是数字，表示只有count
        if (is_numeric($config)) {
            $count = intval($config);
            $type_score = 0; // 旧格式没有题型总分，使用0表示平均分配
        } else {
            // 新格式：{count: X, score: Y}
            $count = intval($config['count'] ?? 0);
            $type_score = intval($config['score'] ?? 0);
        }
        
        if ($count > 0) {
            // 优先抽取该学生在本试卷中「没见过」的题目，提升多次模拟下的题目覆盖率
            $seen_ids = $seen_questions_by_type[$type] ?? [];
            
            if (!empty($seen_ids)) {
                // 构造 NOT IN 占位符
                $placeholders = implode(',', array_fill(0, count($seen_ids), '?'));
                // 优化：先获取所有题目，然后在PHP中随机选择（避免ORDER BY RAND()的性能问题）
                $sql = "
                    SELECT * 
                    FROM questions 
                    WHERE subject_id = ? AND question_type = ?
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$subject_id, $type]);
                $all_questions = $stmt->fetchAll();
                
                // 分离已做和未做的题目
                $unseen_questions = [];
                $seen_questions = [];
                foreach ($all_questions as $q) {
                    if (in_array($q['id'], $seen_ids)) {
                        $seen_questions[] = $q;
                    } else {
                        $unseen_questions[] = $q;
                    }
                }
                
                // 优先从未做过的题目中选择，不足时补充已做过的
                shuffle($unseen_questions);
                shuffle($seen_questions);
                $type_questions = array_slice($unseen_questions, 0, $count);
                if (count($type_questions) < $count) {
                    $needed = $count - count($type_questions);
                    $type_questions = array_merge($type_questions, array_slice($seen_questions, 0, $needed));
                }
            } else {
                // 学生还没做过该题型，获取所有题目后在PHP中随机选择（避免ORDER BY RAND()）
                $stmt = $pdo->prepare("SELECT * FROM questions WHERE subject_id = ? AND question_type = ?");
                $stmt->execute([$subject_id, $type]);
                $all_questions = $stmt->fetchAll();
                shuffle($all_questions);
                $type_questions = array_slice($all_questions, 0, $count);
            }
            
            // 计算该题型下每道题的分值
            $question_score = 0;
            if ($type_score > 0 && count($type_questions) > 0) {
                // 如果设置了题型总分，平均分配到每道题
                $question_score = round($type_score / count($type_questions), 2);
            }
            
            foreach ($type_questions as $question) {
                $question['type_score'] = $question_score; // 保存该题的分值
                $question['question_type_name'] = $type; // 保存题型名称
                $selected_questions[] = $question;
                $type_question_map[$type][] = $question['id'];
            }
            $total_questions += count($type_questions);
        }
    }
    
    if (empty($selected_questions)) {
        header('Location: exam_list.php');
        exit;
    }
    
    // 题目乱序功能已移除，答题页面已通过 ORDER BY RAND() 随机抽选
    
    // 计算未设置题型总分的题目，使用剩余分数平均分配
    $total_type_score = 0;
    $questions_with_score = 0;
    foreach ($selected_questions as $question) {
        if ($question['type_score'] > 0) {
            $total_type_score += $question['type_score'];
            $questions_with_score++;
        }
    }
    
    $remaining_score = $paper['total_score'] - $total_type_score;
    $questions_without_score = $total_questions - $questions_with_score;
    $avg_score_per_question = 0;
    if ($questions_without_score > 0 && $remaining_score > 0) {
        $avg_score_per_question = round($remaining_score / $questions_without_score, 2);
    }
    
    // 确保exam_records表有ip字段
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM exam_records LIKE 'ip'");
        if ($stmt_check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE exam_records ADD COLUMN ip VARCHAR(64) DEFAULT '' COMMENT '客户端IP地址' AFTER created_at");
        }
    } catch (Exception $e) {
        // 如果字段已存在或其他错误，忽略
    }
    
    // 获取客户端IP
    $client_ip = getClientIp();
    
    // 创建考试记录（包含IP地址）
    $stmt = $pdo->prepare("INSERT INTO exam_records (student_id, paper_id, start_time, status, ip) VALUES (?, ?, NOW(), 'in_progress', ?)");
    $stmt->execute([$_SESSION['student_id'], $paper_id, $client_ip]);
    $exam_record_id = $pdo->lastInsertId();
    
    // 将抽取的题目保存到exam_questions表
    $stmt = $pdo->prepare("INSERT INTO exam_questions (exam_record_id, question_id, order_num, score) VALUES (?, ?, ?, ?)");
    foreach ($selected_questions as $index => $question) {
        // 如果该题没有设置分值，使用平均分配的分值
        $final_score = $question['type_score'] > 0 ? $question['type_score'] : $avg_score_per_question;
        $stmt->execute([$exam_record_id, $question['id'], $index + 1, $final_score]);
        $question['score'] = $final_score;
        $selected_questions[$index] = $question;
    }
    
    // 构建题目列表（添加order_num和score字段）
    $questions = [];
    foreach ($selected_questions as $index => $question) {
        $question['order_num'] = $index + 1;
        $questions[] = $question;
    }
    
    $_SESSION['exam_record_id'] = $exam_record_id;
    $_SESSION['exam_start_time'] = time();
    
    // 初始化已答题目列表为空（新考试）
    $answered_question_ids = [];
    
} elseif ($existing_exam) {
    // 继续进行中的考试
    $exam_record_id = $existing_exam['id'];
    $_SESSION['exam_record_id'] = $exam_record_id;
    
    // 获取试卷题目和已答题目（从exam_questions表获取）
    $stmt = $pdo->prepare("SELECT q.*, eq.order_num, eq.score, ar.student_answer, ar.id as answer_id 
                           FROM exam_questions eq 
                           JOIN questions q ON eq.question_id = q.id 
                           LEFT JOIN answer_records ar ON ar.exam_record_id = ? AND ar.question_id = q.id
                           WHERE eq.exam_record_id = ? ORDER BY eq.order_num");
    $stmt->execute([$exam_record_id, $exam_record_id]);
    $questions = $stmt->fetchAll();
    
    $_SESSION['exam_start_time'] = strtotime($existing_exam['start_time']);
    
    // 获取试卷信息（包含科目和乱序设置）
    $stmt = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                           LEFT JOIN subjects s ON p.subject_id = s.id 
                           WHERE p.id = ?");
    $stmt->execute([$paper_id]);
    $paper = $stmt->fetch();
    
} else {
    // 显示开始页面
    // 清除可能存在的旧session数据（确保开始新考试时没有残留数据）
    if (isset($_SESSION['exam_record_id'])) {
        // 检查这个exam_record_id是否属于当前试卷且已完成
        $old_exam_record_id = $_SESSION['exam_record_id'];
        $stmt = $pdo->prepare("SELECT status, paper_id FROM exam_records WHERE id = ? AND student_id = ?");
        $stmt->execute([$old_exam_record_id, $_SESSION['student_id']]);
        $old_exam = $stmt->fetch();
        
        if ($old_exam) {
            // 如果是已完成的考试，跳转到结果页
            if ($old_exam['status'] == 'completed') {
                unset($_SESSION['exam_record_id']);
                unset($_SESSION['exam_start_time']);
                header("Location: exam_result.php?exam_record_id={$old_exam_record_id}");
                exit;
            }
            // 如果是其他试卷的进行中考试，清除session（因为要开始新试卷的考试）
            if ($old_exam['paper_id'] != $paper_id) {
                unset($_SESSION['exam_record_id']);
                unset($_SESSION['exam_start_time']);
            }
        } else {
            // 如果找不到记录，清除session
            unset($_SESSION['exam_record_id']);
            unset($_SESSION['exam_start_time']);
        }
    }
    
    // 获取试卷信息（包含科目和乱序设置）
    $stmt = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                           LEFT JOIN subjects s ON p.subject_id = s.id 
                           WHERE p.id = ?");
    $stmt->execute([$paper_id]);
    $paper = $stmt->fetch();
    
    if (!$paper) {
        header('Location: exam_list.php');
        exit;
    }
    
    include 'exam_start.php';
    exit;
}

// 提交答案
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit') {
    $exam_record_id = $_SESSION['exam_record_id'] ?? 0;
    
    if ($exam_record_id > 0) {
        // 获取试卷信息（包含科目和乱序设置）
        $stmt = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                               LEFT JOIN subjects s ON p.subject_id = s.id 
                               WHERE p.id = ?");
        $stmt->execute([$paper_id]);
        $paper = $stmt->fetch();
        
        if (!$paper) {
            header('Location: exam_list.php');
            exit;
        }
        
        $answers = $_POST['answers'] ?? [];
        
        // 计算得分
        $total_score = 0;
        $correct_count = 0;
        
        foreach ($answers as $question_id => $student_answer) {
            $question_id = intval($question_id);
            $student_answer = trim($student_answer);
            
            // 获取题目信息（从exam_questions表获取）
            $stmt = $pdo->prepare("SELECT q.*, eq.score FROM questions q 
                                   JOIN exam_questions eq ON q.id = eq.question_id 
                                   WHERE q.id = ? AND eq.exam_record_id = ?");
            $stmt->execute([$question_id, $exam_record_id]);
            $question = $stmt->fetch();
            
            if ($question) {
                $correct_answer = trim($question['correct_answer']);
                $student_answer_processed = trim($student_answer);
                $question_type = $question['question_type'];
                $max_score = intval($question['score'] ?? 10);
                
                // 判断是否是主观题（名词解释、实操论述题、填空题也使用相似度评分）
                $subjective_types = ['名词解释', '简答题', '实操论述题', '填空题'];
                
                if (in_array($question_type, $subjective_types)) {
                    // 使用AI评分
                    $ai_result = callAIScoringAPI($student_answer_processed, $correct_answer, $max_score);
                    $score = $ai_result['score'];
                    $is_correct = $ai_result['is_correct'];
                } else {
                    // 客观题（单选题、多选题、判断题）：使用精确匹配
                    // 处理答案比较（支持多选题）
                    $correct_answer_normalized = str_replace(',', '', strtoupper($correct_answer));
                    $student_answer_normalized = str_replace(',', '', strtoupper($student_answer_processed));
                    
                    // 将字符串转换为数组，排序后再组合（用于多选题）
                    $correct_array = str_split($correct_answer_normalized);
                    $student_array = str_split($student_answer_normalized);
                    sort($correct_array);
                    sort($student_array);
                    $correct_answer_normalized = implode('', $correct_array);
                    $student_answer_normalized = implode('', $student_array);
                    
                    $is_correct = ($correct_answer_normalized == $student_answer_normalized) ? 1 : 0;
                    $score = $is_correct ? $max_score : 0;
                }
                
                if ($is_correct) {
                    $correct_count++;
                }
                
                $total_score += $score;
                
                // 保存或更新答案
                $stmt = $pdo->prepare("INSERT INTO answer_records (exam_record_id, question_id, student_answer, is_correct, score) 
                                       VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE student_answer = ?, is_correct = ?, score = ?");
                $stmt->execute([
                    $exam_record_id, $question_id, $student_answer, $is_correct, $score,
                    $student_answer, $is_correct, $score
                ]);
                
                // 如果是错题（得分低于满分的60%），添加到错题本
                if ($score < ($max_score * 0.6)) {
                    $stmt = $pdo->prepare("INSERT INTO wrong_questions (student_id, question_id, wrong_times, last_wrong_time) 
                                           VALUES (?, ?, 1, NOW())
                                           ON DUPLICATE KEY UPDATE wrong_times = wrong_times + 1, last_wrong_time = NOW()");
                    $stmt->execute([$_SESSION['student_id'], $question_id]);
                }
            }
        }
        
        // 更新考试记录
        // 从数据库获取开始时间，确保计算准确
        $stmt = $pdo->prepare("SELECT start_time FROM exam_records WHERE id = ?");
        $stmt->execute([$exam_record_id]);
        $exam_start_record = $stmt->fetch();
        
        $duration = 0;
        if ($exam_start_record && !empty($exam_start_record['start_time'])) {
            // 使用TIMESTAMPDIFF计算时间差（秒），更准确
            $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, start_time, NOW()) as duration FROM exam_records WHERE id = ?");
            $stmt->execute([$exam_record_id]);
            $duration_result = $stmt->fetch();
            if ($duration_result && isset($duration_result['duration'])) {
                $duration = intval($duration_result['duration']);
            } else {
                // 备用方案：使用PHP计算
                $start_timestamp = strtotime($exam_start_record['start_time']);
                if ($start_timestamp !== false) {
                    $duration = time() - $start_timestamp;
                }
            }
        } elseif (isset($_SESSION['exam_start_time'])) {
            // 如果无法获取开始时间，使用session中的时间（兼容处理）
            $duration = time() - intval($_SESSION['exam_start_time']);
        }
        
        // 确保duration不为负数且为整数
        $duration = max(0, intval($duration));
        
        $stmt = $pdo->prepare("UPDATE exam_records SET end_time = NOW(), score = ?, total_score = ?, duration = ?, status = 'completed' WHERE id = ?");
        $stmt->execute([$total_score, $paper['total_score'], $duration, $exam_record_id]);
        
        unset($_SESSION['exam_record_id']);
        unset($_SESSION['exam_start_time']);
        
        // 如果来自“确认退出/跳转”，支持在交卷后按学生原本的操作进行跳转
        $afterUrl = isset($_POST['after_url']) ? trim($_POST['after_url']) : '';
        if (!empty($afterUrl)) {
            // 只允许站内相对路径，避免外部跳转
            if (preg_match('/^(https?:)?\\/\\//i', $afterUrl)) {
                $afterUrl = 'exam_result.php?exam_record_id=' . $exam_record_id;
            } else {
                // 简单白名单，限定可跳转页面，防止传入奇怪路径
                $allowedPages = [
                    'exam_list.php',
                    'records.php',
                    'wrong_questions.php',
                    'logout.php',
                    'help_student.php'
                ];
                $cleanPath = strtok($afterUrl, '?');
                if (!in_array($cleanPath, $allowedPages, true)) {
                    $afterUrl = 'exam_result.php?exam_record_id=' . $exam_record_id;
                }
            }
            header('Location: ' . $afterUrl);
            exit;
        }
        
        // 默认行为：跳转到考试结果页
        header("Location: exam_result.php?exam_record_id={$exam_record_id}");
        exit;
    }
}

// 保存答案（自动保存）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save') {
    $exam_record_id = $_SESSION['exam_record_id'] ?? 0;
    $question_id = intval($_POST['question_id'] ?? 0);
    $student_answer = trim($_POST['student_answer'] ?? '');
    
    if ($exam_record_id > 0 && $question_id > 0) {
        // 这里可以保存临时答案，但不计算分数
        $stmt = $pdo->prepare("INSERT INTO answer_records (exam_record_id, question_id, student_answer, is_correct, score) 
                               VALUES (?, ?, ?, 0, 0)
                               ON DUPLICATE KEY UPDATE student_answer = ?");
        $stmt->execute([$exam_record_id, $question_id, $student_answer, $student_answer]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// 刷新 session（防止考试期间 session 过期）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'refresh_session') {
    // 检查是否有进行中的考试
    $exam_record_id = $_SESSION['exam_record_id'] ?? 0;
    if ($exam_record_id > 0) {
        // 验证考试记录是否存在且未完成
        $stmt = $pdo->prepare("SELECT status FROM exam_records WHERE id = ? AND student_id = ?");
        $stmt->execute([$exam_record_id, $_SESSION['student_id']]);
        $exam_record = $stmt->fetch();
        
        if ($exam_record && $exam_record['status'] == 'in_progress') {
            // 刷新 session：更新最后访问时间
            $_SESSION['last_activity'] = time();
            // 可选：重新生成 session ID（更安全，但可能影响并发）
            // session_regenerate_id(false);
            echo json_encode(['success' => true, 'message' => 'Session refreshed']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Exam not found or completed']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No active exam']);
    }
    exit;
}

// 获取试卷信息（包含科目和乱序设置）
$stmt = $pdo->prepare("SELECT p.*, s.name as subject_name FROM papers p 
                       LEFT JOIN subjects s ON p.subject_id = s.id 
                       WHERE p.id = ?");
$stmt->execute([$paper_id]);
$paper = $stmt->fetch();

// 确保exam_record_id已设置
if (!isset($exam_record_id)) {
    $exam_record_id = $_SESSION['exam_record_id'] ?? 0;
}

// 确保exam_start_time已设置（如果还没有，从数据库获取）
if (!isset($_SESSION['exam_start_time']) && $exam_record_id > 0) {
    $stmt = $pdo->prepare("SELECT start_time, status FROM exam_records WHERE id = ? AND student_id = ?");
    $stmt->execute([$exam_record_id, $_SESSION['student_id']]);
    $exam_record = $stmt->fetch();
    if ($exam_record) {
        // 如果考试已完成，清除session并跳转到结果页
        if ($exam_record['status'] == 'completed') {
            unset($_SESSION['exam_record_id']);
            unset($_SESSION['exam_start_time']);
            header("Location: exam_result.php?exam_record_id={$exam_record_id}");
            exit;
        }
        $_SESSION['exam_start_time'] = strtotime($exam_record['start_time']);
    } else {
        // 如果找不到考试记录，清除session中的exam_record_id
        unset($_SESSION['exam_record_id']);
        unset($_SESSION['exam_start_time']);
        $exam_record_id = 0;
    }
}

// 获取已答题目ID列表（用于导航栏状态显示）
$answered_question_ids = [];
if ($exam_record_id > 0) {
    $stmt = $pdo->prepare("SELECT question_id FROM answer_records WHERE exam_record_id = ? AND student_answer IS NOT NULL AND student_answer != ''");
    $stmt->execute([$exam_record_id]);
    $answered_question_ids = array_column($stmt->fetchAll(), 'question_id');
}

// 计算剩余时间
$current_time = time();
$duration_seconds = intval($paper['duration']) * 60;
$exam_start_time = isset($_SESSION['exam_start_time']) ? intval($_SESSION['exam_start_time']) : $current_time;
$end_time = $exam_start_time + $duration_seconds;
$remaining_time = max(0, $end_time - $current_time);

// 构建正确答案映射（用于前端验证，但不显示）
// 优化：批量处理，减少JSON编码测试次数
$correct_answers_map = [];
foreach ($questions as $q) {
    $answer = trim($q['correct_answer']);
    if (!empty($answer)) {
        $correct_answers_map[$q['id']] = $answer;
    }
}
// 一次性测试整个数组的JSON编码，如果失败再逐个处理
$test_json = json_encode($correct_answers_map, JSON_UNESCAPED_UNICODE);
if ($test_json === false) {
    // 如果整体编码失败，逐个处理并跳过有问题的题目
    $correct_answers_map = [];
    foreach ($questions as $q) {
        $answer = trim($q['correct_answer']);
        if (!empty($answer)) {
            $test_single = json_encode($answer, JSON_UNESCAPED_UNICODE);
            if ($test_single !== false) {
                $correct_answers_map[$q['id']] = $answer;
            } else {
                error_log("Warning: Question ID {$q['id']} correct_answer JSON encode failed: " . json_last_error_msg());
            }
        }
    }
}

// 按题型分组题目，并按照试卷配置的顺序排序
$questions_by_type = [];
$question_index_map = []; // 用于导航栏的题目索引映射
$global_index = 0;

foreach ($questions as $question) {
    $type = $question['question_type'];
    if (!isset($questions_by_type[$type])) {
        $questions_by_type[$type] = [];
    }
    $questions_by_type[$type][] = $question;
    $question_index_map[$question['id']] = $global_index++;
}

// 从试卷配置中获取题型顺序
$type_order = [];
if (!empty($paper['question_config'])) {
    $question_config = json_decode($paper['question_config'], true);
    if ($question_config && is_array($question_config)) {
        // 按照question_config中的顺序（JSON对象的键顺序）
        $type_order = array_keys($question_config);
    }
}

// 如果没有配置顺序，使用默认顺序
if (empty($type_order)) {
    $type_order = ['单选题', '多选题', '判断题', '填空题', '名词解释', '简答题', '实操论述题'];
}

// 按照指定顺序重新排序
$ordered_questions_by_type = [];
foreach ($type_order as $type) {
    if (isset($questions_by_type[$type])) {
        $ordered_questions_by_type[$type] = $questions_by_type[$type];
    }
}
// 添加其他未在顺序列表中的题型
foreach ($questions_by_type as $type => $questions_list) {
    if (!in_array($type, $type_order)) {
        $ordered_questions_by_type[$type] = $questions_list;
    }
}
$questions_by_type = $ordered_questions_by_type;

// 创建按题型排序后的题目列表（用于导航栏）
$ordered_questions = [];
$question_id_to_index = []; // 题目ID到新序号的映射
$new_index = 0;
foreach ($questions_by_type as $type => $type_questions) {
    foreach ($type_questions as $question) {
        $ordered_questions[] = $question;
        $question_id_to_index[$question['id']] = $new_index;
        $new_index++;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>正在答题 - <?php echo escape($paper['title']); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/style.css">
    <!-- Quill 富文本编辑器 - 异步加载，避免阻塞页面渲染 -->
    <link rel="preload" href="https://cdn.quilljs.com/1.3.6/quill.snow.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"></noscript>
    <script>
        // 异步加载Quill.js，避免阻塞页面渲染
        (function() {
            var script = document.createElement('script');
            script.src = 'https://cdn.quilljs.com/1.3.6/quill.js';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        })();
    </script>
    <script>
        // 幽默防复制消息数组（超搞笑玩梗版）
        const funnyWarnings = [
            // 经典“寄了”宇宙
            { emoji: '📄', text: '这点内容还要复制？哥，这波有点小寄。' },
            { emoji: '🪦', text: '别复制了，再复制这门课就要给你立碑了。' },
            { emoji: '🧟', text: '复活失败，复制也救不了这道题，还是动脑吧。' },
            { emoji: '💀', text: '复制失败，这波属于是彻底寄了。' },
            
            // 卷王 & 摸鱼梗
            { emoji: '📚', text: '别卷复制了，卷一卷脑子，效果更好。' },
            { emoji: '🐟', text: '摸鱼可以，但摸题要自己摸，复制不算。' },
            { emoji: '🏃', text: '别乱跑了，卷王，请回到题目本体。' },
            { emoji: '🔥', text: '卷起来！别摆烂！用脑子刷题才是真卷王！' },
            
            // “懂得都懂”/狠活儿
            { emoji: '🧠', text: '懂得都懂：复制这一下，啥也没变。' },
            { emoji: '🎬', text: '收工收工，这里不接复制这种活儿。' },
            { emoji: '🧨', text: '复制已拦截，这波属于是从源头掐断。' },
            { emoji: '🎪', text: '别整这些花活儿，老老实实用脑子刷题！' },
            
            // 学习 & 挂科相关
            { emoji: '🎓', text: '兄弟，这是模拟考，不是Ctrl+C大赛。' },
            { emoji: '📉', text: '再复制下去，绩点要给你表演社会性滑坡。' },
            { emoji: '📝', text: '别急着抄，先想一想，可能你就会了。' },
            { emoji: '📊', text: '复制一时爽，挂科火葬场。' },
            { emoji: '🎯', text: '想作弊？这波操作要寄，还是用脑子吧。' },
            
            // 技术&AI梗
            { emoji: '🤖', text: 'AI 已上线：检测到复制操作，判你思想懒惰罪。' },
            { emoji: '🛰️', text: '复制请求已被卫星拦截，建议本地运算。' },
            { emoji: '🔍', text: '源代码暂不开放，请使用人类大脑进行编译。' },
            { emoji: '💻', text: 'Ctrl+C已禁用，请使用Ctrl+大脑模式。' },
            { emoji: '🛡️', text: '防护盾已开启，复制被拦截！这波稳了。' },
            
            // 轻松自嘲类
            { emoji: '😅', text: '复制是别人脑子跑的步，你只是按了个键。' },
            { emoji: '😏', text: '偷偷复制？监考老师：我当时脸都绿了。' },
            { emoji: '🙃', text: '再复制，监考老师要请你喝“重修套餐”了。' },
            { emoji: '🔥', text: '冷知识：复制并不能顺便复制别人的智商。' },
            { emoji: '😎', text: '这波操作有点小寄，还是用脑子吧。' },
            { emoji: '🤔', text: '想复制？这题得用脑子，不是Ctrl+C。' },
            
            // 动物梗
            { emoji: '🦀', text: '螃蟹都横着走了，你还想复制？' },
            { emoji: '🐌', text: '蜗牛都比你快，快用脑子刷题！' },
            { emoji: '🦖', text: '恐龙都灭绝了，你还在想复制？' },
            { emoji: '🐢', text: '乌龟都比你积极，快回来刷题！' }
        ];
        
        // 显示幽默警告
        function showFunnyWarning() {
            const warning = funnyWarnings[Math.floor(Math.random() * funnyWarnings.length)];
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
                border: 3px solid #ffc107;
                border-radius: 20px;
                padding: 30px 40px;
                box-shadow: 0 10px 40px rgba(255, 193, 7, 0.5);
                z-index: 99999;
                text-align: center;
                font-size: 20px;
                font-weight: 600;
                color: #856404;
                animation: popIn 0.3s ease, fadeOut 0.3s ease 2s forwards;
                min-width: 300px;
            `;
            toast.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 15px;">${warning.emoji}</div>
                <div>${warning.text}</div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 2300);
        }
        
        // 添加动画样式
        const style = document.createElement('style');
        style.textContent = `
            @keyframes popIn {
                0% { transform: translate(-50%, -50%) scale(0.5); opacity: 0; }
                50% { transform: translate(-50%, -50%) scale(1.1); }
                100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                to { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
            }
        `;
        document.head.appendChild(style);
        
        // 禁止复制 & 退出考试确认（答题页面需要允许输入框操作）
        document.addEventListener('DOMContentLoaded', function() {
            // === 退出考试确认逻辑 ===
            let examGuardEnabled = true;      // 是否启用离开确认
            let isSubmittingExam = false;     // 是否正在提交试卷
            let pendingHref = null;           // 待跳转的地址（用户确认后再跳转）
            
            const leaveModal = document.getElementById('exam-leave-modal');
            const leaveOverlay = document.getElementById('exam-leave-overlay');
            const btnStay = document.getElementById('exam-leave-stay');
            const btnLeave = document.getElementById('exam-leave-confirm');
            
            function openLeaveModal(href) {
                pendingHref = href || null;
                if (leaveOverlay) leaveOverlay.style.display = 'block';
                if (leaveModal) leaveModal.style.display = 'block';
            }
            
            function closeLeaveModal() {
                pendingHref = null;
                if (leaveOverlay) leaveOverlay.style.display = 'none';
                if (leaveModal) leaveModal.style.display = 'none';
            }
            
            if (btnStay) {
                btnStay.addEventListener('click', function () {
                    closeLeaveModal();
                });
            }
            
            // 表单提交时不再拦截（正常交卷）
            const examForm = document.getElementById('examForm');
            
            if (btnLeave) {
                btnLeave.addEventListener('click', function () {
                    // 学生确认离开：先交卷，再执行原本的跳转意图
                    if (examForm) {
                        // 在提交表单时携带离开后跳转地址
                        if (pendingHref) {
                            let redirectInput = examForm.querySelector('input[name=\"after_url\"]');
                            if (!redirectInput) {
                                redirectInput = document.createElement('input');
                                redirectInput.type = 'hidden';
                                redirectInput.name = 'after_url';
                                examForm.appendChild(redirectInput);
                            }
                            redirectInput.value = pendingHref;
                        }
                        isSubmittingExam = true;
                        examGuardEnabled = false;
                        closeLeaveModal();
                        examForm.submit();
                    } else {
                        // 理论上不会走到这里，作为兜底行为直接跳转
                        examGuardEnabled = false;
                        const target = pendingHref || 'exam_list.php';
                        closeLeaveModal();
                        window.location.href = target;
                    }
                });
            }
            if (examForm) {
                examForm.addEventListener('submit', function () {
                    isSubmittingExam = true;
                    examGuardEnabled = false;
                });
            }
            
            // 拦截站内链接跳转（锚点链接除外），弹出自定义弹窗
            document.addEventListener('click', function (e) {
                if (!examGuardEnabled) return;
                
                const link = e.target.closest('a');
                if (!link) return;
                
                const href = link.getAttribute('href');
                if (!href) return;
                
                // 同页锚点跳转（题目导航）不拦截
                if (href.startsWith('#')) return;
                
                // javascript: 等特殊链接不处理
                if (href.toLowerCase().startsWith('javascript:')) return;
                
                // 到这里说明是要离开当前考试页面的跳转
                e.preventDefault();
                openLeaveModal(href);
            });
            
            // 关闭/刷新标签页时使用原生 beforeunload 提示兜底
            window.addEventListener('beforeunload', function (e) {
                if (!examGuardEnabled || isSubmittingExam) return;
                e.preventDefault();
                e.returnValue = ''; // 触发浏览器原生提示
            });
            // === 以下为防复制逻辑 ===
            // 禁用右键菜单（但允许在输入框中使用）
            document.addEventListener('contextmenu', function(e) {
                // 允许在输入框、文本域、富文本编辑器中使用右键
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.closest('.ql-editor')) {
                    return true;
                }
                // 在题干、选项等文本内容区域右键时显示警告
                if (e.target.closest('.question-text-content') || 
                    e.target.closest('.question-section-title') || 
                    e.target.closest('.question-card') ||
                    e.target.closest('.options')) {
                    e.preventDefault();
                    showFunnyWarning();
                    return false;
                }
                // 空白区域右键不显示警告，但禁止菜单
                e.preventDefault();
                return false;
            });
            
            // 禁用复制快捷键（但允许在输入框中使用）
            document.addEventListener('keydown', function(e) {
                var isInput = e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.closest('.ql-editor');
                if (!isInput) {
                    // Ctrl+C, Ctrl+A, Ctrl+V, Ctrl+X, Ctrl+S
                    if (e.ctrlKey && (e.keyCode === 67 || e.keyCode === 65 || e.keyCode === 86 || e.keyCode === 88 || e.keyCode === 83)) {
                        e.preventDefault();
                        showFunnyWarning();
                        return false;
                    }
                    // F12 (开发者工具)
                    if (e.keyCode === 123) {
                        e.preventDefault();
                        showFunnyWarning();
                        return false;
                    }
                    // Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U (查看源代码)
                    if (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) {
                        e.preventDefault();
                        showFunnyWarning();
                        return false;
                    }
                    if (e.ctrlKey && e.keyCode === 85) {
                        e.preventDefault();
                        showFunnyWarning();
                        return false;
                    }
                }
            });
            
            // 禁用文本选择（但允许在输入框中选择）
            let selectionStartTime = null;
            document.addEventListener('selectstart', function(e) {
                // 允许在输入框、文本域、富文本编辑器中选择
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.closest('.ql-editor') || e.target.closest('label')) {
                    return true;
                }
                // 题干部分禁止选择
                if (e.target.closest('.question-text-content') || e.target.closest('.question-section-title')) {
                    selectionStartTime = Date.now();
                    return false;
                }
                // 其他区域也禁止选择
                selectionStartTime = Date.now();
                return false;
            });
            
            // 检测是否有文本被选中（只有真正选中文本时才警告）
            document.addEventListener('mouseup', function(e) {
                if (selectionStartTime && (Date.now() - selectionStartTime) > 100) {
                    const selection = window.getSelection();
                    if (selection && selection.toString().trim().length > 0) {
                        // 如果选中的不是输入框中的内容，显示警告
                        const selectedText = selection.toString();
                        const range = selection.getRangeAt(0);
                        const container = range.commonAncestorContainer;
                        const isInInput = container.nodeType === 1 && (
                            container.tagName === 'INPUT' || 
                            container.tagName === 'TEXTAREA' || 
                            container.closest('.ql-editor') ||
                            container.closest('label')
                        );
                        if (!isInInput && selectedText.trim().length > 0) {
                            showFunnyWarning();
                            selection.removeAllRanges();
                        }
                    }
                    selectionStartTime = null;
                }
            });
            
            // 禁用拖拽（只有真正拖拽时才警告）
            document.ondragstart = function(e) {
                if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA' && !e.target.closest('.ql-editor')) {
                    // 只有在拖拽文本内容时才警告
                    if (e.target.textContent && e.target.textContent.trim().length > 0) {
                        showFunnyWarning();
                    }
                    return false;
                }
            };
        });
    </script>
    <style>
        body {
            padding-left: 220px; /* 为左侧导航栏留出空间 */
        }
        /* 确保header样式与records.php一致 */
        .main-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            color: white !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15) !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1000 !important;
            left: 220px !important;
            right: 0 !important;
        }
        .main-header h1 {
            font-size: 22px !important;
            font-weight: 600 !important;
            letter-spacing: 0.5px !important;
            margin: 0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
        }
        .header-content {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            padding: 18px 30px !important;
            font-size: 14px !important;
            max-width: 1400px !important;
            margin: 0 auto !important;
        }
        .user-info {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            flex-wrap: wrap !important;
        }
        .user-info span {
            font-weight: 500 !important;
            opacity: 0.95 !important;
        }
        .user-info a {
            color: white !important;
            text-decoration: none !important;
            padding: 8px 16px !important;
            background: rgba(255, 255, 255, 0.2) !important;
            border-radius: 8px !important;
            transition: all 0.3s ease !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            -webkit-backdrop-filter: blur(10px) !important;
            backdrop-filter: blur(10px) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
        }
        .user-info a:hover {
            background: rgba(255, 255, 255, 0.3) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;
        }
        .timer {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 16px 20px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
            position: sticky;
            top: 0;
            z-index: 100;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .nav-logo {
            flex-shrink: 0;
        }
        .timer.warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }
        .timer.danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.5);
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.02); }
        }
        
        /* 右上角悬浮得分显示 */
        .floating-score-display {
            position: fixed;
            top: 100px;
            right: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.4);
            z-index: 1000;
            min-width: 120px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .floating-score-display:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.5);
        }
        
        .floating-score-display .score-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .floating-score-display .score-value {
            font-size: 28px;
            font-weight: bold;
            line-height: 1.2;
            margin-bottom: 3px;
        }
        
        .floating-score-display .score-total {
            font-size: 11px;
            opacity: 0.8;
        }
        
        @media (max-width: 768px) {
            .floating-score-display {
                top: 80px;
                right: 10px;
                padding: 12px 15px;
                min-width: 100px;
            }
            .floating-score-display .score-value {
                font-size: 24px;
            }
        }
        
        .question-nav {
            position: fixed;
            left: 0;
            top: 0;
            width: 200px;
            height: 100vh;
            background: white;
            border-right: 2px solid #e0e0e0;
            z-index: 999;
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            padding-bottom: 50px; /* 为footer留出空间 */
        }
        .question-nav h3 {
            padding: 14px 12px;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 14px;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }
        .nav-question-list {
            padding: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-start;
            flex: 1;
            overflow-y: auto;
            background: #f8f9fa;
            align-content: flex-start;
        }
        .nav-question-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            border: 2px solid #ddd;
            background: white;
            transition: all 0.3s ease;
            position: relative;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        .nav-question-item:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-color: #667eea;
            transform: translateY(-1px) scale(1.05);
            box-shadow: 0 3px 8px rgba(102, 126, 234, 0.3);
        }
        .nav-question-item.answered {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: #28a745;
            color: #155724;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.2);
        }
        .nav-question-item.answered.wrong-answer {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border-color: #dc3545;
            color: #721c24;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.2);
        }
        .nav-question-item.current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            transform: scale(1.1);
        }
        .question-section {
            margin-bottom: 35px;
        }
        .question-section-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
            padding: 18px 0;
            margin-bottom: 20px;
            border-bottom: 3px solid #667eea;
            position: relative;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }
        .question-section-title::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 80px;
            height: 3px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        .question-card {
            font-size: 14px;
            margin-bottom: 35px;
            padding: 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #667eea;
        }
        
        /* 题干内容禁止选中 */
        .question-text-content {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-touch-callout: none !important;
        }
        
        /* 选项标签也禁止选中（但允许点击） */
        .question-card .options label {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }
        .question-card h3 {
            font-size: 16px;
            margin-bottom: 15px;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
        }
        .question-text {
            font-size: 15px;
            line-height: 1.8;
            margin-bottom: 20px;
            color: #34495e;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            /* 禁止选中 */
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-touch-callout: none !important;
        }
        
        /* 题干部分禁止选中 */
        .question-card > div:first-child,
        .question-card strong,
        .question-section-title {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-touch-callout: none !important;
        }
        .options {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
            margin-bottom: 15px;
        }
        .options label {
            flex: 1 1 calc(50% - 8px);
            min-width: 200px;
            display: flex;
            align-items: center;
            padding: 12px 15px;
            margin: 0;
            cursor: pointer;
            font-size: 14px;
            line-height: 1.6;
            color: #333;
            background: #fafafa;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .options label:hover {
            background: #f0f4ff;
            border-color: #667eea;
            transform: translateX(5px);
        }
        .options.judgment label {
            flex: 0 1 auto;
            min-width: auto;
            margin-right: 30px;
            background: #fafafa;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 20px;
        }
        .options input[type="radio"]:checked + label,
        .options input[type="checkbox"]:checked + label {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        .options input[type="radio"],
        .options input[type="checkbox"] {
            margin-right: 12px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }
        .form-group {
            margin-top: 15px;
            margin-bottom: 15px;
        }
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
            background: #fafafa;
            resize: vertical;
        }
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }
        .exam-actions {
            position: fixed;
            bottom: 100px; /* 为footer留出空间 */
            left: 220px;
            right: 0;
            background: white;
            padding: 20px 30px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
        }
        .exam-actions button {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        .save-btn {
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
        }
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.5);
        }
        .submit-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        @media (max-width: 768px) {
            body {
                padding-left: 0;
            }
            .question-nav {
                display: none;
            }
            .exam-actions {
                left: 0;
                bottom: 100px; /* 移动端也为footer留出空间 */
                flex-direction: column;
                gap: 10px;
            }
            .exam-actions button {
                width: 100%;
            }
            .wrong-answer-toast {
                right: 10px !important;
                left: 10px !important;
                max-width: calc(100% - 20px) !important;
            }
        }
        
        /* 错误提示动画关键帧 */
        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0) scale(1); }
            25% { transform: translateY(-10px) scale(1.05); }
            50% { transform: translateY(0) scale(1); }
            75% { transform: translateY(-5px) scale(1.02); }
        }
        
        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
            to {
                opacity: 0;
                transform: translateX(400px) scale(0.8);
            }
        }
        
        @keyframes rotate {
            0% { transform: rotate(0deg); }
            25% { transform: rotate(-15deg); }
            75% { transform: rotate(15deg); }
            100% { transform: rotate(0deg); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        /* 错误提示样式 */
        .wrong-answer-toast {
            position: fixed;
            top: 100px;
            right: 30px;
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            border: 3px solid #ffc107;
            border-radius: 16px;
            padding: 20px 25px;
            box-shadow: 0 8px 30px rgba(255, 193, 7, 0.4);
            z-index: 10000;
            max-width: 350px;
            animation: slideInRight 0.5s ease, bounce 0.6s ease 0.5s, fadeOut 0.5s ease 3s forwards;
            transform-origin: center;
        }
        
        .wrong-answer-toast .emoji {
            font-size: 48px;
            text-align: center;
            margin-bottom: 10px;
            animation: rotate 0.6s ease 0.5s, shake 0.5s ease 1s;
        }
        
        .wrong-answer-toast .message {
            font-size: 16px;
            font-weight: 600;
            color: #856404;
            text-align: center;
            line-height: 1.5;
        }
    </style>
    <script>
        <?php include 'inc/inactivity_reminder.inc.php'; ?>
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <h1>
                <?php echo escape($paper['title']); ?>
            </h1>
            <div class="user-info">
                <span>
                    学号：<?php echo escape($_SESSION['student_no']); ?>
                    <?php if (!empty($_SESSION['student_name'])): ?>
                        | 姓名：<?php echo escape($_SESSION['student_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['student_class'])): ?>
                        | 班级：<?php echo escape($_SESSION['student_class']); ?>
                    <?php endif; ?>
                </span>
                <a href="exam_list.php">考试</a>
                <a href="records.php">我的记录</a>
                <a href="wrong_questions.php">错题本</a>
                <a href="logout.php">退出</a>
                <a href="help_student.php">使用说明</a>
            </div>
        </div>
    </header>
    
    <!-- 右上角悬浮得分显示 -->
    <div class="floating-score-display" id="floating-score-display">
        <div class="score-label">当前得分</div>
        <div class="score-value" id="current-score">0.00</div>
        <div class="score-total">/ <?php echo $paper['total_score']; ?> 分</div>
    </div>
    
    <!-- 左侧题目导航栏 -->
    <div class="question-nav">
        <div class="timer" id="timer">
            <img src="/favicon.svg" alt="<?php echo escape($paper['title']); ?>" class="nav-logo" style="width: 32px; height: 32px; margin-right: 10px; vertical-align: middle;">
            <span>剩余时间：<span id="time-display"></span></span>
        </div>
        <h3>题目导航</h3>
        <div class="nav-question-list" style="display: block; padding: 12px 10px;">
            <?php 
            $nav_global_index = 0;
            foreach ($questions_by_type as $type => $type_questions): 
                if (empty($type_questions)) continue;
            ?>
                <div class="nav-type-group" style="margin-bottom: 15px;">
                    <div style="font-size: 13px; font-weight: bold; color: #667eea; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px dashed #e0e0e0;">
                        <?php echo escape($type); ?>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <?php 
                        foreach ($type_questions as $question): 
                            $is_answered = in_array($question['id'], $answered_question_ids);
                        ?>
                            <a href="#question-<?php echo $question['id']; ?>" 
                               class="nav-question-item <?php echo $is_answered ? 'answered' : ''; ?>" 
                               data-question-id="<?php echo $question['id']; ?>"
                               data-question-score="<?php echo $question['score']; ?>"
                               id="nav-question-<?php echo $question['id']; ?>"
                               title="<?php echo escape($type); ?> - 第 <?php echo $nav_global_index + 1; ?> 题（<?php echo $question['score']; ?>分）">
                                <?php echo $nav_global_index + 1; ?>
                            </a>
                        <?php 
                            $nav_global_index++;
                        endforeach; 
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="padding: 15px; border-top: 2px solid #ddd; margin-top: 10px; margin-bottom: 0; position: sticky; bottom: 50px; background: white; z-index: 10;">
            <button type="submit" form="examForm" class="btn btn-primary" 
                    style="width: 100%; padding: 12px; font-size: 14px; font-weight: bold;"
                    onclick="syncQuillContent(); return confirm('确定要提交试卷吗？提交后无法修改。')">
                提交试卷
            </button>
        </div>
    </div>
    
    <div class="container" style="padding: 15px 20px;">
        <form method="POST" id="examForm">
            <input type="hidden" name="action" value="submit">
            
            <?php 
            $section_index = 0;
            $global_question_index = 0;
            foreach ($questions_by_type as $type => $type_questions): 
                $section_index++;
            ?>
                <div class="question-section">
                    <div class="question-section-title">
                        第<?php echo $section_index; ?>大题：<?php echo escape($type); ?>
                    </div>
                    
                    <?php foreach ($type_questions as $question): ?>
                        <div class="question-card" id="question-<?php echo $question['id']; ?>">
                            <div class="question-text-content" style="font-size: 15px; margin-bottom: 12px; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; -webkit-touch-callout: none;">
                                <strong><?php echo $global_question_index + 1; ?>.</strong> <?php echo nl2br(escape($question['question_text'])); ?> <span style="color: #999;">（<?php echo $question['score']; ?>分）</span>
                            </div>
                            
                            <?php 
                            $question_type = $question['question_type'];
                            $saved_answer = $question['student_answer'] ?? '';
                            $saved_answers_array = !empty($saved_answer) ? explode(',', $saved_answer) : [];
                            
                            if ($question_type == '单选题'): 
                                // 单选题：单选框（A、B、C、D），每行两个
                            ?>
                                <div class="options">
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="A" 
                                        <?php echo $saved_answer == 'A' ? 'checked' : ''; ?>> 
                                        A. <?php echo escape($question['option_a'] ?? ''); ?></label>
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="B" 
                                        <?php echo $saved_answer == 'B' ? 'checked' : ''; ?>> 
                                        B. <?php echo escape($question['option_b'] ?? ''); ?></label>
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="C" 
                                        <?php echo $saved_answer == 'C' ? 'checked' : ''; ?>> 
                                        C. <?php echo escape($question['option_c'] ?? ''); ?></label>
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="D" 
                                        <?php echo $saved_answer == 'D' ? 'checked' : ''; ?>> 
                                        D. <?php echo escape($question['option_d'] ?? ''); ?></label>
                                </div>
                            <?php elseif ($question_type == '多选题'): 
                                // 多选题：复选框（A、B、C、D），每行两个
                            ?>
                                <div class="options">
                                    <label><input type="checkbox" class="multi-choice" data-question-id="<?php echo $question['id']; ?>" value="A" 
                                        <?php echo in_array('A', $saved_answers_array) ? 'checked' : ''; ?>> 
                                        A. <?php echo escape($question['option_a'] ?? ''); ?></label>
                                    <label><input type="checkbox" class="multi-choice" data-question-id="<?php echo $question['id']; ?>" value="B" 
                                        <?php echo in_array('B', $saved_answers_array) ? 'checked' : ''; ?>> 
                                        B. <?php echo escape($question['option_b'] ?? ''); ?></label>
                                    <label><input type="checkbox" class="multi-choice" data-question-id="<?php echo $question['id']; ?>" value="C" 
                                        <?php echo in_array('C', $saved_answers_array) ? 'checked' : ''; ?>> 
                                        C. <?php echo escape($question['option_c'] ?? ''); ?></label>
                                    <label><input type="checkbox" class="multi-choice" data-question-id="<?php echo $question['id']; ?>" value="D" 
                                        <?php echo in_array('D', $saved_answers_array) ? 'checked' : ''; ?>> 
                                        D. <?php echo escape($question['option_d'] ?? ''); ?></label>
                                </div>
                                <input type="hidden" name="answers[<?php echo $question['id']; ?>]" id="multi-answer-<?php echo $question['id']; ?>" value="<?php echo escape($saved_answer); ?>">
                            <?php elseif ($question_type == '填空题'): 
                                // 填空题：输入框
                            ?>
                                <div class="form-group">
                                    <input type="text" name="answers[<?php echo $question['id']; ?>]" 
                                        placeholder="请输入答案" 
                                        class="answer-input" 
                                        data-question-id="<?php echo $question['id']; ?>"
                                        value="<?php echo escape($saved_answer); ?>" 
                                        style="width: 100%; padding: 10px; font-size: 14px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            <?php elseif ($question_type == '判断题'): 
                                // 判断题：单选框（正确/错误），同一行
                            ?>
                                <div class="options judgment">
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="正确" 
                                        <?php echo $saved_answer == '正确' ? 'checked' : ''; ?>> 
                                        正确</label>
                                    <label><input type="radio" name="answers[<?php echo $question['id']; ?>]" value="错误" 
                                        <?php echo $saved_answer == '错误' ? 'checked' : ''; ?>> 
                                        错误</label>
                                </div>
                            <?php elseif ($question_type == '名词解释' || $question_type == '简答题'): 
                                // 名词解释/简答题：文本框（textarea）
                            ?>
                                <div class="form-group">
                                    <textarea name="answers[<?php echo $question['id']; ?>]" rows="5" 
                                        placeholder="请输入答案" 
                                        class="answer-input" 
                                        data-question-id="<?php echo $question['id']; ?>"
                                        style="width: 100%; max-width: 800px; padding: 8px 10px; font-size: 14px; border: 1px solid #ccc; border-radius: 2px; font-family: inherit;"><?php echo escape($saved_answer); ?></textarea>
                                </div>
                            <?php elseif ($question_type == '实操论述题'): 
                                // 实操论述题：富文本框
                            ?>
                                <div class="form-group">
                                    <div id="editor-<?php echo $question['id']; ?>" style="height: 300px; margin-bottom: 10px; max-width: 900px;"></div>
                                    <textarea name="answers[<?php echo $question['id']; ?>]" 
                                        id="richtext-<?php echo $question['id']; ?>"
                                        class="answer-input richtext-editor" 
                                        data-question-id="<?php echo $question['id']; ?>"
                                        style="display: none;"><?php echo escape($saved_answer); ?></textarea>
                                </div>
                            <?php else: 
                                // 默认情况：文本框
                            ?>
                                <div class="form-group">
                                    <textarea name="answers[<?php echo $question['id']; ?>]" rows="5" 
                                        placeholder="请输入答案" 
                                        class="answer-input" 
                                        data-question-id="<?php echo $question['id']; ?>"
                                        style="width: 100%; padding: 10px; font-size: 14px; border: 1px solid #ddd; border-radius: 4px;"><?php echo escape($saved_answer); ?></textarea>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php 
                        $global_question_index++;
                    endforeach; ?>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
    
    <!-- 退出考试确认弹窗 -->
    <div id="exam-leave-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:12000;"></div>
    <div id="exam-leave-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:linear-gradient(135deg,#ffffff 0%,#f3f4ff 100%); border-radius:18px; box-shadow:0 20px 60px rgba(15,23,42,0.35); padding:26px 28px 22px; max-width:420px; width:90%; z-index:12001; border:1px solid rgba(129,140,248,0.35);">
        <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px;">
            <div style="width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#f97316,#facc15); display:flex; align-items:center; justify-content:center; box-shadow:0 10px 25px rgba(248,113,113,0.4); font-size:24px;">
                ⚠️
            </div>
            <div>
                <div style="font-size:18px; font-weight:700; color:#1f2937; margin-bottom:4px;">退出模拟考试？</div>
                <div style="font-size:13px; color:#4b5563; line-height:1.6;">
                    当前试卷还没有提交，直接离开本页将中断本次模拟考试。<br>
                    建议先完成并提交试卷，再离开页面。
                </div>
            </div>
        </div>
        <div style="margin:10px 0 16px; padding:10px 12px; border-radius:10px; background:linear-gradient(135deg,rgba(129,140,248,0.08),rgba(79,70,229,0.05)); border:1px dashed rgba(129,140,248,0.5); font-size:12px; color:#4b5563;">
            <span style="font-weight:600; color:#4f46e5;">小提示：</span>这是模拟考试，提前退出不会记入成绩，但当前答题进度可能无法完整保留。
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:6px;">
            <button type="button" id="exam-leave-stay" style="padding:9px 16px; border-radius:999px; border:1px solid rgba(148,163,184,0.7); background:#ffffff; font-size:13px; font-weight:500; color:#4b5563; cursor:pointer; min-width:96px;">
                继续答题
            </button>
            <button type="button" id="exam-leave-confirm" style="padding:9px 18px; border-radius:999px; border:none; background:linear-gradient(135deg,#6366f1,#8b5cf6); font-size:13px; font-weight:600; color:#ffffff; cursor:pointer; box-shadow:0 6px 18px rgba(79,70,229,0.45); min-width:110px;">
                确认退出
            </button>
        </div>
    </div>
    
    <script>
        // 正确答案映射（用于验证，但不显示）
        // 添加错误处理，防止JSON解析失败导致页面无法加载
        let correctAnswers = {};
        try {
            const correctAnswersJson = <?php 
                $json_output = json_encode($correct_answers_map, JSON_UNESCAPED_UNICODE);
                if ($json_output === false) {
                    // 如果JSON编码失败，输出空对象并记录错误
                    error_log("Error: correct_answers_map JSON encode failed: " . json_last_error_msg());
                    echo '{}';
                } else {
                    echo $json_output;
                }
            ?>;
            correctAnswers = correctAnswersJson || {};
        } catch (e) {
            console.error('Failed to parse correctAnswers:', e);
            correctAnswers = {};
        }
        
        // 记录每题的答案状态和得分
        const answerStatus = {};
        
        // 题目分值映射（批量初始化，提高性能）
        const questionScores = <?php 
            $scores_map = [];
            foreach ($ordered_questions as $question) {
                $scores_map[$question['id']] = floatval($question['score']);
            }
            echo json_encode($scores_map, JSON_UNESCAPED_UNICODE);
        ?>;
        
        // 计算当前总得分
        function calculateCurrentScore() {
            let totalScore = 0;
            for (const questionId in answerStatus) {
                const status = answerStatus[questionId];
                if (status && status.isCorrect === true && status.score !== undefined) {
                    totalScore += status.score;
                } else if (status && status.isCorrect === false && status.partialScore !== undefined) {
                    // 主观题可能有部分得分
                    totalScore += status.partialScore || 0;
                }
            }
            return Math.round(totalScore * 100) / 100; // 保留两位小数
        }
        
        // 更新得分显示
        function updateScoreDisplay() {
            const currentScore = calculateCurrentScore();
            const scoreElement = document.getElementById('current-score');
            if (scoreElement) {
                scoreElement.textContent = currentScore.toFixed(2);
            }
        }
        
        // 幽默诙谐的错误提示语（超搞笑玩梗版）
        const wrongAnswerMessages = [
            // “寄了”/挂科相关
            { emoji: '📉', text: '这题再错两次，绩点要给你来一波自由落体。' },
            { emoji: '🪦', text: '这道题：我先给你立块小碑，等你做对再来刮彩票。' },
            { emoji: '🧊', text: '冷知识：这题已经把你冰箱里剩下的底线冻没了。' },
            { emoji: '💀', text: '这波操作有点小寄，再错就彻底寄了。' },
            { emoji: '📊', text: '再错下去，你的绩点要表演社会性滑坡了。' },
            
            // 卷王/摆烂调侃
            { emoji: '📚', text: '卷王提示：这题不能靠气质，全靠思路。' },
            { emoji: '🛋️', text: '摆是可以摆的，但别摆在同一道题上。' },
            { emoji: '🍵', text: '先别急着摆烂，这题再瞅一眼可能就对了。' },
            { emoji: '🔥', text: '别摆烂了，卷起来！这题再想想。' },
            { emoji: '💪', text: '卷王从不放弃，这题再试试！' },
            
            // 网络语境梗
            { emoji: '🧠', text: '这波属于是——大脑短路了一下，再插上电。' },
            { emoji: '🤔', text: '这题已经给足你面子了，就差你给它个正确答案。' },
            { emoji: '🎮', text: '你刚刚那一选，相当于在难度噩梦里裸装开局。' },
            { emoji: '🎯', text: '这波操作有点小寄，再想想吧。' },
            { emoji: '🤖', text: 'AI提示：这题得用脑子，不是蒙的。' },
            
            // 温柔劝导类
            { emoji: '💪', text: '别慌，模拟考就是用来犯错的，期末别错就行。' },
            { emoji: '🌈', text: '这题错了不丢人，继续做下去才是狠人。' },
            { emoji: '🔁', text: '再来一遍，这题只是想多和你相处一会儿。' },
            { emoji: '⭐', text: '别放弃，星星在为你加油！' },
            { emoji: '🌙', text: '月亮都看不下去了，再想想吧。' },
            
            // 自嘲系
            { emoji: '🙃', text: '这题：你再这样选，我可就要举报你了（向老师）。' },
            { emoji: '🤣', text: '笑死，这选项都被你玩坏了，就是没玩对。' },
            { emoji: '🤡', text: '别把选择题做成抽盲盒，概率对你不怎么友好。' },
            { emoji: '😅', text: '这波操作有点小寄，还是用脑子吧。' },
            { emoji: '😎', text: '这题得用脑子，不是靠运气。' },
            
            // 学习鸡汤反讽小段子
            { emoji: '📎', text: '学习小贴士：多看一眼题干，少看一眼手机。' },
            { emoji: '📌', text: '记一笔：这道题以后在错题本见，先把它做对。' },
            { emoji: '🚀', text: '这道题是你通往高分的电梯，你刚才走错楼层了。' },
            { emoji: '🎪', text: '别整这些花活儿，老老实实做题。' },
            { emoji: '🎭', text: '这题不是演戏，得用真本事。' },
            
            // 动物梗
            { emoji: '🦀', text: '螃蟹都横着走了，你还选错了？' },
            { emoji: '🐌', text: '蜗牛都比你快，快用脑子想想。' },
            { emoji: '🦖', text: '恐龙都灭绝了，你还在选错？' },
            { emoji: '🐢', text: '乌龟都比你积极，快回来做题！' }
        ];
        
        // 显示错误提示
        function showWrongAnswerToast(questionId = null) {
            // 移除已存在的提示
            const existingToast = document.querySelector('.wrong-answer-toast');
            if (existingToast) {
                existingToast.remove();
            }
            
            // 随机选择一条提示
            const randomMessage = wrongAnswerMessages[Math.floor(Math.random() * wrongAnswerMessages.length)];
            
            // 获取题号（从导航中查找，提高精准度）
            let questionNumber = '';
            if (questionId) {
                const navItem = document.getElementById('nav-question-' + questionId);
                if (navItem) {
                    // 方法1：优先从title属性获取（最准确）
                    const title = navItem.getAttribute('title');
                    if (title) {
                        const titleMatch = title.match(/第\s*(\d+)\s*题/);
                        if (titleMatch) {
                            questionNumber = `第${titleMatch[1]}题`;
                        }
                    }
                    
                    // 方法2：如果title没有，从textContent获取
                    if (!questionNumber) {
                        const numberText = navItem.textContent.trim();
                        // 提取数字（可能是 "1" 或 "1. 题目" 格式）
                        const match = numberText.match(/^(\d+)/);
                        if (match) {
                            questionNumber = `第${match[1]}题`;
                        }
                    }
                    
                    // 方法3：如果前两种都失败，尝试从data属性或父元素获取
                    if (!questionNumber) {
                        const dataId = navItem.getAttribute('data-question-id');
                        // 尝试从题目元素中获取题号
                        const questionEl = document.getElementById('question-' + questionId);
                        if (questionEl) {
                            // 查找题目编号（可能在题目文本中）
                            const questionText = questionEl.textContent || '';
                            const questionMatch = questionText.match(/[（(]?\s*(\d+)\s*[）)]?[、.]/);
                            if (questionMatch) {
                                questionNumber = `第${questionMatch[1]}题`;
                            }
                        }
                    }
                }
            }
            
            // 创建提示元素
            const toast = document.createElement('div');
            toast.className = 'wrong-answer-toast';
            // 基础提示文案：先明确“第几题做错了”，再接调侃内容
            const baseText = questionNumber 
                ? `${questionNumber}做错啦~<br>` 
                : `有题目做错啦~<br>`;
            
            toast.innerHTML = `
                <div class="emoji">${randomMessage.emoji}</div>
                <div class="message">
                    ${baseText}${randomMessage.text}
                </div>
            `;
            
            document.body.appendChild(toast);
            
            // 3.5秒后自动移除
            setTimeout(() => {
                if (toast && toast.parentNode) {
                    toast.remove();
                }
            }, 3500);
        }
        
        // 获取题目类型（从DOM中获取）
        function getQuestionType(questionId) {
            const questionEl = document.getElementById('question-' + questionId);
            if (!questionEl) return null;
            
            // 查找题目所在的section标题
            const section = questionEl.closest('.question-section');
            if (section) {
                const title = section.querySelector('.question-section-title');
                if (title) {
                    const match = title.textContent.match(/第\d+大题：(.+)/);
                    if (match) return match[1];
                }
            }
            return null;
        }
        
        // 检查答案是否正确
        function checkAnswer(questionId, studentAnswer) {
            const correctAnswer = correctAnswers[questionId];
            if (!correctAnswer) {
                return null;
            }
            
            if (!studentAnswer || studentAnswer.trim() === '') {
                return null;
            }
            
            // 获取题目类型
            const questionType = getQuestionType(questionId);
            
            // 对于主观题（填空题、名词解释、实操论述题），不进行实时检查
            // 因为这些题目需要AI评分，无法简单判断对错
            if (questionType && ['填空题', '名词解释', '简答题', '实操论述题'].includes(questionType)) {
                return null; // 返回null表示无法判断
            }
            
            // 标准化答案（去除空格，转大写）
            const normalizeAnswer = (ans) => {
                if (!ans) return '';
                // 移除HTML标签（如果是富文本内容）
                const text = ans.toString().replace(/<[^>]*>/g, '');
                return text.replace(/\s+/g, '').toUpperCase();
            };
            
            const normalizedStudent = normalizeAnswer(studentAnswer);
            const normalizedCorrect = normalizeAnswer(correctAnswer);
            
            // 处理多选题（包含逗号或长度大于1的答案）
            if (normalizedCorrect.includes(',') || (normalizedCorrect.length > 1 && !['A', 'B', 'C', 'D', 'T', 'F', 'Y', 'N'].includes(normalizedCorrect))) {
                const studentArray = normalizedStudent.split(',').map(s => s.trim()).filter(s => s).sort();
                const correctArray = normalizedCorrect.split(',').map(s => s.trim()).filter(s => s).sort();
                return studentArray.join(',') === correctArray.join(',');
            }
            
            // 单选题、判断题等
            return normalizedStudent === normalizedCorrect;
        }
        
        // 记录答案状态
        function recordAnswerStatus(questionId, studentAnswer) {
            if (!studentAnswer || studentAnswer.trim() === '') {
                delete answerStatus[questionId];
                updateScoreDisplay();
                return;
            }
            
            const isCorrect = checkAnswer(questionId, studentAnswer);
            const questionScore = questionScores[questionId] || 0;
            
            // 只有能判断对错的题目才记录状态（主观题返回null，不记录）
            if (isCorrect !== null) {
                answerStatus[questionId] = {
                    answer: studentAnswer,
                    isCorrect: isCorrect,
                    score: isCorrect ? questionScore : 0 // 客观题：正确得满分，错误得0分
                };
            } else {
                // 主观题也记录，但标记为无法判断（得分需要提交后由服务器计算）
                answerStatus[questionId] = {
                    answer: studentAnswer,
                    isCorrect: null, // null表示无法判断
                    score: 0 // 主观题暂时不计算得分
                };
            }
            
            // 更新得分显示
            updateScoreDisplay();
        }
        
        // 初始化已保存答案的状态（批量处理，提高性能）
        const savedAnswers = {};
        <?php 
        // 批量构建已保存答案的映射，减少JSON编码次数
        $saved_answers_map = [];
        foreach ($questions as $q) {
            if (!empty($q['student_answer'])) {
                $saved_answers_map[$q['id']] = $q['student_answer'];
            }
        }
        if (!empty($saved_answers_map)): 
        ?>
        savedAnswers = <?php echo json_encode($saved_answers_map, JSON_UNESCAPED_UNICODE); ?>;
        <?php endif; ?>
        
        // 批量初始化答案状态和导航栏
        try {
            for (const questionId in savedAnswers) {
                const answer = savedAnswers[questionId];
                recordAnswerStatus(parseInt(questionId), answer);
                updateNavStatus(parseInt(questionId), answer);
            }
        } catch (e) {
            console.error('Failed to initialize saved answers:', e);
        }
        
        // 初始化得分显示
        updateScoreDisplay();
        
        let remainingTime = <?php echo $remaining_time; ?>;
        const timerElement = document.getElementById('timer');
        const timeDisplay = document.getElementById('time-display');
        
        function updateTimer() {
            if (remainingTime <= 0) {
                timeDisplay.textContent = '时间到！';
                document.getElementById('examForm').submit();
                return;
            }
            
            const hours = Math.floor(remainingTime / 3600);
            const minutes = Math.floor((remainingTime % 3600) / 60);
            const seconds = remainingTime % 60;
            
            timeDisplay.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (remainingTime < 300) {
                timerElement.className = 'timer danger';
            } else if (remainingTime < 600) {
                timerElement.className = 'timer warning';
            }
            
            remainingTime--;
        }
        
        updateTimer();
        setInterval(updateTimer, 1000);
        
        // 初始化富文本编辑器（Quill）- 延迟加载，等待Quill.js加载完成
        const quillEditors = {};
        function initQuillEditors() {
            if (typeof Quill === 'undefined') {
                // Quill.js还未加载完成，延迟重试
                setTimeout(initQuillEditors, 100);
                return;
            }
            
            document.querySelectorAll('.richtext-editor').forEach(textarea => {
            const questionId = textarea.getAttribute('data-question-id');
            const editorId = 'editor-' + questionId;
            const editorEl = document.getElementById(editorId);
            const textareaEl = document.getElementById('richtext-' + questionId);
            
            if (editorEl && textareaEl) {
                // 初始化Quill编辑器
                const quill = new Quill('#' + editorId, {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            [{ 'align': [] }],
                            ['link', 'image'],
                            ['clean']
                        ]
                    }
                });
                
                // 设置初始内容
                const initialContent = textareaEl.value || '';
                if (initialContent) {
                    // 如果内容是HTML，直接设置；如果是纯文本，也需要处理
                    try {
                        quill.root.innerHTML = initialContent;
                    } catch (e) {
                        quill.setText(initialContent);
                    }
                }
                
                // 监听内容变化
                quill.on('text-change', function() {
                    const content = quill.root.innerHTML;
                    textareaEl.value = content;
                    saveAnswer(questionId, content);
                    updateNavStatus(questionId, content);
                });
                
                quillEditors[questionId] = quill;
            }
            });
        }
        // 延迟初始化，避免阻塞页面渲染
        setTimeout(initQuillEditors, 300);
        
        // 单选题和判断题：单选框
        document.querySelectorAll('input[type="radio"]').forEach(element => {
            element.addEventListener('change', function() {
                const questionId = this.name.match(/\[(\d+)\]/)[1];
                const answer = this.value;
                saveAnswer(questionId, answer);
                updateNavStatus(questionId, answer);
            });
        });
        
        // 多选题：复选框
        document.querySelectorAll('.multi-choice').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const questionId = this.getAttribute('data-question-id');
                const checkboxes = document.querySelectorAll('.multi-choice[data-question-id="' + questionId + '"]:checked');
                const answers = Array.from(checkboxes).map(cb => cb.value).sort().join(',');
                document.getElementById('multi-answer-' + questionId).value = answers;
                saveAnswer(questionId, answers);
                updateNavStatus(questionId, answers);
            });
        });
        
        // 填空题和名词解释：输入框和文本框
        document.querySelectorAll('input[type="text"].answer-input, textarea.answer-input:not(.richtext-editor)').forEach(element => {
            element.addEventListener('input', function() {
                const questionId = this.getAttribute('data-question-id');
                const answer = this.value;
                saveAnswer(questionId, answer);
                updateNavStatus(questionId, answer);
            });
        });
        
        function saveAnswer(questionId, answer) {
            fetch('exam.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save&question_id=${questionId}&student_answer=${encodeURIComponent(answer)}`
            });
            
            // 记录答案状态
            recordAnswerStatus(questionId, answer);
        }
        
        function updateNavStatus(questionId, answer) {
            const navItem = document.getElementById('nav-question-' + questionId);
            if (navItem) {
                if (answer && answer.trim() !== '') {
                    navItem.classList.add('answered');
                    // 根据答案正确性添加样式
                    const status = answerStatus[questionId];
                    if (status && status.isCorrect === false) {
                        navItem.classList.add('wrong-answer');
                    } else {
                        navItem.classList.remove('wrong-answer');
                    }
                } else {
                    navItem.classList.remove('answered', 'wrong-answer');
                }
            }
            // 更新得分显示
            updateScoreDisplay();
        }
        
        // 监听滚动，高亮当前题目
        let currentQuestionId = null;
        let previousQuestionId = null;
        let lastCheckedQuestionId = null; // 记录最后一次检查的题目ID，避免重复提示
        
        function updateCurrentQuestion() {
            const questions = document.querySelectorAll('.question-card');
            const scrollPos = window.scrollY + 150;
            
            questions.forEach(question => {
                const questionTop = question.offsetTop;
                const questionBottom = questionTop + question.offsetHeight;
                const questionId = question.id.replace('question-', '');
                
                if (scrollPos >= questionTop && scrollPos < questionBottom) {
                    if (currentQuestionId !== questionId) {
                        // 在切换题目前，先检查并记录当前题的答案状态（使用currentQuestionId，因为这是即将成为"上一题"的题目）
                        if (currentQuestionId && currentQuestionId !== questionId && lastCheckedQuestionId !== currentQuestionId) {
                            // 获取当前题的答案（即将成为上一题）
                            const currentQuestionEl = document.getElementById('question-' + currentQuestionId);
                            if (currentQuestionEl) {
                                let currentAnswer = '';
                                
                                // 尝试从各种输入元素获取答案
                                const radio = currentQuestionEl.querySelector('input[type="radio"]:checked');
                                if (radio) {
                                    currentAnswer = radio.value;
                                } else {
                                    const multiCheckboxes = currentQuestionEl.querySelectorAll('.multi-choice:checked');
                                    if (multiCheckboxes.length > 0) {
                                        currentAnswer = Array.from(multiCheckboxes).map(cb => cb.value).sort().join(',');
                                    } else {
                                        const textInput = currentQuestionEl.querySelector('input[type="text"].answer-input');
                                        if (textInput) {
                                            currentAnswer = textInput.value;
                                        } else {
                                            const textarea = currentQuestionEl.querySelector('textarea.answer-input');
                                            if (textarea) {
                                                currentAnswer = textarea.value;
                                            }
                                        }
                                    }
                                }
                                
                                // 如果有答案，立即记录状态
                                if (currentAnswer && currentAnswer.trim() !== '') {
                                    recordAnswerStatus(currentQuestionId, currentAnswer);
                                }
                                
                                // 检查当前题是否答错
                                const currentStatus = answerStatus[currentQuestionId];
                                
                                // 只有当答案状态明确为错误时才显示提示（isCorrect === false）
                                if (currentStatus && currentStatus.isCorrect === false && currentStatus.answer && currentStatus.answer.trim() !== '') {
                                    // 当前题答错了，显示提示
                                    showWrongAnswerToast(currentQuestionId);
                                    lastCheckedQuestionId = currentQuestionId; // 标记已检查
                                }
                            }
                        }
                        
                        // 在更新ID之前，先保存当前题目ID作为上一题
                        const oldCurrentQuestionId = currentQuestionId;
                        
                        // 移除之前的current类
                        if (currentQuestionId) {
                            const prevNav = document.getElementById('nav-question-' + currentQuestionId);
                            if (prevNav) prevNav.classList.remove('current');
                        }
                        
                        // 更新题目ID
                        previousQuestionId = oldCurrentQuestionId;
                        currentQuestionId = questionId;
                        
                        // 添加新的current类
                        const nav = document.getElementById('nav-question-' + questionId);
                        if (nav) nav.classList.add('current');
                    }
                    return;
                }
            });
        }
        
        window.addEventListener('scroll', updateCurrentQuestion);
        updateCurrentQuestion(); // 初始化
        
        // 平滑滚动到题目
        document.querySelectorAll('.nav-question-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const questionId = this.getAttribute('data-question-id');
                
                // 在切换前，先获取并记录当前题的答案
                if (currentQuestionId && currentQuestionId !== questionId && lastCheckedQuestionId !== currentQuestionId) {
                    const currentQuestionEl = document.getElementById('question-' + currentQuestionId);
                    if (currentQuestionEl) {
                        let currentAnswer = '';
                        
                        // 尝试从各种输入元素获取答案
                        const radio = currentQuestionEl.querySelector('input[type="radio"]:checked');
                        if (radio) {
                            currentAnswer = radio.value;
                        } else {
                            const multiCheckboxes = currentQuestionEl.querySelectorAll('.multi-choice:checked');
                            if (multiCheckboxes.length > 0) {
                                currentAnswer = Array.from(multiCheckboxes).map(cb => cb.value).sort().join(',');
                            } else {
                                const textInput = currentQuestionEl.querySelector('input[type="text"].answer-input');
                                if (textInput) {
                                    currentAnswer = textInput.value;
                                } else {
                                    const textarea = currentQuestionEl.querySelector('textarea.answer-input');
                                    if (textarea) {
                                        currentAnswer = textarea.value;
                                    }
                                }
                            }
                        }
                        
                        // 如果有答案，立即记录状态
                        if (currentAnswer && currentAnswer.trim() !== '') {
                            recordAnswerStatus(currentQuestionId, currentAnswer);
                            
                            // 立即检查答案状态（因为recordAnswerStatus是同步的）
                            const currentStatus = answerStatus[currentQuestionId];
                            
                            // 只有当答案状态明确为错误时才显示提示（isCorrect === false）
                            if (currentStatus && currentStatus.isCorrect === false) {
                                // 当前题答错了，显示提示
                                showWrongAnswerToast(currentQuestionId);
                                lastCheckedQuestionId = currentQuestionId; // 标记已检查
                            }
                        }
                    }
                }
                
                const questionEl = document.getElementById('question-' + questionId);
                if (questionEl) {
                    previousQuestionId = currentQuestionId;
                    currentQuestionId = questionId;
                    questionEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    
                    // 更新导航高亮
                    document.querySelectorAll('.nav-question-item').forEach(nav => {
                        nav.classList.remove('current');
                    });
                    this.classList.add('current');
                }
            });
        });
        
        // 提交表单前同步Quill编辑器内容
        function syncQuillContent() {
            Object.keys(quillEditors).forEach(questionId => {
                const quill = quillEditors[questionId];
                const textarea = document.getElementById('richtext-' + questionId);
                if (quill && textarea) {
                    textarea.value = quill.root.innerHTML;
                }
            });
        }
        
        // 表单提交时同步内容
        document.getElementById('examForm').addEventListener('submit', function(e) {
            syncQuillContent();
        });
        
        // 定期刷新 session，防止考试期间 session 过期
        let sessionRefreshInterval = null;
        
        function refreshSession() {
            fetch('exam.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=refresh_session'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Session 刷新成功，静默处理（不输出日志，避免干扰用户）
                } else {
                    // 如果考试已完成或不存在，停止刷新
                    if (data.message && (data.message.includes('completed') || data.message.includes('No active exam'))) {
                        if (sessionRefreshInterval) {
                            clearInterval(sessionRefreshInterval);
                            sessionRefreshInterval = null;
                        }
                    }
                }
            })
            .catch(error => {
                // 网络错误时静默处理，不影响考试进行
                console.error('Session refresh error:', error);
            });
        }
        
        // 每 5 分钟（300秒）刷新一次 session
        // 这样可以确保在 60 分钟的 session 超时时间内，session 始终保持活跃
        const SESSION_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 分钟（毫秒）
        
        // 页面加载后立即刷新一次 session
        refreshSession();
        
        // 然后每 5 分钟定期刷新
        sessionRefreshInterval = setInterval(refreshSession, SESSION_REFRESH_INTERVAL);
        
        // 页面可见性变化时也刷新（用户切换回标签页时）
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && sessionRefreshInterval) {
                refreshSession();
            }
        });
    </script>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

