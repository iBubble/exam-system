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
