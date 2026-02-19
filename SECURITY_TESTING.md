# Security Testing Guide for MarkdownQuiz Plugin

## Overview
This guide provides step-by-step instructions for penetration testing the MarkdownQuiz plugin to verify all security fixes are effective.

## Testing Tools

### 1. OWASP ZAP (Recommended)
- **Download**: https://www.zaproxy.org/download/
- **Type**: Free, Open Source
- **Best for**: Automated scanning, manual testing, AJAX spidering

### 2. Burp Suite Community Edition
- **Download**: https://portswigger.net/burp/communitydownload
- **Type**: Free (Community Edition)
- **Best for**: Manual testing, intercepting requests, fuzzing

### 3. SQLMap
- **Download**: https://sqlmap.org/
- **Type**: Free, Open Source, Command-line
- **Best for**: SQL injection testing

### 4. XSStrike
- **Download**: https://github.com/s0md3v/XSStrike
- **Type**: Free, Open Source, Python
- **Best for**: XSS vulnerability detection

---

## Pre-Testing Setup

### 1. Create Test Environment
```bash
# Ensure you're testing on a non-production instance
# Never test on live production systems!

# Create a test quiz instance in ILIAS
# URL will be something like:
# http://localhost/ilias/goto.php?target=xmdq_123&client_id=default
```

### 2. Enable Error Logging
```php
// Temporarily enable detailed error logging for testing
// In ILIAS: Administration > System Settings > Server > Error Handling
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

### 3. Backup Database
```bash
# Before testing, backup your test database
mysqldump -u root -p ilias_test > ilias_backup_$(date +%Y%m%d).sql
```

---

## Test Cases

## 1. SQL Injection Testing

### Automated Testing with SQLMap

```bash
# Test the Edit Questions form (requires authenticated session)
sqlmap -u "http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions" \
  --cookie="PHPSESSID=your_session_id" \
  --data="markdown_content=test&il_csrf_token=token" \
  --level=5 --risk=3 \
  --dbms=mysql \
  --batch

# Test AI generation endpoint
sqlmap -u "http://localhost/ilias/ilias.php?ref_id=123&cmd=generate" \
  --cookie="PHPSESSID=your_session_id" \
  --data="prompt=test&difficulty=medium&question_count=5" \
  --level=5 --risk=3
```

### Manual SQL Injection Tests

**Test 1: Edit Questions Form**
```
Payload: ' OR '1'='1
Expected: Should be escaped, no SQL error
Location: Edit Questions textarea
```

**Test 2: Sample Question Type Parameter**
```
URL: ilias.php?cmd=addSampleQuestion&type=single' OR '1'='1
Expected: Type validation should reject, defaults to 'single'
```

**Test 3: Database Class Direct Test**
Create test script:
```php
<?php
// File: test_sql_injection.php
require_once './classes/platform/class.ilMarkdownQuizDatabase.php';

$db = new platform\ilMarkdownQuizDatabase();

// Test 1: Invalid column name
try {
    $db->select('xmdq_config', ['name\' OR \'1\'=\'1' => 'test']);
    echo "FAIL: SQL injection possible\n";
} catch (Exception $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

// Test 2: Extra parameter injection
try {
    $db->select('xmdq_config', null, null, 'ORDER BY id; DROP TABLE xmdq_config;--');
    echo "FAIL: Extra SQL injection possible\n";
} catch (Exception $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
```

**Expected Results**: All tests should throw `ilMarkdownQuizException` with "Invalid column name" or "deprecated for security reasons"

---

## 2. XSS (Cross-Site Scripting) Testing

### Using OWASP ZAP

1. **Configure ZAP**:
   - Start ZAP
   - Set browser proxy to 127.0.0.1:8080
   - Navigate to your ILIAS instance
   - Login and navigate to MarkdownQuiz

2. **Active Scan**:
   - Right-click on MarkdownQuiz pages in Sites tree
   - Select "Attack" > "Active Scan"
   - Enable XSS scan rules
   - Start scan

### Manual XSS Tests

**Test 1: Markdown Content (Reflected XSS)**
```javascript
// In Edit Questions textarea, paste:
<script>alert('XSS')</script>
<img src=x onerror=alert('XSS')>
javascript:alert('XSS')
<svg onload=alert('XSS')>
```
**Expected**: All stripped/escaped, no alert box

**Test 2: Question Text (Stored XSS)**
```
Question with <script>alert(document.cookie)</script>?
- [ ] Answer with <img src=x onerror=alert('XSS')>
- [x] Safe answer
```
**Expected**: Script tags removed, HTML escaped

**Test 3: AI Generation Prompt (DOM-based XSS)**
```javascript
// In prompt field:
"><script>alert('XSS')</script>
<iframe src="javascript:alert('XSS')">
```
**Expected**: Sanitized via ilMarkdownQuizXSSProtection::sanitizeUserInput()

**Test 4: File Upload (if applicable)**
```html
<!-- Upload HTML file with embedded script -->
<!DOCTYPE html>
<html><body><script>alert('XSS')</script></body></html>
```
**Expected**: File rejected or content sanitized

---

## 3. CSRF (Cross-Site Request Forgery) Testing

### Manual CSRF Test

**Test 1: Edit Questions Form**

Create external HTML file:
```html
<!-- csrf_test.html -->
<!DOCTYPE html>
<html>
<body onload="document.forms[0].submit()">
<form action="http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions" method="POST">
  <input type="hidden" name="markdown_content" value="Hacked content?
- [ ] Wrong
- [x] Right">
</form>
</body>
</html>
```

1. Login to ILIAS in one browser tab
2. Open `csrf_test.html` in another tab
3. Check if markdown content was changed

**Expected**: Form submission should FAIL with "Invalid security token" message

**Test 2: Verify Token Validation**
```bash
# Using curl (replace with your session cookie and ref_id)
curl -X POST 'http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions' \
  -H 'Cookie: PHPSESSID=your_session_id' \
  -d 'markdown_content=Test without token'
```
**Expected**: Error message about invalid/missing CSRF token

---

## 4. Authentication & Authorization Testing

### Test Unauthorized Access

**Test 1: Anonymous Access**
```bash
# Logout, then try to access:
http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions
http://localhost/ilias/ilias.php?ref_id=123&cmd=generate
http://localhost/ilias/ilias.php?ref_id=123&cmd=settings
```
**Expected**: Redirect to login or "Permission denied"

**Test 2: Reader Role Access**
```
1. Login as user with "Read" permission only
2. Try to access:
   - Edit Questions tab
   - Settings tab
   - AI Generate tab
```
**Expected**: Tabs not visible, direct access blocked with permission error

**Test 3: Permission Bypass Attempt**
```bash
# Try to bypass checkPermission() by manipulating URL
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions&permission=write' \
  -H 'Cookie: PHPSESSID=reader_session'
```
**Expected**: Access denied despite URL parameter

---

## 5. Input Validation Testing

### Fuzzing with Burp Suite

1. **Setup**:
   - Capture POST request to `editQuestions`
   - Send to Intruder
   - Set payload position on `markdown_content`

2. **Payloads**:
   - Very long strings (>10,000 chars)
   - Null bytes: `\x00`
   - Unicode: `\u0000`, `\uFEFF`
   - Special chars: `%00`, `%0a`, `%0d`
   - SQL keywords: `SELECT`, `DROP`, `UNION`

3. **Expected Results**:
   - Length limit enforced (max 10,000 chars)
   - Null bytes removed
   - SQL keywords escaped/sanitized

### Manual Boundary Tests

**Test 1: Question Count Validation**
```
Valid: 1-20
Invalid tests: 0, -1, 21, 999, 'abc', null
```

**Test 2: Difficulty Validation**
```
Valid: easy, medium, hard, mixed
Invalid tests: 'invalid', '', '<script>', '../../etc/passwd'
```

**Test 3: File Size Limits**
```
Upload files of varying sizes to test limits:
- 1 KB (should work)
- 5 MB (within limit)
- 11 MB (should be rejected - limit is 10 MB)
- 100 MB (should be rejected)
```

---

## 6. API Security Testing

### Rate Limiting

**Test 1: AI Generation Rate Limit**
```bash
# Send 20 requests rapidly
for i in {1..20}; do
  curl -X POST 'http://localhost/ilias/ilias.php?ref_id=123&cmd=generate' \
    -H 'Cookie: PHPSESSID=your_session' \
    -d 'prompt=test&difficulty=medium&question_count=5' &
done
wait
```
**Expected**: After ~5-10 requests, should get rate limit error

**Test 2: File Processing Rate Limit**
```bash
# Upload 10 files rapidly
for i in {1..10}; do
  curl -X POST 'http://localhost/ilias/ilias.php?ref_id=123&cmd=processFile' \
    -H 'Cookie: PHPSESSID=your_session' \
    -F 'file=@test.pdf' &
done
wait
```
**Expected**: Rate limiting kicks in after threshold

### API Key Security

**Test 1: Verify Encryption**
```sql
-- Check database to ensure API keys are encrypted
SELECT name, value FROM xmdq_config WHERE name LIKE '%api_key%';
-- Should see base64-encoded encrypted values, not plain text
```

**Test 2: Exposure in Responses**
```bash
# Check if API keys leak in error messages
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=generate' \
  -H 'Cookie: PHPSESSID=your_session' | grep -i "api.key\|sk-"
```
**Expected**: No API keys in response

---

## 7. File Upload Security Testing

### Malicious File Upload Tests

**Test 1: PHP File Upload**
```php
// Create malicious.php
<?php system($_GET['cmd']); ?>
```
Upload as: malicious.php, malicious.php.pdf, malicious.pdf.php
**Expected**: Rejected by file type validation

**Test 2: Executable Disguised as PDF**
```bash
# Create fake PDF with executable content
echo -e "%PDF-1.4\n<?php system('whoami'); ?>" > fake.pdf
```
**Expected**: Rejected by magic byte validation

**Test 3: ZIP Bomb**
```bash
# Create ZIP bomb (expands to huge size)
# Warning: Don't extract on production!
dd if=/dev/zero bs=1M count=1000 | gzip > zipbomb.gz
```
**Expected**: Rejected by ZIP bomb detection

**Test 4: XXE (XML External Entity)**
```xml
<!-- Create malicious.xml -->
<?xml version="1.0"?>
<!DOCTYPE foo [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<root>&xxe;</root>
```
**Expected**: XXE protection should disable external entities

---

## 8. Session Security Testing

**Test 1: Session Fixation**
```bash
# Get session ID before login
SESSION_ID=$(curl -I 'http://localhost/ilias/' | grep 'Set-Cookie' | grep 'PHPSESSID')
# Login with that session
# Check if session ID changed after login
```
**Expected**: New session ID after authentication

**Test 2: Session Hijacking**
```
1. Login in Browser A
2. Copy session cookie
3. Open Browser B, inject copied cookie
4. Try to access protected pages
```
**Expected**: ILIAS's built-in session validation should apply

---

## 9. Information Disclosure Testing

**Test 1: Error Messages**
```bash
# Trigger various errors and check responses
curl 'http://localhost/ilias/ilias.php?ref_id=999999&cmd=editQuestions'
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=invalidCommand'
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=generate' -d 'invalid_data'
```
**Expected**: Generic error messages, no stack traces or DB details

**Test 2: Debug Information**
```bash
# Check for debug logs in responses
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=generate' | grep -i 'debug\|var_dump\|print_r'
```
**Expected**: No debug information in production

**Test 3: File System Information**
```bash
# Try path traversal
curl 'http://localhost/ilias/ilias.php?ref_id=123&file=../../../../etc/passwd'
curl 'http://localhost/ilias/ilias.php?ref_id=123&cmd=generate&prompt=..%2F..%2F..%2Fetc%2Fpasswd'
```
**Expected**: Path traversal blocked

---

## 10. Security Headers Testing

### Check HTTP Security Headers

```bash
# Test security headers
curl -I 'http://localhost/ilias/ilias.php?ref_id=123&cmd=editQuestions' \
  -H 'Cookie: PHPSESSID=your_session'
```

**Expected Headers**:
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; ...
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

---

## Testing Checklist

- [ ] **SQL Injection**: All forms tested, no vulnerabilities
- [ ] **XSS**: Markdown, prompts, file uploads all sanitized
- [ ] **CSRF**: Token validation working on all POST forms
- [ ] **Authentication**: Permission checks enforced
- [ ] **Input Validation**: Length limits, type checks, whitelists working
- [ ] **Rate Limiting**: API calls and file processing limited
- [ ] **File Upload**: Magic bytes, size limits, ZIP bombs detected
- [ ] **Session Security**: Fixation and hijacking prevented
- [ ] **Information Disclosure**: No sensitive data in errors
- [ ] **Security Headers**: CSP, XSS protection, frame options set

---

## Reporting Vulnerabilities

If you find any vulnerabilities during testing:

1. **Document**:
   - Vulnerability type (SQL injection, XSS, etc.)
   - Location (file, line number, function)
   - Payload used
   - Impact (severity: Critical/High/Medium/Low)

2. **Example Format**:
   ```
   Vulnerability: SQL Injection
   Location: class.ilObjMarkdownQuizGUI.php:450
   Payload: ' OR '1'='1
   Impact: HIGH - Could expose database contents
   Fix: Add input validation and parameterized query
   ```

3. **Re-test After Fixes**: Verify the vulnerability is resolved

---

## Continuous Security Testing

### Automated Testing (CI/CD)

```yaml
# .github/workflows/security-scan.yml
name: Security Scan
on: [push, pull_request]
jobs:
  security:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Run OWASP Dependency Check
        run: |
          wget https://github.com/jeremylong/DependencyCheck/releases/download/v7.0.0/dependency-check-7.0.0-release.zip
          unzip dependency-check-7.0.0-release.zip
          ./dependency-check/bin/dependency-check.sh --scan . --format HTML
```

### Regular Scanning Schedule

- **Weekly**: Automated OWASP ZAP scan
- **Monthly**: Manual penetration testing
- **Quarterly**: Full security audit
- **After Updates**: Test all changed code

---

## Resources

- **OWASP Testing Guide**: https://owasp.org/www-project-web-security-testing-guide/
- **OWASP Top 10**: https://owasp.org/www-project-top-ten/
- **PortSwigger Web Security Academy**: https://portswigger.net/web-security
- **HackerOne Hacker101**: https://www.hacker101.com/

---

## Support

For security questions or to report vulnerabilities:
- GitHub Issues: https://github.com/your-repo/MarkdownQuiz/issues
- Security Email: security@example.com (create a dedicated email)
