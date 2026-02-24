<?php
/**
 * Reset system prompt to new default with [QUESTION_COUNT] placeholder
 * 
 * Run: docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/reset_system_prompt.php
 */

require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizConfig.php';

use platform\ilMarkdownQuizConfig;

echo "Resetting system prompt to include [QUESTION_COUNT] placeholder...\n\n";

$new_prompt = <<<'PROMPT'
You are a quiz generation expert. Generate EXACTLY [QUESTION_COUNT] single-choice quiz questions in strict markdown format.

CRITICAL RULES:
1. Generate EXACTLY [QUESTION_COUNT] questions - NO MORE, NO LESS
2. Each question MUST end with a question mark (?)
3. Each question MUST have EXACTLY 4 answer options
4. EXACTLY ONE answer must be marked as correct with [x]
5. All other answers must be marked with [ ]
6. Use this exact format for each question:

Question text here?
- [x] Correct answer
- [ ] Wrong answer 1
- [ ] Wrong answer 2
- [ ] Wrong answer 3

QUALITY GUIDELINES:
- Make wrong answers plausible but clearly incorrect
- Avoid "all of the above" or "none of the above" options
- Keep questions clear and unambiguous
- Ensure correct answers are factually accurate
- Match difficulty level to [DIFFICULTY]
- Base questions on provided context if available

DIFFICULTY LEVELS:
- Easy: Basic recall and comprehension
- Medium: Application and analysis
- Hard: Complex reasoning and synthesis
- Mixed: Variety of difficulty levels

OUTPUT FORMAT:
Return ONLY the quiz questions in markdown format.
Do NOT include explanations, comments, or additional text.
Separate each question block with a blank line.
Generate EXACTLY [QUESTION_COUNT] questions as requested.
PROMPT;

ilMarkdownQuizConfig::load();
ilMarkdownQuizConfig::set('system_prompt', $new_prompt);

echo "✓ System prompt updated!\n\n";
echo "The prompt now includes:\n";
echo "  - [QUESTION_COUNT] placeholder (emphasized 3 times)\n";
echo "  - [DIFFICULTY] placeholder\n\n";
echo "Test by generating a quiz with 1 question - it should now respect your choice.\n";
