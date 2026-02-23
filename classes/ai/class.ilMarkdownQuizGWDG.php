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
 * GWDG Academic Cloud LLM Provider for MarkdownQuiz
 * 
 * Integrates GWDG Academic Cloud API for quiz generation.
 * 
 * Supported Models:
 * - meta-llama/Llama-3.3-70B-Instruct
 * - Qwen/Qwen3-235B-A22B-Thinking-2507
 * - mistralai/Mistral-Large-Instruct-2501
 * 
 * API Docs: https://chat-ai.academiccloud.de/docs
 * Features: OpenAI-compatible API, SSE streaming, free for German universities, GDPR-compliant
 * Security: Circuit breaker, TLS verification/cert pinning, request signing, response validation
 */
class ilMarkdownQuizGWDG extends ilMarkdownQuizLLM
{
    /** @var string GWDG API key */
    private string $api_key;
    
    /** @var string Model identifier (e.g., "meta-llama/Meta-Llama-3.1-70B-Instruct") */
    private string $model;

    /**
     * Constructor
     * 
     * @param string $api_key GWDG API key from admin config
     * @param string $model Model name (must be supported by GWDG)
     */
    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate quiz using GWDG Academic Cloud API
     * 
     * @param string $user_prompt Quiz topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * @return string Generated quiz in Markdown format
     * @throws ilMarkdownQuizException On API errors or Circuit Breaker open
     */
    public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string
    {
        $serviceName = 'gwdg';
        
        try {
            // Check Circuit Breaker availability
            ilMarkdownQuizCircuitBreaker::checkAvailability($serviceName);
            
            // Build final prompt
            $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);

            // Call GWDG API
            $response = $this->callAPI($prompt);
            
            // Parse and clean response
            $parsed = $this->parseResponse($response);
            
            // Record success
            ilMarkdownQuizCircuitBreaker::recordSuccess($serviceName);
            
            return $parsed;
            
        } catch (\Exception $e) {
            // Record failure (too many failures will open circuit)
            ilMarkdownQuizCircuitBreaker::recordFailure($serviceName);
            throw $e;
        }
    }

    /**
     * Build prompt from system template and user input
     * 
     * Placeholders: [QUESTION_COUNT], [DIFFICULTY]
     * Legacy formats are auto-converted
     * 
     * @param string $user_prompt User's topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * @return string Final combined prompt
     */
    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        // Load system prompt from config
        ilMarkdownQuizConfig::load();
        $system_prompt = ilMarkdownQuizConfig::get('system_prompt');
        
        // Fallback if no system prompt configured
        if (empty($system_prompt)) {
            $system_prompt = "Generate exactly [QUESTION_COUNT] quiz questions with difficulty level: [DIFFICULTY]";
        }
        
        // Convert legacy placeholder formats
        $system_prompt = str_replace('{{question_count}}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{{difficulty}}', '[DIFFICULTY]', $system_prompt);
        $system_prompt = str_replace('{question_count}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{difficulty}', '[DIFFICULTY]', $system_prompt);
        
        // Replace placeholders with actual values
        $system_prompt = str_replace('[DIFFICULTY]', $difficulty, $system_prompt);
        $system_prompt = str_replace('[QUESTION_COUNT]', (string)$question_count, $system_prompt);
        
        // Combine system prompt + LaTeX instructions + user input
        $final_prompt = $system_prompt . $this->getLatexInstructions() . "\n\n" . $user_prompt;

        return $final_prompt;
    }

    /**
     * Call GWDG Academic Cloud API
     * 
     * Endpoint: https://chat-ai.academiccloud.de/v1/chat/completions
     * Format: OpenAI-compatible (Chat Completions)
     * 
     * @param string $prompt Full prompt (system + user)
     * @return string Raw JSON response
     * @throws ilMarkdownQuizException On missing config, network errors, or HTTP errors
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key and model are configured
        if (empty($this->api_key) || empty($this->model)) {
            throw new ilMarkdownQuizException("GWDG configuration is incomplete");
        }

        // API endpoint
        $endpoint = 'https://chat-ai.academiccloud.de/v1/chat/completions';
        
        // Build request payload (OpenAI-compatible format)
        $payload = [
            "model" => $this->model,
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
            "temperature" => 0.7
        ];

        $payload_json = json_encode($payload);
        if ($payload_json === false) {
            throw new ilMarkdownQuizException("Failed to encode GWDG API payload");
        }

        $metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('gwdg');
        $signature = ilMarkdownQuizRequestSigner::signRequest('gwdg', $payload, $this->api_key);

        // Initialize CURL
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        ilMarkdownQuizCertificatePinner::configureCurl($ch, 'chat-ai.academiccloud.de');

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload_json,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->api_key,
                "X-Request-Signature: " . $signature,
                "X-Request-ID: " . $metadata['request_id']
            ],
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        try {
            ilMarkdownQuizCertificatePinner::verifyCertificate('chat-ai.academiccloud.de', $ch);
        } catch (\Exception $e) {
            curl_close($ch);
            throw new ilMarkdownQuizException("Certificate verification failed: " . $e->getMessage());
        }

        curl_close($ch);

        // Check for CURL errors
        if ($response === false) {
            throw new ilMarkdownQuizException("API call failed: " . $error);
        }

        // Check HTTP status code (200 = OK, 401 = Unauthorized, 429 = Rate Limit, 500 = Server Error)
        if ($http_code !== 200) {
            $decoded = json_decode($response, true);
            $error_msg = $decoded['error']['message'] ?? 'Unknown error';
            throw new ilMarkdownQuizException("GWDG API error (HTTP {$http_code}): {$error_msg}");
        }

        return $response;
    }

    /**
     * Clean and validate API response
     * 
     * Removes markdown code block wrappers if present
     * 
     * @param string $response Raw text from API
     * @return string Cleaned markdown text
     */
    private function parseResponse(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new ilMarkdownQuizException("Invalid JSON response from GWDG API");
        }

        try {
            ilMarkdownQuizResponseValidator::validateGWDGResponse($data);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Response validation failed: " . $e->getMessage());
        }

        $content = (string)$data['choices'][0]['message']['content'];

        // Remove markdown code block wrappers
        $content = preg_replace('/^```(?:markdown)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        $content = trim($content);

        try {
            ilMarkdownQuizResponseValidator::validateMarkdownQuizFormat($content);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Quiz format validation failed: " . $e->getMessage());
        }

        return $content;
    }
}
