#!/bin/bash

# 生产环境清理脚本
# 移除测试文件、开发文档和临时文件

echo "=========================================="
echo "Exam项目生产环境清理"
echo "=========================================="
echo ""

# 进入项目目录
cd "$(dirname "$0")"

echo "当前目录: $(pwd)"
echo ""

# 创建备份目录
BACKUP_DIR="cleanup_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"
echo "✓ 创建备份目录: $BACKUP_DIR"
echo ""

# 要删除的文件列表
FILES_TO_REMOVE=(
    # 测试文件
    "test_db_connection.php"
    "test_db_simple.php"
    "test_fileinfo.php"
    "test_fileinfo_check.php"
    
    # 初始化脚本（已完成初始化）
    "init_admin.php"
    "init_database.php"
    "install.php"
    
    # 数据库文件（已导入）
    "database.sql"
    "update_database_class.sql"
    
    # 备份脚本（不需要在生产环境）
    "backup_script.sh"
    
    # Composer工具（已安装依赖）
    "composer.phar"
    
    # 开发文档
    "BACKUP.md"
    "CLEANUP_REPORT.md"
    "INSTALL.md"
    "RELEASE_CHECKLIST.md"
    "VERSION.md"
    "任务总览.md"
    
    # 迁移文档（保留到docs目录）
    "测试报告.md"
    "迁移完成总结.md"
    "部署说明.md"
    
    # changes目录（开发变更记录）
    "changes/"
    
    # projects目录（示例项目）
    "projects/"
)

echo "准备删除以下文件和目录："
echo "----------------------------------------"

# 移动文件到备份目录
for item in "${FILES_TO_REMOVE[@]}"; do
    if [ -e "$item" ]; then
        echo "  - $item"
        # 移动到备份目录而不是直接删除
        if [ -d "$item" ]; then
            cp -r "$item" "$BACKUP_DIR/"
        else
            cp "$item" "$BACKUP_DIR/"
        fi
    fi
done

echo ""
read -p "确认删除这些文件？(y/n): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo "开始清理..."
    echo "----------------------------------------"
    
    # 删除文件
    for item in "${FILES_TO_REMOVE[@]}"; do
        if [ -e "$item" ]; then
            if [ -d "$item" ]; then
                rm -rf "$item"
                echo "✓ 已删除目录: $item"
            else
                rm -f "$item"
                echo "✓ 已删除文件: $item"
            fi
        fi
    done
    
    echo ""
    echo "=========================================="
    echo "清理完成！"
    echo "=========================================="
    echo ""
    echo "备份位置: $BACKUP_DIR"
    echo ""
    echo "保留的重要文件："
    echo "  - README.md (项目说明)"
    echo "  - composer.json/lock (依赖配置)"
    echo "  - 所有功能PHP文件"
    echo "  - admin/ (管理后台)"
    echo "  - inc/ (核心文件)"
    echo "  - css/ (样式)"
    echo "  - images/ (图片)"
    echo "  - uploads/ (上传文件)"
    echo "  - vendor/ (依赖库)"
    echo ""
    
else
    echo ""
    echo "取消清理操作"
    rm -rf "$BACKUP_DIR"
fi
