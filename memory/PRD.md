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

## What's implemented — 2026-02-12 (this session)

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
