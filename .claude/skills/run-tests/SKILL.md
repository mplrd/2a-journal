---
name: run-tests
description: Run backend (PHPUnit) and/or frontend (Vitest) tests with clear reporting. Use after writing or modifying code, before considering a task complete, or when the user asks to run tests, check tests, or verify tests pass.
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
