# 04 — Products & Inventory Module

---

## What It Does
Manages the product catalog: CRUD for products with variants (attributes), categories, brands, providers, a global/base catalog for shared products, composite kits (bundles), per-branch inventory/stock tracking with reservations, and product reviews. This is the largest module by model count.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/Product.php` | Main product with stock management, pricing tiers, online store config |
| `app/Models/ProductAttribute.php` | Product variants (size, color, etc.) |
| `app/Models/ProductComponent.php` | Composite kit items (bundles) |
| `app/Models/BranchProduct.php` | Pivot model for per-branch stock |
| `app/Models/BranchProductAttribute.php` | Pivot model for variant per-branch stock |
| `app/Models/Category.php` | Product/service categories |
| `app/Models/Brand.php` | Brands |
| `app/Models/Provider.php` | Providers/suppliers |
| `app/Models/GlobalProduct.php` | Base catalog (shared across tenants) |
| `app/Models/AttributeDefinition.php` | Schema for product attributes |
| `app/Models/AttributeOption.php` | Predefined values for attributes |
| `app/Models/ProductReview.php` | Customer reviews |
| `app/Actions/Product/CreateProduct.php` | Product creation orchestrator |
| `app/Actions/Product/UpdateProduct.php` | Product update orchestrator |
| `app/Actions/Product/AdjustProductStockAction.php` | Stock adjustment |
| `app/Http/Controllers/ProductController.php` | Product CRUD + toggles |
| `app/Http/Controllers/ProductStockController.php` | Stock operations |
| `app/Http/Controllers/AttributeDefinitionController.php` | Attribute CRUD |
| `app/Http/Controllers/CategoryController.php` | Category CRUD |
| `app/Http/Controllers/BrandController.php` | Brand CRUD |
| `app/Http/Controllers/ProviderController.php` | Provider CRUD |
| `app/Http/Controllers/GlobalProductController.php` | Base catalog import |
| `app/Http/Controllers/BaseCatalogController.php` | Base catalog linking |
| `app/Http/Controllers/ProductReviewController.php` | Review management |
| `app/Http/Controllers/ProductPromotionController.php` | Promotions on products |
| `app/Services/InventoryReportService.php` | Inventory analytics |
| `app/Exports/ProductsExport.php` | Product export |
| `app/Imports/` | Product import classes |
| `routes/web/products.php` | Product routes |
| `routes/web/products-stock.php` | Stock routes |
| `routes/web/base-catalog.php` | Base catalog routes |
| `routes/web/categories.php` | Category routes |
| `routes/web/brands.php` | Brand routes |
| `routes/web/providers.php` | Provider routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Product/Index.vue` | Product list with filters |
| `Pages/Product/Create.vue` | Create product form |
| `Pages/Product/Edit.vue` | Edit product form |
| `Pages/Product/Show.vue` | Product detail |
| `Pages/Product/BaseCatalog.vue` | Global product catalog browser |
| `Pages/Product/Reports.vue` | Inventory/product reports |
| `Pages/Product/Reports/PrintReport.vue` | Print report |
| `Components/ManageCategoriesModal.vue` | Inline category management |
| `Components/ManageBrandsModal.vue` | Inline brand management |
| `Components/ManageProvidersModal.vue` | Inline provider management |
| `Components/ManageCustomFields.vue` | Custom field editor |
| `Components/CreateProductModal.vue` | Quick-create product |

---

## Main Endpoints

### Products (`/products`)
- Full resource CRUD: `index`, `create`, `store`, `show`, `edit`, `update`, `destroy`
- `POST /products/batch-destroy` — Bulk delete
- `POST /products/bulk-update` — Bulk price/category update
- `POST /products/update-price-pos` — Quick price update from POS
- `PUT /products/{product}/toggle-online` — Show/hide in online store
- `PUT /products/{product}/toggle-featured` — Toggle featured flag
- `PUT /products/{product}/toggle-pos` — Show/hide in POS

### Stock (`/products/{product}/stock`)
- `POST /products/{product}/stock` — Adjust individual product stock
- `POST /products/stock/batch` — Batch stock adjustment

### Base Catalog (`/products/base-catalog`)
- `GET /products/base-catalog/index` — Browse global products
- `POST /products/base-catalog/import` — Import global product to tenant
- `POST /products/base-catalog/unlink` — Unlink from global

### Attribute Definitions (`/attribute-definitions`)
- Resource CRUD (except create/edit views): `index`, `store`, `update`, `destroy`

### Categories, Brands, Providers
- Categories at `GET /app/categories` (index, update, destroy)
- Brands at `GET /brands` (index, update, destroy)
- Providers at resource routes (index, update, destroy)

---

## Stock Management Architecture

### Location
Stock lives in pivot tables, not on the product itself:
- `branch_product` — `(branch_id, product_id, current_stock, reserved_stock, min_stock, max_stock, location)`
- `branch_product_attribute` — Same structure for variants

### Stock Change Methods
On `Product` and `ProductAttribute`:
- `processStockChange(int $branchId, float $quantity, string $type)` — Validates and executes stock change
- `applyDirectStockChange(int $branchId, float $quantity, string $type)` — Direct DB update (bypasses validation)
- Wrappers: `reserveStock()`, `deductStock()`, `restockStock()`, `releaseReservedStock()`

Stock type constants: `deduct`, `add`, `reserve`, `release_reserved`.

### Composite Kits
A product can be marked as a kit via `ProductComponent` records. When the kit is sold:
1. The kit product's own stock is deducted
2. Each component's stock is deducted (`componentable` morphs to `Product` or `ProductAttribute`)

---

## Dependencies
- **Subscriptions**: Products are scoped via `HasSubscription` trait (through branch)
- **Promotions**: Products can have promotions via `PromotionRule` and `PromotionEffect` polymorphic relations
- **Transactions/POS**: Products appear in `TransactionItem` via polymorphic `itemable`
- **Online Store**: Products can be shown online with `show_online` and `online_price`
- **Media Library**: Products use Spatie Media Library for images
- **Activity Log**: Product changes are logged via `LogsActivity` trait

---

## Known Limitations / Technical Debt
1. **No true inventory transactions table** — Stock changes are direct updates to pivot tables. There's no immutable audit trail of each stock movement.
2. **Product migration** (`2026_02_24_121005_clean_products_and_variants_tables.php`) suggests recent refactoring of product/variant structure — some edge cases may remain.
3. **Global products are not auto-synced** — If a global product changes, linked tenant products don't update.
4. **No barcode system** — Products have SKU but no barcode scanning integration.
5. **Stock validation at POS** — Stock checks happen at checkout time but there's no real-time stock reservation during cart building (only `reserved_stock` for orders).
6. **Decimal stock** — Supports decimal quantities (`decimal:2`) for bulk/measured products. Some calculations may have floating-point precision issues.
