#!/bin/bash

# Exam项目生产环境配置脚本
# 用途：自动配置生产环境的安全设置
# 使用方法：bash production_setup.sh

echo "========================================="
echo "  Exam项目生产环境安全配置"
echo "========================================="
echo ""

# 项目根目录
PROJECT_DIR="/www/wwwroot/ibubble.vicp.net/Projects/Exam"
cd "$PROJECT_DIR" || exit 1

echo "✓ 当前目录: $PROJECT_DIR"
echo ""

# 1. 创建必要的目录
echo "1. 创建必要的目录..."
mkdir -p uploads logs docs
echo "✓ 目录创建完成"
echo ""

# 2. 设置文件权限
echo "2. 设置文件权限..."
echo "  - 设置目录权限为 775"
find . -type d -exec chmod 775 {} \;
echo "  - 设置文件权限为 664"
find . -type f -exec chmod 664 {} \;
echo "  - 设置特殊目录权限"
chmod 775 uploads logs
echo "✓ 文件权限设置完成"
echo ""

# 3. 设置所有者和组
echo "3. 设置所有者和组..."
sudo chown -R gemini:www .
echo "✓ 所有者设置为 gemini:www"
echo ""

# 4. 创建.htaccess文件
echo "4. 创建.htaccess安全配置..."
cat > .htaccess << 'EOF'
# Exam项目安全配置

# 禁止访问敏感文件
<FilesMatch "\.(ini|log|sql|md|sh)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# 禁止访问隐藏文件
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

# 禁止目录浏览
Options -Indexes

# 启用HTTPS重定向（如果需要，取消注释）
# RewriteEngine On
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
EOF
echo "✓ .htaccess创建完成"
echo ""

# 5. 保护uploads目录
echo "5. 保护uploads目录..."
cat > uploads/.htaccess << 'EOF'
# 禁止执行PHP文件
<FilesMatch "\.php$">
    Order allow,deny
    Deny from all
</FilesMatch>

# 只允许特定文件类型
<FilesMatch "\.(xls|xlsx|csv)$">
    Order allow,deny
    Allow from all
</FilesMatch>
EOF
echo "✓ uploads目录保护完成"
echo ""

# 6. 创建.user.ini配置
echo "6. 创建PHP安全配置..."
cat > .user.ini << 'EOF'
; Exam项目PHP安全配置

; 关闭错误显示
display_errors = Off
display_startup_errors = Off

; 记录错误到日志
log_errors = On
error_log = logs/php_errors.log

; Session安全
session.cookie_httponly = 1
session.use_only_cookies = 1
session.cookie_samesite = Strict

; 文件上传限制
upload_max_filesize = 10M
post_max_size = 10M
max_file_uploads = 5
EOF
echo "✓ PHP配置创建完成"
echo ""

# 7. 创建日志文件
echo "7. 初始化日志文件..."
touch logs/php_errors.log
chmod 664 logs/php_errors.log
chown gemini:www logs/php_errors.log
echo "✓ 日志文件创建完成"
echo ""

# 8. 检查数据库配置
echo "8. 检查数据库配置..."
if grep -q "Gl5181081" inc/db.inc.php; then
    echo "⚠️  警告: 数据库密码使用默认值，建议修改！"
    echo "   文件: inc/db.inc.php"
    echo "   当前密码: Gl5181081"
else
    echo "✓ 数据库密码已修改"
fi
echo ""

# 9. 检查vendor目录
echo "9. 检查依赖库..."
if [ -d "vendor" ]; then
    echo "✓ vendor目录存在"
else
    echo "⚠️  警告: vendor目录不存在"
    echo "   如果需要Excel导入功能，请运行: composer install"
fi
echo ""

# 10. 创建备份脚本
echo "10. 创建数据库备份脚本..."
cat > backup_database.sh << 'EOF'
#!/bin/bash
# 数据库备份脚本

BACKUP_DIR="backups"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="exam"
DB_USER="exam"
DB_PASS="Gl5181081"

mkdir -p "$BACKUP_DIR"

mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_DIR/exam_$DATE.sql"

if [ $? -eq 0 ]; then
    echo "✓ 数据库备份成功: $BACKUP_DIR/exam_$DATE.sql"
    # 压缩备份文件
    gzip "$BACKUP_DIR/exam_$DATE.sql"
    echo "✓ 备份文件已压缩"
    
    # 删除7天前的备份
    find "$BACKUP_DIR" -name "exam_*.sql.gz" -mtime +7 -delete
    echo "✓ 已清理7天前的备份"
else
    echo "✗ 数据库备份失败"
    exit 1
fi
EOF
chmod +x backup_database.sh
echo "✓ 备份脚本创建完成: backup_database.sh"
echo ""

# 11. 安全检查总结
echo "========================================="
echo "  配置完成！安全检查总结"
echo "========================================="
echo ""
echo "✓ 已完成:"
echo "  - 目录和文件权限设置"
echo "  - .htaccess安全配置"
echo "  - uploads目录保护"
echo "  - PHP安全配置(.user.ini)"
echo "  - 日志文件初始化"
echo "  - 数据库备份脚本"
echo ""
echo "⚠️  需要手动完成:"
echo "  1. 修改数据库密码（inc/db.inc.php）"
echo "  2. 如果使用HTTPS，启用.htaccess中的重定向"
echo "  3. 测试所有功能是否正常"
echo "  4. 设置定时任务备份数据库"
echo ""
echo "📝 相关文档:"
echo "  - 安全检查报告: docs/安全检查报告.md"
echo "  - 生产环境清理报告: docs/生产环境清理报告.md"
echo ""
echo "========================================="
echo "  配置脚本执行完成！"
echo "========================================="
