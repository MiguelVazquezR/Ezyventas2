# 14 — Reports & Dashboard Module

---

## What It Does
Main dashboard with sales KPIs, financial control/reports, inventory reports, product performance reports, and data export capabilities. Provides analytics across sales, expenses, services, and staff performance.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Main dashboard + expiring layaways, upcoming deliveries |
| `app/Http/Controllers/FinancialReportController.php` | Financial control page + export |
| `app/Http/Controllers/InventoryReportController.php` | Inventory reports |
| `app/Http/Controllers/ImportExportController.php` | Multi-entity import/export |
| `app/Services/SalesDashboardService.php` | Sales dashboard data aggregation |
| `app/Services/FinancialReportService.php` | Financial report logic |
| `app/Services/InventoryReportService.php` | Inventory analytics |
| `app/Services/CustomerReportService.php` | Customer analytics |
| `app/Services/ExpenseReportService.php` | Expense analytics |
| `app/Services/ServiceOrderReportService.php` | Service order analytics |
| `app/Services/QuoteInvoiceReportService.php` | Quote/invoice analytics |
| `app/Services/PromotionReportService.php` | Promotion performance |
| `app/Services/CashRegisterReportService.php` | Cash register reports |
| `app/Services/StaffPerformanceService.php` | Staff performance metrics |
| `app/Exports/FinancialReportExport.php` | Multi-sheet financial Excel export |
| `app/Exports/Sheets/` | Individual sheets for financial export |
| `routes/web/reports.php` | Financial export route |
| `routes/web/products-reports.php` | Inventory report routes |
| `routes/web/import-export.php` | Import/export routes |
| `routes/web/financial-control.php` | Financial control page route |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Dashboard.vue` | Main dashboard |
| `Pages/FinancialControl/Index.vue` | Financial control page |
| `Pages/Product/Reports.vue` | Inventory/product reports |
| `Pages/Product/Reports/PrintReport.vue` | Printed report |
| `Pages/Admin/Reports/Index.vue` | Super-admin reports |
| `Components/DashboardGraph.vue` | Chart.js graph component |

---

## Main Endpoints

### Dashboard
- `GET /dashboard` — Main dashboard view
- `GET /dashboard/expiring-layaways` — Expiring layaway alerts
- `GET /dashboard/upcoming-deliveries` — Upcoming delivery orders

### Financial Control
- `GET /financial-control` — `financial-control.index` — Financial dashboard
- `GET /financial-control/export` — `financial-control.export` — Excel export

### Product/Inventory Reports
- `GET /productos/reportes` — `products.reports` — Report page
- `GET /productos/reportes/imprimir` — `products.reports.print` — Print
- `GET /productos/reportes/generar` — `products.reports.generate` — Generate data

### Import/Export
- `GET /export/products` — Export products to Excel
- `GET /export/products/info` — Product export metadata
- `POST /import/products` — Import products from Excel
- `GET /export/customers` — Export customers
- `POST /import/customers` — Import customers
- `GET /export/expenses` — Export expenses
- `POST /import/expenses` — Import expenses
- `GET /export/services` — Export services
- `POST /import/services` — Import services
- `GET /export/service-orders` — Export service orders
- `POST /import/service-orders` — Import service orders
- `GET /export/quotes` — Export quotes

---

## Dashboard KPIs

The dashboard (`SalesDashboardService`) typically shows:
- Today's sales total
- Transaction count
- Average ticket
- Top-selling products
- Sales by hour/day chart
- Expiring layaways (within X days)
- Upcoming deliveries
- Recent transactions

---

## Dependencies
- **All modules**: Reports pull data from transactions, products, customers, expenses, etc.
- **Maatwebsite Excel**: All exports use this package
- **Chart.js**: Dashboard graphs

---

## Known Limitations / Technical Debt
1. **No date range picker on dashboard** — Dashboard shows today/current period without custom date selection.
2. **Reports use direct DB queries** — Some report services may bypass Eloquent for performance, making them harder to maintain.
3. **Import is fragile** — Excel imports have no error recovery; if one row fails, the whole import may fail.
4. **No scheduled reports** — No automated email delivery of reports.
5. **No comparison periods** — Reports show absolute numbers without YoY or period-over-period comparison.
6. **Mobile dashboard is separate** — `IndexMobile.vue` in POS may have different features than desktop.
