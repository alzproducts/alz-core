# Architecture Overview

Visual guide to alz-core's system structure. For implementation details, see [CLAUDE.md](../../CLAUDE.md) and layer-specific CLAUDE.md files.

---

## 1. System Context

Where alz-core sits in the broader ecosystem.

```mermaid
C4Context
    title System Context — alz-core

    Person(staff, "Staff User", "Customer service, operations")

    System(alzCore, "alz-core", "Laravel backend: order processing, inventory sync, analytics, customer service APIs")
    System(alzAdmin, "Admin Dashboard", "Next.js dashboard: staff-facing UI")

    System_Ext(shopwired, "Shopwired", "eCommerce platform: orders, products, customers")
    System_Ext(linnworks, "Linnworks", "Inventory management: stock items, suppliers")
    System_Ext(googleAds, "Google Ads", "Campaign metrics via gRPC/GAQL")
    System_Ext(bingAds, "Bing Ads", "Campaign metrics via SOAP/CSV")
    System_Ext(mixpanel, "Mixpanel", "Analytics: events, lookup tables")
    System_Ext(helpscout, "HelpScout", "Customer service: conversations, mailboxes")
    System_Ext(reviewsIo, "Reviews.io", "Product ratings and reviews")

    SystemDb(supabase, "Supabase PostgreSQL", "Shared database with Admin Dashboard")
    System_Ext(redis, "Redis", "Cache, queues, sessions, distributed locks")
    System_Ext(sentry, "Sentry", "Error tracking")

    Rel(staff, alzAdmin, "Uses", "Browser")
    Rel(alzAdmin, alzCore, "Consumes APIs", "REST/JWT")
    Rel(alzAdmin, supabase, "Auth & reads", "Supabase SDK")

    Rel(shopwired, alzCore, "Sends webhooks", "HMAC-signed POST")
    Rel(alzCore, shopwired, "Reads/writes", "REST API")
    Rel(alzCore, linnworks, "Reads/writes", "REST API")
    Rel(alzCore, googleAds, "Reads metrics", "gRPC")
    Rel(alzCore, bingAds, "Reads metrics", "SOAP + HTTP/CSV")
    Rel(alzCore, mixpanel, "Writes events", "REST API")
    Rel(alzCore, helpscout, "Reads/writes", "REST API + OAuth2")
    Rel(alzCore, reviewsIo, "Reads ratings", "REST API")

    Rel(alzCore, supabase, "Reads/writes", "PostgreSQL")
    Rel(alzCore, redis, "Cache/queues", "Redis protocol")
    Rel(alzCore, sentry, "Reports errors", "Sentry SDK")
```

**Key relationships:**
- **Shared database** — alz-core and the Admin Dashboard share the same Supabase PostgreSQL. Supabase manages `auth.*`; Laravel manages `shopwired.*` and `public.*`.
- **Webhook-driven** — Shopwired pushes real-time events via HMAC-signed webhooks. alz-core saves the partial payload, then dispatches a job to re-fetch the full entity from the API.
- **Analytics pipeline** — Order and ad spend data flow into Mixpanel via scheduled jobs.

---

## 2. Container Diagram (Railway)

```mermaid
C4Container
    title Container Diagram — Railway Deployment

    Person(staff, "Staff User")
    System_Ext(alzAdmin, "Admin Dashboard", "Next.js dashboard")
    System_Ext(shopwired, "Shopwired", "eCommerce platform")
    System_Ext(integrations, "External APIs", "Linnworks, Google Ads, Bing Ads, Mixpanel, HelpScout, Reviews.io")

    Container_Boundary(railway, "Railway Platform") {
        Container(web, "Web Service", "PHP 8.4 / Swoole (Octane)", "HTTP: webhooks, authenticated APIs, product feeds, ops endpoints")
        Container(worker, "Worker Service", "Laravel Horizon", "Queue jobs: sync, reconciliation, analytics")
        Container(scheduler, "Scheduler Service", "schedule:work", "Cron-triggered tasks across 9 schedule providers")
        ContainerDb(redis, "Redis", "Redis", "Queues (high/default/low), cache, sessions, locks")
    }

    ContainerDb(supabase, "Supabase PostgreSQL", "PostgreSQL", "Shared DB: auth.*, shopwired.*, public.*, access.*")
    System_Ext(sentry, "Sentry", "Error tracking")

    Rel(staff, alzAdmin, "Uses")
    Rel(alzAdmin, web, "REST API calls", "JWT Bearer token")
    Rel(shopwired, web, "Webhooks", "HMAC-signed POST")

    Rel(web, redis, "Dispatch jobs, read cache")
    Rel(web, supabase, "Read/write data")
    Rel(worker, redis, "Consume jobs, write cache")
    Rel(worker, supabase, "Read/write data")
    Rel(worker, integrations, "API calls", "REST/gRPC/SOAP")
    Rel(scheduler, redis, "Dispatch scheduled jobs")
    Rel(web, sentry, "Report errors")
    Rel(worker, sentry, "Report errors")
```

Three queue priority tiers (`high`, `default`, `low`) route jobs by urgency. See `config/horizon.php` for current timeouts and worker config.

---

## 3. Clean Architecture Layers

```
Presentation → Application → Domain ← Infrastructure
                                ↑           |
                                └───────────┘
                              (implements interfaces)
```

```mermaid
C4Component
    title Component Diagram — Clean Architecture Layers

    Container_Boundary(presentation, "Presentation Layer") {
        Component(controllers, "Controllers", "HTTP entry points", "Webhooks, authenticated APIs, contact form, feeds, ops")
        Component(commands, "Console Commands", "CLI entry points", "Admin tasks, manual triggers")
        Component(middleware, "Middleware", "Request pipeline", "JWT validation, HMAC verification, rate limiting")
    }

    Container_Boundary(application, "Application Layer") {
        Component(usecases, "Use Cases & Services", "Business operations", "Order sync, stock sync, ad spend, customer service, feeds")
        Component(contracts, "Contracts", "Dispatcher interfaces", "Abstract job dispatch — decouples from Infrastructure")
    }

    Container_Boundary(domain, "Domain Layer") {
        Component(entities, "Entities & Value Objects", "Business objects", "Order, Product, Customer, StockItem, Money, IntId, Guid")
        Component(domainInterfaces, "Interfaces", "Cross-layer contracts", "Repository and service interfaces")
        Component(exceptions, "Exceptions", "Business rules", "Transient/Permanent API failure hierarchy, domain violations")
    }

    Container_Boundary(infrastructure, "Infrastructure Layer") {
        Component(clients, "API Clients", "External communication", "Shopwired, Linnworks, Google Ads, Bing Ads, Mixpanel, HelpScout, Reviews.io")
        Component(jobs, "Queue Jobs", "Async delivery mechanisms", "Retry policies, exception translation, queue routing")
        Component(repos, "Repositories", "Data access", "Eloquent implementations of Domain interfaces")
    }

    Rel(controllers, usecases, "Delegates to")
    Rel(commands, usecases, "Delegates to")
    Rel(usecases, domainInterfaces, "Depends on")
    Rel(contracts, jobs, "Implemented by")
    Rel(clients, domainInterfaces, "Implements")
    Rel(repos, domainInterfaces, "Implements")
    Rel(jobs, usecases, "Invokes")
    Rel(clients, exceptions, "Translates SDK errors to")
```

**Rules:** Domain depends on nothing. Application depends only on Domain. Infrastructure implements Domain interfaces. Presentation calls Application, never Infrastructure directly. Interfaces live where they're **used**, not where they're implemented.

---

## 4. Key Data Flows

### Webhook → Re-fetch Pattern (orders, products, customers, etc.)

```mermaid
flowchart LR
    SW[Shopwired] -->|"HMAC-signed\nwebhook"| CTRL[Controller]
    CTRL --> SVC[HandleWebhookService]
    SVC -->|"Route by intent"| UC[Use Case]
    UC -->|Save partial data| DB[(Supabase)]
    UC -->|"Dispatch via\ninterface"| JOB[SyncEntityJob]
    JOB -->|"Re-fetch full\nentity from API"| SW
    JOB -->|Persist| DB
    CTRL -->|"200 OK"| SW
```

Webhooks save the partial payload synchronously, dispatch a single re-fetch job, and return immediately. Downstream analytics and cross-system syncs run on their own schedules.

### Scheduled Sync Pattern (inventory, ad spend, analytics)

```mermaid
flowchart LR
    SCHED[Scheduler] -->|"Cron trigger"| JOB[Sync Job]
    JOB -->|"Fetch data"| API[External API]
    API --> JOB
    JOB -->|Persist / transform| DB[(Supabase)]
    JOB -->|"Forward to\ndownstream"| DEST[Destination API]
```

Nine schedule providers orchestrate background work: Shopwired entity syncs, Linnworks stock/order sync, inventory push to Shopwired, ad spend to Mixpanel, product feeds, Reviews.io ratings, and queue maintenance.

### Customer Service (request-driven)

```mermaid
flowchart LR
    ADMIN[Admin Dashboard] -->|"JWT Bearer"| WEB[Web Service]
    WEB --> UC[Use Case]
    UC -->|Check cache| CACHE[(Redis)]
    CACHE -->|Miss or refresh| HS[HelpScout API]
    UC -->|Response| ADMIN
```

---

## 5. Exception Flow

```mermaid
flowchart TD
    SDK[SDK/API Exception] -->|Caught in| INFRA[Infrastructure Layer]
    INFRA -->|"Log + translate"| DOM[Domain Exception]
    DOM -->|Bubbles through| APP[Application Layer]
    APP --> PRES[Presentation Layer]

    PRES -->|Queue job| QUEUE{"Exception type?"}
    QUEUE -->|TransientApiFailure| RETRY[Retry with backoff]
    QUEUE -->|PermanentApiFailure| FAIL[Fail immediately]
    QUEUE -->|Throwable| REPORT[Report to Sentry]
```

Infrastructure catches SDK exceptions, logs technical details, and translates to domain exceptions. Application doesn't catch — exceptions bubble to Presentation, which handles delivery (HTTP responses, queue retry logic).

---

## Further Reading

- [CLAUDE.md](../../CLAUDE.md) — Project conventions, layer rules, development setup
- [tests/TestingStrategy.md](../../tests/TestingStrategy.md) — What to test per layer
- [docs/guides/critical-pitfalls.md](guides/critical-pitfalls.md) — Date range and sync pitfalls
- [docs/deployment/railway-octane-setup.md](deployment/railway-octane-setup.md) — Railway deployment details
- Layer-specific CLAUDE.md files in `app/Domain/`, `app/Application/`, `app/Infrastructure/`, `app/Presentation/`
