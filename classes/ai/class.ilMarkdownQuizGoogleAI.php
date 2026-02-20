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
 * - gemini-2.0-flash-exp (recommended, fast)
 * - gemini-pro (higher quality, slower)
 * 
 * API Docs: https://ai.google.dev/docs/gemini_api_overview
 * API Key: https://makersuite.google.com/app/apikey
 * 
 * Security: Circuit Breaker, 30s timeout, JSON validation
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
     * Debug logging to /tmp/mdquiz_prompt_debug.log
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
     * @return string Generated text from API
     * @throws ilMarkdownQuizException On network errors or invalid response
     */
    private function callAPI(string $prompt): string
    {
        // Check if API key is configured
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("Google API key is not configured");
        }

        // Build API URL with model name and API key (URL-encoded for security)
        $url = "https://generativelanguage.googleapis.com/v1/models/" . 
               urlencode($this->model) . ":generateContent?key=" . urlencode($this->api_key);

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
                "temperature" => 0.7,      // Creativity: 0.0=deterministic, 1.0=very creative
                "maxOutputTokens" => 16384  // High limit needed: reasoning models use output tokens for thinking
            ]
        ];

        // Initialize CURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        // Set CURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 180
        ]);

        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
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

        // Parse JSON response
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ilMarkdownQuizException("Invalid API response format");
        }

        // Extract text from nested response structure (candidates[0]->content->parts[0]->text)
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            throw new ilMarkdownQuizException("Could not extract text from Google API response");
        }

        return $decoded['candidates'][0]['content']['parts'][0]['text'];
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
        // Remove markdown code block wrapper if present (```markdown\n ... \n```)
        $response = preg_replace('/^```markdown\n/', '', $response);
        $response = preg_replace('/\n```$/', '', $response);
        
        // Remove leading/trailing whitespace
        $response = trim($response);
        
        return $response;
    }
}
