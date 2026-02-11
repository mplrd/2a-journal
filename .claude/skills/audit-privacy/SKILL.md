---
name: audit-privacy
description: Audit data protection and privacy practices. Use to verify sensitive data handling, token storage, cookies, and cleanup on logout.
argument-hint: "[backend|frontend|all]"
allowed-tools: "Read, Grep, Glob, Bash"
---

# Data Protection Audit

Audit data protection for scope: **$ARGUMENTS** (default: all).

## Checks to perform

### 1. Token Storage
- Identify where JWT access and refresh tokens are stored (localStorage, sessionStorage, cookies, memory)
- Evaluate XSS exposure risk for chosen storage
- Verify tokens are not stored in URLs or query parameters

### 2. Cookies
- All cookies carrying auth data have `HttpOnly` flag
- All cookies have `Secure` flag (for HTTPS)
- All cookies have `SameSite=Strict` or `SameSite=Lax`
- No sensitive data in non-HttpOnly cookies
- Reasonable expiration times

### 3. Sensitive Data in Responses
- API never returns `password_hash` or password fields
- API never returns internal tokens (refresh tokens in list endpoints)
- API never returns other users' private data
- Verify `SELECT` queries specify columns (no `SELECT *` leaking extra fields)

### 4. Sensitive Data in Storage
- Inventory everything stored in `localStorage` and `sessionStorage`
- No passwords, secrets, or PII stored unnecessarily client-side
- Verify stored data is the minimum needed

### 5. Logout Cleanup
- Logout clears localStorage/sessionStorage tokens
- Logout resets Pinia store state
- Logout invalidates refresh token in database
- Logout clears any auth cookies
- After logout, protected API calls return 401

### 6. Password Handling
- Passwords hashed with bcrypt (cost >= 10)
- Raw passwords never logged, stored, or returned
- Password not included in user profile responses

### 7. Data Exposure in Logs
- No sensitive data written to error logs (passwords, tokens, PII)
- No tokens in Apache/PHP access logs (query strings)
- Error handlers do not dump sensitive request body

### 8. Soft Delete & Data Retention
- Soft-deleted records (deleted_at) are filtered from all queries
- Deleted user data is not accessible via API
- Ownership checks prevent cross-user data access

### 9. Minimal Data Exposure
- API endpoints return only necessary fields
- List endpoints do not include nested sensitive data
- Pagination prevents mass data extraction

## Output
Report findings as:
- **PASS**: Data properly protected
- **WARN**: Potential exposure, should investigate
- **FAIL**: Sensitive data exposed, must fix

Provide file:line for each finding.
