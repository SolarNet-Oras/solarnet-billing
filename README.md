# ISP Billing & Network Management System

Enterprise-grade ISP Billing and Network Management Platform for MikroTik IPoE subscribers.

## 🏗️ Architecture

### Technology Stack

- **Backend:** Laravel 12.62.0 (PHP 8.2)
- **Frontend:** React 19 + TypeScript + Vite 8.1
- **Database:** PostgreSQL 15
- **Cache/Queue:** Redis 7
- **Styling:** Tailwind CSS 4.3 + shadcn/ui
- **API:** RESTful with versioning (/api/v1)

### System Components

```
├── Backend (Laravel 12)
│   ├── PostgreSQL Database
│   ├── Redis Cache & Queue
│   ├── JWT Authentication
│   └── RESTful API v1
│
├── Frontend (React + TypeScript)
│   ├── Vite Build Tool
│   ├── React Router
│   ├── Axios HTTP Client
│   └── Tailwind CSS + shadcn/ui
│
└── Infrastructure
    ├── Supervisor Process Manager
    ├── PostgreSQL Server
    └── Redis Server
```

## 🚀 Current Status

### ✅ Phase 1: Foundation Complete

- [x] Docker Compose configuration created
- [x] Laravel 12 installed with PHP 8.2
- [x] PostgreSQL 15 database configured
- [x] Redis cache and queue configured
- [x] React + TypeScript + Vite frontend
- [x] Tailwind CSS + shadcn/ui integrated
- [x] JWT authentication library installed
- [x] API routing configured (/api/v1)
- [x] CORS configured
- [x] Supervisor process management
- [x] Both services running successfully

## 📡 API Endpoints

### Health Check
```bash
GET /api/health
```

### API v1
```bash
GET /api/v1/status
GET /api/v1/user (protected)
```

## 🔧 Configuration

### Backend (.env)
```
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=isp_billing
DB_USERNAME=isp_user
DB_PASSWORD=isp_secure_password

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

JWT_SECRET=(auto-generated)
```

### Frontend (.env)
```
VITE_API_URL=https://network-ops-center-2.preview.emergentagent.com
```

## 🎯 Services

### Running Services

```bash
# Check status
supervisorctl status

# Restart services
supervisorctl restart laravel
supervisorctl restart isp_frontend
supervisorctl restart all
```

### Service URLs

- **Backend API:** http://localhost:8001/api
- **Frontend:** http://localhost:3001
- **PostgreSQL:** localhost:5432
- **Redis:** localhost:6379

## 🧪 Testing

### Test Backend API
```bash
# Health check
curl http://localhost:8001/api/health

# API v1 status
curl http://localhost:8001/api/v1/status
```

### Test Database Connection
```bash
cd /app/backend
php artisan migrate:status
```

## 📦 Database

### Credentials
- **Database:** isp_billing
- **User:** isp_user
- **Password:** isp_secure_password
- **Port:** 5432

### Migrations
```bash
cd /app/backend
php artisan migrate
php artisan migrate:status
php artisan migrate:rollback
```

## 🔐 Authentication

- JWT authentication configured
- Laravel Sanctum installed for API tokens
- Role-Based Access Control (RBAC) implemented for staff operations

## 📋 Next Steps (Phase 2)

### Phase 2: Authentication & RBAC
- [x] User model with JWT integration
- [x] Role and Permission models
- [x] Authentication API (login, refresh, logout)
- [x] Secure staff password-reset flow
- [x] Active-account, role, and permission middleware
- [x] Super Administrator user management CRUD
- [x] Staff login and administrator-controlled account UI

### Phase 3: Dashboard & Core UI
- [ ] Admin dashboard layout
- [ ] Real-time metrics widgets
- [ ] Light/Dark theme toggle
- [ ] Responsive navigation
- [ ] Dashboard API endpoints

## 🏢 Planned Modules

1. **Customer Management** - ISP subscriber records
2. **MikroTik Integration** - RouterOS API integration
3. **DHCP Synchronization** - Automatic lease import
4. **Service Plans** - Bandwidth packages
5. **Billing Engine** - Invoice generation
6. **Payment Processing** - Multi-method support
7. **Suspension/Restoration** - Automatic service control
8. **Customer Portal** - Self-service interface
9. **Ticketing System** - Support management
10. **Inventory** - Equipment tracking
11. **OLT Module** - Multi-vendor support
12. **Reports & Analytics** - Business intelligence
13. **Notifications** - SMS/Email alerts
14. **Audit Logs** - Complete activity tracking

## 🐛 Troubleshooting

### Check Logs
```bash
# Laravel logs
tail -f /var/log/supervisor/laravel.log
tail -f /var/log/supervisor/laravel.err.log

# Frontend logs
tail -f /var/log/supervisor/isp_frontend.log
tail -f /var/log/supervisor/isp_frontend.err.log

# PostgreSQL logs
tail -f /var/log/postgresql/postgresql-15-main.log

# Redis logs
tail -f /var/log/redis/redis-server.log
```

### Restart Services
```bash
# Restart PostgreSQL
service postgresql restart

# Restart Redis
service redis-server restart

# Restart application
supervisorctl restart laravel isp_frontend
```

## 📚 Documentation

### Laravel 12
- [Official Documentation](https://laravel.com/docs/12.x)
- [API Documentation](https://laravel.com/docs/12.x/sanctum)

### React + TypeScript
- [React Documentation](https://react.dev/)
- [TypeScript Documentation](https://www.typescriptlang.org/docs/)
- [Vite Documentation](https://vitejs.dev/)

### Tailwind CSS
- [Official Documentation](https://tailwindcss.com/docs)
- [shadcn/ui Components](https://ui.shadcn.com/)

## 🎉 Success Criteria

- ✅ Laravel 12 backend operational
- ✅ React + TypeScript frontend operational
- ✅ PostgreSQL database connected
- ✅ Redis cache working
- ✅ API endpoints responding
- ✅ CORS configured
- ✅ JWT authentication ready
- ✅ Both services managed by Supervisor

---

**Phase 1 Complete!** 🚀

Ready to proceed to Phase 2: Authentication & RBAC System
