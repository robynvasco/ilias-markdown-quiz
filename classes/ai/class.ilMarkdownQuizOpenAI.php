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
 * OpenAI Provider for MarkdownQuiz
 *
 * Uses the OpenAI Responses API (POST /v1/responses) for quiz generation.
 * API Docs: https://developers.openai.com/api/docs/guides/text
 *
 * Security:
 * - Circuit Breaker Pattern
 * - Certificate Pinning (HTTPS cert verification)
 * - Request Signing (HMAC signature for audit trail)
 * - Response Validation (schema and format checks)
 * - 180s request timeout
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

    public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string
    {
        $serviceName = 'openai';

        try {
            ilMarkdownQuizCircuitBreaker::checkAvailability($serviceName);

            $prompt = $this->buildPrompt($user_prompt, $difficulty, $question_count);
            $response = $this->callAPI($prompt);
            $parsed = $this->parseResponse($response);

            ilMarkdownQuizCircuitBreaker::recordSuccess($serviceName);
            return $parsed;

        } catch (\Exception $e) {
            ilMarkdownQuizCircuitBreaker::recordFailure($serviceName);
            throw $e;
        }
    }

    protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string
    {
        ilMarkdownQuizConfig::load();
        $system_prompt = ilMarkdownQuizConfig::get('system_prompt');

        if (empty($system_prompt)) {
            $system_prompt = "Generate exactly [QUESTION_COUNT] quiz questions with difficulty level: [DIFFICULTY]";
        }

        // Convert legacy placeholder formats
        $system_prompt = str_replace('{{question_count}}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{{difficulty}}', '[DIFFICULTY]', $system_prompt);
        $system_prompt = str_replace('{question_count}', '[QUESTION_COUNT]', $system_prompt);
        $system_prompt = str_replace('{difficulty}', '[DIFFICULTY]', $system_prompt);

        $system_prompt = str_replace('[DIFFICULTY]', $difficulty, $system_prompt);
        $system_prompt = str_replace('[QUESTION_COUNT]', (string)$question_count, $system_prompt);

        return $system_prompt . $this->getLatexInstructions() . "\n\n" . $user_prompt;
    }

    /**
     * Call OpenAI Responses API
     *
     * Endpoint: POST https://api.openai.com/v1/responses
     * Docs: https://developers.openai.com/api/docs/guides/text
     */
    private function callAPI(string $prompt): string
    {
        if (empty($this->api_key)) {
            throw new ilMarkdownQuizException("OpenAI API key is not configured");
        }

        $url = "https://api.openai.com/v1/responses";

        $payload = [
            "model" => $this->model,
            "input" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
            "reasoning" => [
                "effort" => "low"
            ],
            "store" => false
        ];
        $payload_json = json_encode($payload);
        if ($payload_json === false) {
            throw new ilMarkdownQuizException("Failed to encode OpenAI API payload");
        }

        $metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('openai');
        $signature = ilMarkdownQuizRequestSigner::signRequest('openai', $payload, $this->api_key);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ilMarkdownQuizException("Failed to initialize CURL");
        }

        ilMarkdownQuizCertificatePinner::configureCurl($ch, 'api.openai.com');

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

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

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
     * Parse OpenAI Responses API output
     *
     * Response format:
     * { "output": [{ "type": "message", "content": [{ "type": "output_text", "text": "..." }] }] }
     */
    private function parseResponse(string $response): string
    {
        $data = json_decode($response, true);

        if ($data === null) {
            throw new ilMarkdownQuizException("Invalid JSON response from OpenAI API");
        }

        try {
            ilMarkdownQuizResponseValidator::validateOpenAIResponse($data);
        } catch (\Exception $e) {
            throw new ilMarkdownQuizException("Response validation failed: " . $e->getMessage());
        }

        // Extract text from Responses API format
        $content = $this->extractOutputText($data);

        // Clean markdown code block wrappers
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

    /**
     * Extract text from Responses API output array
     */
    private function extractOutputText(array $data): string
    {
        $text = '';
        foreach ($data['output'] as $item) {
            if (($item['type'] ?? '') === 'message' && isset($item['content'])) {
                foreach ($item['content'] as $part) {
                    if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                        $text .= $part['text'];
                    }
                }
            }
        }
        return $text;
    }
}
