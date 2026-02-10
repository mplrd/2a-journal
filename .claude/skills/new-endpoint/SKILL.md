---
name: new-endpoint
description: Scaffold a new API endpoint with all layers (controller, service, repository, tests). Use when adding a new route to the API.
argument-hint: "[METHOD] [/route]"
allowed-tools: "Read, Write, Edit, Grep, Glob"
---

# New Endpoint Scaffold

Create the API endpoint **$ARGUMENTS** with all required layers.

## Steps

1. **Route**: Add route in `api/config/routes.php`
2. **Controller**: Add method in appropriate controller (`api/src/Controllers/`)
   - Thin controller: parse request, call service, return response
   - Use `Response::json()` format: `{ success, data, meta }` or `{ success, error }`
3. **Service**: Add method in appropriate service (`api/src/Services/`)
   - Business logic and validation here
   - Return data or throw typed exceptions
4. **Repository**: Add method if data access needed (`api/src/Repositories/`)
   - PDO prepared statements only
   - Return typed data
5. **Tests**: Create tests following TDD naming
   - Unit test for Service method
   - Unit test for Repository method
   - Integration test for full endpoint (HTTP)
6. **Validation**: Ensure input validation server-side
7. **i18n**: All error messages use `message_key`, not raw text

## Response format reminder
```json
// Success
{ "success": true, "data": {...}, "meta": {...} }

// Error
{ "success": false, "error": { "code": "...", "message_key": "...", "field": "..." } }
```
