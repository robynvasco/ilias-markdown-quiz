<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;

require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizConfig.php';
require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/class.ilMarkdownQuizLLM.php';

/**
 * Google Gemini LLM provider for MarkdownQuiz
 */
class ilMarkdownQuizGoogleAI extends ilMarkdownQuizLLM
{
    private string $api_key;
    private string $model;

    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate a quiz in markdown format from a topic
     * @throws ilMarkdownQuizException
     */
    public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string
    {
        error_log('MarkdownQuiz: generateQuiz called with prompt: ' . substr($user_prompt, 0, 50));
        $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);
        $response = $this->callAPI($prompt);
        $parsed = $this->parseResponse($response);
        error_log('MarkdownQuiz: generateQuiz returning length: ' . strlen($parsed));

        return $parsed;
    }

    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        ilMarkdownQuizConfig::load();
        $system_prompt = ilMarkdownQuizConfig::get('system_prompt');
        
        // Use configured prompt or simple default if empty
        if (empty($system_prompt)) {
            $system_prompt = "Generate exactly {question_count} quiz questions with difficulty level: {difficulty}";
        }
        
        // Replace placeholders
        $system_prompt = str_replace('{difficulty}', $difficulty, $system_prompt);
        $system_prompt = str_replace('{question_count}', (string)$question_count, $system_prompt);
        
        // Combine system prompt with user prompt
        $final_prompt = $system_prompt . "\n\n" . $user_prompt;
        
        return $final_prompt;
    }

    /**
     * @throws ilMarkdownQuizException
     */
    private function callAPI(string $prompt): string
    {
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("Google API key is not configured");
        }

        $url = "https://generativelanguage.googleapis.com/v1/models/" . 
               urlencode($this->model) . ":generateContent?key=" . urlencode($this->api_key);

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        [
                            "text" => $prompt
                        ]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.7,
                "maxOutputTokens" => 2000
            ]
        ];

        error_log("MarkdownQuiz: Calling Google API with URL: " . $url);
        error_log("MarkdownQuiz: Prompt: " . substr($prompt, 0, 200));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("MarkdownQuiz: API HTTP Code: " . $http_code);
        error_log("MarkdownQuiz: API Response: " . substr($response, 0, 500));

        if ($response === false) {
            throw new ilMarkdownQuizException("API call failed: " . $error);
        }

        if ($http_code !== 200) {
            $decoded = json_decode($response, true);
            $error_msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error';
            throw new ilMarkdownQuizException("Google API returned status code " . $http_code . ": " . $error_msg);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ilMarkdownQuizException("Invalid API response format");
        }

        // Navigate through the response structure
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            error_log("MarkdownQuiz: Full API response: " . print_r($decoded, true));
            throw new ilMarkdownQuizException("Could not extract text from Google API response");
        }

        return $decoded['candidates'][0]['content']['parts'][0]['text'];
    }

    private function parseResponse(string $response): string
    {
        error_log('MarkdownQuiz: Raw API response length: ' . strlen($response));
        error_log('MarkdownQuiz: Raw API response preview: ' . substr($response, 0, 200));
        
        // Remove common markdown code blocks if present
        $response = preg_replace('/^```markdown\n/', '', $response);
        $response = preg_replace('/\n```$/', '', $response);
        $response = trim($response);
        
        error_log('MarkdownQuiz: Parsed response length: ' . strlen($response));

        return $response;
    }
}
