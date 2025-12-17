<?php
/**
 * 智能文件前置處理平台 (IDP) - API 處理端點
 * 處理文件上傳和分析請求
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/GeminiClient.php';
require_once __DIR__ . '/classes/DocumentProcessor.php';

// 錯誤處理
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // 檢查請求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只接受 POST 請求');
    }
    
    // 檢查是否有上傳文件
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件大小超過伺服器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超過表單限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上傳',
            UPLOAD_ERR_NO_FILE => '沒有文件被上傳',
            UPLOAD_ERR_NO_TMP_DIR => '缺少臨時資料夾',
            UPLOAD_ERR_CANT_WRITE => '無法寫入檔案',
            UPLOAD_ERR_EXTENSION => '上傳被擴展程式中斷'
        ];
        
        $errorCode = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new Exception($errorMessages[$errorCode] ?? '文件上傳失敗');
    }
    
    $file = $_FILES['document'];
    
    // 檢查文件大小
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('文件大小超過限制 (' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB)');
    }
    
    // 檢查文件類型
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_EXTENSIONS)) {
        throw new Exception('不支援的文件格式。支援的格式：' . implode(', ', ALLOWED_EXTENSIONS));
    }
    
    // 生成唯一檔名並移動文件
    $uniqueName = uniqid('doc_') . '_' . time() . '.' . $extension;
    $uploadPath = UPLOAD_DIR . $uniqueName;
    
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        throw new Exception('無法儲存上傳的文件');
    }
    
    // 檢查 API Key
    if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        // 清理上傳的文件
        @unlink($uploadPath);
        throw new Exception('請先在 config.php 中設定 Gemini API Key');
    }
    
    // 初始化處理器
    $geminiClient = new GeminiClient(GEMINI_API_KEY, GEMINI_API_URL);
    $processor = new DocumentProcessor($geminiClient);
    
    // 處理文件
    $startTime = microtime(true);
    $result = $processor->processDocument($uploadPath, $file['name']);
    $processingTime = round(microtime(true) - $startTime, 2);
    
    // 返回結果
    echo json_encode([
        'success' => true,
        'data' => $result,
        'meta' => [
            'original_filename' => $file['name'],
            'file_size' => $file['size'],
            'processing_time' => $processingTime . ' 秒',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // 清理上傳的文件（可選，根據需求保留）
    // @unlink($uploadPath);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
