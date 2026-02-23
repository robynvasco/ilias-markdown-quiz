<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizException;

/**
 * Abstract base class for all LLM providers.
 *
 * Implemented by OpenAI, Google and GWDG provider classes.
 */
abstract class ilMarkdownQuizLLM
{
    /**
     * Generate a markdown quiz using the provider API.
     *
     * @param string $user_prompt User topic/prompt
     * @param string $difficulty Difficulty enum
     * @param int $question_count Number of questions (1-10)
     * @throws ilMarkdownQuizException On API/validation errors
     */
    abstract public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string;

    /**
     * Build provider-specific prompt payload.
     */
    abstract protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string;

    /**
     * Get LaTeX formatting instructions added to prompts.
     */
    protected function getLatexInstructions(): string
    {
        return "\n\nFor math/formulas, use LaTeX in dollar signs (e.g. " .
            '$\frac{a}{b}$, $\sqrt{x}$, $\alpha$' .
            "). Use LaTeX commands instead of Unicode symbols for math.\n";
    }
}
