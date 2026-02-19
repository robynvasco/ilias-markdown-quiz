#!/bin/bash
#
# Quick Security Test Script for MarkdownQuiz Plugin
# Tests common vulnerabilities automatically
#
# Usage: ./security_test.sh <ilias_url> <ref_id> <session_cookie>
# Example: ./security_test.sh "http://localhost/ilias" 123 "PHPSESSID=abc123"

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
ILIAS_URL="${1:-http://localhost/ilias}"
REF_ID="${2:-123}"
SESSION_COOKIE="${3:-}"

if [ -z "$SESSION_COOKIE" ]; then
    echo -e "${RED}Error: Session cookie required${NC}"
    echo "Usage: $0 <ilias_url> <ref_id> <session_cookie>"
    echo "Example: $0 'http://localhost/ilias' 123 'PHPSESSID=abc123'"
    exit 1
fi

echo "========================================="
echo "MarkdownQuiz Security Test Suite"
echo "========================================="
echo "Target: ${ILIAS_URL}"
echo "Ref ID: ${REF_ID}"
echo "========================================="
echo ""

PASSED=0
FAILED=0
TOTAL=0

# Test function
test_vulnerability() {
    local test_name="$1"
    local test_cmd="$2"
    local expected_pattern="$3"
    local should_fail="${4:-false}"

    TOTAL=$((TOTAL + 1))
    echo -n "Test $TOTAL: $test_name... "

    response=$(eval "$test_cmd" 2>&1 || true)

    if [ "$should_fail" = "true" ]; then
        # Test should fail (vulnerability not found)
        if echo "$response" | grep -qi "$expected_pattern"; then
            echo -e "${RED}FAIL${NC}"
            echo "  Expected: No match for '$expected_pattern'"
            echo "  Got: Match found (potential vulnerability)"
            FAILED=$((FAILED + 1))
        else
            echo -e "${GREEN}PASS${NC}"
            PASSED=$((PASSED + 1))
        fi
    else
        # Test should pass (protection working)
        if echo "$response" | grep -qi "$expected_pattern"; then
            echo -e "${GREEN}PASS${NC}"
            PASSED=$((PASSED + 1))
        else
            echo -e "${RED}FAIL${NC}"
            echo "  Expected: '$expected_pattern'"
            echo "  Got: $(echo "$response" | head -n 3)"
            FAILED=$((FAILED + 1))
        fi
    fi
}

echo "========================================="
echo "1. SQL Injection Tests"
echo "========================================="

# Test 1.1: SQL injection in type parameter
test_vulnerability \
    "SQL injection in type parameter" \
    "curl -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=addSampleQuestion&type=single%27%20OR%20%271%27=%271' -H 'Cookie: ${SESSION_COOKIE}'" \
    "Invalid column name" \
    false

# Test 1.2: SQL injection in markdown content
test_vulnerability \
    "SQL injection in markdown content" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}' -d 'markdown_content=test%27%20OR%20%271%27=%271&il_csrf_token=test'" \
    "Invalid security token" \
    false

echo ""
echo "========================================="
echo "2. XSS Protection Tests"
echo "========================================="

# Test 2.1: Script tag in markdown
test_vulnerability \
    "Script tag sanitization" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}' -d 'markdown_content=<script>alert(1)</script>&il_csrf_token=test'" \
    "<script>" \
    true

# Test 2.2: Event handler in prompt
test_vulnerability \
    "Event handler sanitization" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=generate' -H 'Cookie: ${SESSION_COOKIE}' -d 'prompt=<img src=x onerror=alert(1)>&difficulty=medium&question_count=5'" \
    "onerror" \
    true

echo ""
echo "========================================="
echo "3. CSRF Protection Tests"
echo "========================================="

# Test 3.1: Missing CSRF token
test_vulnerability \
    "CSRF token validation" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}' -d 'markdown_content=test'" \
    "Invalid security token" \
    false

# Test 3.2: Invalid CSRF token
test_vulnerability \
    "Invalid CSRF token rejection" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}' -d 'markdown_content=test&il_csrf_token=invalid'" \
    "Invalid security token" \
    false

echo ""
echo "========================================="
echo "4. Input Validation Tests"
echo "========================================="

# Test 4.1: Invalid difficulty
test_vulnerability \
    "Difficulty validation" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=generate' -H 'Cookie: ${SESSION_COOKIE}' -d 'prompt=test&difficulty=invalid&question_count=5'" \
    "Invalid difficulty" \
    false

# Test 4.2: Invalid question count (too high)
test_vulnerability \
    "Question count upper limit" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=generate' -H 'Cookie: ${SESSION_COOKIE}' -d 'prompt=test&difficulty=medium&question_count=999'" \
    "Invalid question count\|must be between" \
    false

# Test 4.3: Negative question count
test_vulnerability \
    "Question count lower limit" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=generate' -H 'Cookie: ${SESSION_COOKIE}' -d 'prompt=test&difficulty=medium&question_count=-1'" \
    "Invalid question count\|must be between" \
    false

echo ""
echo "========================================="
echo "5. Security Headers Tests"
echo "========================================="

# Test 5.1: CSP header
test_vulnerability \
    "Content-Security-Policy header" \
    "curl -I -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}'" \
    "Content-Security-Policy" \
    false

# Test 5.2: X-Content-Type-Options
test_vulnerability \
    "X-Content-Type-Options header" \
    "curl -I -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}'" \
    "X-Content-Type-Options: nosniff" \
    false

# Test 5.3: X-Frame-Options
test_vulnerability \
    "X-Frame-Options header" \
    "curl -I -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}'" \
    "X-Frame-Options" \
    false

echo ""
echo "========================================="
echo "6. Information Disclosure Tests"
echo "========================================="

# Test 6.1: No debug information
test_vulnerability \
    "No debug information leak" \
    "curl -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}'" \
    "var_dump\|print_r\|DEBUG\|error_log" \
    true

# Test 6.2: No stack traces
test_vulnerability \
    "No stack traces in errors" \
    "curl -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=invalidCommand' -H 'Cookie: ${SESSION_COOKIE}'" \
    "Fatal error\|Call Stack\|in /var/www" \
    true

# Test 6.3: No database errors
test_vulnerability \
    "No database error messages" \
    "curl -s -X POST '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions' -H 'Cookie: ${SESSION_COOKIE}' -d 'invalid_data=1'" \
    "mysql\|SELECT \*\|Table.*doesn't exist" \
    true

echo ""
echo "========================================="
echo "7. Path Traversal Tests"
echo "========================================="

# Test 7.1: Path traversal in file parameter
test_vulnerability \
    "Path traversal protection" \
    "curl -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&file=../../../../etc/passwd' -H 'Cookie: ${SESSION_COOKIE}'" \
    "root:x:0:0" \
    true

# Test 7.2: Null byte injection
test_vulnerability \
    "Null byte injection protection" \
    "curl -s '${ILIAS_URL}/ilias.php?ref_id=${REF_ID}&cmd=editQuestions&file=test.php%00.txt' -H 'Cookie: ${SESSION_COOKIE}'" \
    "<?php" \
    true

echo ""
echo "========================================="
echo "Test Results Summary"
echo "========================================="
echo -e "Total Tests: ${TOTAL}"
echo -e "${GREEN}Passed: ${PASSED}${NC}"
echo -e "${RED}Failed: ${FAILED}${NC}"
echo "========================================="

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All security tests passed!${NC}"
    echo -e "${GREEN}The plugin appears to be secure.${NC}"
    exit 0
else
    echo -e "${RED}✗ Some security tests failed!${NC}"
    echo -e "${RED}Please review the failed tests above.${NC}"
    exit 1
fi
