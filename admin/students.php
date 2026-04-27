<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// 全局刷题次数（完成考试记录总数），用于与首页保持一致
$total_completed_exams = (int)$pdo->query("SELECT COUNT(*) FROM exam_records WHERE status = 'completed'")->fetchColumn();

// 学生刷题列表（按科目拆分，每行是“学生-科目”）
// 统计：该学生在该科目刷到过的不同题数 seen_count；该科目题库总题数 total_count；覆盖率 rate

// ---- 读取筛选与排序参数 ----
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$page       = max(1, intval($_GET['page'] ?? 1));
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$sort_by    = $_GET['sort_by'] ?? 'last_practice_at';
$sort_dir   = strtolower($_GET['sort_dir'] ?? 'desc');

// 允许排序的列
$allowed_sort = [
    'student_id'        => 'student_id',
    'student_no'        => 'student_no',
    'student_name'      => 'student_name',
    'subject_name'      => 'subject_name',
    'total_count'       => 'total_count',
    'seen_count'        => 'seen_count',
    'rate'              => 'rate',
    'exam_count'        => 'exam_count',
    'last_practice_at'  => 'last_practice_at',
];

if (!isset($allowed_sort[$sort_by])) {
    $sort_by = 'last_practice_at';
}
if (!in_array($sort_dir, ['asc', 'desc'], true)) {
    $sort_dir = 'desc';
}

// 排序 SQL 片段
$order_sql = " ORDER BY " . $allowed_sort[$sort_by] . " " . strtoupper($sort_dir) . ", student_id DESC, subject_id ASC";

// ---- 科目列表（用于筛选）----
$stmtSubjects = $pdo->query("SELECT id, name FROM subjects ORDER BY id ASC");
$subjects = $stmtSubjects->fetchAll();

// ---- 构建基础 SQL（供统计和分页使用）----
$base_sql = "
        FROM students s
    JOIN subjects sub ON 1=1
    LEFT JOIN (
        -- 学生在该科目刷到过的不同题目数量（已完成的考试，只统计当前题库中存在的题目）
        SELECT 
            er.student_id,
            p.subject_id,
            COUNT(DISTINCT eq.question_id) AS seen_count
        FROM exam_records er
        JOIN exam_questions eq ON eq.exam_record_id = er.id
        JOIN papers p ON er.paper_id = p.id
        JOIN questions q ON eq.question_id = q.id AND q.subject_id = p.subject_id
        WHERE er.status = 'completed'
        GROUP BY er.student_id, p.subject_id
    ) AS seen ON seen.student_id = s.id AND seen.subject_id = sub.id
    LEFT JOIN (
        -- 最近刷题时间（已完成的考试，使用最近一次考试的开始时间）
        SELECT
            er.student_id,
            p.subject_id,
            MAX(er.start_time) AS last_practice_at
        FROM exam_records er
        JOIN papers p ON er.paper_id = p.id
        WHERE er.status = 'completed'
        GROUP BY er.student_id, p.subject_id
    ) AS latest ON latest.student_id = s.id AND latest.subject_id = sub.id
    LEFT JOIN (
        -- 刷题次数（该学生在该科目完成的考试次数）
        SELECT
            er.student_id,
            p.subject_id,
            COUNT(*) AS exam_count
        FROM exam_records er
        JOIN papers p ON er.paper_id = p.id
        WHERE er.status = 'completed'
        GROUP BY er.student_id, p.subject_id
    ) AS exam_count ON exam_count.student_id = s.id AND exam_count.subject_id = sub.id
    LEFT JOIN (
        -- 该科目题库总题目数
        SELECT subject_id, COUNT(DISTINCT id) AS total_count
        FROM questions
        GROUP BY subject_id
    ) AS qs ON qs.subject_id = sub.id
    WHERE qs.total_count IS NOT NULL AND qs.total_count > 0
";

// 按科目筛选
$params = [];
if ($subject_id > 0) {
    $base_sql .= " AND sub.id = :subject_id ";
    $params[':subject_id'] = $subject_id;
}

// ---- 先统计总行数以便分页 ----
$count_sql = "SELECT COUNT(*) AS cnt " . $base_sql;
$stmt = $pdo->prepare($count_sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_INT);
}
$stmt->execute();
$total_rows = (int)($stmt->fetch()['cnt'] ?? 0);

// 计算分页
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
}

// ---- 检查exam_records表是否有ip字段 ----
$has_ip_field = false;
try {
    $stmt_check = $pdo->query("SHOW COLUMNS FROM exam_records LIKE 'ip'");
    $has_ip_field = $stmt_check->rowCount() > 0;
} catch (Exception $e) {
    $has_ip_field = false;
}

// ---- 查询当前页数据 ----
$ip_subquery = $has_ip_field ? "
    , (SELECT er.ip FROM exam_records er 
       JOIN papers p ON er.paper_id = p.id 
       WHERE er.student_id = s.id 
       AND p.subject_id = sub.id
       AND er.status = 'completed'
       ORDER BY er.start_time DESC 
       LIMIT 1) AS last_ip
" : "
    , '' AS last_ip
";

$data_sql = "
    SELECT
        s.id AS student_id,
        s.student_no,
        s.name AS student_name,
        sub.id AS subject_id,
        sub.name AS subject_name,
        IFNULL(seen.seen_count, 0) AS seen_count,
        IFNULL(qs.total_count, 0) AS total_count,
        CASE 
            WHEN qs.total_count IS NULL OR qs.total_count = 0 THEN 0
            WHEN seen.seen_count > qs.total_count THEN 100.0
            ELSE ROUND(seen.seen_count / qs.total_count * 100, 1)
        END AS rate,
        IFNULL(exam_count.exam_count, 0) AS exam_count,
        latest.last_practice_at
        " . $ip_subquery . "
" . $base_sql . $order_sql;
if ($per_page > 0) {
    $data_sql .= " LIMIT :limit OFFSET :offset";
}

$stmt = $pdo->prepare($data_sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_INT);
}
if ($per_page > 0) {
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$stmt->execute();
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生刷题列表 - 后台管理</title>
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
        
        .table-container table th a {
            color: #2c3e50;
            text-decoration: none;
            font-weight: 600;
            display: block;
        }
        
        .table-container table th a:hover {
            color: #667eea;
        }
        
        /* 数字列右对齐，更紧凑 */
        .table-container table td:nth-child(1),
        .table-container table td:nth-child(2),
        .table-container table td:nth-child(7),
        .table-container table td:nth-child(8),
        .table-container table td:nth-child(9),
        .table-container table td:nth-child(10) {
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
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>学生刷题列表
            <?php if ($per_page > 0): ?>
                （共<?php echo $total_rows; ?>条，第<?php echo $page; ?>/<?php echo $total_pages; ?>页）
            <?php else: ?>
                （共<?php echo $total_rows; ?>条，全部显示）
            <?php endif; ?>
        </h2>
        <div style="margin: 8px 0 18px; color: #4b5563; font-size: 13px;">
            总刷题次数（完成考试记录）：<strong><?php echo $total_completed_exams; ?></strong>
        </div>
        
        <div class="table-container">
            <form method="GET" style="margin-bottom: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="subject_id">按科目筛选：</label>
                    <select id="subject_id" name="subject_id" onchange="this.form.page.value = 1; this.form.submit();">
                        <option value="0">全部科目</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>" <?php echo $subject_id == $sub['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($sub['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="per_page">每页显示：</label>
                    <select id="per_page" name="per_page" onchange="this.form.page.value = 1; this.form.submit();">
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                        <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                    </select>
                </div>
                <input type="hidden" name="sort_by" value="<?php echo escape($sort_by); ?>">
                <input type="hidden" name="sort_dir" value="<?php echo escape($sort_dir); ?>">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
            </form>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php 
                        // 生成带排序的表头链接
                        function sort_link($label, $column, $currentSortBy, $currentSortDir, $subject_id, $page) {
                            $nextDir = 'asc';
                            $arrow = '';
                            if ($currentSortBy === $column) {
                                if ($currentSortDir === 'asc') {
                                    $nextDir = 'desc';
                                    $arrow = ' ▲';
                                } else {
                                    $nextDir = 'asc';
                                    $arrow = ' ▼';
                                }
                            }
                            $query = http_build_query([
                                'subject_id' => $subject_id,
                                'sort_by'    => $column,
                                'sort_dir'   => $nextDir,
                                'page'       => $page,
                            ]);
                            return '<a href="students.php?' . $query . '">' . $label . $arrow . '</a>';
                        }
                        ?>
                        <th><?php echo sort_link('学生ID', 'student_id', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('学号', 'student_no', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('姓名', 'student_name', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('科目', 'subject_name', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('最近刷题时间', 'last_practice_at', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th>IP</th>
                        <th><?php echo sort_link('刷题次数', 'exam_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('已刷到题数', 'seen_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('题目总数', 'total_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('覆盖率', 'rate', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center;">暂无数据</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo $row['student_id']; ?></td>
                                <td><?php echo escape($row['student_no']); ?></td>
                                <td><?php echo escape($row['student_name'] ?? '-'); ?></td>
                                <td><?php echo escape($row['subject_name'] ?? '-'); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($row['last_practice_at'])) {
                                        echo date('m-d H:i', strtotime($row['last_practice_at']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="white-space: nowrap; font-family: monospace;">
                                    <?php echo !empty($row['last_ip']) ? escape($row['last_ip']) : '-'; ?>
                                </td>
                                <td><?php echo $row['exam_count']; ?></td>
                                <td><?php echo $row['seen_count']; ?></td>
                                <td><?php echo $row['total_count']; ?></td>
                                <td><?php echo number_format($row['rate'], 1); ?>%</td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="student_records.php?student_id=<?php echo $row['student_id']; ?>" 
                                           class="btn btn-primary">刷题记录</a>
                                        <a href="question_analysis.php?student_id=<?php echo $row['student_id']; ?>" 
                                           class="btn btn-success">答题分析</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($per_page > 0 && $total_pages > 1): ?>
        <div style="margin-top: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 13px; color: #6b7280;">
                显示第 <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_rows); ?> 条，共 <?php echo $total_rows; ?> 条
            </div>
            <div class="pagination">
                <?php
                $buildPageUrl = function($p) use ($subject_id, $sort_by, $sort_dir, $per_page) {
                    return 'students.php?' . http_build_query([
                        'subject_id' => $subject_id,
                        'sort_by'    => $sort_by,
                        'sort_dir'   => $sort_dir,
                        'per_page'   => $per_page,
                        'page'       => $p,
                    ]);
                };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?php echo $buildPageUrl(1); ?>">&laquo; 首页</a>
                    <a href="<?php echo $buildPageUrl($page - 1); ?>">上一页</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 3);
                $end   = min($total_pages, $page + 3);
                for ($p = $start; $p <= $end; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo $buildPageUrl($p); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo $buildPageUrl($page + 1); ?>">下一页</a>
                    <a href="<?php echo $buildPageUrl($total_pages); ?>">末页 &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

