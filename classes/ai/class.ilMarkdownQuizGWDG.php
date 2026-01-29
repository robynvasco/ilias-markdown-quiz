<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizConfig;
use platform\ilMarkdownQuizException;
use security\ilMarkdownQuizCircuitBreaker;
use security\ilMarkdownQuizResponseValidator;
use security\ilMarkdownQuizRequestSigner;
use security\ilMarkdownQuizCertificatePinner;

require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizConfig.php';
require_once dirname(__DIR__) . '/platform/class.ilMarkdownQuizException.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownQuizCircuitBreaker.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownQuizResponseValidator.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownQuizRequestSigner.php';
require_once dirname(__DIR__) . '/security/class.ilMarkdownQuizCertificatePinner.php';
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
        $serviceName = 'gwdg';
        
        try {
            // Check circuit breaker
            ilMarkdownQuizCircuitBreaker::checkAvailability($serviceName);
            
            $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);
            $response = $this->callAPI($prompt);
            $parsed = $this->parseResponse($response);
            
            // Record success
            ilMarkdownQuizCircuitBreaker::recordSuccess($serviceName);
            
            return $parsed;
            
        } catch (\Exception $e) {
            // Record failure
            ilMarkdownQuizCircuitBreaker::recordFailure($serviceName);
            throw $e;
        }
    }

    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        ilMarkdownQuizConfig::load();
        $system_prompt = ilMarkdownQuizConfig::get('system_prompt');
        
        // Use configured prompt or simple default if empty
        if (empty($system_prompt)) {
            $system_prompt = "Generate exactly [QUESTION_COUNT] quiz questions with difficulty level: [DIFFICULTY]";
        }
        
        // Convert legacy formats to new [PLACEHOLDER] format
        $system_prompt = str_replace('{{question_count}}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{{difficulty}}', '[DIFFICULTY]', $system_prompt);
        $system_prompt = str_replace('{question_count}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{difficulty}', '[DIFFICULTY]', $system_prompt);
        
        // Replace placeholders with actual values
        $system_prompt = str_replace('[DIFFICULTY]', $difficulty, $system_prompt);
        $system_prompt = str_replace('[QUESTION_COUNT]', (string)$question_count, $system_prompt);
        
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
