---
name: doc-feature
description: Generate or update feature documentation in French in the docs/ directory. Use after completing a feature to deliver its documentation.
argument-hint: "[feature-name]"
allowed-tools: "Read, Write, Edit, Grep, Glob"
---

# Feature Documentation

Generate documentation for **$ARGUMENTS** in `docs/$ARGUMENTS.md`.

## Language
All documentation MUST be written in **French**.

## Required sections

### 1. Fonctionnalites
- Describe what the feature does from the **user's perspective**
- List all capabilities and behaviors
- Include edge cases and limits

### 2. Choix d'implementation
- Explain **technical decisions** and why they were made
- Architecture choices, patterns used
- Trade-offs considered
- Security considerations if applicable

### 3. Couverture des tests
- Table of all tests related to this feature:

| Test | Scenario | Statut |
|------|----------|--------|
| testMethodName_scenario_expected | Description | Pass/Fail |

- Mention edge cases tested
- Mention what is NOT tested and why (if applicable)

## Process
1. Read the implementation (controllers, services, repos, components)
2. Read all related test files
3. Generate the doc following the template above
4. Verify all tests listed actually exist in the codebase
