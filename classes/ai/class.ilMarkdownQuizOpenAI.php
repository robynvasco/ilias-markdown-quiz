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
 * OpenAI GPT provider for MarkdownQuiz
 */
class ilMarkdownQuizOpenAI extends ilMarkdownQuizLLM
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
        $serviceName = 'openai';
        
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
        
        // DEBUG: Write to file
        $debug_file = '/tmp/mdquiz_prompt_debug.log';
        file_put_contents($debug_file, "=== OPENAI PROMPT DEBUG ===\n", FILE_APPEND);
        file_put_contents($debug_file, "Time: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        file_put_contents($debug_file, "system_prompt from config (first 200 chars): " . substr($system_prompt, 0, 200) . "\n", FILE_APPEND);
        file_put_contents($debug_file, "question_count: " . $question_count . "\n", FILE_APPEND);
        file_put_contents($debug_file, "difficulty: " . $difficulty . "\n", FILE_APPEND);
        
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
        
        // DEBUG: After replacement
        file_put_contents($debug_file, "system_prompt after replacement (first 300 chars): " . substr($system_prompt, 0, 300) . "\n", FILE_APPEND);
        
        // Combine system prompt with user prompt
        $final_prompt = $system_prompt . "\n\n" . $user_prompt;
        
        // DEBUG: Final prompt
        file_put_contents($debug_file, "final_prompt sent to API (first 500 chars): " . substr($final_prompt, 0, 500) . "\n", FILE_APPEND);
        file_put_contents($debug_file, "===================\n\n", FILE_APPEND);
        
        return $final_prompt;
    }

    /**
     * @throws ilMarkdownQuizException
     */
    private function callAPI(string $prompt): string
    {
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("OpenAI API key is not configured");
        }

        $url = "https://api.openai.com/v1/chat/completions";

        $payload = [
            "model" => $this->model,
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
            "temperature" => 0.7,
            "max_tokens" => 2000
        ];
        
        // Create request metadata for auditing
        $metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('openai');
        
        // Sign request
        $signature = ilMarkdownQuizRequestSigner::signRequest('openai', $payload, $this->api_key);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }
        
        // Configure certificate pinning
        ilMarkdownQuizCertificatePinner::configureCurl($ch, 'api.openai.com');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->api_key,
                "X-Request-Signature: " . $signature,
                "X-Request-ID: " . $metadata['request_id']
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Verify certificate (if pinning enabled)
        try {
            ilMarkdownQuizCertificatePinner::verifyCertificate('api.openai.com', $ch);
        } catch (\Exception $e) {
            curl_close($ch);
            throw new ilMarkdownQuizException("Certificate verification failed: " . $e->getMessage());
        }
        
        curl_close($ch);

        if ($response === false) {
            throw new ilMarkdownQuizException("OpenAI API call failed: " . $error);
        }

        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown error';
            throw new ilMarkdownQuizException("OpenAI API error (HTTP $http_code): " . $error_message);
        }

        return $response;
    }

    /**
     * Parse the API response to extract quiz content
     * @throws ilMarkdownQuizException
     */
    private function parseResponse(string $response): string
    {
        $data = json_decode($response, true);
        
        if ($data === null) {
            throw new ilMarkdownQuizException("Invalid JSON response from OpenAI API");
        }
        
        // Validate response schema
        try {
            ilMarkdownQuizResponseValidator::validateOpenAIResponse($data);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Response validation failed: " . $e->getMessage());
        }

        $content = $data['choices'][0]['message']['content'];
        
        // Clean up markdown code blocks if present
        $content = preg_replace('/^```(?:markdown)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        
        $content = trim($content);
        
        // Validate markdown quiz format
        try {
            ilMarkdownQuizResponseValidator::validateMarkdownQuizFormat($content);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Quiz format validation failed: " . $e->getMessage());
        }
        
        return $content;
    }
}
