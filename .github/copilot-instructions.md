# ERP Sistema - AI Coding Agent Instructions

## Architecture Overview

This is a **multi-tenant modular ERP system** built with PHP 8+ using a custom dependency injection container and module-based architecture. The system follows a **multi-company** pattern where all data is segregated by `company_id`.

### Core Components
- **`src/Core/`** - Singleton App class with dependency container, routing, and module loader
- **`src/Modules/`** - Self-contained business modules (Dashboard, CRM, Estoque, PDV, Financeiro, Marketing, BI, Sistema)
- **`src/Shared/`** - Cross-module Models, Services, and Utils
- **Multi-tenancy** - Every database table has `company_id` for data isolation

## Key Architectural Patterns

### Module Structure
Each module follows this pattern:
```php
// Module registration in {ModuleName}Module.php
public function register(): void {
    $router = app()->get('router');
    $router->group('api/v1/modulename', ['auth'], function($router) {
        // Route definitions
    });
}
```

### Dependency Resolution
Use the global `app()` helper for service location:
```php
$this->database = app('database');
$this->cache = app('cache');
$this->auth = app('auth');
```

### Multi-tenant Data Access
**CRITICAL**: Always filter by `company_id` in queries:
```php
// ✅ Correct
$clients = $this->database->where('company_id', $user->company_id)->get('clients');

// ❌ Wrong - security vulnerability
$clients = $this->database->get('clients');
```

## Development Workflows

### Setup Commands
```bash
# Docker environment
docker-compose up -d

# Installation (creates default company, admin user, sample data)
php artisan erp:install

# Module scaffolding
php artisan make:module ModuleName
```

### Database Management
- **Schema**: `database/schema.sql` contains complete multi-tenant structure
- **Migrations**: Use `php artisan migrate` for schema updates
- **Multi-company**: Default company (ID=1) with admin@demo.com/password

### API Development
- Routes auto-register via module `register()` methods
- Middleware: `['auth']` for authentication, `['auth', 'permission:module']` for authorization
- All endpoints under `api/v1/{module}/` pattern

## Project-Specific Conventions

### Configuration Loading
Modules enabled/disabled via `config/modules.php`:
```php
'enabled' => ['Dashboard', 'CRM', 'Estoque', 'PDV', 'Financeiro', 'Marketing', 'BI', 'Sistema']
```

### Permission System
JSON-based permissions in `roles.permissions` column:
```php
'permissions' => '{"crm": ["read", "write"], "financeiro": ["read"]}'
```

### Event System
Use EventBus for cross-module communication:
```php
app('eventBus')->dispatch('sale.created', $saleData);
```

### Caching Strategy
Redis-backed caching with company-specific keys:
```php
$cacheKey = "company_{$companyId}_dashboard_metrics";
app('cache')->remember($cacheKey, 3600, $callback);
```

## Critical Integration Points

### Database Schema Relationships
- **`companies`** → All other tables via `company_id`
- **`users`** → `user_roles` → `roles` for permissions
- **`sales`** → `sale_items` → `products` for transaction data
- **`accounts_receivable/payable`** → Financial module integration

### Module Interdependencies
- **PDV** depends on **Estoque** for stock updates
- **Financeiro** integrates with **PDV** for receivables
- **Dashboard** aggregates data from all modules
- **CRM** clients link to **PDV** sales

### Security Boundaries
- Company isolation enforced at database query level
- Role-based permissions checked via middleware
- Two-factor authentication support in user table
- Security event logging in `security_logs` table

## Testing & Debugging

### Log Locations
- Application logs: `storage/logs/`
- System events: `logs` table with JSON context
- Security events: `security_logs` table

### Common Debug Commands
```php
// Check module loading
app()->getLoadedModules();

// Verify permissions
app('auth')->user()->hasPermission('module.action');

// Database debug
app('database')->enableQueryLog();
```

When working with this codebase, always consider multi-tenancy implications and ensure proper company_id filtering in all database operations.
