<?php
/**
 * Test script for Input Validation
 * 
 * Run from terminal:
 * docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_input_validation.php
 */

require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizException.php';
require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizXSSProtection.php';

use platform\ilMarkdownQuizXSSProtection;
use platform\ilMarkdownQuizException;

echo "========================================\n";
echo "Input Validation Test Suite\n";
echo "========================================\n\n";

$passed = 0;
$failed = 0;

// Test 1: Prompt Length Validation (max 5000 chars)
echo "Test 1: Prompt Length Validation\n";
echo "----------------------------------\n";
try {
    $valid_prompt = str_repeat("a", 5000);
    $result = ilMarkdownQuizXSSProtection::sanitizeUserInput($valid_prompt, 5000);
    echo "✓ Valid prompt (5000 chars) accepted\n";
    $passed++;
} catch (Exception $e) {
    echo "✗ Valid prompt rejected: " . $e->getMessage() . "\n";
    $failed++;
}

try {
    $invalid_prompt = str_repeat("a", 5001);
    $result = ilMarkdownQuizXSSProtection::sanitizeUserInput($invalid_prompt, 5000);
    echo "✗ Invalid prompt (5001 chars) was accepted - should have failed!\n";
    $failed++;
} catch (ilMarkdownQuizException $e) {
    echo "✓ Invalid prompt (5001 chars) rejected: " . $e->getMessage() . "\n";
    $passed++;
}
echo "\n";

// Test 2: Context Length Validation (max 10000 chars)
echo "Test 2: Context Length Validation\n";
echo "-----------------------------------\n";
try {
    $valid_context = str_repeat("b", 10000);
    $result = ilMarkdownQuizXSSProtection::sanitizeUserInput($valid_context, 10000);
    echo "✓ Valid context (10000 chars) accepted\n";
    $passed++;
} catch (Exception $e) {
    echo "✗ Valid context rejected: " . $e->getMessage() . "\n";
    $failed++;
}

try {
    $invalid_context = str_repeat("b", 10001);
    $result = ilMarkdownQuizXSSProtection::sanitizeUserInput($invalid_context, 10000);
    echo "✗ Invalid context (10001 chars) was accepted - should have failed!\n";
    $failed++;
} catch (ilMarkdownQuizException $e) {
    echo "✓ Invalid context (10001 chars) rejected: " . $e->getMessage() . "\n";
    $passed++;
}
echo "\n";

// Test 3: Difficulty Enum Validation
echo "Test 3: Difficulty Enum Validation\n";
echo "------------------------------------\n";
$valid_difficulties = ['easy', 'medium', 'hard', 'mixed'];
foreach ($valid_difficulties as $difficulty) {
    if (ilMarkdownQuizXSSProtection::validateDifficulty($difficulty)) {
        echo "✓ Valid difficulty '{$difficulty}' accepted\n";
        $passed++;
    } else {
        echo "✗ Valid difficulty '{$difficulty}' rejected\n";
        $failed++;
    }
}

$invalid_difficulties = ['Easy', 'HARD', 'simple', 'expert', 'invalid', '', 'hard; DROP TABLE users;'];
foreach ($invalid_difficulties as $difficulty) {
    if (!ilMarkdownQuizXSSProtection::validateDifficulty($difficulty)) {
        echo "✓ Invalid difficulty '{$difficulty}' rejected\n";
        $passed++;
    } else {
        echo "✗ Invalid difficulty '{$difficulty}' was accepted!\n";
        $failed++;
    }
}
echo "\n";

// Test 4: Question Count Range Validation (1-10)
echo "Test 4: Question Count Range Validation\n";
echo "-----------------------------------------\n";
$valid_counts = [1, 3, 5, 7, 10];
foreach ($valid_counts as $count) {
    if (ilMarkdownQuizXSSProtection::validateQuestionCount($count)) {
        echo "✓ Valid count {$count} accepted\n";
        $passed++;
    } else {
        echo "✗ Valid count {$count} rejected\n";
        $failed++;
    }
}

$invalid_counts = [0, -1, 11, 15, 20, 50, 999];
foreach ($invalid_counts as $count) {
    if (!ilMarkdownQuizXSSProtection::validateQuestionCount($count)) {
        echo "✓ Invalid count {$count} rejected\n";
        $passed++;
    } else {
        echo "✗ Invalid count {$count} was accepted!\n";
        $failed++;
    }
}
echo "\n";

// Test 5: Null Byte Sanitization
echo "Test 5: Null Byte Sanitization\n";
echo "--------------------------------\n";
try {
    $input_with_nulls = "Test\0input\0with\0nulls";
    $sanitized = ilMarkdownQuizXSSProtection::sanitizeUserInput($input_with_nulls, 5000);
    if (strpos($sanitized, "\0") === false) {
        echo "✓ Null bytes removed: '{$sanitized}'\n";
        $passed++;
    } else {
        echo "✗ Null bytes not removed\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 6: Whitespace Normalization
echo "Test 6: Whitespace Normalization\n";
echo "----------------------------------\n";
try {
    $input_with_whitespace = "Test   multiple    spaces\n\nand\n\nnewlines";
    $sanitized = ilMarkdownQuizXSSProtection::sanitizeUserInput($input_with_whitespace, 5000);
    if (!preg_match('/\s{2,}/', $sanitized)) {
        echo "✓ Whitespace normalized: '{$sanitized}'\n";
        $passed++;
    } else {
        echo "✗ Whitespace not properly normalized: '{$sanitized}'\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 7: HTML Escaping
echo "Test 7: HTML Escaping\n";
echo "----------------------\n";
$dangerous_inputs = [
    '<script>alert("XSS")</script>',
    '<img src=x onerror=alert(1)>',
    '"><script>alert(document.cookie)</script>',
    "' OR '1'='1",
    '<iframe src="evil.com"></iframe>'
];

foreach ($dangerous_inputs as $input) {
    $escaped = ilMarkdownQuizXSSProtection::escapeHTML($input);
    
    // Check that the output is escaped (different from input)
    // For HTML tags: < becomes &lt;, > becomes &gt;
    // For quotes: ' becomes &apos;, " becomes &quot;
    $is_escaped = ($escaped !== $input) && (
        (strpos($input, '<') !== false && strpos($escaped, '&lt;') !== false) ||
        (strpos($input, '>') !== false && strpos($escaped, '&gt;') !== false) ||
        (strpos($input, '"') !== false && strpos($escaped, '&quot;') !== false) ||
        (strpos($input, "'") !== false && strpos($escaped, '&apos;') !== false) ||
        (strpos($input, '&') !== false && strpos($escaped, '&amp;') !== false)
    );
    
    if ($is_escaped) {
        echo "✓ Dangerous input escaped: " . substr($input, 0, 30) . "...\n";
        $passed++;
    } else {
        echo "✗ Dangerous input not properly escaped: {$input}\n";
        echo "  Escaped result: {$escaped}\n";
        $failed++;
    }
}
echo "\n";

// Test 8: Question Length Validation in Markdown
echo "Test 8: Question Length Validation\n";
echo "------------------------------------\n";
$valid_question = "What is the capital of France?\n- [x] Paris\n- [ ] London\n- [ ] Berlin";
try {
    $result = ilMarkdownQuizXSSProtection::validateMarkdownStructure($valid_question);
    if ($result) {
        echo "✓ Valid question structure accepted\n";
        $passed++;
    } else {
        echo "✗ Valid question structure rejected\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $failed++;
}

$too_long_question = str_repeat("a", 501) . "?\n- [x] Answer 1\n- [ ] Answer 2";
try {
    $result = ilMarkdownQuizXSSProtection::validateMarkdownStructure($too_long_question);
    echo "✗ Too long question (>500 chars) was accepted!\n";
    $failed++;
} catch (ilMarkdownQuizException $e) {
    echo "✓ Too long question rejected: " . $e->getMessage() . "\n";
    $passed++;
}
echo "\n";

// Test 9: Option Length Validation in Markdown
echo "Test 9: Option Length Validation\n";
echo "----------------------------------\n";
$valid_option = "What is 2+2?\n- [x] " . str_repeat("Four", 1) . "\n- [ ] Five";
try {
    $result = ilMarkdownQuizXSSProtection::validateMarkdownStructure($valid_option);
    if ($result) {
        echo "✓ Valid option length accepted\n";
        $passed++;
    } else {
        echo "✗ Valid option rejected\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    $failed++;
}

$too_long_option = "What is 2+2?\n- [x] " . str_repeat("a", 301);
try {
    $result = ilMarkdownQuizXSSProtection::validateMarkdownStructure($too_long_option);
    echo "✗ Too long option (>300 chars) was accepted!\n";
    $failed++;
} catch (ilMarkdownQuizException $e) {
    echo "✓ Too long option rejected: " . $e->getMessage() . "\n";
    $passed++;
}
echo "\n";

// Test 10: Safe Data Attribute Creation
echo "Test 10: Safe Data Attribute\n";
echo "------------------------------\n";
$test_inputs = [
    'q_1' => 'q_1',
    'question_5' => 'question_5',
    '<script>alert(1)</script>' => 'scriptalert1script',
    'q\'1"2' => 'q12',
    'test-input_123' => 'testinput_123'
];

foreach ($test_inputs as $input => $expected) {
    $safe = ilMarkdownQuizXSSProtection::createSafeDataAttribute($input);
    if ($safe === $expected || preg_match('/^[a-zA-Z0-9\s\.\,\-\_]+$/', $safe)) {
        echo "✓ Safe attribute created from '{$input}': '{$safe}'\n";
        $passed++;
    } else {
        echo "✗ Unsafe attribute from '{$input}': '{$safe}'\n";
        $failed++;
    }
}
echo "\n";

echo "========================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

if ($failed === 0) {
    echo "✓ All input validation tests passed!\n";
    exit(0);
} else {
    echo "✗ Some tests failed\n";
    exit(1);
}
