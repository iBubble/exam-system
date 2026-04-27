<!-- 版权信息 - 便利贴样式 -->
<footer class="copyright-sticky">
    <div class="copyright-content">
        <span class="copyright-text">Copyright © 2025-2026 Gemini</span>
        <span class="copyright-separator">|</span>
        <span class="copyright-text">All Rights Reserved</span>
    </div>
</footer>

<style>
/* 版权信息便利贴样式 */
.copyright-sticky {
    position: fixed !important;
    bottom: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 9999 !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
}

.copyright-content {
    background: #fff9e6 !important;
    background-image: 
        linear-gradient(135deg, #fff9e6 0%, #fffef7 100%),
        repeating-linear-gradient(
            0deg,
            transparent,
            transparent 28px,
            rgba(200, 180, 140, 0.15) 28px,
            rgba(200, 180, 140, 0.15) 29px
        ) !important;
    padding: 12px 30px !important;
    text-align: center !important;
    font-size: 13px !important;
    color: #856404 !important;
    box-shadow: 
        0 -2px 8px rgba(0, 0, 0, 0.1),
        0 -4px 16px rgba(0, 0, 0, 0.08),
        inset 0 1px 0 rgba(255, 255, 255, 0.9) !important;
    border-top: 2px solid rgba(200, 180, 140, 0.3) !important;
    position: relative !important;
    transform: rotate(-0.3deg) !important;
    margin: 0 auto !important;
    max-width: 100% !important;
    width: 100% !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}

/* 便利贴折角效果 */
.copyright-content::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 0;
    height: 0;
    border-style: solid;
    border-width: 0 25px 25px 0;
    border-color: transparent rgba(200, 180, 140, 0.4) transparent transparent;
    box-shadow: -2px 2px 3px rgba(0, 0, 0, 0.1);
}

/* 便利贴折角内部高光 */
.copyright-content::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 0;
    height: 0;
    border-style: solid;
    border-width: 0 20px 20px 0;
    border-color: transparent rgba(255, 255, 255, 0.6) transparent transparent;
}

.copyright-text {
    font-weight: 500;
    letter-spacing: 0.5px;
    text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8);
}

.copyright-separator {
    margin: 0 12px;
    opacity: 0.6;
    font-weight: 300;
}

/* 响应式设计 */
@media (max-width: 768px) {
    .copyright-content {
        padding: 10px 15px;
        font-size: 12px;
        transform: rotate(0deg);
    }
    
    .copyright-separator {
        margin: 0 8px;
    }
    
    .copyright-content::before {
        border-width: 0 20px 20px 0;
    }
    
    .copyright-content::after {
        border-width: 0 16px 16px 0;
    }
}

/* 确保页面内容不被遮挡（为footer留出空间） */
body {
    padding-bottom: 50px !important;
}

/* 对于有固定底部操作栏的页面（如exam.php），需要特殊处理 */
.exam-actions ~ .copyright-sticky {
    bottom: 80px; /* 为底部操作栏留出空间 */
}

@media (max-width: 768px) {
    .exam-actions ~ .copyright-sticky {
        bottom: 120px; /* 移动端底部操作栏更高 */
    }
}
</style>

