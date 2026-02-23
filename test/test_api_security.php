<?php
/**
 * API Security Test Script
 * Tests circuit breaker, response validation, request signing
 * 
 * Run: docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownQuiz/test/test_api_security.php
 */

// Start session for circuit breaker
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../classes/security/class.ilMarkdownQuizCircuitBreaker.php';
require_once __DIR__ . '/../classes/security/class.ilMarkdownQuizResponseValidator.php';
require_once __DIR__ . '/../classes/security/class.ilMarkdownQuizRequestSigner.php';
require_once __DIR__ . '/../classes/security/class.ilMarkdownQuizCertificatePinner.php';

use security\ilMarkdownQuizCircuitBreaker;
use security\ilMarkdownQuizResponseValidator;
use security\ilMarkdownQuizRequestSigner;
use security\ilMarkdownQuizCertificatePinner;

echo "=== API Security Test Suite ===\n\n";

// Test 1: Circuit Breaker
echo "TEST 1: Circuit Breaker\n";
echo "------------------------\n";

// Reset circuit breaker
ilMarkdownQuizCircuitBreaker::resetAll();

// Record some successes
echo "Recording 3 successful API calls...\n";
for ($i = 0; $i < 3; $i++) {
    ilMarkdownQuizCircuitBreaker::recordSuccess('openai');
}
echo "✓ Successes recorded\n";

// Record failures to trigger circuit breaker
echo "\nRecording 5 failures to open circuit...\n";
for ($i = 0; $i < 5; $i++) {
    ilMarkdownQuizCircuitBreaker::recordFailure('openai');
}
echo "✓ Failures recorded\n";

// Try to check availability (should fail)
try {
    ilMarkdownQuizCircuitBreaker::checkAvailability('openai');
    echo "✗ Circuit should be open!\n";
} catch (\Exception $e) {
    echo "✓ Circuit breaker opened: " . $e->getMessage() . "\n";
}

$status = ilMarkdownQuizCircuitBreaker::getStatus();
echo "\nCircuit Breaker Status:\n";
print_r($status);

// Test 2: Response Validation
echo "\n\nTEST 2: Response Validation\n";
echo "----------------------------\n";

// Valid OpenAI Responses API response
$validOpenAI = [
    'output' => [
        [
            'type' => 'message',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => "What is PHP?\n- [x] A programming language\n- [ ] A database\n- [ ] An operating system\n- [ ] A web browser"
                ]
            ]
        ]
    ]
];

try {
    ilMarkdownQuizResponseValidator::validateOpenAIResponse($validOpenAI);
    echo "✓ Valid OpenAI response accepted\n";
} catch (\Exception $e) {
    echo "✗ Valid response rejected: " . $e->getMessage() . "\n";
}

// Invalid OpenAI response (missing output)
$invalidOpenAI = [
    'choices' => []
];

try {
    ilMarkdownQuizResponseValidator::validateOpenAIResponse($invalidOpenAI);
    echo "✗ Invalid response accepted (should have failed)\n";
} catch (\Exception $e) {
    echo "✓ Invalid response rejected: " . $e->getMessage() . "\n";
}

// Valid Google AI response
$validGoogle = [
    'candidates' => [
        [
            'content' => [
                'parts' => [
                    ['text' => "What is PHP?\n- [x] A programming language\n- [ ] A database\n- [ ] An operating system\n- [ ] A web browser"]
                ]
            ]
        ]
    ]
];

try {
    ilMarkdownQuizResponseValidator::validateGoogleResponse($validGoogle);
    echo "✓ Valid Google response accepted\n";
} catch (\Exception $e) {
    echo "✗ Valid response rejected: " . $e->getMessage() . "\n";
}

// Test 3: Response Content Safety
echo "\n\nTEST 3: Response Content Safety\n";
echo "--------------------------------\n";

// Response with script tag
$maliciousResponse = [
    'output' => [
        [
            'type' => 'message',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => "What is XSS?<script>alert('XSS')</script>\n- [x] Cross-site scripting\n- [ ] A database\n- [ ] An operating system\n- [ ] A web browser"
                ]
            ]
        ]
    ]
];

try {
    ilMarkdownQuizResponseValidator::validateOpenAIResponse($maliciousResponse);
    echo "✗ Malicious response accepted (should have failed)\n";
} catch (\Exception $e) {
    echo "✓ Malicious response blocked: " . $e->getMessage() . "\n";
}

// Test 4: Markdown Quiz Format Validation
echo "\n\nTEST 4: Markdown Quiz Format Validation\n";
echo "----------------------------------------\n";

$validQuiz = "What is PHP?\n- [x] A programming language\n- [ ] A database\n- [ ] An operating system\n- [ ] A web browser\n\nWhat is MySQL?\n- [ ] A programming language\n- [x] A database\n- [ ] An operating system\n- [ ] A web browser";

try {
    $questions = ilMarkdownQuizResponseValidator::validateMarkdownQuizFormat($validQuiz);
    echo "✓ Valid quiz format accepted (" . count($questions) . " questions)\n";
} catch (\Exception $e) {
    echo "✗ Valid quiz rejected: " . $e->getMessage() . "\n";
}

// Invalid quiz (missing question mark)
$invalidQuiz = "What is PHP\n- [x] A programming language\n- [ ] A database\n- [ ] An operating system\n- [ ] A web browser";

try {
    ilMarkdownQuizResponseValidator::validateMarkdownQuizFormat($invalidQuiz);
    echo "✗ Invalid quiz accepted (should have failed)\n";
} catch (\Exception $e) {
    echo "✓ Invalid quiz rejected: " . $e->getMessage() . "\n";
}

// Test 5: Request Signing
echo "\n\nTEST 5: Request Signing\n";
echo "------------------------\n";

$payload = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'test']]];
$apiKey = 'test-api-key-12345';

$signature = ilMarkdownQuizRequestSigner::signRequest('openai', $payload, $apiKey);
echo "✓ Request signature generated: " . substr($signature, 0, 40) . "...\n";

$isValid = ilMarkdownQuizRequestSigner::verifySignature('openai', $payload, $signature, $apiKey);
if ($isValid) {
    echo "✓ Signature verification successful\n";
} else {
    echo "✗ Signature verification failed\n";
}

// Test with wrong key
$isValid = ilMarkdownQuizRequestSigner::verifySignature('openai', $payload, $signature, 'wrong-key');
if (!$isValid) {
    echo "✓ Tampered signature correctly rejected\n";
} else {
    echo "✗ Tampered signature accepted (security violation!)\n";
}

// Test 6: Request Metadata
echo "\n\nTEST 6: Request Metadata\n";
echo "------------------------\n";

$metadata = ilMarkdownQuizRequestSigner::createRequestMetadata('openai');
echo "✓ Request metadata created:\n";
echo "  Request ID: " . $metadata['request_id'] . "\n";
echo "  Timestamp: " . $metadata['timestamp'] . "\n";
echo "  Service: " . $metadata['service'] . "\n";

echo "\n=== All Tests Complete ===\n";
