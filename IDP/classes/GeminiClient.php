<?php
/**
 * Gemini API 客戶端類別
 * 負責與 Google Gemini API 進行通訊
 */

class GeminiClient {
    private $apiKey;
    private $apiUrl;
    
    public function __construct($apiKey, $apiUrl = null) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    }
    
    /**
     * 發送請求到 Gemini API
     */
    public function sendRequest($prompt, $imageData = null, $mimeType = null) {
        $url = $this->apiUrl . '?key=' . $this->apiKey;
        
        $parts = [];
        
        // 添加文字提示
        $parts[] = ['text' => $prompt];
        
        // 如果有圖片，添加圖片數據
        if ($imageData && $mimeType) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => $imageData
                ]
            ];
        }
        
        $requestBody = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topK' => 32,
                'topP' => 1,
                'maxOutputTokens' => 4096,
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = isset($responseData['error']['message']) 
                ? $responseData['error']['message'] 
                : 'Unknown API error';
            throw new Exception("API Error ($httpCode): " . $errorMsg);
        }
        
        return $responseData;
    }
    
    /**
     * 從回應中提取文字內容
     */
    public function extractText($response) {
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }
        return '';
    }
}
