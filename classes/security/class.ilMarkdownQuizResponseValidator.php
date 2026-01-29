<?php
declare(strict_types=1);

namespace security;

/**
 * API Response Schema Validator
 * Validates API responses match expected structure to prevent injection attacks
 */
class ilMarkdownQuizResponseValidator
{
    /**
     * Validate OpenAI API response structure
     * @throws \Exception if response is invalid
     */
    public static function validateOpenAIResponse(array $response): void
    {
        // Check required top-level fields
        if (!isset($response['choices']) || !is_array($response['choices'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "choices" field');
        }
        
        if (empty($response['choices'])) {
            throw new \Exception('Invalid OpenAI response: empty "choices" array');
        }
        
        // Validate first choice
        $choice = $response['choices'][0];
        
        if (!isset($choice['message']) || !is_array($choice['message'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "message" field');
        }
        
        if (!isset($choice['message']['content']) || !is_string($choice['message']['content'])) {
            throw new \Exception('Invalid OpenAI response: missing or invalid "content" field');
        }
        
        // Validate content is not empty
        if (trim($choice['message']['content']) === '') {
            throw new \Exception('Invalid OpenAI response: empty content');
        }
        
        // Check for suspicious patterns that might indicate injection
        self::validateContentSafety($choice['message']['content']);
    }
    
    /**
     * Validate Google Gemini API response structure
     * @throws \Exception if response is invalid
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
     * Validate GWDG API response structure
     * @throws \Exception if response is invalid
     */
    public static function validateGWDGResponse(array $response): void
    {
        // GWDG uses OpenAI-compatible API
        self::validateOpenAIResponse($response);
    }
    
    /**
     * Check content for suspicious patterns that might indicate injection
     * @throws \Exception if suspicious patterns detected
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
     * Validate markdown quiz format structure
     * @throws \Exception if format is invalid
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
            
            // Check exactly one correct answer
            $correctCount = 0;
            foreach ($question['options'] as $option) {
                if ($option['checked']) {
                    $correctCount++;
                }
            }
            
            if ($correctCount !== 1) {
                $errors[] = "Question {$questionNum}: Must have exactly 1 correct answer (found {$correctCount})";
            }
        }
        
        if (!empty($errors)) {
            throw new \Exception("Quiz validation failed:\n" . implode("\n", $errors));
        }
        
        return $questions;
    }
}
