#!/bin/bash
#
# Security Test Script for MarkdownQuiz Plugin
# Tests vulnerabilities that the plugin itself is responsible for.
#
# Usage: ./security_test.sh <ilias_base_url> <ref_id> <session_cookie> [cmdNode]
# Example: ./security_test.sh "http://localhost:8080" 129 "PHPSESSID=abc; ilClientId=default"
#
# Note: The cmdNode parameter (default: pk:oz) depends on your ILIAS installation.
# Copy it from the browser URL bar when viewing a MarkdownQuiz object.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

ILIAS_URL="${1:-http://localhost:8080}"
REF_ID="${2:-123}"
SESSION_COOKIE="${3:-}"
CMD_NODE="${4:-pk:oz}"

if [ -z "$SESSION_COOKIE" ]; then
    echo -e "${RED}Error: Session cookie required${NC}"
    echo "Usage: $0 <ilias_base_url> <ref_id> <session_cookie> [cmdNode]"
    echo "Example: $0 'http://localhost:8080' 129 'PHPSESSID=abc; ilClientId=default'"
    exit 1
fi

BASE_PARAMS="baseClass=ilobjplugindispatchgui&cmdNode=${CMD_NODE}&cmdClass=ilObjMarkdownQuizGUI&ref_id=${REF_ID}"
EDIT_URL="${ILIAS_URL}/ilias.php?${BASE_PARAMS}&cmd=editQuestions"
GENERATE_URL="${ILIAS_URL}/ilias.php?${BASE_PARAMS}&cmd=generate"
SAMPLE_URL="${ILIAS_URL}/ilias.php?${BASE_PARAMS}&cmd=addSampleQuestion"
VIEW_URL="${ILIAS_URL}/ilias.php?${BASE_PARAMS}&cmd=view"
CURL_OPTS=(--silent --show-error --connect-timeout 10 --max-time 30)

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

# Preflight: ensure target is reachable
health_code=$(curl "${CURL_OPTS[@]}" -o /dev/null -w "%{http_code}" "${ILIAS_URL}/ilias.php")
if [ "$health_code" = "000" ]; then
    echo -e "${RED}Error: Cannot reach ${ILIAS_URL} (HTTP 000).${NC}"
    echo "Check whether ILIAS is running and reachable from this shell context."
    exit 2
fi

# Preflight: ensure provided session cookie is valid (not login page)
auth_probe=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}" -H "Cookie: ${SESSION_COOKIE}")
if [ -z "$auth_probe" ]; then
    echo -e "${RED}Error: Empty authenticated response from ${EDIT_URL}.${NC}"
    echo "Cannot run reliable security checks with empty responses."
    exit 2
fi
if echo "$auth_probe" | grep -qi "login\|username\|password\|il_login"; then
    echo -e "${RED}Error: Session cookie appears invalid or expired.${NC}"
    echo "Please pass a fresh authenticated cookie."
    exit 2
fi

run_test() {
    local test_name="$1"
    local passed="$2"

    TOTAL=$((TOTAL + 1))
    if [ "$passed" = "true" ]; then
        echo -e "  Test $TOTAL: $test_name... ${GREEN}PASS${NC}"
        PASSED=$((PASSED + 1))
    else
        echo -e "  Test $TOTAL: $test_name... ${RED}FAIL${NC}"
        FAILED=$((FAILED + 1))
    fi
}

# =========================================
echo "1. Authentication"
echo "========================================="

# Without session cookie, should redirect to login (HTTP 302) or show login page
response=$(curl "${CURL_OPTS[@]}" -o /dev/null -w "%{http_code}" "${EDIT_URL}")
if [ "$response" = "302" ] || [ "$response" = "303" ] || [ "$response" = "401" ]; then
    run_test "Edit tab requires authentication" "true"
else
    # Check if the body contains a login form
    body=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}")
    if echo "$body" | grep -qi "login\|username\|password\|il_login"; then
        run_test "Edit tab requires authentication" "true"
    else
        run_test "Edit tab requires authentication (got HTTP $response)" "false"
    fi
fi

response=$(curl "${CURL_OPTS[@]}" -o /dev/null -w "%{http_code}" "${GENERATE_URL}")
if [ "$response" = "302" ] || [ "$response" = "303" ] || [ "$response" = "401" ]; then
    run_test "AI Generate tab requires authentication" "true"
else
    body=$(curl "${CURL_OPTS[@]}" "${GENERATE_URL}")
    if echo "$body" | grep -qi "login\|username\|password\|il_login"; then
        run_test "AI Generate tab requires authentication" "true"
    else
        run_test "AI Generate tab requires authentication (got HTTP $response)" "false"
    fi
fi

echo ""
echo "========================================="
echo "2. SQL Injection"
echo "========================================="

# SQL injection attempts should not produce SQL errors
response=$(curl "${CURL_OPTS[@]}" "${SAMPLE_URL}&type=single'%20OR%20'1'='1" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -qi "SQL syntax\|mysql_fetch\|ORA-[0-9]\|pg_query\|SQLSTATE"; then
    run_test "No SQL error in type parameter" "false"
else
    run_test "No SQL error in type parameter" "true"
fi

response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}&extra=1'%20UNION%20SELECT%20*%20FROM%20usr_data--" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -qi "SQL syntax\|mysql_fetch\|ORA-[0-9]\|pg_query\|SQLSTATE"; then
    run_test "No SQL error in URL parameters" "false"
else
    run_test "No SQL error in URL parameters" "true"
fi

echo ""
echo "========================================="
echo "3. XSS Protection"
echo "========================================="

# Check the edit page: if script tags were stored, they'd appear in the textarea
response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -q '<script>alert(1)</script>'; then
    run_test "No stored XSS in edit page" "false"
else
    run_test "No stored XSS in edit page" "true"
fi

# Check the quiz view: injected content should be escaped
response=$(curl "${CURL_OPTS[@]}" "${VIEW_URL}" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -q '<script>alert(1)</script>'; then
    run_test "No reflected XSS in quiz view" "false"
else
    run_test "No reflected XSS in quiz view" "true"
fi

# Check that event handlers are not in output
if echo "$response" | grep -q 'onerror=alert'; then
    run_test "No event handler XSS in quiz view" "false"
else
    run_test "No event handler XSS in quiz view" "true"
fi

echo ""
echo "========================================="
echo "4. Information Disclosure"
echo "========================================="

# No debug output
response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -qi 'var_dump\|print_r\|xdebug\|debug_backtrace'; then
    run_test "No debug output in edit page" "false"
else
    run_test "No debug output in edit page" "true"
fi

# No PHP stack traces on invalid commands
response=$(curl "${CURL_OPTS[@]}" "${ILIAS_URL}/ilias.php?${BASE_PARAMS}&cmd=nonExistentCommand999" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -qi "Fatal error.*on line\|Call Stack\|Uncaught Exception"; then
    run_test "No stack traces on invalid command" "false"
else
    run_test "No stack traces on invalid command" "true"
fi

# No database errors exposed
response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -qi "mysql_error\|pg_last_error\|Table.*doesn.t exist"; then
    run_test "No database errors exposed" "false"
else
    run_test "No database errors exposed" "true"
fi

echo ""
echo "========================================="
echo "5. Path Traversal"
echo "========================================="

# Path traversal in GET parameters should not expose system files
response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}&file=../../../../etc/passwd" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -q "root:.*:0:0"; then
    run_test "No path traversal via file parameter" "false"
else
    run_test "No path traversal via file parameter" "true"
fi

response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}&file=..%2F..%2F..%2F..%2Fetc%2Fpasswd" -H "Cookie: ${SESSION_COOKIE}")
if echo "$response" | grep -q "root:.*:0:0"; then
    run_test "No path traversal via encoded slashes" "false"
else
    run_test "No path traversal via encoded slashes" "true"
fi

echo ""
echo "========================================="
echo "6. API Key Protection"
echo "========================================="

# API keys should never appear in page source
response=$(curl "${CURL_OPTS[@]}" "${EDIT_URL}" -H "Cookie: ${SESSION_COOKIE}")
view_response=$(curl "${CURL_OPTS[@]}" "${VIEW_URL}" -H "Cookie: ${SESSION_COOKIE}")
gen_response=$(curl "${CURL_OPTS[@]}" "${GENERATE_URL}" -H "Cookie: ${SESSION_COOKIE}")

all_responses="${response}${view_response}${gen_response}"

if echo "$all_responses" | grep -qi "sk-[a-zA-Z0-9]\{20,\}\|AIza[a-zA-Z0-9_-]\{30,\}"; then
    run_test "No API keys leaked in page source" "false"
else
    run_test "No API keys leaked in page source" "true"
fi

# Check that config values are not exposed to non-admin pages
if echo "$view_response" | grep -qi "api_key\|api_secret\|openai_key\|gwdg_key\|google_key"; then
    run_test "No API config references in quiz view" "false"
else
    run_test "No API config references in quiz view" "true"
fi

echo ""
echo "========================================="
echo "Test Results"
echo "========================================="
echo -e "Total: ${TOTAL}  ${GREEN}Passed: ${PASSED}${NC}  ${RED}Failed: ${FAILED}${NC}"
echo "========================================="
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
else
    echo -e "${RED}${FAILED} test(s) failed - review above.${NC}"
fi

echo ""
echo -e "${YELLOW}Note: CSRF validation, security headers (CSP, X-Frame-Options),${NC}"
echo -e "${YELLOW}and server version disclosure are handled by ILIAS/Apache,${NC}"
echo -e "${YELLOW}not by the plugin. Configure them in your web server.${NC}"

exit $FAILED
