# PRD — Solarnet Internet ISP Billing & Network Management System

## Original problem statement
Design and build a production-ready **Enterprise ISP Billing & Network Management System** primarily for MikroTik IPoE subscribers. Add a full **AI Agent / AI Automation** module (powered by **Claude Sonnet 4.6**) for customer support, billing, NOC troubleshooting, and quote generation.

## Tech Stack
- **Backend:** Laravel 12, PHP 8.4, PostgreSQL, Redis, evilfreelancer/routeros-api-php
- **Frontend:** React 19, TypeScript, Vite, Tailwind CSS, Lucide React
- **Deployment:** Self-hosted Docker Compose on DigitalOcean + Caddy (auto-HTTPS)
- **Branding:** "Solarnet Internet", BIR-format VAT-inclusive, Philippine Peso (₱)

## Personas
- **Super Admin / Owner** — full RBAC, sees dashboards, billing, customers, network
- **NOC operator** — MikroTik status, DHCP sync, queue management
- **Billing staff** — invoices, quotes, payments
- **Customer** — self-signup + portal login, sees own invoices/plans

---

## What's implemented — 2026-02-13 (Wave 3: MikroTik VPN-safe port + Scheduled Automations)

### MikroTik Setup Script — Hardcoded API Port 8728
- ✅ `MikrotikScriptGenerator::generateSetupScript` now **hardcodes** `$apiPort = 8728` regardless of what `$router->port` is. Rationale: even when the MikroTik is reached by the billing app through a VPN tunnel (where `router.port` might be a mapped port like 18728), the router's own `/ip service` must always listen on the RouterOS default 8728 for consistency.
- ✅ Verified via curl on `POST /api/v1/routers/preview-script` with `port=18728` — output script contains `port=8728` and does NOT contain `18728`.
- Both `previewSetupScript` (unsaved router wizard) and `generateSetupScript` (persisted routers) share the same generator, so both endpoints are fixed.

### AI Phase 3 — Scheduled Automations
- ✅ **4 Artisan commands** (all record to `automation_logs`, all honour `automation.enabled` master switch):
  - `automation:update-overdue` (daily 02:00 Manila) — flips past-due `sent` invoices to `overdue`
  - `automation:db-backup` (daily 02:15) — gzipped `pg_dump` to `storage/app/backups/`, prunes files older than `automation.backup_retention_days` (default 7)
  - `automation:invoice-reminders` (daily 08:00) — mails reminders X days before due (`automation.reminder_days_before`, default 3) and N days after due (`automation.overdue_reminder_days`, default `1,7,14`). Uses `MAIL_MAILER=log` in dev; SMTP in prod when configured.
  - `automation:auto-suspend` (daily 09:00) — flips `status='suspended'` on active customers whose oldest unpaid invoice is older than `billing.auto_suspend_days` (default 15). CustomerObserver then automatically throttles the MikroTik queue via QueueService.
- ✅ `AutomationRunner` service wraps every command: times it, captures errors, writes an `automation_logs` row with `success` / `partial` / `error` status.
- ✅ **API**:
  - `GET  /api/v1/automation/jobs`  — jobs + last run summary (permission: view-settings)
  - `GET  /api/v1/automation/logs`  — paginated run history (permission: view-settings)
  - `POST /api/v1/automation/run/{job}` — manual trigger (role: super_admin only)
- ✅ **Frontend** — new `AutomationPanel` component embedded in `SettingsPage`:
  - 4 job cards (label, cron, "Run now" button, last-run status pill, summary chips)
  - Recent runs table (job, status, when, duration, trigger)
- ✅ **Settings keys** added: `automation.enabled`, `automation.auto_suspend_enabled`, `automation.reminder_days_before`, `automation.overdue_reminder_days`, `automation.backup_retention_days`.
- ✅ Verified end-to-end via curl and screenshot — manual `run/update_overdue` recorded with `triggered_by=manual`, `pg_dump` produced a 33.4 KB backup, master switch short-circuits jobs correctly.

**Prod requirement**: The Docker prod image must include `postgresql-client` in the backend container (needed for `pg_dump`). And `MAIL_MAILER` should be set to `smtp` with the SMTP creds for real reminder emails; the code already uses the mail facade so no code change needed.

**Cron requirement**: `deploy.sh` / prod cron must include `* * * * * cd /var/www && php artisan schedule:run >> /dev/null 2>&1` so Laravel's scheduler fires.

---

## What's implemented — 2026-02-12 (Wave 2: Super-admin AI Code Assistant)

### AI code exploration (super-admin only, READ-ONLY)
- ✅ **3 new AI tools** locked to `super_admin` role (verified in AiToolRegistry::schemasFor — non-super users literally don't see them in OpenAI schemas):
  - `list_source_files` — enumerate a directory (allow-listed)
  - `read_source_file` — read a single file (max 64 KB, extension allow-list)
  - `search_code` — grep across allow-listed roots (Symfony Process, argv, no shell, 15s timeout)
- ✅ **`CodeToolGuards`** hardened:
  - ALLOWED_ROOTS: `/app/backend/app`, `/app/backend/config`, `/app/backend/database/migrations`, `/app/backend/routes`, `/app/backend/tests`, `/app/frontend/src`
  - ALLOWED_EXTS: php, ts, tsx, js, jsx, json, md, yml, yaml, css, scss, html, blade.php
  - MAX_FILE_BYTES = 64_000
  - Rejects `..`, rejects paths outside allowed roots, rejects binaries
- ✅ **NO file writes, NO code execution** — AI only echoes code as fenced markdown blocks; user reviews and applies manually
- ✅ **System prompt upgraded** — when talking to a super_admin, AI is instructed to:
  - Use unified diff or full-file format for changes
  - Warn about breaking changes / migrations / new deps
  - Never suggest destructive commands (`rm -rf`, `truncate`, `DROP TABLE`, `chmod 777`, editing protected `.env` vars)
  - Add tests for non-trivial logic
- ✅ **Frontend upgraded**:
  - `react-markdown` + `remark-gfm` render assistant replies
  - Custom `CodeBlock` component with copy-to-clipboard button (`data-testid="ai-copy-code-btn"`)
  - Chat drawer widened (520px) to fit code
  - 4 additional super-admin-only suggestion chips (total 8 for super_admin, 4 for others)
- ✅ **OpenAI 429 handling** — new `OpenAiRateLimitException` translates provider throttling into a friendly HTTP 429 with `code=rate_limited` so the UI can back off vs treat it as a bug

### Verified (iteration_15)
- All security guards verified via direct PHP (traversal blocked, ext whitelist enforced, root whitelist enforced, 64 KB cap enforced)
- Non-super_admin `authorize()` returns false for all 3 code tools — verified with a customer-role user
- Super_admin sees 8 suggestion chips in the drawer — verified via Playwright
- LLM round-trip: 6/7 tests passed before OpenAI daily quota (50/day tier) exhausted; guard tests are quota-free and 100% pass
- No critical or UI bugs found; minor demo user login issue is unrelated pre-existing

### Known constraints
- **OpenAI account is on a low tier** (RPD 50/day, RPM 10/min for gpt-5.4-mini). Recommend upgrading OpenAI plan for production usage.
- Non-super_admins in a Customer role don't see the AI drawer at all (they use CustomerLayout, not DashboardLayout) — safer than exposing it there anyway.

---

## What's implemented — 2026-02-12 (Wave 1: Floating AI Assistant)

### AI Assistant Wave 1
- ✅ **Backend AI stack** (Laravel 12, guzzle to OpenAI REST — no extra composer deps)
  - Config `/app/backend/config/openai.php` (model=gpt-5.4-mini, key from `.env`, timeout, max tool iterations)
  - Migrations: `ai_conversations`, `ai_messages`, `ai_audit_logs`
  - Models: `AiConversation`, `AiMessage`, `AiAuditLog`
  - `OpenAiClient` (Guzzle wrapper for `/chat/completions` with tool-calling support)
  - `AiToolRegistry` + `AiTool` interface + 5 read-only tools:
    - `get_network_status` (customers/invoices/routers/leases snapshot)
    - `list_customers` (filter by status/plan/router/search)
    - `get_customer_details` (by id/account_number/search)
    - `search_by_mac_or_ip`
    - `list_unregistered_leases` (variant: static_commented|dynamic|all)
  - `AiService` orchestrator (multi-turn conversation loop, up to 5 tool iterations, per-call audit log)
  - `AiController` with endpoints:
    - `POST /api/v1/ai/chat`
    - `GET /api/v1/ai/conversations`
    - `GET /api/v1/ai/conversations/{id}/messages`
    - `DELETE /api/v1/ai/conversations/{id}`
- ✅ **Frontend floating chat drawer** at `/app/frontend/src/components/ai/FloatingAiAssistant.tsx`
  - Mounted in `DashboardLayout` → visible on every authenticated page
  - Purple/violet gradient floating button (bottom-right)
  - Chat drawer with: header, message list, tool-call visualization (`🔧 tool_name · ok`), input, suggestion chips
  - History sidebar (list past conversations, load, delete, "New chat")
  - Multi-turn context maintained via `conversation_id` echoed by backend
  - Enter to send, Shift+Enter for newline
- ✅ **Security**: OPENAI_API_KEY stored in `/app/backend/.env`, never sent to the browser (verified — testing agent grep'd HTML/JS for `sk-` string)
- ✅ **RBAC**: 4/5 tools require `view-customers` permission OR `super-admin` role; `get_network_status` open to all
- ✅ **Audit**: every tool call writes an `ai_audit_logs` row (user, args, result, latency_ms, status)

### Verified
- Testing agent iteration_14: 13/13 backend + full frontend E2E all PASSED, zero critical or minor issues
- End-to-end demo working: user question → GPT-5.4-mini → tool call → live PG data → formatted reply, ~2.4s

### Model / Key
- Model: **gpt-5.4-mini** (user's choice)
- Key: user's own OpenAI project key (stored server-side only)
- Est. cost per Wave 1 conversation: ~$0.005 (1600 in + 100 out tokens)

---

## What's implemented — 2026-02-12 (Earlier session)

### Wave 1 completion
- ✅ **Add Client** page (`/customers/create`) was previously broken due to missing imports; fully rewritten:
  - Router selector
  - Service plan selector auto-fills monthly fee (₱)
  - Reads `?mac=&ip=&router=` query params for prefill (from Unregistered flow)
  - Shows one-time portal credentials + welcome-email status inline
- ✅ **Unregistered Clients page** (`/unregistered-clients`) — NEW major feature:
  - **Tab 1: Static + Comment** — leases with MikroTik `comment` and non-dynamic. One-click "Register" uses comment as full_name and matches `rate-limit` (e.g. `10M/5M`) to an active ServicePlan by download/upload speed. If matched, the plan and price auto-fill.
  - **Tab 2: Dynamic / Manual** — dynamic leases (or static without comment). "Add as Client" opens the CreateCustomer form with MAC/IP prefilled.
  - Top action **"Sync from all routers"** persists MikroTik lease data locally (no auto-customer creation — admin reviews first).
- ✅ **DHCP lease schema extended**: `comment`, `rate_limit`, `is_dynamic` columns added; `MikrotikService` / `DhcpSyncService` now capture these.
- ✅ **Empty migration bug fixed** — `2026_07_01_170617_add_queue_fields_to_customers_table` was a no-op, causing 42703 errors inside `DB::transaction`. Now properly adds `queue_synced`, `queue_last_synced_at`, `queue_sync_status`.
- ✅ **MikroTik hang bug fixed** — Added TCP timeouts (3s connect / 5s read / no retries) to all 7 `MikrotikService` connection sites. `QueueService::syncCustomerQueue` now short-circuits when the router's `connection_status` is `offline` / `unknown` / null so a dead router no longer hangs a customer-create HTTP request.
- ✅ Sidebar link **"Unregistered"** added.

### API endpoints added
- `POST /api/v1/unregistered-leases/sync-all` — sync leases from all active routers
- `GET  /api/v1/unregistered-leases/static-commented` — static+commented leases + suggested plan
- `GET  /api/v1/unregistered-leases/dynamic` — dynamic or uncommented leases
- `POST /api/v1/unregistered-leases/{id}/quick-register` — 1-click convert lease → Customer

### Verified
- Backend testing agent: **15/16 tests pre-fix, all 4 new endpoints verified post-fix by curl**
- Auth guard (401), 404, 422 (already-matched), 201 with correct plan/name/fee/mac — all confirmed
- Frontend smoke screenshot: `/unregistered-clients` renders with tabs and empty state

---

## Roadmap

### P0 — AI Phase 1: Core Integration (next up)
- Laravel-based AI service layer using **Claude Sonnet 4.6** via **Emergent Universal LLM Key** (`integration_playbook_expert_v2` mandatory)
- AI conversation tables/models
- `POST /api/ai/chat` endpoint
- Tools: Customer balance, Support issue classification, Payment reminder generator
- AI Assistant admin dashboard + conversation history UI

### P1 — AI Phase 2: Advanced tools
- Quote generation
- Ticket creation tool
- MikroTik diagnostics tools
- Customer-specific AI context panel
- Action approval flow

### P2 — Operational
- Real SMTP config on VPS `.env` (currently logs emails to file)
- Refactor `CreateCustomerPage.tsx` and `NewDashboardPage.tsx` into smaller components
- Move MikroTik I/O in `CustomerObserver` behind `afterCommit` + a queued job (currently synchronous with 3-8s worst-case delay per customer create)

---

## Deployment
Production is a self-hosted DigitalOcean VPS running Docker Compose. Update flow:
1. In Emergent chat, click **"Save to GitHub"**
2. SSH to VPS, `cd /opt/solarnet-isp && git pull origin main`
3. `cd deploy && ./deploy.sh` — rebuilds containers, runs migrations, restarts services
4. Postgres data persisted under `data/` (git-ignored)

## Key Credentials (dev only)
- Super Admin: `admin@ispbilling.local` / `password`
- Demo Admin:  `demo@ispbilling.local` / `password`
