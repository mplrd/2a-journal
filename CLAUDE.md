# Trading Journal

## Project Overview
Application web de journal de trading (PHP backend + Vue.js 3 frontend).
Specs completes dans `docs/specs/trading-journal-specs-v5.md`.

## Quick Reference
- **Backend**: PHP 8.4, MVC custom (NO framework), PDO, JWT auth
- **Frontend**: Vue 3 + Vite + Tailwind CSS + PrimeVue + vue-i18n
- **Database**: MariaDB `2ai_tools_journal` (utf8mb4_unicode_ci), root/no password (dev)
- **Tests**: PHPUnit (backend), Vitest (frontend) - TDD obligatoire
- **Methodology**: TDD - tests first, then code, then refactor, then doc
- **Domain**: journal.2ai-tools.local
- **API URL**: http://journal.2ai-tools.local/api
- **Frontend dev**: http://localhost:5173

## Conventions
- Code & DB: English
- Docs: French
- Commits: English, conventional (feat:, fix:, refactor:, docs:, test:, chore:)
- camelCase (vars/functions), PascalCase (classes), snake_case (DB), UPPER_SNAKE_CASE (enums)

## Git Workflow
- Never push, commit, or run destructive git commands without explicit user request
- User controls all git operations

## Key Architecture Rules
- Enums for all statuses (never hardcoded strings)
- API returns i18n translation keys, not text (`message_key`)
- Prepared statements (PDO) for all queries
- Server-side validation always
- Controllers are thin, delegate to Services
- Business logic in Services, data access in Repositories
- Each feature produces a doc in `docs/` (French)

## API Response Format
```json
{ "success": true, "data": {...}, "meta": {...} }
{ "success": false, "error": { "code": "...", "message_key": "...", "field": "..." } }
```

## Project Structure
```
journal/
├── api/                        # PHP Backend
│   ├── public/index.php        # Single entry point
│   ├── config/                 # app, database, routes, cors
│   ├── src/
│   │   ├── Core/               # Router, Request, Response, Database, Controller
│   │   ├── Enums/              # OrderStatus, TradeStatus, ExitType, Direction...
│   │   ├── Controllers/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   ├── Middlewares/
│   │   └── Exceptions/
│   ├── database/schema.sql
│   └── tests/ (Unit/, Integration/)
├── frontend/                   # Vue.js Frontend
│   └── src/ (components/, composables/, constants/, locales/, services/, stores/, views/, router/)
├── docs/                       # Feature docs (French)
│   └── specs/                  # Reference specs
└── .claude/skills/             # Invocable skills
```

## Skills
- If the user requests something that should be a reusable skill, propose creating one
- Available: /tdd-feature, /new-endpoint, /new-component, /run-tests, /doc-feature, /check-quality, /audit-security, /audit-privacy

## Apache Config
- VHost: journal.2ai-tools.local -> E:/2A-tools/journal
- Alias /api -> api/public/ (PHP router strips /api prefix from REQUEST_URI)
