---
name: audit-security
description: Audit security vulnerabilities in the codebase (OWASP Top 10). Use to verify the application is protected against common attack vectors.
argument-hint: "[backend|frontend|all]"
allowed-tools: "Read, Grep, Glob, Bash"
---

# Security Audit

Audit security for scope: **$ARGUMENTS** (default: all).

## Checks to perform

### 1. SQL Injection
- All PDO queries use prepared statements with bound parameters
- No string concatenation or interpolation in SQL queries
- Search for patterns: `query(".*\$`, `exec(".*\$`, `"SELECT.*" .`

### 2. XSS (Cross-Site Scripting)
- No `v-html` without sanitization in Vue components
- No `innerHTML`, `outerHTML`, or `document.write` usage
- No unescaped user input rendered in templates

### 3. Authentication & JWT
- JWT secret is sufficiently strong (not default/weak)
- Access token TTL is short (< 30 min), refresh token TTL is reasonable
- Refresh token rotation on use (old token invalidated)
- Logout invalidates refresh token in database
- Password hashing uses bcrypt (not md5/sha1)

### 4. CORS Configuration
- Allowed origins are explicit (no wildcard `*` in production)
- Credentials mode is properly configured
- Only necessary HTTP methods and headers are allowed

### 5. Input Validation
- All API endpoints validate input server-side
- Type checking, length limits, format validation
- Reject unexpected fields (no mass assignment)
- Validate IDs, enums, numeric ranges

### 6. Rate Limiting
- Login/register endpoints have brute-force protection
- API endpoints have request rate limits
- Report if no rate limiting is implemented (WARN)

### 7. Error Handling
- Production mode does not leak stack traces
- No internal paths, class names, or DB structure in error responses
- Generic error messages for 500 errors
- Specific validation errors only for 422

### 8. HTTP Security Headers
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY` or `SAMEORIGIN`
- `Strict-Transport-Security` (if HTTPS)
- `Content-Security-Policy` (report if absent)

### 9. Dependencies
- Run `composer audit` in `api/` for known PHP vulnerabilities
- Run `npm audit` in `frontend/` for known JS vulnerabilities
- Report any critical or high severity issues

## Output
Report findings as:
- **PASS**: No vulnerability found
- **WARN**: Potential issue, should investigate
- **FAIL**: Vulnerability confirmed, must fix

Provide file:line for each finding.
