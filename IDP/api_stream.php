<?php
/**
 * 智能文件前置處理平台 (IDP) - 串流 API 處理端點
 * 使用 Server-Sent Events (SSE) 提供真實進度更新
 */

// 設定 SSE 標頭
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// 確保輸出不被緩衝
if (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/GeminiClient.php';
require_once __DIR__ . '/classes/DocumentProcessor.php';

/**
 * 發送 SSE 事件
 */
function sendEvent($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

/**
 * 發送進度更新
 */
function sendProgress($step, $message, $percent) {
    sendEvent('progress', [
        'step' => $step,
        'message' => $message,
        'percent' => $percent
    ]);
}

// 錯誤處理
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // 檢查請求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只接受 POST 請求');
    }
    
    sendProgress('init', '正在初始化...', 5);
    
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
    
    sendProgress('upload', '文件上傳成功，正在驗證...', 10);
    
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
    
    sendProgress('save', '文件已儲存，正在準備分析...', 15);
    
    // 檢查 API Key
    if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
        @unlink($uploadPath);
        throw new Exception('請先在 config.php 中設定 Gemini API Key');
    }
    
    // 初始化處理器
    $geminiClient = new GeminiClient(GEMINI_API_KEY, GEMINI_API_URL);
    
    sendProgress('ocr_start', '正在進行 AI-OCR 文字辨識...', 20);
    
    $startTime = microtime(true);
    
    // 讀取文件
    $fileContent = file_get_contents($uploadPath);
    $base64Data = base64_encode($fileContent);
    $mimeType = getMimeType($extension);
    
    sendProgress('ocr_process', '正在呼叫 Gemini AI 進行 OCR 分析...', 30);
    
    // OCR 處理
    $ocrPrompt = <<<EOT
你是一個專業的 OCR 文字辨識系統。請仔細分析這份文件圖片，提取所有可見的文字內容。

請遵循以下規則：
1. 保持原始文件的格式和結構
2. 如果文字模糊，請盡可能推測正確內容
3. 標記任何不確定的文字，用 [?] 標註
4. 特別注意數字的準確性（0和O、1和l、3和8等易混淆字符）
5. 識別表格結構並保持對齊

請以純文字格式輸出識別結果，保持原始排版。
EOT;

    $ocrResponse = $geminiClient->sendRequest($ocrPrompt, $base64Data, $mimeType);
    $rawText = $geminiClient->extractText($ocrResponse);
    
    $ocrResult = [
        'raw_text' => $rawText,
        'confidence' => estimateConfidence($rawText)
    ];
    
    sendProgress('ocr_done', 'OCR 文字辨識完成！', 50);
    sendProgress('nlp_start', '正在進行 NLP 語意分析...', 55);
    
    // NLP 處理
    $nlpPrompt = <<<EOT
你是一個專業的財務文件分析系統。請分析以下文件內容，識別並提取關鍵財務欄位。

文件內容：
---
$rawText
---

請以 JSON 格式返回分析結果，包含以下欄位（如果存在）：

{
    "document_type": "文件類型（如：財務報表、發票、資產負債表、損益表等）",
    "company_info": {
        "name": "公司名稱",
        "tax_id": "統一編號",
        "address": "地址",
        "contact": "聯絡方式"
    },
    "financial_data": {
        "revenue": "營業收入",
        "cost": "營業成本",
        "gross_profit": "毛利",
        "operating_expenses": "營業費用",
        "operating_income": "營業利益",
        "net_income": "淨利",
        "total_assets": "資產總額",
        "total_liabilities": "負債總額",
        "total_equity": "權益總額"
    },
    "date_info": {
        "document_date": "文件日期",
        "period_start": "期間起始",
        "period_end": "期間結束"
    },
    "other_fields": [
        {"field_name": "欄位名稱", "value": "數值", "location": "在文件中的位置描述"}
    ],
    "uncertain_items": [
        {"field": "欄位", "original_value": "原始值", "reason": "不確定原因"}
    ]
}

重要：
1. 數值請保留原始格式（含千分位符號）
2. 如果某欄位不存在，請設為 null
3. 識別所有數字欄位，即使不在上述列表中
4. 標記任何可能有誤的數據

只返回 JSON，不要其他說明文字。
EOT;

    sendProgress('nlp_process', '正在分析文件語意結構...', 65);
    
    $nlpResponse = $geminiClient->sendRequest($nlpPrompt);
    $analysisText = $geminiClient->extractText($nlpResponse);
    
    // 清理並解析 JSON
    $analysisText = cleanJsonResponse($analysisText);
    $nlpResult = json_decode($analysisText, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $nlpResult = [
            'error' => 'JSON parsing failed: ' . json_last_error_msg(),
            'raw_response' => $analysisText
        ];
    }
    
    sendProgress('nlp_done', 'NLP 語意分析完成！', 80);
    sendProgress('validation_start', '正在執行邏輯檢核...', 85);
    
    // 執行驗證
    $processor = new DocumentProcessor($geminiClient);
    $validationResult = performValidation($nlpResult);
    
    sendProgress('validation_done', '邏輯檢核完成！', 95);
    sendProgress('complete', '正在生成分析報告...', 98);
    
    $processingTime = round(microtime(true) - $startTime, 2);
    
    // 生成摘要
    $summary = generateSummary($ocrResult, $nlpResult, $validationResult);
    
    // 發送最終結果
    sendEvent('result', [
        'success' => true,
        'data' => [
            'ocr' => $ocrResult,
            'nlp' => $nlpResult,
            'validation' => $validationResult,
            'summary' => $summary
        ],
        'meta' => [
            'original_filename' => $file['name'],
            'file_size' => $file['size'],
            'processing_time' => $processingTime . ' 秒',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    sendEvent('error', [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// 輔助函數
function getMimeType($extension) {
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'pdf' => 'application/pdf'
    ];
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}

function estimateConfidence($text) {
    $uncertainCount = substr_count($text, '[?]');
    $totalLength = strlen($text);
    if ($totalLength === 0) return 0;
    $uncertainRatio = ($uncertainCount * 10) / $totalLength;
    return round(max(0, min(100, 100 - ($uncertainRatio * 100))), 1);
}

function cleanJsonResponse($text) {
    $text = trim($text);
    $text = preg_replace('/^```json\s*/im', '', $text);
    $text = preg_replace('/^```\s*/im', '', $text);
    $text = preg_replace('/\s*```\s*$/m', '', $text);
    
    $firstBrace = strpos($text, '{');
    $lastBrace = strrpos($text, '}');
    
    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
        $text = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
    }
    
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    
    return trim($text);
}

function parseNumber($value) {
    if ($value === null || $value === '') return null;
    $cleaned = preg_replace('/[,\s]/', '', $value);
    if (preg_match('/^\((.+)\)$/', $cleaned, $matches)) {
        $cleaned = '-' . $matches[1];
    }
    return is_numeric($cleaned) ? floatval($cleaned) : null;
}

function performValidation($nlpResult) {
    if (isset($nlpResult['error'])) {
        return ['status' => 'skipped', 'reason' => 'NLP analysis failed'];
    }
    
    $errors = [];
    $warnings = [];
    $financialData = $nlpResult['financial_data'] ?? [];
    
    // 會計恆等式檢核
    $totalAssets = parseNumber($financialData['total_assets'] ?? null);
    $totalLiabilities = parseNumber($financialData['total_liabilities'] ?? null);
    $totalEquity = parseNumber($financialData['total_equity'] ?? null);
    
    if ($totalAssets !== null && $totalLiabilities !== null && $totalEquity !== null) {
        $expectedAssets = $totalLiabilities + $totalEquity;
        $difference = abs($totalAssets - $expectedAssets);
        $tolerance = max($totalAssets, $expectedAssets) * 0.01;
        
        if ($difference > $tolerance) {
            $errors[] = [
                'type' => 'accounting_equation',
                'message' => '會計恆等式不平衡：資產(' . number_format($totalAssets) . 
                           ') ≠ 負債(' . number_format($totalLiabilities) . 
                           ') + 權益(' . number_format($totalEquity) . ')',
                'expected' => number_format($expectedAssets),
                'actual' => number_format($totalAssets),
                'difference' => number_format($difference),
                'severity' => 'high'
            ];
        }
    }
    
    // 毛利計算檢核
    $revenue = parseNumber($financialData['revenue'] ?? null);
    $cost = parseNumber($financialData['cost'] ?? null);
    $grossProfit = parseNumber($financialData['gross_profit'] ?? null);
    
    if ($revenue !== null && $cost !== null && $grossProfit !== null) {
        $expectedGrossProfit = $revenue - $cost;
        $difference = abs($grossProfit - $expectedGrossProfit);
        $tolerance = max(abs($grossProfit), abs($expectedGrossProfit)) * 0.01;
        
        if ($difference > $tolerance) {
            $errors[] = [
                'type' => 'gross_profit_calculation',
                'message' => '毛利計算不符',
                'expected' => number_format($expectedGrossProfit),
                'actual' => number_format($grossProfit),
                'difference' => number_format($difference),
                'severity' => 'high'
            ];
        }
    }
    
    // 不確定項目
    $uncertainItems = $nlpResult['uncertain_items'] ?? [];
    foreach ($uncertainItems as $item) {
        $warnings[] = [
            'type' => 'uncertain_value',
            'field' => $item['field'] ?? 'unknown',
            'value' => $item['original_value'] ?? '',
            'reason' => $item['reason'] ?? 'OCR 識別不確定',
            'severity' => 'medium'
        ];
    }
    
    return [
        'status' => empty($errors) ? 'passed' : 'failed',
        'errors' => $errors,
        'warnings' => $warnings,
        'checks_performed' => [
            'accounting_equation' => ($totalAssets !== null && $totalLiabilities !== null && $totalEquity !== null),
            'gross_profit_check' => ($revenue !== null && $cost !== null && $grossProfit !== null),
            'operating_income_check' => false,
            'tax_id_check' => isset($nlpResult['company_info']['tax_id'])
        ]
    ];
}

function generateSummary($ocrResult, $nlpResult, $validationResult) {
    $summary = [
        'processing_status' => 'completed',
        'ocr_confidence' => $ocrResult['confidence'] ?? 0,
        'document_type' => $nlpResult['document_type'] ?? 'unknown',
        'validation_status' => $validationResult['status'] ?? 'unknown',
        'error_count' => count($validationResult['errors'] ?? []),
        'warning_count' => count($validationResult['warnings'] ?? []),
        'key_findings' => []
    ];
    
    if (!isset($nlpResult['error'])) {
        if (isset($nlpResult['company_info']['name'])) {
            $summary['key_findings'][] = '公司名稱：' . $nlpResult['company_info']['name'];
        }
        if (isset($nlpResult['company_info']['tax_id'])) {
            $summary['key_findings'][] = '統一編號：' . $nlpResult['company_info']['tax_id'];
        }
        if (isset($nlpResult['financial_data']['revenue'])) {
            $summary['key_findings'][] = '營業收入：' . $nlpResult['financial_data']['revenue'];
        }
        if (isset($nlpResult['financial_data']['net_income'])) {
            $summary['key_findings'][] = '淨利：' . $nlpResult['financial_data']['net_income'];
        }
    }
    
    return $summary;
}
