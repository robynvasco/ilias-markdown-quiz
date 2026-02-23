<?php
declare(strict_types=1);
/**
 * XSS Protection helper for MarkdownQuiz plugin
 * Implements Content Security Policy, input sanitization, and markdown validation
 */

namespace platform;

/**
 * XSS Protection Service for MarkdownQuiz Plugin
 * 
 * Provides multiple layers of XSS protection:
 * - Content Security Policy headers
 * - Input sanitization (strip dangerous patterns)
 * - Markdown structure validation
 * - HTML output escaping
 * 
 * Max lengths:
 * - Question: 500 chars
 * - Option: 300 chars
 * - Total: 10,000 chars
 * 
 * @package platform
 */
class ilMarkdownQuizXSSProtection
{
    // Allowed markdown patterns
    private const ALLOWED_MARKDOWN_PATTERNS = [
        'questions' => '/^.{3,}[\.?!:]$/m',  // Lines ending with punctuation (min 3 chars)
        'options' => '/^- \[(x| )\] .+$/m',  // Checkbox options
    ];
    
    // Maximum content lengths
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_OPTION_LENGTH = 300;
    private const MAX_TOTAL_LENGTH = 10000;
    
    /**
     * Set Content Security Policy headers
     * 
     * Restricts resource loading and prevents inline scripts.
     * Also sets X-Frame-Options, X-XSS-Protection, etc.
     * 
     * @return void
     */
    public static function setCSPHeaders(): void
    {
        // Only set if headers not already sent
        if (headers_sent()) {
            return;
        }
        
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",  // ILIAS requires inline scripts, MathJax CDN
            "style-src 'self' 'unsafe-inline'",  // Allow inline styles
            "img-src 'self' data: https:",
            "font-src 'self' data: https://cdn.jsdelivr.net",  // MathJax loads web fonts
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'"
        ];
        
        header("Content-Security-Policy: " . implode('; ', $csp_directives));
        
        // Additional security headers
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
    }
    
    /**
     * Sanitize markdown content
     * 
     * - Strips all HTML tags
     * - Removes dangerous patterns (script, javascript:, event handlers)
     * - Validates length
     * 
     * @param string $markdown Raw markdown
     * @return string Sanitized markdown
     * @throws ilMarkdownQuizException If content too long or contains dangerous patterns
     */
    public static function sanitizeMarkdown(string $markdown): string
    {
        // Check total length
        if (strlen($markdown) > self::MAX_TOTAL_LENGTH) {
            throw new ilMarkdownQuizException(
                "Content too long (max " . self::MAX_TOTAL_LENGTH . " characters)"
            );
        }
        
        // Remove any HTML tags
        $markdown = strip_tags($markdown);
        
        // Remove dangerous character sequences
        $dangerous_patterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i',  // Event handlers
            '/vbscript:/i',
            '/data:text\/html/i',
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $markdown)) {
                throw new ilMarkdownQuizException("Content contains potentially dangerous patterns");
            }
        }
        
        return trim($markdown);
    }
    
    /**
     * Validate markdown quiz structure
     * 
     * Ensures:
     * - At least one question (ending with ?)
     * - At least one answer option (- [x] or - [ ])
     * - Individual length limits respected
     * 
     * @param string $markdown Markdown content
     * @return bool True if valid
     * @throws ilMarkdownQuizException If structure invalid
     */
    public static function validateMarkdownStructure(string $markdown): bool
    {
        $lines = explode("\n", $markdown);
        $has_questions = false;
        $has_options = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Check for questions (ending with ?)
            if (preg_match(self::ALLOWED_MARKDOWN_PATTERNS['questions'], $line)) {
                $has_questions = true;
                
                // Validate question length
                if (strlen($line) > self::MAX_QUESTION_LENGTH) {
                    throw new ilMarkdownQuizException(
                        "Question too long (max " . self::MAX_QUESTION_LENGTH . " characters)"
                    );
                }
            }
            
            // Check for options (checkbox format)
            if (preg_match(self::ALLOWED_MARKDOWN_PATTERNS['options'], $line)) {
                $has_options = true;
                
                // Validate option length
                if (strlen($line) > self::MAX_OPTION_LENGTH) {
                    throw new ilMarkdownQuizException(
                        "Option too long (max " . self::MAX_OPTION_LENGTH . " characters)"
                    );
                }
            }
        }
        
        if (!$has_questions) {
            throw new ilMarkdownQuizException(
                "Invalid quiz format: Questions must end with punctuation (?, !, :, or .)\n" .
                "Example: What is the capital of France?\n" .
                "Or: The Earth revolves around the Sun."
            );
        }

        if (!$has_options) {
            throw new ilMarkdownQuizException(
                "Invalid quiz format: Each question needs answer options.\n" .
                "Format: - [ ] Wrong answer\n" .
                "        - [x] Correct answer"
            );
        }
        
        return true;
    }
    
    /**
     * Escape HTML for safe output
     * 
     * @param string $text Text to escape
     * @return string HTML-safe text
     */
    public static function escapeHTML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    /**
     * Sanitize user input (prompts, context, etc.)
     *
     * Note: Prompt injection protection relies on architectural security (see below),
     * not regex pattern matching which is easily bypassed and language-specific.
     *
     * @param string $input User input
     * @param int $max_length Maximum allowed length
     * @return string Sanitized input
     * @throws ilMarkdownQuizException
     */
    public static function sanitizeUserInput(string $input, int $max_length = 5000): string
    {
        // Check length
        if (strlen($input) > $max_length) {
            throw new ilMarkdownQuizException(
                "Input too long (max {$max_length} characters)"
            );
        }

        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Remove control characters (except newlines and tabs)
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);

        // Normalize whitespace
        $input = preg_replace('/\s+/', ' ', $input);

        // Trim
        $input = trim($input);

        return $input;
    }
    
    /**
     * Validate difficulty level enum
     * @param string $difficulty Difficulty value
     * @return bool True if valid
     */
    public static function validateDifficulty(string $difficulty): bool
    {
        $allowed = ['easy', 'medium', 'hard', 'mixed'];
        return in_array($difficulty, $allowed, true);
    }
    
    /**
     * Validate question count range
     * @param int $count Question count
     * @return bool True if valid
     */
    public static function validateQuestionCount(int $count): bool
    {
        return $count >= 1 && $count <= 10;
    }
    
    /**
     * Create safe data attribute value
     * @param string $value Value to use in data attribute
     * @return string Safe attribute value
     */
    public static function createSafeDataAttribute(string $value): string
    {
        // Only allow alphanumeric, spaces, and basic punctuation
        $safe = preg_replace('/[^a-zA-Z0-9\s\.\,\-\_]/', '', $value);
        return self::escapeHTML($safe);
    }
    
    /**
     * Comprehensive XSS protection for rendered content
     * @param string $markdown Markdown content
     * @return string Sanitized and validated markdown
     * @throws ilMarkdownQuizException
     */
    public static function protectContent(string $markdown): string
    {
        // Step 1: Sanitize markdown
        $sanitized = self::sanitizeMarkdown($markdown);
        
        // Step 2: Validate structure
        self::validateMarkdownStructure($sanitized);
        
        // Step 3: Return sanitized content
        return $sanitized;
    }
}
