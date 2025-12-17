<?php
/**
 * 文件處理器類別
 * 負責處理上傳的文件，進行 OCR 和 NLP 分析
 */

require_once __DIR__ . '/GeminiClient.php';

class DocumentProcessor {
    private $geminiClient;
    
    public function __construct(GeminiClient $geminiClient) {
        $this->geminiClient = $geminiClient;
    }
    
    /**
     * 處理上傳的文件
     */
    public function processDocument($filePath, $originalName) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($extension);
        
        // 讀取文件並轉換為 Base64
        $fileContent = file_get_contents($filePath);
        $base64Data = base64_encode($fileContent);
        
        // 步驟1: OCR 提取文字
        $ocrResult = $this->performOCR($base64Data, $mimeType);
        
        // 步驟2: NLP 語意分析
        $nlpResult = $this->performNLP($ocrResult['raw_text']);
        
        // 步驟3: 自動糾錯和邏輯檢核
        $validationResult = $this->performValidation($nlpResult);
        
        return [
            'ocr' => $ocrResult,
            'nlp' => $nlpResult,
            'validation' => $validationResult,
            'summary' => $this->generateSummary($ocrResult, $nlpResult, $validationResult)
        ];
    }
    
    /**
     * 執行 OCR 提取文字
     */
    private function performOCR($base64Data, $mimeType) {
        $prompt = <<<EOT
你是一個專業的 OCR 文字辨識系統。請仔細分析這份文件圖片，提取所有可見的文字內容。

請遵循以下規則：
1. 保持原始文件的格式和結構
2. 如果文字模糊，請盡可能推測正確內容
3. 標記任何不確定的文字，用 [?] 標註
4. 特別注意數字的準確性（0和O、1和l、3和8等易混淆字符）
5. 識別表格結構並保持對齊

請以純文字格式輸出識別結果，保持原始排版。
EOT;
        
        $response = $this->geminiClient->sendRequest($prompt, $base64Data, $mimeType);
        $extractedText = $this->geminiClient->extractText($response);
        
        return [
            'raw_text' => $extractedText,
            'confidence' => $this->estimateConfidence($extractedText)
        ];
    }
    
    /**
     * 執行 NLP 語意分析
     */
    private function performNLP($rawText) {
        $prompt = <<<EOT
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

重要：只返回純 JSON 格式，不要包含任何 markdown 標記（如 ```json）或其他說明文字。直接以 { 開頭，以 } 結尾。
EOT;
        
        $response = $this->geminiClient->sendRequest($prompt);
        $analysisText = $this->geminiClient->extractText($response);
        
        // 清理並解析 JSON
        $analysisText = $this->cleanJsonResponse($analysisText);
        $analysis = json_decode($analysisText, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 嘗試修復常見的 JSON 問題
            $fixedText = $this->tryFixJson($analysisText);
            $analysis = json_decode($fixedText, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error' => 'JSON parsing failed: ' . json_last_error_msg(),
                    'raw_response' => $analysisText
                ];
            }
        }
        
        return $analysis;
    }
    
    /**
     * 嘗試修復常見的 JSON 問題
     */
    private function tryFixJson($text) {
        // 移除尾部逗號
        $text = preg_replace('/,(\s*[}\]])/', '$1', $text);
        
        // 修復單引號為雙引號
        // $text = str_replace("'", '"', $text);
        
        // 移除註解
        $text = preg_replace('/\/\/[^\n]*/', '', $text);
        $text = preg_replace('/\/\*.*?\*\//s', '', $text);
        
        return $text;
    }
    
    /**
     * 執行自動糾錯和邏輯檢核
     */
    private function performValidation($nlpResult) {
        if (isset($nlpResult['error'])) {
            return ['status' => 'skipped', 'reason' => 'NLP analysis failed'];
        }
        
        $errors = [];
        $warnings = [];
        $suggestions = [];
        
        $financialData = $nlpResult['financial_data'] ?? [];
        
        // 會計恆等式檢核：資產 = 負債 + 權益
        $totalAssets = $this->parseNumber($financialData['total_assets'] ?? null);
        $totalLiabilities = $this->parseNumber($financialData['total_liabilities'] ?? null);
        $totalEquity = $this->parseNumber($financialData['total_equity'] ?? null);
        
        if ($totalAssets !== null && $totalLiabilities !== null && $totalEquity !== null) {
            $expectedAssets = $totalLiabilities + $totalEquity;
            $difference = abs($totalAssets - $expectedAssets);
            $tolerance = max($totalAssets, $expectedAssets) * 0.01; // 1% 容差
            
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
        
        // 損益檢核：毛利 = 營收 - 成本
        $revenue = $this->parseNumber($financialData['revenue'] ?? null);
        $cost = $this->parseNumber($financialData['cost'] ?? null);
        $grossProfit = $this->parseNumber($financialData['gross_profit'] ?? null);
        
        if ($revenue !== null && $cost !== null && $grossProfit !== null) {
            $expectedGrossProfit = $revenue - $cost;
            $difference = abs($grossProfit - $expectedGrossProfit);
            $tolerance = max(abs($grossProfit), abs($expectedGrossProfit)) * 0.01;
            
            if ($difference > $tolerance) {
                $errors[] = [
                    'type' => 'gross_profit_calculation',
                    'message' => '毛利計算不符：營收(' . number_format($revenue) . 
                               ') - 成本(' . number_format($cost) . 
                               ') ≠ 毛利(' . number_format($grossProfit) . ')',
                    'expected' => number_format($expectedGrossProfit),
                    'actual' => number_format($grossProfit),
                    'difference' => number_format($difference),
                    'severity' => 'high'
                ];
            }
        }
        
        // 營業利益檢核：營業利益 = 毛利 - 營業費用
        $operatingExpenses = $this->parseNumber($financialData['operating_expenses'] ?? null);
        $operatingIncome = $this->parseNumber($financialData['operating_income'] ?? null);
        
        if ($grossProfit !== null && $operatingExpenses !== null && $operatingIncome !== null) {
            $expectedOperatingIncome = $grossProfit - $operatingExpenses;
            $difference = abs($operatingIncome - $expectedOperatingIncome);
            $tolerance = max(abs($operatingIncome), abs($expectedOperatingIncome)) * 0.01;
            
            if ($difference > $tolerance) {
                $warnings[] = [
                    'type' => 'operating_income_calculation',
                    'message' => '營業利益計算可能有誤',
                    'expected' => number_format($expectedOperatingIncome),
                    'actual' => number_format($operatingIncome),
                    'severity' => 'medium'
                ];
            }
        }
        
        // 檢查不確定項目
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
        
        // 統一編號格式檢核
        $taxId = $nlpResult['company_info']['tax_id'] ?? null;
        if ($taxId !== null) {
            $cleanTaxId = preg_replace('/[^0-9]/', '', $taxId);
            if (strlen($cleanTaxId) !== 8) {
                $warnings[] = [
                    'type' => 'tax_id_format',
                    'message' => '統一編號格式可能有誤（應為8位數字）',
                    'value' => $taxId,
                    'severity' => 'medium'
                ];
            } elseif (!$this->validateTaxId($cleanTaxId)) {
                $warnings[] = [
                    'type' => 'tax_id_checksum',
                    'message' => '統一編號檢核碼驗證失敗',
                    'value' => $taxId,
                    'severity' => 'low'
                ];
            }
        }
        
        return [
            'status' => empty($errors) ? 'passed' : 'failed',
            'errors' => $errors,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'checks_performed' => [
                'accounting_equation' => ($totalAssets !== null && $totalLiabilities !== null && $totalEquity !== null),
                'gross_profit_check' => ($revenue !== null && $cost !== null && $grossProfit !== null),
                'operating_income_check' => ($grossProfit !== null && $operatingExpenses !== null && $operatingIncome !== null),
                'tax_id_check' => ($taxId !== null)
            ]
        ];
    }
    
    /**
     * 解析數字字串
     */
    private function parseNumber($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        // 移除千分位符號和空白
        $cleaned = preg_replace('/[,\s]/', '', $value);
        
        // 處理括號表示的負數 (123) -> -123
        if (preg_match('/^\((.+)\)$/', $cleaned, $matches)) {
            $cleaned = '-' . $matches[1];
        }
        
        if (is_numeric($cleaned)) {
            return floatval($cleaned);
        }
        
        return null;
    }
    
    /**
     * 驗證台灣統一編號
     */
    private function validateTaxId($taxId) {
        if (strlen($taxId) !== 8 || !ctype_digit($taxId)) {
            return false;
        }
        
        $weights = [1, 2, 1, 2, 1, 2, 4, 1];
        $sum = 0;
        
        for ($i = 0; $i < 8; $i++) {
            $product = intval($taxId[$i]) * $weights[$i];
            $sum += intval($product / 10) + ($product % 10);
        }
        
        // 特殊處理第7位為7的情況
        if ($taxId[6] === '7') {
            return ($sum % 10 === 0) || (($sum + 1) % 10 === 0);
        }
        
        return $sum % 10 === 0;
    }
    
    /**
     * 估算 OCR 信心度
     */
    private function estimateConfidence($text) {
        $uncertainCount = substr_count($text, '[?]');
        $totalLength = strlen($text);
        
        if ($totalLength === 0) {
            return 0;
        }
        
        $uncertainRatio = ($uncertainCount * 10) / $totalLength;
        $confidence = max(0, min(100, 100 - ($uncertainRatio * 100)));
        
        return round($confidence, 1);
    }
    
    /**
     * 清理 JSON 回應
     */
    private function cleanJsonResponse($text) {
        // 移除前後空白
        $text = trim($text);
        
        // 移除 markdown 代碼區塊標記（支援多種格式）
        $text = preg_replace('/^```json\s*/im', '', $text);
        $text = preg_replace('/^```\s*/im', '', $text);
        $text = preg_replace('/\s*```\s*$/m', '', $text);
        
        // 嘗試找到 JSON 物件的開始和結束
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $text = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        }
        
        // 移除可能的 BOM
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        
        // 移除控制字符（但保留換行和空格）
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        
        return trim($text);
    }
    
    /**
     * 取得 MIME 類型
     */
    private function getMimeType($extension) {
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
    
    /**
     * 產生處理摘要
     */
    private function generateSummary($ocrResult, $nlpResult, $validationResult) {
        $summary = [
            'processing_status' => 'completed',
            'ocr_confidence' => $ocrResult['confidence'] ?? 0,
            'document_type' => $nlpResult['document_type'] ?? 'unknown',
            'validation_status' => $validationResult['status'] ?? 'unknown',
            'error_count' => count($validationResult['errors'] ?? []),
            'warning_count' => count($validationResult['warnings'] ?? []),
            'key_findings' => []
        ];
        
        // 提取關鍵發現
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
}
