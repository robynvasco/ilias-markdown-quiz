<?php
declare(strict_types=1);

namespace security;

/**
 * API Response Schema Validator
 * 
 * Validates API responses against expected structure:
 * - Schema validation (required fields present)
 * - Security checks (script tags, SQL patterns, etc.)
 * - Quiz format validation (questions, options, correct answers)
 * 
 * Prevents:
 * - Injection attacks via malformed responses
 * - DoS via oversized responses (100KB limit)
 * - Invalid quiz structures
 * 
 * @package security
 */
class ilMarkdownQuizResponseValidator
{
    /**
     * Validate OpenAI Responses API structure
     *
     * Checks:
     * - output array exists with message items
     * - output[].content[].text exists and is non-empty
     * - No suspicious patterns (scripts, SQL, javascript:)
     *
     * Response format:
     * { "output": [{ "type": "message", "content": [{ "type": "output_text", "text": "..." }] }] }
     *
     * @param array $response Decoded JSON response
     * @throws \Exception If invalid structure or security violation
     */
    public static function validateOpenAIResponse(array $response): void
    {
        // Check required top-level fields
        if (!isset($response['output']) || !is_array($response['output'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "output" field');
        }

        if (empty($response['output'])) {
            throw new \Exception('Invalid OpenAI response: empty "output" array');
        }

        // Extract text from output items
        $text = '';
        foreach ($response['output'] as $item) {
            if (($item['type'] ?? '') === 'message' && isset($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $part) {
                    if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                        $text .= $part['text'];
                    }
                }
            }
        }

        // Validate content is not empty
        if (trim($text) === '') {
            throw new \Exception('Invalid OpenAI response: empty content');
        }

        // Check for suspicious patterns that might indicate injection
        self::validateContentSafety($text);
    }
    
    /**
     * Validate Google Gemini response structure
     * 
     * Checks:
     * - candidates[0].content.parts[0].text exists and is non-empty
     * - No suspicious patterns
     * 
     * @param array $response Decoded JSON response
     * @throws \Exception If invalid structure or security violation
     */
    public static function validateGoogleResponse(array $response): void
    {
        // Check required top-level fields
        if (!isset($response['candidates']) || !is_array($response['candidates'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "candidates" field');
        }
        
        if (empty($response['candidates'])) {
            throw new \Exception('Invalid Google AI response: empty "candidates" array');
        }
        
        // Validate first candidate
        $candidate = $response['candidates'][0];
        
        if (!isset($candidate['content']) || !is_array($candidate['content'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "content" field');
        }
        
        if (!isset($candidate['content']['parts']) || !is_array($candidate['content']['parts'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "parts" field');
        }
        
        if (empty($candidate['content']['parts'])) {
            throw new \Exception('Invalid Google AI response: empty "parts" array');
        }
        
        if (!isset($candidate['content']['parts'][0]['text']) || !is_string($candidate['content']['parts'][0]['text'])) {
            throw new \Exception('Invalid Google AI response: missing or invalid "text" field');
        }
        
        // Validate content is not empty
        if (trim($candidate['content']['parts'][0]['text']) === '') {
            throw new \Exception('Invalid Google AI response: empty text');
        }
        
        // Check for suspicious patterns
        self::validateContentSafety($candidate['content']['parts'][0]['text']);
    }
    
    /**
     * Validate GWDG API response structure (OpenAI Chat Completions compatible)
     *
     * Checks:
     * - choices[0].message.content exists and is non-empty string
     * - No suspicious patterns (scripts, SQL, javascript:)
     *
     * @param array $response Decoded JSON response
     * @throws \Exception If invalid structure or security violation
     */
    public static function validateGWDGResponse(array $response): void
    {
        if (!isset($response['choices']) || !is_array($response['choices'])) {
            throw new \Exception('Invalid GWDG response: missing or invalid "choices" field');
        }

        if (empty($response['choices'])) {
            throw new \Exception('Invalid GWDG response: empty "choices" array');
        }

        $choice = $response['choices'][0];

        if (!isset($choice['message']) || !is_array($choice['message'])) {
            throw new \Exception('Invalid GWDG response: missing or invalid "message" field');
        }

        if (!isset($choice['message']['content']) || !is_string($choice['message']['content'])) {
            throw new \Exception('Invalid GWDG response: missing or invalid "content" field');
        }

        if (trim($choice['message']['content']) === '') {
            throw new \Exception('Invalid GWDG response: empty content');
        }

        self::validateContentSafety($choice['message']['content']);
    }
    
    /**
     * Check content for injection patterns
     * 
     * Rejects:
     * - <script> tags
     * - <?php code
     * - SQL keywords (DROP, DELETE, UPDATE, INSERT)
     * - javascript: protocol in markdown images
     * - Content > 100KB
     * 
     * @param string $content Response content
     * @throws \Exception If suspicious pattern detected
     */
    private static function validateContentSafety(string $content): void
    {
        // Check for script tags
        if (preg_match('/<script\b[^>]*>/i', $content)) {
            throw new \Exception('Security violation: script tag detected in API response');
        }
        
        // Check for suspicious PHP code
        if (preg_match('/<\?php/i', $content)) {
            throw new \Exception('Security violation: PHP code detected in API response');
        }
        
        // Check for SQL injection patterns in markdown (should not be present)
        if (preg_match('/;\s*(DROP|DELETE|UPDATE|INSERT)\s+/i', $content)) {
            throw new \Exception('Security violation: SQL-like pattern detected in API response');
        }
        
        // Check for markdown image with javascript protocol
        if (preg_match('/!\[.*?\]\(javascript:/i', $content)) {
            throw new \Exception('Security violation: javascript protocol in markdown image');
        }
        
        // Check for excessive length (potential DoS)
        if (strlen($content) > 100000) { // 100KB limit
            throw new \Exception('Security violation: response content exceeds maximum length');
        }
    }
    
    /**
     * Validate markdown quiz format
     *
     * Requirements:
     * - Questions end with '?'
     * - Each question has exactly 4 options (- [x] or - [ ])
     * - At least 1 correct answer per question (supports single & multiple choice)
     *
     * @param string $markdown Quiz content
     * @return array Parsed questions with validation
     * @throws \Exception If format invalid (with detailed error messages)
     */
    public static function validateMarkdownQuizFormat(string $markdown): array
    {
        $lines = explode("\n", trim($markdown));
        $questions = [];
        $currentQuestion = null;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            // Skip empty lines
            if ($line === '') {
                if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
                    $questions[] = $currentQuestion;
                    $currentQuestion = null;
                }
                continue;
            }

            // Check if it's an option line
            if (preg_match('/^-\s*\[([ x])\]\s*(.+)$/i', $line, $matches)) {
                if ($currentQuestion === null) {
                    $errors[] = "Line " . ($lineNum + 1) . ": Option found without question";
                    continue;
                }

                $currentQuestion['options'][] = [
                    'checked' => strtolower($matches[1]) === 'x',
                    'text' => trim($matches[2])
                ];
            } else {
                // Must be a question (should end with ?)
                if (!str_ends_with($line, '?')) {
                    $errors[] = "Line " . ($lineNum + 1) . ": Question must end with '?'";
                }

                // Save previous question if exists
                if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
                    $questions[] = $currentQuestion;
                }

                $currentQuestion = [
                    'question' => $line,
                    'options' => []
                ];
            }
        }

        // Save last question
        if ($currentQuestion !== null && count($currentQuestion['options']) >= 4) {
            $questions[] = $currentQuestion;
        }

        // Validate each question
        foreach ($questions as $idx => $question) {
            $questionNum = $idx + 1;

            // Check has exactly 4 options
            if (count($question['options']) !== 4) {
                $errors[] = "Question {$questionNum}: Must have exactly 4 options (found " . count($question['options']) . ")";
            }

            // Check at least one correct answer (supports both single and multiple choice)
            $correctCount = 0;
            foreach ($question['options'] as $option) {
                if ($option['checked']) {
                    $correctCount++;
                }
            }

            if ($correctCount < 1) {
                $errors[] = "Question {$questionNum}: Must have at least 1 correct answer (found 0)";
            }
        }

        if (!empty($errors)) {
            throw new \Exception("Quiz validation failed:\n" . implode("\n", $errors));
        }

        return $questions;
    }
}
