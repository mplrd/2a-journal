---
name: check-i18n
description: Check for missing i18n translation keys across locale files and source code. Detects orphan keys, missing translations, and keys used in code but absent from locale files.
allowed-tools: "Read, Grep, Glob, Edit"
---

# i18n Translation Keys Audit

Scan the codebase for missing or inconsistent i18n translation keys.

## Files to inspect

- **Locale files**: `frontend/src/locales/fr.json` and `frontend/src/locales/en.json`
- **Vue files**: `frontend/src/views/**/*.vue` and `frontend/src/components/**/*.vue`
- **PHP files**: `api/src/**/*.php`

## Steps to perform

### 1. Compare fr.json and en.json key structures

- Read both `frontend/src/locales/fr.json` and `frontend/src/locales/en.json`
- Recursively extract all dot-notation keys from each file (e.g. `auth.error.field_required`)
- Identify keys present in `en.json` but missing from `fr.json`
- Identify keys present in `fr.json` but missing from `en.json`
- Report findings

### 2. Scan Vue files for t() calls

- Search all `.vue` files in `frontend/src/views/` and `frontend/src/components/` for translation function calls
- Match patterns: `$t('...')`, `t('...')`, `$t("...")`, `t("...")`
- Also match computed/dynamic keys using template literals if possible (report as warnings)
- Extract all string literal keys
- Verify each extracted key exists in both `fr.json` and `en.json`
- Report keys used in Vue code but missing from one or both locale files

### 3. Scan PHP files for message_key strings

- Search all `.php` files in `api/src/` for translation key patterns
- Match patterns in API responses: strings that look like i18n keys (dot-notation like `auth.error.field_required`, `orders.success.deleted`, etc.)
- Focus on:
  - `message_key` values in Response/Exception calls
  - String arguments to `HttpException`, `ValidationException`, `NotFoundException`, `ForbiddenException`, `UnauthorizedException`, `TooManyRequestsException`
  - Any string matching the pattern `word.word.word` used as a message key in error/success responses
- Verify each extracted key exists in both `fr.json` and `en.json`
- Report keys used in PHP code but missing from one or both locale files

### 4. Report findings

Group results into these categories:

#### Missing in fr.json
Keys found in `en.json` but absent from `fr.json`.

#### Missing in en.json
Keys found in `fr.json` but absent from `en.json`.

#### Used in Vue code but missing from both locale files
Keys referenced by `t()` or `$t()` in Vue files that exist in neither locale file.

#### Used in Vue code but missing from one locale file
Keys referenced in Vue files that exist in one locale file but not the other.

#### Used in PHP code but missing from both locale files
Keys referenced as `message_key` in PHP files that exist in neither locale file.

#### Used in PHP code but missing from one locale file
Keys referenced in PHP files that exist in one locale file but not the other.

For each category, list:
- The key name
- Where it is referenced (file:line)
- Which locale file(s) it is missing from

Use these status labels:
- **PASS**: All keys are consistent and present in both locale files
- **WARN**: Key exists in code but could be a dynamic/computed key (cannot fully verify)
- **FAIL**: Key is missing from one or both locale files

### 5. Propose and apply fixes

For any missing keys found:
- Propose appropriate translations (French for `fr.json`, English for `en.json`)
- Follow existing naming patterns and translation style from the locale files
- Apply the fixes by editing the locale files with the missing keys
- Maintain alphabetical ordering within each section when inserting new keys
