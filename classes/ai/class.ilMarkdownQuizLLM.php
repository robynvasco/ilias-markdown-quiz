<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizException;

/**
 * Abstract base class for all LLM providers (Large Language Models)
 * 
 * Defines common interface for AI providers like GWDG, Google Gemini, and OpenAI.
 * Each provider must implement these methods.
 * 
 * Benefits:
 * - Unified interface for all LLM providers
 * - Easy to add new providers via inheritance
 * - Guaranteed consistent method signatures
 * 
 * Adding new providers:
 * ```php
 * class ilMarkdownQuizAnthropic extends ilMarkdownQuizLLM {
 *     public function generateQuiz(...) { // Implementation }
 *     protected function buildPrompt(...) { // Implementation }
 * }
 * ```
 * 
 * @package ai
 * @abstract
 */
abstract class ilMarkdownQuizLLM
{
    /**
     * Generate quiz in Markdown format using AI
     * 
     * Sends request to LLM provider and returns Markdown-formatted quiz questions.
     * 
     * Provider must:
     * 1. Format prompt correctly (see buildPrompt)
     * 2. Send API request
     * 3. Validate response
     * 4. Extract and return Markdown text
     * 
     * @param string $user_prompt Quiz topic (e.g., "Photosynthesis", "World War II")
     * @param string $difficulty Difficulty level: "easy", "medium", or "hard"
     * @param int $question_count Number of questions to generate (typically 5-20)
     * 
     * @return string Generated quiz in Markdown format with:
     *                - Question headings (# Question 1)
     *                - Multiple-choice options
     *                - Correct answers marked
     * 
     * @throws ilMarkdownQuizException If API call fails, rate limit exceeded, or response invalid
     * 
     * @example
     * ```php
     * $llm = new ilMarkdownQuizGoogleAI();
     * $quiz = $llm->generateQuiz("Climate Change", "medium", 10);
     * // Returns: "# Question 1\nWhat is the greenhouse effect?\n- A) ...\n- B) ..."
     * ```
     */
    abstract public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string;

    /**
     * Build final prompt for LLM provider
     * 
     * Combines system instructions (from config) with user input into complete prompt.
     * Each provider has different prompt formats:
     * 
     * - **OpenAI**: Separate system/user messages in JSON
     * - **Google**: Combined prompt with Markdown structures
     * - **GWDG**: System prompt + user content separated
     * 
     * Typical prompt structure:
     * ```
     * System: "You are an educational quiz generator..."
     * User: "Create 10 questions about {topic} with {difficulty} difficulty"
     * ```
     * 
     * @param string $user_prompt Quiz topic
     * @param string $difficulty Difficulty level
     * @param int $question_count Number of questions
     * 
     * @return string Fully formatted prompt for the API
     * 
     * @example
     * ```php
     * $prompt = $this->buildPrompt("Photosynthesis", "easy", 5);
     * // OpenAI format:
     * // [
     * //   {"role": "system", "content": "..."},
     * //   {"role": "user", "content": "Create 5 questions about Photosynthesis..."}
     * // ]
     * ```
     */
    abstract protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string;

    /**
     * Get LaTeX formatting instructions for the AI
     *
     * Hardcoded here (not in config) because ILIAS template engine
     * strips curly braces {} from config textarea values.
     */
    protected function getLatexInstructions(): string
    {
        return "\n\nFor math/formulas, use LaTeX in dollar signs (e.g. " .
            '$\frac{a}{b}$, $\sqrt{x}$, $\alpha$' .
            "). Use LaTeX commands instead of Unicode symbols for math.\n";
    }
}

