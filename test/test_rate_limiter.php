<?php
/**
 * Test script for ilMarkdownQuizRateLimiter
 * 
 * Run from terminal:
 * docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_rate_limiter.php
 */

require_once __DIR__ . '/../classes/platform/class.ilMarkdownQuizRateLimiter.php';

use platform\ilMarkdownQuizRateLimiter;

echo "========================================\n";
echo "Rate Limiter Test Suite\n";
echo "========================================\n\n";

// Reset all limits to start fresh
ilMarkdownQuizRateLimiter::resetAll();
echo "✓ Reset all rate limits\n\n";

// Test 1: Initial Status
echo "Test 1: Initial Status\n";
echo "------------------------\n";
$status = ilMarkdownQuizRateLimiter::getStatus();
echo "API Calls: {$status['api_calls']['used']}/{$status['api_calls']['limit']}\n";
echo "File Processing: {$status['file_processing']['used']}/{$status['file_processing']['limit']}\n";
echo "Quiz Generation: " . ($status['quiz_generation']['can_generate'] ? "Ready" : "In cooldown") . "\n\n";

// Test 2: API Call Limits
echo "Test 2: API Call Limits\n";
echo "------------------------\n";
try {
    for ($i = 1; $i <= 3; $i++) {
        ilMarkdownQuizRateLimiter::recordApiCall();
        echo "✓ API call #{$i} recorded\n";
    }
    $status = ilMarkdownQuizRateLimiter::getStatus();
    echo "API Calls used: {$status['api_calls']['used']}/{$status['api_calls']['limit']}\n";
    echo "Remaining: {$status['api_calls']['remaining']}\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: File Processing Limits
echo "Test 3: File Processing Limits\n";
echo "--------------------------------\n";
try {
    for ($i = 1; $i <= 2; $i++) {
        ilMarkdownQuizRateLimiter::recordFileProcessing();
        echo "✓ File processing #{$i} recorded\n";
    }
    $status = ilMarkdownQuizRateLimiter::getStatus();
    echo "File Processing used: {$status['file_processing']['used']}/{$status['file_processing']['limit']}\n";
    echo "Remaining: {$status['file_processing']['remaining']}\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Quiz Generation Cooldown
echo "Test 4: Quiz Generation Cooldown\n";
echo "----------------------------------\n";
try {
    ilMarkdownQuizRateLimiter::recordQuizGeneration();
    echo "✓ Quiz generation #1 recorded\n";
    
    // Try immediate second generation (should fail)
    try {
        ilMarkdownQuizRateLimiter::recordQuizGeneration();
        echo "✗ Should have failed due to cooldown!\n";
    } catch (Exception $e) {
        echo "✓ Cooldown enforced: " . $e->getMessage() . "\n";
    }
    
    $remaining = ilMarkdownQuizRateLimiter::getQuizGenerationCooldownRemaining();
    echo "Cooldown remaining: {$remaining} seconds\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Concurrent Request Limits
echo "Test 5: Concurrent Request Limits\n";
echo "-----------------------------------\n";
try {
    for ($i = 1; $i <= 3; $i++) {
        ilMarkdownQuizRateLimiter::incrementConcurrent();
        echo "✓ Concurrent request #{$i} started\n";
    }
    
    $status = ilMarkdownQuizRateLimiter::getStatus();
    echo "Current concurrent requests: {$status['concurrent']['current']}/{$status['concurrent']['limit']}\n";
    
    // Try exceeding limit
    try {
        ilMarkdownQuizRateLimiter::incrementConcurrent();
        echo "✗ Should have failed due to concurrent limit!\n";
    } catch (Exception $e) {
        echo "✓ Concurrent limit enforced: " . $e->getMessage() . "\n";
    }
    
    // Clean up
    for ($i = 1; $i <= 3; $i++) {
        ilMarkdownQuizRateLimiter::decrementConcurrent();
    }
    echo "✓ All concurrent requests completed\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Exceeding API Limit
echo "Test 6: Exceeding API Limit\n";
echo "-----------------------------\n";
try {
    // We already have 3 API calls from Test 2
    // Add 17 more to reach the limit of 20
    for ($i = 4; $i <= 20; $i++) {
        ilMarkdownQuizRateLimiter::recordApiCall();
    }
    echo "✓ Recorded 20 API calls (limit reached)\n";
    
    // Try one more (should fail)
    try {
        ilMarkdownQuizRateLimiter::recordApiCall();
        echo "✗ Should have failed - API limit exceeded!\n";
    } catch (Exception $e) {
        echo "✓ API limit enforced: " . $e->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "Note: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 7: Final Status
echo "Test 7: Final Status\n";
echo "---------------------\n";
$status = ilMarkdownQuizRateLimiter::getStatus();
echo "API Calls: {$status['api_calls']['used']}/{$status['api_calls']['limit']} ";
echo "(Reset in {$status['api_calls']['reset_in_minutes']} minutes)\n";
echo "File Processing: {$status['file_processing']['used']}/{$status['file_processing']['limit']} ";
echo "(Reset in {$status['file_processing']['reset_in_minutes']} minutes)\n";
echo "Quiz Generation: " . ($status['quiz_generation']['can_generate'] ? "Ready" : "Wait {$status['quiz_generation']['remaining_seconds']}s") . "\n";
echo "Concurrent: {$status['concurrent']['current']}/{$status['concurrent']['limit']}\n\n";

// Cleanup
ilMarkdownQuizRateLimiter::resetAll();
echo "✓ Cleaned up - all limits reset\n\n";

echo "========================================\n";
echo "All tests completed!\n";
echo "========================================\n";
