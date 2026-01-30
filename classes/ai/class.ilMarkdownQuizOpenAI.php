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
 * OpenAI GPT Provider for MarkdownQuiz
 * 
 * Integrates OpenAI Chat Completions API for quiz generation.
 * 
 * Supported Models:
 * - gpt-4o (latest, multimodal)
 * - gpt-4-turbo (fast and affordable)
 * - gpt-3.5-turbo (legacy, not recommended)
 * 
 * API Docs: https://platform.openai.com/docs/api-reference/chat
 * Features: Highest quiz quality, complex prompts, reasoning support
 * Pricing: ~$0.01 per 1000 tokens
 * 
 * Security:
 * - Circuit Breaker Pattern
 * - Certificate Pinning (HTTPS cert verification)
 * - Request Signing (HMAC signature for audit trail)
 * - Response Validation (schema and format checks)
 * - 30s timeout
 * 
 * @author Robyn
 * @version 1.0
 */
class ilMarkdownQuizOpenAI extends ilMarkdownQuizLLM
{
    /** @var string OpenAI API key */
    private string $api_key;
    
    /** @var string Model identifier (e.g., "gpt-4o") */
    private string $model;

    /**
     * Constructor
     * 
     * @param string $api_key OpenAI API key from admin config
     * @param string $model Model name (e.g., "gpt-4o", "gpt-4-turbo")
     */
    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate quiz using OpenAI API
     * 
     * Includes advanced security features (Certificate Pinning, Request Signing)
     * 
     * @param string $user_prompt Quiz topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * @return string Generated quiz in Markdown format
     * @throws ilMarkdownQuizException On API errors, validation errors, or Circuit Breaker open
     */
    public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string
    {
        $serviceName = 'openai';
        
        try {
            // Check Circuit Breaker availability
            ilMarkdownQuizCircuitBreaker::checkAvailability($serviceName);
            
            // Build final prompt
            $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);
            
            // Call API (with Certificate Pinning + Request Signing)
            $response = $this->callAPI($prompt);
            
            // Validate and clean response
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
     * Debug logging to /tmp/mdquiz_prompt_debug.log
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
        
        // DEBUG logging for development
        $debug_file = '/tmp/mdquiz_prompt_debug.log';
        file_put_contents($debug_file, "=== OPENAI PROMPT DEBUG ===\n", FILE_APPEND);
        file_put_contents($debug_file, "Time: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        file_put_contents($debug_file, "system_prompt from config (first 200 chars): " . substr($system_prompt, 0, 200) . "\n", FILE_APPEND);
        file_put_contents($debug_file, "question_count: " . $question_count . "\n", FILE_APPEND);
        file_put_contents($debug_file, "difficulty: " . $difficulty . "\n", FILE_APPEND);
        
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
        
        // DEBUG: After replacement
        file_put_contents($debug_file, "system_prompt after replacement (first 300 chars): " . substr($system_prompt, 0, 300) . "\n", FILE_APPEND);
        
        // Combine system prompt with user input
        $final_prompt = $system_prompt . "\n\n" . $user_prompt;
        
        // DEBUG: Final prompt
        file_put_contents($debug_file, "final_prompt sent to API (first 500 chars): " . substr($final_prompt, 0, 500) . "\n", FILE_APPEND);
        file_put_contents($debug_file, "===================\n\n", FILE_APPEND);
        
        return $final_prompt;
    }

    /**
     * Call OpenAI API with enhanced security features
     * 
     * Endpoint: https://api.openai.com/v1/chat/completions
     * Format: Chat Completions (standard OpenAI format)
     * 
     * Security features:
     * - Request Signing (HMAC-SHA256 for audit trail)
     * - Certificate Pinning (verifies HTTPS cert against known public keys)
     * - Request Metadata (unique request ID and timestamp)
     * 
     * @param string $prompt Full prompt (system + user)
     * @return string Raw JSON response from OpenAI (processed in parseResponse())
     * @throws ilMarkdownQuizException On missing config, network errors, HTTP errors, or cert verification failure
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key is configured
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("OpenAI API key is not configured");
        }

        // OpenAI Chat Completions endpoint
        $url = "https://api.openai.com/v1/chat/completions";

        // Build request payload
        $payload = [
            "model" => $this->model,  // e.g., "gpt-4o"
            "messages" => [
                [
                    "role" => "user",      // Role: user, system, assistant
                    "content" => $prompt   // Full prompt
                ]
            ],
            "temperature" => 0.7,      // Creativity: 0.0=deterministic, 1.0=very creative
            "max_tokens" => 2000       // Max response length (~1500 words)
        ];
        
        // Create request metadata for audit trail (contains: request_id, timestamp, service_name)
        $metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('openai');
        
        // Sign request with HMAC-SHA256 (prevents tampering, enables authentication)
        $signature = ilMarkdownQuizRequestSigner::signRequest('openai', $payload, $this->api_key);

        // Initialize CURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }
        
        // Configure Certificate Pinning (verifies OpenAI server has genuine cert, protects against MITM)
        ilMarkdownQuizCertificatePinner::configureCurl($ch, 'api.openai.com');

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->api_key,  // Bearer token auth
                "X-Request-Signature: " . $signature,  // Custom: HMAC signature
                "X-Request-ID: " . $metadata['request_id']  // Custom: Request ID for tracking
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,  // Verify SSL certificate (CRITICAL!)
            CURLOPT_SSL_VERIFYHOST => 2      // Verify hostname in certificate (CRITICAL!)
        ]);

        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Verify certificate against known public keys
        try {
            ilMarkdownQuizCertificatePinner::verifyCertificate('api.openai.com', $ch);
        } catch (\Exception $e) {
            curl_close($ch);
            throw new ilMarkdownQuizException("Certificate verification failed: " . $e->getMessage());
        }
        
        curl_close($ch);

        // Check for CURL errors
        if ($response === false) {
            throw new ilMarkdownQuizException("OpenAI API call failed: " . $error);
        }

        // Check HTTP status code (200 = OK, 401 = Unauthorized, 429 = Rate Limit, 500 = Server Error)
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown error';
            throw new ilMarkdownQuizException("OpenAI API error (HTTP $http_code): " . $error_message);
        }

        // Return raw JSON (processed in parseResponse())
        return $response;
    }

    /**
     * Parse and validate API response
     * 
     * Steps:
     * 1. JSON parsing
     * 2. Schema validation (OpenAI response structure)
     * 3. Content extraction from nested structure
     * 4. Remove markdown code block wrappers
     * 5. Format validation (Markdown quiz format)
     * 
     * @param string $response Raw JSON response from OpenAI API
     * @return string Cleaned and validated quiz in Markdown format
     * @throws ilMarkdownQuizException On JSON errors, schema validation, or format validation errors
     */
    private function parseResponse(string $response): string
    {
        // Step 1: Parse JSON
        $data = json_decode($response, true);
        
        if ($data === null) {
            throw new ilMarkdownQuizException("Invalid JSON response from OpenAI API");
        }
        
        // Step 2: Validate response schema (checks required fields: choices[0].message.content, id, created, model, etc.)
        try {
            ilMarkdownQuizResponseValidator::validateOpenAIResponse($data);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Response validation failed: " . $e->getMessage());
        }

        // Step 3: Extract content from nested structure (path: choices[0]->message->content)
        $content = $data['choices'][0]['message']['content'];
        
        // Step 4: Clean markdown code block wrappers (OpenAI sometimes returns ```markdown\n...\n```)
        $content = preg_replace('/^```(?:markdown)?\s*/m', '', $content);
        $content = preg_replace('/```\s*$/m', '', $content);
        
        // Remove leading/trailing whitespace
        $content = trim($content);
        
        // Step 5: Validate Markdown quiz format (checks for questions, answers, correct syntax)
        try {
            ilMarkdownQuizResponseValidator::validateMarkdownQuizFormat($content);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Quiz format validation failed: " . $e->getMessage());
        }
        
        return $content;
    }
}
