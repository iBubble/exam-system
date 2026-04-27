#!/bin/bash

# Exam项目生产环境测试脚本
# 用途：测试所有关键功能是否正常工作

echo "========================================="
echo "  Exam项目生产环境测试"
echo "========================================="
echo ""

BASE_URL="http://home.liukun.com/Projects/Exam"
PASS_COUNT=0
FAIL_COUNT=0

# 测试函数
test_url() {
    local name=$1
    local url=$2
    local expected_code=${3:-200}
    
    echo -n "测试 $name ... "
    
    # 使用-L跟随重定向
    http_code=$(curl -L -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$http_code" = "$expected_code" ]; then
        echo "✓ 通过 (HTTP $http_code)"
        ((PASS_COUNT++))
        return 0
    else
        echo "✗ 失败 (HTTP $http_code, 期望 $expected_code)"
        ((FAIL_COUNT++))
        return 1
    fi
}

# 测试数据库连接
test_database() {
    echo -n "测试数据库连接 ... "
    
    # 创建临时PHP测试文件
    cat > /tmp/test_db.php << 'EOF'
<?php
require_once '/www/wwwroot/ibubble.vicp.net/Projects/Exam/inc/db.inc.php';
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM students");
    $count = $stmt->fetchColumn();
    echo "OK:$count";
} catch (Exception $e) {
    echo "ERROR:" . $e->getMessage();
}
EOF
    
    result=$(php /tmp/test_db.php)
    rm -f /tmp/test_db.php
    
    if [[ $result == OK:* ]]; then
        count=${result#OK:}
        echo "✓ 通过 (学生数: $count)"
        ((PASS_COUNT++))
        return 0
    else
        echo "✗ 失败 ($result)"
        ((FAIL_COUNT++))
        return 1
    fi
}

# 测试文件权限
test_permissions() {
    echo -n "测试文件权限 ... "
    
    local errors=0
    
    # 检查uploads目录是否可写
    if [ ! -w "uploads" ]; then
        echo "✗ uploads目录不可写"
        ((errors++))
    fi
    
    # 检查logs目录是否可写
    if [ ! -w "logs" ]; then
        echo "✗ logs目录不可写"
        ((errors++))
    fi
    
    if [ $errors -eq 0 ]; then
        echo "✓ 通过"
        ((PASS_COUNT++))
        return 0
    else
        echo "✗ 失败 ($errors 个错误)"
        ((FAIL_COUNT++))
        return 1
    fi
}

# 开始测试
echo "1. 页面访问测试"
echo "-----------------------------------"
test_url "学生登录页" "$BASE_URL/index.php"
test_url "管理员登录页" "$BASE_URL/admin/login.php"
test_url "考试列表页" "$BASE_URL/exam_list.php" "302"  # 未登录会重定向
test_url "管理后台首页" "$BASE_URL/admin/index.php" "302"  # 未登录会重定向
echo ""

echo "2. 数据库连接测试"
echo "-----------------------------------"
cd /www/wwwroot/ibubble.vicp.net/Projects/Exam
test_database
echo ""

echo "3. 文件权限测试"
echo "-----------------------------------"
test_permissions
echo ""

echo "4. 安全配置测试"
echo "-----------------------------------"
echo -n "测试.htaccess存在 ... "
if [ -f ".htaccess" ]; then
    echo "✓ 通过"
    ((PASS_COUNT++))
else
    echo "✗ 失败"
    ((FAIL_COUNT++))
fi

echo -n "测试.user.ini存在 ... "
if [ -f ".user.ini" ]; then
    echo "✓ 通过"
    ((PASS_COUNT++))
else
    echo "✗ 失败"
    ((FAIL_COUNT++))
fi

echo -n "测试uploads/.htaccess存在 ... "
if [ -f "uploads/.htaccess" ]; then
    echo "✓ 通过"
    ((PASS_COUNT++))
else
    echo "✗ 失败"
    ((FAIL_COUNT++))
fi
echo ""

echo "5. 日志文件测试"
echo "-----------------------------------"
echo -n "测试日志目录存在 ... "
if [ -d "logs" ]; then
    echo "✓ 通过"
    ((PASS_COUNT++))
else
    echo "✗ 失败"
    ((FAIL_COUNT++))
fi

echo -n "测试日志文件可写 ... "
if [ -w "logs/php_errors.log" ]; then
    echo "✓ 通过"
    ((PASS_COUNT++))
else
    echo "✗ 失败"
    ((FAIL_COUNT++))
fi
echo ""

# 测试总结
echo "========================================="
echo "  测试完成"
echo "========================================="
echo ""
echo "通过: $PASS_COUNT 项"
echo "失败: $FAIL_COUNT 项"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo "✓ 所有测试通过！项目可以部署到生产环境。"
    echo ""
    echo "访问地址:"
    echo "  学生端: http://home.liukun.com/Projects/Exam/"
    echo "  管理端: http://home.liukun.com/Projects/Exam/admin/"
    exit 0
else
    echo "✗ 有 $FAIL_COUNT 项测试失败，请检查配置。"
    exit 1
fi
