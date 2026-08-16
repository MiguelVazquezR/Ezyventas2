# 12 — Promotions Module

---

## What It Does
Flexible promotion/discount engine: define promotions with rules (conditions) and effects (discounts), apply to specific products or categories, set date ranges and usage limits, and apply at checkout in POS. Supports cart-level and product-level promotions.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/Promotion.php` | Promotion with type, dates, limits |
| `app/Models/PromotionRule.php` | Conditions that trigger the promotion |
| `app/Models/PromotionEffect.php` | Discount/freebie effects |
| `app/Enums/PromotionType.php` | Type of promotion |
| `app/Enums/PromotionRuleType.php` | Rule condition types |
| `app/Enums/PromotionEffectType.php` | Effect types (discount %, fixed amount, free item, etc.) |
| `app/Http/Controllers/PromotionController.php` | Promotion update/delete |
| `app/Http/Controllers/ProductPromotionController.php` | Create promotions on products |
| `app/Services/PromotionReportService.php` | Promotion analytics |
| `routes/web/promotions.php` | Promotion routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Promotion/Create.vue` | Create promotion form |

---

## Main Endpoints

### Promotions (nested under products)
- `GET /products/{product}/promotions/create` — `products.promotions.create` — Create form
- `POST /products/{product}/promotions` — `products.promotions.store` — Create
- `PATCH /promotions/{promotion}` — `promotions.update` — Update
- `DELETE /promotions/{promotion}` — `promotions.destroy` — Delete

---

## Promotion Architecture

### Polymorphic Targeting
Both `PromotionRule` and `PromotionEffect` use polymorphic `itemable` to target:
- A specific `Product` (discount on product X)
- A `Category` (discount on all products in category)
- (Extensible to other entity types)

### Rule Types (conditions)
Conditions determine WHEN a promotion is applied:
- Minimum quantity of items
- Specific product in cart
- Specific category in cart
- Cart total threshold

### Effect Types (actions)
Effects determine WHAT the promotion does:
- Percentage discount on specific products
- Fixed amount off
- Buy X get Y free
- Cart-level discount

### Application at Checkout
The `promotion_transaction` pivot table tracks which promotions were applied to which transactions. This enables:
- Usage limit enforcement (`usage_limit` on Promotion)
- Promotion performance reporting
- Audit trail of applied discounts

---

## Promotion Fields

| Field | Description |
|---|---|
| `type` | Category of promotion (e.g., discount, bundle) |
| `start_date`, `end_date` | Active date range |
| `is_active` | Manual on/off switch |
| `is_exclusive` | Cannot be combined with other promotions |
| `usage_limit` | Max times this promotion can be used (null = unlimited) |
| `priority` | For conflict resolution when multiple promotions apply |

---

## Dependencies
- **Products**: Rules and effects can target products
- **Categories**: Rules and effects can target categories
- **Transactions**: Applied promotions tracked via pivot

---

## Known Limitations / Technical Debt
1. **No coupon codes** — Promotions are applied automatically based on cart contents; there's no manual coupon code entry system.
2. **No Buy X Get Y (BOGO) fully implemented** — The polymorphic effect structure supports it, but the checkout logic may not be complete.
3. **Promotion conflict resolution is basic** — Uses `priority` integer but no sophisticated conflict rules.
4. **No customer-specific promotions** — Promotions can't target specific customer groups or tiers.
5. **Limited frontend** — Only a Create page exists; no Index or Edit dedicated pages (editing is modal-based from product pages).
6. **No bulk promotion creation** — Each promotion is created per-product; no way to apply a promotion to an entire category at once from the UI.
