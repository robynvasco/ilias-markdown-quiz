<?php
declare(strict_types=1);

namespace ai;

use platform\ilMarkdownQuizException;

/**
 * Base class for LLM providers
 */
abstract class ilMarkdownQuizLLM
{
    /**
     * Generate a quiz in markdown format
     * @throws ilMarkdownQuizException
     */
    abstract public function generateQuiz(string $user_prompt, string $difficulty, int $question_count): string;

    /**
     * Build the prompt with system message and user content
     */
    abstract protected function buildPrompt(string $user_prompt, string $difficulty, int $question_count): string;
}
