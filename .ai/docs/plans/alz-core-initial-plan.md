# E-commerce Backend Service Migration Guide

## Project Overview

### Current State
- **Legacy System**: PHP application without modern framework
- **Users**: Internal tool for 3-4 staff members
- **Issues**: Poor API error handling, overly complex OOP, no proper organization
- **Frontend**: Next.js app already built using Supabase for database/auth

### Migration Goals
- Modern backend service for webhooks and background jobs
- Reliable API integrations with external services
- Scheduled sync jobs for orders, inventory, and products
- Clean separation between frontend and backend operations

## Architecture Decisions

### Technology Stack

| Component         | Choice                | Reasoning                                               |
|-------------------|-----------------------|---------------------------------------------------------|
| Backend Framework | Laravel + Octane      | Modern PHP, excellent DX, 10-30x performance boost      |
| Database          | Supabase (PostgreSQL) | Already integrated with Next.js, single source of truth |
| Cache/Queue       | Redis                 | Fast, reliable, great Laravel integration               |
| Deployment        | Railway               | Vercel-like experience, easy deployment                 |
| Monitoring        | Horizon + Telescope   | Built-in Laravel tools for queue and debug monitoring   |

### Domain Structure

```
app.yourdomain.com      â†’ Next.js (Staff Portal)
admin.yourdomain.com    â†’ Laravel (Admin Tools, Horizon, Telescope)  
api.yourdomain.com      â†’ Laravel (API Endpoints, Webhooks)
```

## Key Design Principles

### 1. Database Strategy
- **Primary Database**: Keep Supabase as the main database
- **Connection**: Laravel connects directly to Supabase PostgreSQL
- **Optimization**: Deploy Laravel in same region as Supabase (minimize latency)
- **Caching**: Use Redis for frequently accessed data

### 2. Caching Philosophy
- **Default**: Cache aggressively, remove only when problems arise
- **No Cache**: Payments, auth checks, financial transactions
- **Short Cache (1-2 min)**: Active support data, order status
- **Medium Cache (5-10 min)**: Product listings, customer data
- **Long Cache (1+ hour)**: Dashboards, reports, analytics

### 3. API SDK Design (E-commerce Platform)
- **Thin SDK**: No caching or complex logic in the SDK
- **Raw Responses**: Return API data with headers (rate limits)
- **Consumer Control**: Let Laravel handle caching/pagination
- **Memory Efficient**: Use generators for large datasets

## Project Structure

### Laravel Backend Structure
```
backend-service/
â”œâ”€â”€ app/
â”‚   â”œâ”€â”€ Console/
â”‚   â”‚   â””â”€â”€ Commands/        # Sync commands (orders, products)
â”‚   â”œâ”€â”€ Http/
â”‚   â”‚   â””â”€â”€ Controllers/
â”‚   â”‚       â””â”€â”€ Webhooks/    # Webhook handlers
â”‚   â”œâ”€â”€ Jobs/                # Background job processors
â”‚   â””â”€â”€ Services/            # Business logic layer
â”œâ”€â”€ packages/
â”‚   â””â”€â”€ ecommerce-api/       # Custom API SDK
â”œâ”€â”€ routes/
â”‚   â”œâ”€â”€ api.php             # API endpoints
â”‚   â””â”€â”€ console.php         # Scheduled tasks
â””â”€â”€ config/
    â””â”€â”€ services.php        # API configurations
```

### E-commerce API Package Structure
```
packages/ecommerce-api/
â”œâ”€â”€ src/
â”‚   â”œâ”€â”€ Client.php          # Main API client
â”‚   â”œâ”€â”€ Resources/
â”‚   â”‚   â”œâ”€â”€ OrderResource.php
â”‚   â”‚   â”œâ”€â”€ ProductResource.php
â”‚   â”‚   â””â”€â”€ CustomerResource.php
â”‚   â”œâ”€â”€ Entities/           # Data objects
â”‚   â””â”€â”€ Exceptions/         # Custom exceptions
â”œâ”€â”€ tests/
â””â”€â”€ composer.json
```

## Implementation Roadmap

### Phase 1: Foundation Setup (Week 1)

#### 1. Create Laravel Project
```bash
composer create-project laravel/laravel backend-service
cd backend-service

# Essential packages
composer require predis/predis guzzlehttp/guzzle
composer require laravel/horizon

# Dev tools
composer require --dev laravel/pint pestphp/pest pestphp/pest-plugin-laravel
```

#### 2. Environment Configuration
```env
# Cache and Queue via Redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Supabase connection
DB_CONNECTION=pgsql
DB_HOST=your-project.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=your-password

# E-commerce API (placeholder)
ECOMMERCE_API_KEY=
ECOMMERCE_API_URL=
```

#### 3. Create E-commerce SDK
```php
// packages/ecommerce-api/src/Client.php
namespace YourCompany\EcommerceAPI;

class Client {
    public function __construct(
        private string $apiKey,
        private string $baseUrl
    ) {
        // Initialize Guzzle client
    }
    
    public function orders(): OrderResource {
        return new OrderResource($this->http);
    }
}
```

#### 4. First Webhook Implementation
```php
// app/Http/Controllers/Webhooks/EcommerceWebhookController.php
class EcommerceWebhookController {
    public function handle(Request $request) {
        // Queue for async processing
        ProcessWebhook::dispatch($request->all());
        return response()->json(['status' => 'accepted'], 200);
    }
}
```

### Phase 2: Core Features (Weeks 2-3)

#### 1. Sync Commands
- [ ] Implement order sync with date range support
- [ ] Handle API pagination with generators
- [ ] Add rate limit awareness and backoff
- [ ] Create sync status tracking

#### 2. Scheduled Jobs
- [ ] Configure Laravel scheduler
- [ ] Implement daily/hourly sync jobs
- [ ] Add failed job handling
- [ ] Set up job monitoring

#### 3. Admin Interface
- [ ] Basic auth for admin subdomain
- [ ] Horizon dashboard access
- [ ] Manual sync triggers
- [ ] Cache management tools

### Phase 3: Optimization (Week 4+)

#### 1. Performance
- [ ] Install Laravel Octane with Swoole
- [ ] Configure worker pools
- [ ] Implement connection pooling
- [ ] Add request/response caching

#### 2. Monitoring
- [ ] Sentry error tracking
- [ ] Custom health checks
- [ ] Performance metrics
- [ ] Uptime monitoring

#### 3. Advanced Features
- [ ] Webhook signature verification
- [ ] Advanced retry strategies
- [ ] Circuit breakers for external APIs
- [ ] Comprehensive audit logging

## Critical Implementation Details

### Database Optimization
```php
// Always use eager loading
Order::with(['items', 'customer', 'shipping'])->get();

// Batch operations
DB::table('products')->insert($products->chunk(100));

// Prepared statements for repeated queries
$statement = DB::getPdo()->prepare('SELECT * FROM orders WHERE id = ?');
```

### Caching Strategy
```php
// Service layer with cache control
class OrderService {
    public function getOrder($id, $fresh = false) {
        if ($fresh) {
            return Order::find($id);
        }
        
        return Cache::remember("order:$id", 600, fn() => 
            Order::find($id)
        );
    }
}
```

### Queue Configuration
```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['webhooks', 'sync', 'default'],
            'balance' => 'auto',
            'maxProcesses' => 10,
        ],
    ],
],
```

### Error Handling Pattern
```php
// Graceful degradation
public function getOrderData($orderId) {
    try {
        return Order::find($orderId);
    } catch (Exception $e) {
        // Fall back to cache if available
        return Cache::get("order:$orderId") 
            ?? throw new ServiceException("Order data unavailable");
    }
}
```

## Development Tools Setup

### Code Quality
```json
// composer.json scripts
{
    "scripts": {
        "test": "pest",
        "lint": "pint",
        "stan": "phpstan analyse",
        "check": ["@lint", "@stan", "@test"]
    }
}
```

### PHPStan Configuration
```neon
# phpstan.neon
includes:
    - vendor/larastan/larastan/extension.neon
    
parameters:
    level: 6  # Start here, increase gradually
    paths:
        - app
        - packages/ecommerce-api/src
```

## Deployment Configuration

### Railway Setup
```yaml
# railway.toml
[build]
builder = "NIXPACKS"

[deploy]
startCommand = "php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"
healthcheckPath = "/health"

[service]
internalPort = 8000
```

### Environment Variables
- Configure all API keys in Railway dashboard
- Set up Redis instance
- Configure domain routing
- Enable HTTPS

## Security Considerations

### Admin Access
- Separate authentication system for admin panel
- 2FA implementation instead of IP restrictions
- Session-based verification for sensitive operations
- Comprehensive audit logging

### API Security
- Webhook signature verification
- Rate limiting on all endpoints
- CORS configuration for Next.js frontend
- API token rotation strategy

## Quick Start Checklist

### Immediate Actions
- [ ] Create Laravel project with basic structure
- [ ] Set up local PostgreSQL and Redis
- [ ] Create e-commerce API package skeleton
- [ ] Implement first webhook handler
- [ ] Write basic order sync command
- [ ] Deploy to Railway (basic version)

### Week 1 Deliverables
- [ ] Working webhook processing
- [ ] One functional sync job
- [ ] Basic error logging
- [ ] Horizon queue monitoring
- [ ] Simple admin authentication

### Success Metrics
- Webhook response time < 200ms
- Queue processing time < 5 seconds per job
- Zero data inconsistencies
- 99.9% uptime for critical services

## Key Takeaways

1. **Start Simple**: Don't over-engineer. Add complexity only when needed.
2. **Cache Aggressively**: Default to caching, remove only when problematic.
3. **Thin SDK**: Keep the e-commerce SDK simple, let Laravel handle complexity.
4. **Performance**: Use Octane for speed, but deploy basic version first.
5. **Monitoring**: Horizon + Telescope provide excellent visibility out of the box.

## Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Laravel Octane Guide](https://laravel.com/docs/octane)
- [Railway Deployment](https://railway.app/docs)
- [Pest PHP Testing](https://pestphp.com/)
- [PHPStan Static Analysis](https://phpstan.org/)

---

*This guide represents the key decisions and implementation strategy discussed for migrating from a legacy PHP system to a modern Laravel backend service.*
