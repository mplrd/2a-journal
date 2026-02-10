---
name: check-quality
description: Review code quality and conventions compliance. Use to verify the codebase follows project standards (naming, enums, i18n, security).
allowed-tools: "Read, Grep, Glob"
---

# Quality Check

Audit the codebase for compliance with project conventions.

## Checks to perform

### 1. Naming conventions
- Variables/functions: camelCase
- Classes: PascalCase
- Database columns: snake_case
- Enums: UPPER_SNAKE_CASE
- Files: match class name (PascalCase.php)

### 2. Enum usage
- Search for hardcoded status strings ('PENDING', 'OPEN', etc.)
- Verify they use Enum classes instead (OrderStatus::PENDING, etc.)
- Check both PHP backend and JS frontend constants

### 3. i18n compliance
- Backend: API responses must use `message_key`, never raw user-facing text
- Frontend: all user-facing text must use `$t('key')`, never hardcoded strings
- Check fr.json and en.json have the same keys

### 4. Security
- All SQL queries use PDO prepared statements (no string concatenation)
- Input validation on all API endpoints
- No sensitive data in responses (passwords, tokens)
- JWT middleware on protected routes

### 5. Architecture
- Controllers are thin (delegate to Services)
- Business logic lives in Services
- Data access lives in Repositories
- No direct DB access from Controllers

## Output
Report findings as:
- **PASS**: Convention respected
- **WARN**: Minor issue, should fix
- **FAIL**: Convention violated, must fix

Provide file:line for each finding.
