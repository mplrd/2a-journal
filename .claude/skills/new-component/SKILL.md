---
name: new-component
description: Scaffold a new Vue 3 component with i18n support and Vitest tests. Use when creating a new frontend component.
argument-hint: "[ComponentName]"
allowed-tools: "Read, Write, Edit, Grep, Glob"
---

# New Vue Component Scaffold

Create the Vue 3 component **$ARGUMENTS** with tests and i18n.

## Steps

1. **Component**: Create in appropriate directory under `frontend/src/components/`
   - Use Composition API (`<script setup>`)
   - Use PrimeVue components where applicable
   - Use Tailwind CSS for styling
   - All user-facing text via `$t('key')` (never hardcoded)
   - Use constants from `frontend/src/constants/` for enums

2. **i18n keys**: Add keys to both:
   - `frontend/src/locales/fr.json`
   - `frontend/src/locales/en.json`

3. **Tests**: Create `__tests__/$ARGUMENTS.test.js` next to the component
   - Mount with `@vue/test-utils`
   - Mock i18n and store if needed
   - Test rendering, user interactions, edge cases

4. **Store**: Create/update Pinia store if component needs state management (`frontend/src/stores/`)

5. **Composable**: Extract reusable logic into composable (`frontend/src/composables/`) if applicable
