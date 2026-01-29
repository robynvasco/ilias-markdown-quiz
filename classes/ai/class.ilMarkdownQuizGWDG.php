<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;

require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizConfig.php';
require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/class.ilMarkdownQuizLLM.php';

/**
 * GWDG/Academic Cloud LLM provider for MarkdownQuiz
 */
class ilMarkdownQuizGWDG extends ilMarkdownQuizLLM
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
        $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);
        $response = $this->callAPI($prompt);

        return $this->parseResponse($response);
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
        if (empty($this->api_key) || empty($this->model)) {
            throw new ilMarkdownQuizException("GWDG configuration is incomplete");
        }

        $endpoint = 'https://chat-ai.academiccloud.de/v1/chat/completions';
        $payload = [
            "model" => $this->model,
            "messages" => [
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.7,
            "max_tokens" => 2000
        ];

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->api_key
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new ilMarkdownQuizException("API call failed: " . $error);
        }

        if ($http_code !== 200) {
            throw new ilMarkdownQuizException("API returned status code: " . $http_code);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
            throw new ilMarkdownQuizException("Invalid API response format");
        }

        return $decoded['choices'][0]['message']['content'];
    }

    private function parseResponse(string $response): string
    {
        // Remove common markdown code blocks if present
        $response = preg_replace('/^```markdown\n/', '', $response);
        $response = preg_replace('/\n```$/', '', $response);
        $response = trim($response);

        return $response;
    }
}
