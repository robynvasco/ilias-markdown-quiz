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
 * Google Gemini AI Provider for MarkdownQuiz
 * 
 * Integrates Google Gemini API for quiz generation.
 * 
 * Supported Models:
 * - gemini-2.5-flash
 * 
 * API Docs: https://ai.google.dev/docs/gemini_api_overview
 * API Key: https://makersuite.google.com/app/apikey
 * 
 * Security: Circuit breaker, TLS verification/cert pinning, request signing, response validation
 * 
 * @package ai
 */
class ilMarkdownQuizGoogleAI extends ilMarkdownQuizLLM
{
    /** @var string Google API key from config */
    private string $api_key;
    
    /** @var string Model name (e.g., "gemini-2.0-flash-exp") */
    private string $model;

    /**
     * Constructor
     * 
     * @param string $api_key Google AI API key
     * @param string $model Model identifier
     */
    public function __construct(string $api_key, string $model)
    {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Generate quiz using Google Gemini API
     * 
     * @param string $user_prompt Quiz topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * @return string Generated quiz in Markdown format
     * @throws ilMarkdownQuizException On API errors or timeout
     */
    public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string
    {
        $serviceName = 'google';
        
        try {
            // Check Circuit Breaker (is API available?)
            ilMarkdownQuizCircuitBreaker::checkAvailability($serviceName);
            
            // Build full prompt
            $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);

            // Call Google API
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
     * Build prompt for Google Gemini API
     * 
     * Placeholders: [QUESTION_COUNT], [DIFFICULTY]
     * Legacy formats {{var}}, {var} are auto-converted
     * 
     * @param string $user_prompt Quiz topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * @return string Final prompt text
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
     * Call Google Gemini API
     * 
     * Endpoint: generativelanguage.googleapis.com/v1/models/{model}:generateContent
     * 
     * @param string $prompt Full prompt text
     * @return string Raw JSON response
     * @throws ilMarkdownQuizException On network errors or invalid response
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key is configured
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("Google API key is not configured");
        }

        // Build API URL with model name only. API key is sent in a header.
        $url = "https://generativelanguage.googleapis.com/v1/models/" . 
               urlencode($this->model) . ":generateContent";

        // Build request payload in Google-specific format
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
                "temperature" => 0.7       // Creativity: 0.0=deterministic, 1.0=very creative
            ]
        ];

        $payload_json = json_encode($payload);
        if ($payload_json === false) {
            throw new ilMarkdownQuizException("Failed to encode Google API payload");
        }

        $metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('google');
        $signature = ilMarkdownQuizRequestSigner::signRequest('google', $payload, $this->api_key);

        // Initialize CURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        ilMarkdownQuizCertificatePinner::configureCurl($ch, 'generativelanguage.googleapis.com');

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload_json,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "x-goog-api-key: " . $this->api_key,
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
            ilMarkdownQuizCertificatePinner::verifyCertificate('generativelanguage.googleapis.com', $ch);
        } catch (\Exception $e) {
            curl_close($ch);
            throw new ilMarkdownQuizException("Certificate verification failed: " . $e->getMessage());
        }

        curl_close($ch);

        // Check for CURL errors
        if ($response === false) {
            throw new ilMarkdownQuizException("API call failed: " . $error);
        }

        // Check HTTP status code
        if ($http_code !== 200) {
            $decoded = json_decode($response, true);
            $error_msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error';
            throw new ilMarkdownQuizException("Google API returned status code " . $http_code . ": " . $error_msg);
        }

        return $response;
    }

    /**
     * Clean and validate API response
     * 
     * Google sometimes returns markdown in code blocks:
     * ```markdown
     * # Question 1
     * ...
     * ```
     * 
     * This function removes the outer code block markers.
     * 
     * @param string $response Raw text from API
     * @return string Cleaned markdown text
     */
    private function parseResponse(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new ilMarkdownQuizException("Invalid JSON response from Google API");
        }

        try {
            ilMarkdownQuizResponseValidator::validateGoogleResponse($data);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Response validation failed: " . $e->getMessage());
        }

        $content = (string)$data['candidates'][0]['content']['parts'][0]['text'];

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
