---
name: run-tests
description: Run backend (PHPUnit) and/or frontend (Vitest) tests with clear reporting. Use when you need to verify tests pass.
argument-hint: "[backend|frontend|all]"
allowed-tools: "Bash, Read"
---

# Run Tests

Run tests for scope: **$ARGUMENTS** (default: all).

## Backend (PHPUnit)
```bash
cd api && vendor/bin/phpunit --testdox
```
- Report any failures with file:line and assertion details
- Summarize: X passed, Y failed, Z skipped

## Frontend (Vitest)
```bash
cd frontend && npx vitest run
```
- Report any failures with file:line and assertion details
- Summarize: X passed, Y failed, Z skipped

## After running
- If all pass: report green summary
- If failures: list each failing test with the reason, suggest fixes
- Never mark a feature as complete if tests are failing
