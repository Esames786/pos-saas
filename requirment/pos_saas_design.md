# POS SaaS — Design Document (design.md)

## 1. Overview
A Laravel 13 multi-tenant SaaS POS/ERP for restaurants, stores, and hybrid businesses. One codebase, one master DB, database-per-tenant. Wildcard DNS for subdomains.

## 2. Architecture
- Monolith app with microservice-like isolation (DB-per-tenant)
- Master DB: tenants, domains, databases, plans, subscriptions, route_catalog
- Tenant DB: operational data (users, POS, inventory, purchasing, printers, tables)
- Middleware: IdentifyTenant → switch DB dynamically

## 3. Tenancy Flow
Request host → tenant_domains → tenant → tenant_databases → set connection → serve request

## 4. Modules
- Auth & Users (Spatie roles/permissions)
- Branches, Terminals, Shifts
- Catalog (categories, products, variants, barcodes)
- Inventory (ledger, batches, expiry, FEFO)
- Recipes (ingredients, recipe headers/lines)
- Purchasing (suppliers, PO, GRN, bills, payments)
- POS (store + restaurant)
- Tables/Floors/Waiter assignment
- Printers/KOT (stations, routing, jobs)
- Promotions/Discounts
- Tax/GST engine
- Payments (split, methods, banks)
- Reports
- Settings (currency, denominations, templates, sequences)

## 5. i18n
English-first, multi-language ready:
- resources/lang/en/*
- translation tables for products/categories
- RTL support (future Arabic)

## 6. Printing Design
- Prep stations → printers
- Routing rules by category/product
- QZ Tray or system print fallback
- Print jobs queue with retries

## 7. POS UX
- Fast search + barcode scan
- Touch-friendly UI
- Split bill, discounts, coupons
- Payment modal (multi-line)

## 8. Inventory Design
- Stock ledger is source of truth
- Batches + expiry (FEFO)
- Recipe auto-consumption

## 9. Security
- Tenant DB isolation
- Permission per route
- Audit via ledgers/logs

## 10. Deployment
- Nginx + PHP-FPM
- MySQL (master + tenant DBs)
- Redis queues
- Scheduler
- Wildcard SSL

## 11. Payment Design
- Gateways: 2Checkout (global), PayFast/PayPro (PK), Payoneer (payout)
- Webhooks → subscription updates

## 12. UI Screens (high-level)
Auth, Dashboard, Admin (tenants/plans), Users/Roles, Branches, Catalog, Inventory, Purchasing, POS, Restaurant, Printers, Reports, Settings

