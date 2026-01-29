<?php
declare(strict_types=1);
/**
 * XSS Protection helper for MarkdownQuiz plugin
 * Implements Content Security Policy, input sanitization, and markdown validation
 */

namespace platform;

/**
 * Class ilMarkdownQuizXSSProtection
 * Provides XSS protection for user-generated content
 */
class ilMarkdownQuizXSSProtection
{
    // Allowed markdown patterns
    private const ALLOWED_MARKDOWN_PATTERNS = [
        'questions' => '/^.+\?$/m',  // Lines ending with ?
        'options' => '/^- \[(x| )\] .+$/m',  // Checkbox options
        'text' => '/^[a-zA-Z0-9\s\.\,\?\!\-\:\;\(\)\/\'\"\äöüÄÖÜß]+$/u',  // Safe characters
    ];
    
    // Maximum content lengths
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_OPTION_LENGTH = 300;
    private const MAX_TOTAL_LENGTH = 10000;
    
    // Dangerous HTML tags and attributes
    private const DANGEROUS_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 
        'meta', 'link', 'style', 'base', 'form'
    ];
    
    private const DANGEROUS_ATTRIBUTES = [
        'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout',
        'onkeydown', 'onkeyup', 'onfocus', 'onblur', 'onchange',
        'onsubmit', 'onreset', 'ondblclick', 'oncontextmenu'
    ];
    
    /**
     * Set Content Security Policy headers
     * Prevents inline scripts and restricts resource loading
     */
    public static function setCSPHeaders(): void
    {
        // Only set if headers not already sent
        if (headers_sent()) {
            return;
        }
        
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",  // ILIAS requires inline scripts
            "style-src 'self' 'unsafe-inline'",  // Allow inline styles
            "img-src 'self' data: https:",
            "font-src 'self' data:",
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
     * Sanitize markdown content before rendering
     * @param string $markdown Raw markdown content
     * @return string Sanitized markdown
     * @throws ilMarkdownQuizException
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
     * Validate markdown structure
     * Ensures content follows expected quiz format
     * @param string $markdown Markdown content
     * @return bool True if valid
     * @throws ilMarkdownQuizException
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
            throw new ilMarkdownQuizException("Content must contain at least one question");
        }
        
        if (!$has_options) {
            throw new ilMarkdownQuizException("Content must contain answer options");
        }
        
        return true;
    }
    
    /**
     * Escape HTML output safely
     * Uses htmlspecialchars with proper flags
     * @param string $text Text to escape
     * @return string Escaped text
     */
    public static function escapeHTML(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    /**
     * Sanitize HTML output (alternative to DOMPurify for PHP)
     * Removes dangerous tags and attributes
     * @param string $html HTML content
     * @return string Sanitized HTML
     */
    public static function sanitizeHTML(string $html): string
    {
        // Remove dangerous tags
        foreach (self::DANGEROUS_TAGS as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html);
            $html = preg_replace('/<' . $tag . '\b[^>]*>/i', '', $html);
        }
        
        // Remove dangerous attributes
        foreach (self::DANGEROUS_ATTRIBUTES as $attr) {
            $html = preg_replace('/' . $attr . '\s*=\s*["\'][^"\']*["\']/i', '', $html);
        }
        
        // Remove javascript: and data: URIs
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $html);
        $html = preg_replace('/src\s*=\s*["\']data:text\/html[^"\']*["\']/i', '', $html);
        
        return $html;
    }
    
    /**
     * Sanitize user input (prompts, context, etc.)
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
        
        // Normalize whitespace
        $input = preg_replace('/\s+/', ' ', $input);
        
        // Trim
        $input = trim($input);
        
        return $input;
    }
    
    /**
     * Generate safe inline script with nonce
     * @param string $script JavaScript code
     * @return array ['nonce' => string, 'script' => string]
     */
    public static function generateSafeScript(string $script): array
    {
        // Generate cryptographic nonce
        $nonce = base64_encode(random_bytes(16));
        
        // Wrap script in safe container
        $safe_script = sprintf(
            '<script nonce="%s">%s</script>',
            self::escapeHTML($nonce),
            $script  // Don't escape the script itself, but it should be pre-validated
        );
        
        return [
            'nonce' => $nonce,
            'script' => $safe_script
        ];
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
        return $count >= 1 && $count <= 20;
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
