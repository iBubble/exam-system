<?php
/**
 * AI评分工具函数
 * 用于主观题（名词解释、实操论述题）的自动评分
 */

/**
 * 计算文本相似度（使用简单的余弦相似度算法作为基础）
 * 实际应用中可替换为真实的AI API调用
 * 
 * @param string $student_answer 学生答案
 * @param string $correct_answer 正确答案
 * @return float 相似度分数（0-1之间）
 */
function calculateTextSimilarity($student_answer, $correct_answer) {
    if (empty($student_answer) || empty($correct_answer)) {
        return 0.0;
    }
    
    // 移除标点符号和多余空格
    $student = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower(trim($student_answer)));
    $correct = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower(trim($correct_answer)));
    
    if (empty($student) || empty($correct)) {
        return 0.0;
    }
    
    // 简单的词汇匹配算法
    $student_words = array_unique(explode(' ', preg_replace('/\s+/', ' ', $student)));
    $correct_words = array_unique(explode(' ', preg_replace('/\s+/', ' ', $correct)));
    
    $intersection = count(array_intersect($student_words, $correct_words));
    $union = count(array_unique(array_merge($student_words, $correct_words)));
    
    if ($union == 0) {
        return 0.0;
    }
    
    // Jaccard相似度
    $jaccard = $intersection / $union;
    
    // 长度相似度
    $length_ratio = min(mb_strlen($student), mb_strlen($correct)) / max(mb_strlen($student), mb_strlen($correct));
    
    // 综合评分（可以调整权重）
    $similarity = ($jaccard * 0.7 + $length_ratio * 0.3);
    
    return min(1.0, max(0.0, $similarity));
}

/**
 * AI评分主观题
 * 
 * @param string $student_answer 学生答案
 * @param string $correct_answer 正确答案
 * @param int $max_score 该题满分
 * @return array ['score' => 得分, 'similarity' => 相似度, 'is_correct' => 是否完全正确]
 */
function aiScoreSubjectiveQuestion($student_answer, $correct_answer, $max_score = 10) {
    $similarity = calculateTextSimilarity($student_answer, $correct_answer);
    
    // 根据相似度计算得分
    $score = round($similarity * $max_score, 1);
    
    // 相似度达到0.8以上认为基本正确，0.95以上认为完全正确
    $is_correct = ($similarity >= 0.8) ? 1 : 0;
    
    return [
        'score' => $score,
        'similarity' => $similarity,
        'is_correct' => $is_correct
    ];
}

/**
 * 调用外部AI API进行评分（示例框架）
 * 可以根据实际需求替换为真实的AI服务
 * 
 * @param string $student_answer 学生答案
 * @param string $correct_answer 正确答案
 * @param int $max_score 该题满分
 * @return array ['score' => 得分, 'similarity' => 相似度, 'is_correct' => 是否完全正确]
 */
function callAIScoringAPI($student_answer, $correct_answer, $max_score = 10) {
    // TODO: 在这里接入真实的AI API
    // 示例：OpenAI API、百度文心一言、腾讯混元等
    
    // 当前使用本地相似度算法
    return aiScoreSubjectiveQuestion($student_answer, $correct_answer, $max_score);
}

