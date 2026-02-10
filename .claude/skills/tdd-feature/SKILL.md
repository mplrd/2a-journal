---
name: tdd-feature
description: Execute the full TDD workflow for a new feature. Use when implementing any new feature on the project. Follows the strict cycle tests → code → refactor → doc.
argument-hint: "[feature-name]"
allowed-tools: "Read, Write, Edit, Grep, Glob, Bash"
---

# TDD Feature Workflow

Implement the feature **$ARGUMENTS** following strict TDD methodology.

## Phase 1: Understand
1. Read `docs/specs/trading-journal-specs-v5.md` for relevant sections
2. Identify all API endpoints, models, services, and repositories involved
3. List what needs to be built (backend + frontend)

## Phase 2: Backend Tests First
1. Write **unit tests** in `api/tests/Unit/` for:
   - Service layer (business logic)
   - Repository layer (data access)
2. Write **integration tests** in `api/tests/Integration/` for:
   - Controller endpoints (HTTP tests)
   - Full request/response cycle
3. Test naming: `test{MethodName}_{scenario}_{expectedResult}`
4. Run tests → they MUST all fail (red phase)

## Phase 3: Backend Implementation
1. Create/update Enums in `api/src/Enums/` if needed
2. Implement Repository (PDO prepared statements only)
3. Implement Service (business logic, validation)
4. Implement Controller (thin, delegates to Service)
5. Add routes in `api/config/routes.php`
6. Run tests → they MUST all pass (green phase)

## Phase 4: Backend Refactor
1. Review code for duplication, naming, conventions
2. Ensure enums are used (no hardcoded strings)
3. Ensure API returns `message_key` for i18n (no raw text)
4. Ensure server-side validation
5. Run tests → still green

## Phase 5: Frontend Tests First (if applicable)
1. Write tests in `frontend/src/**/__tests__/` using Vitest
2. Test composables, services, and critical components
3. Run tests → they MUST fail

## Phase 6: Frontend Implementation (if applicable)
1. Create components, composables, services, store
2. Use `$t('key')` for all user-facing text
3. Use constants from `frontend/src/constants/` (enum mirrors)
4. Run tests → they MUST pass

## Phase 7: Documentation
1. Generate `docs/$ARGUMENTS.md` in French with:
   - **Fonctionnalites**: what the feature does (user perspective)
   - **Choix d'implementation**: technical decisions and why
   - **Couverture des tests**: table of all tests, scenarios, status

## Checklist before marking complete
- [ ] All backend tests pass
- [ ] All frontend tests pass (if applicable)
- [ ] No hardcoded strings for statuses
- [ ] API returns translation keys
- [ ] i18n keys added to fr.json and en.json
- [ ] Documentation delivered in docs/
