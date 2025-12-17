<?php
/**
 * 智能文件前置處理平台 (IDP) - 配置檔案
 * Intelligent Document Processing Platform Configuration
 */

// Gemini API 設定
define('GEMINI_API_KEY', ''); // 請替換成您的 API Key
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');

// 檔案上傳設定
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff']);

// 系統設定
define('DEBUG_MODE', true);

// 確保上傳目錄存在
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
