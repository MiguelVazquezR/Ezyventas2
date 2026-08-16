# 01 — Database Schema

> Covers ~100 tables grouped by domain. Focus on key columns, relationships, and business logic.
> Enum values are in Spanish (UI language); code identifiers in English.

---

## 1. Users / Auth / Tenancy (~15 tables)

### `subscriptions`
The top-level tenant entity. Each paying customer = 1 subscription.
| Key Columns | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `business_name` | string | Legal business name |
| `commercial_name` | string | Display name |
| `business_type_id` | FK → `business_types` | e.g., retail, repair shop |
| `status` | enum | `SubscriptionStatus`: `activa`, `expirada`, `suspendida` |
| `slug` | string | Unique URL slug for store |
| `tax_id` | string | RFC / tax ID |
| `address` | json | Street, city, state, zip |
| `contact_phone`, `contact_email` | string | |
| `onboarding_completed_at` | datetime | null until wizard done |
| `referrer_discount_active` | boolean | Ongoing referrer discount flag |

### `business_types`
| Column | Notes |
|---|---|
| `id`, `name` | e.g., "Taller mecánico", "Tienda de ropa" |

### `branches`
Each subscription has 1+ branches. Multi-branch inventory and sales scoping.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `subscription_id` | FK → `subscriptions` |
| `name` | string |
| `is_main` | boolean | One main branch per subscription |
| `manager_id` | FK → `users` (nullable) | Branch manager |
| `address` | json | |
| `contact_phone`, `contact_email` | string | |
| `timezone` | string | IANA timezone |
| `operating_hours` | json | Schedule |

### `users`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `email`, `password` | Standard auth |
| `phone` | string | |
| `is_active` | boolean | Soft disable |
| `branch_id` | FK → `branches` | User's home branch |
| `email_verified_at` | datetime | |
| `google_id` | string | Google OAuth link |

### `roles` (Spatie — `spatie/laravel-permission`)
Standard Spatie tables: `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions`.

### `personal_access_tokens` (Sanctum)
Standard Laravel Sanctum tokens table.

### `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs`
Standard Laravel tables.

### `onboarding_tours`, `onboarding_tour_user`
Tracks which onboarding steps a user has completed.

---

## 2. Products / Inventory / Catalog (~18 tables)

### `categories`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name` | string |
| `type` | string | `product`, `service`, or `both` |
| `business_type` | string | |
| `subscription_id` | FK → `subscriptions` | Scoped per tenant |

### `brands`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name` | string |
| `subscription_id` | FK → `subscriptions` |

### `brand_business_type` (pivot)
Links brands to business types (many-to-many).

### `providers`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `contact_name`, `email`, `phone` | |
| `address` | json | |
| `subscription_id` | FK → `subscriptions` |

### `global_products`
Shared product catalog (base catalog) available to all tenants for import.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `description`, `sku` | |
| `selling_price` | decimal | Suggested price |
| `category_id`, `brand_id` | FK | |
| `business_type_id` | FK | |
| `image` (media) | Spatie | Single product image |

### `products` ⭐
The main product entity, scoped per branch.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `description`, `sku` | |
| `selling_price`, `cost_price` | decimal | |
| `price_tiers` | json | Tiered pricing (wholesale) |
| `measure_unit` | string | `pieza`, `kg`, `lt`, etc. |
| `category_id` | FK → `categories` | |
| `brand_id` | FK → `brands` | |
| `provider_id` | FK → `providers` | |
| `branch_id` | FK → `branches` | Owning branch |
| `global_product_id` | FK → `global_products` (nullable) | Link to base catalog |
| `is_bulk` | boolean | Sold by weight/measure |
| `show_in_pos` | boolean | Visible in POS terminal |
| `show_online` | boolean | Visible in online store |
| `online_price` | decimal | Price in online store |
| `slug` | string | SEO-friendly URL |
| `is_featured`, `is_on_sale` | boolean | Storefront flags |
| `sale_price` | decimal | |
| `sale_start_date`, `sale_end_date` | datetime | |
| `weight`, `length`, `width`, `height` | decimal | Shipping dimensions |
| `requires_shipping` | boolean | |
| `view_count`, `purchase_count` | integer | Analytics |
| `delivery_days` | integer | Estimated delivery time |
| `tags` | json | Search tags |
| Media: `product-general-images`, `product-variant-images` | Spatie | |

### `product_attributes` (variants)
Product variants with stock tracked separately.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `product_id` | FK → `products` | Parent product |
| `attributes` | json | e.g., `{"Color": "Rojo", "Talla": "M"}` |
| `selling_price_modifier` | decimal | Added to base price |
| `sku_suffix` | string | Appended to parent SKU |
| `global_product_id` | FK → `global_products` | |

### `product_components` (composite kits / bundles)
Allows a "combo" product to deduct stock from component products.
| Key Columns | Notes |
|---|---|
| `composite_product_id` | FK → `products` | The kit/parent product |
| `componentable_id` + `componentable_type` | polymorphic | Points to `Product` or `ProductAttribute` |
| `quantity` | decimal | How many of the component needed |

### `branch_product` (pivot)
Per-branch inventory for products with stock.
| Key Columns | Notes |
|---|---|
| `branch_id` + `product_id` | composite PK | |
| `current_stock` | decimal | |
| `reserved_stock` | decimal | Held for pending orders |
| `min_stock`, `max_stock` | decimal | Reorder thresholds |
| `location` | string | Warehouse location |

### `branch_product_attribute` (pivot)
Per-branch inventory for product variants.
| Key Columns | Same structure as `branch_product` but FK → `product_attributes` | |

### `attribute_definitions`
Schema for product attributes (e.g., "Color", "Talla").
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name` | string | e.g., "Color" |
| `subscription_id` | FK → `subscriptions` | |

### `attribute_options`
Predefined values per attribute.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `attribute_definition_id` | FK | Parent attribute |
| `value` | string | e.g., "Rojo", "Azul" |

### `product_reviews`
| Key Columns | Notes |
|---|---|
| `product_id` | FK → `products` | |
| `customer_name`, `customer_email` | string | |
| `rating` | integer | 1-5 |
| `title`, `comment` | text | |

### `media` (Spatie Media Library)
Standard Spatie table for all file uploads.

---

## 3. Transactions / Sales / POS (~9 tables)

### `transactions` ⭐
Every sale, layaway, or order goes here.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `folio` | string | Human-readable sequential number |
| `customer_id` | FK → `customers` (nullable) | |
| `contact_info` | json | Name/phone if no customer account |
| `branch_id` | FK → `branches` | |
| `user_id` | FK → `users` | Cashier |
| `cash_register_session_id` | FK → `cash_register_sessions` | |
| `transactionable_id` + `transactionable_type` | polymorphic | Links to `ServiceOrder`, `Order`, etc. |
| `status` | enum | `TransactionStatus`: `completado`, `pendiente`, `cancelado`, `reembolsado`, `apartado`, `cambiado`, `por_entregar`, `en_ruta`, `entregado_por_pagar` |
| `delivery_status` | string | For delivery orders |
| `channel` | enum | `TransactionChannel`: POS, online store, etc. |
| `subtotal`, `shipping_cost`, `total_discount`, `total_tax` | decimal | |
| `currency` | string | Default MXN |
| `notes` | text | |
| `shipping_address` | json | |
| `invoiced` | boolean | |
| `layaway_expiration_date` | date | For layaways |
| `delivery_date` | datetime | For deliveries |
| `created_at`, `updated_at` | | |

Computed (accessor): `total` (subtotal - discount + tax + shipping), `total_paid` (sum of payments), `remaining_due`.

### `transaction_items`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `transaction_id` | FK → `transactions` | |
| `itemable_id` + `itemable_type` | polymorphic | `Product`, `ProductAttribute`, `Service`, `ServiceVariant` |
| `description` | string | |
| `quantity`, `unit_price` | decimal | |
| `discount_amount`, `discount_reason` | decimal, string | |
| `tax_amount`, `line_total` | decimal | |

### `payments`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `transaction_id` | FK → `transactions` | |
| `cash_register_session_id` | FK → `cash_register_sessions` | |
| `bank_account_id` | FK → `bank_accounts` (nullable) | |
| `amount` | decimal | |
| `payment_method` | enum | `PaymentMethod`: `efectivo`, `tarjeta`, `transferencia`, etc. |
| `payment_date` | datetime | |
| `status` | enum | `PaymentStatus` |
| `notes` | text | |

### `customers`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `branch_id` | FK → `branches` | |
| `company_name`, `name` | string | Company or individual |
| `email`, `phone` | string | |
| `address` | json | |
| `tax_id` | string | RFC |
| `balance` | decimal | Current outstanding balance |
| `credit_limit` | decimal | Max credit allowed |

Computed: `available_credit` = `credit_limit - balance`.

### `customer_balance_movements`
Audit trail for customer balance changes.
| Key Columns | Notes |
|---|---|
| `customer_id` | FK → `customers` | |
| `transaction_id` | FK → `transactions` (nullable) | |
| `type` | enum | `CustomerBalanceMovementType`: `credit_usage`, `payment`, `refund_credit`, `cancellation_credit`, `manual_adjustment` |
| `amount`, `balance_after` | decimal | |
| `notes` | text | |

### `customer_balance_movements` and `layawayTransactions` / `layawayItems`
Layaway-specific functionality accessed via `hasMany` on Customer model using `status = 'apartado'` scoping.

---

## 4. Services / Service Orders (~6 tables)

### `services`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `category_id` | FK → `categories` | |
| `branch_id` | FK → `branches` | |
| `name`, `description`, `slug` | | |
| `base_price` | decimal | |
| `duration_estimate` | integer | Minutes |
| `show_online` | boolean | |

### `branch_service` (pivot)
Links services to branches with availability.

### `service_variants`
Service pricing tiers.
| Key Columns | Notes |
|---|---|
| `service_id` | FK → `services` | |
| `name` | string | e.g., "Básico", "Premium" |
| `price` | decimal | |
| `duration_estimate` | integer | Minutes |

### `service_orders` ⭐
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `folio` | string | Sequential number |
| `branch_id` | FK → `branches` | |
| `user_id` | FK → `users` | Creator |
| `quote_id` | FK → `quotes` (nullable) | Source quote |
| `customer_id` | FK → `customers` | |
| `itemable_id` + `itemable_type` | polymorphic | Item being serviced |
| `customer_name/email/phone/address` | | Snapshot at order time |
| `technician_name` | string | |
| `technician_commission_type/value` | string/decimal | |
| `status` | enum | `ServiceOrderStatus`: `pendiente`, `en_progreso`, `esperando_refaccion`, `terminado`, `entregado`, `cancelado` |
| `received_at`, `promised_at` | datetime | |
| `item_description` | text | |
| `reported_problems` | text | |
| `technician_diagnosis` | text | |
| `subtotal`, `discount_type`, `discount_value`, `discount_amount`, `final_total` | | |
| `custom_fields`, `customer_address` | json | |
| Media: `initial-service-order-evidence`, `closing-service-order-evidence` | Spatie | Before/after photos |

### `service_order_items`
| Key Columns | Notes |
|---|---|
| `service_order_id` | FK → `service_orders` | |
| `itemable_id` + `itemable_type` | polymorphic | `Service`, `ServiceVariant`, `Product` |
| `description` | string | |
| `quantity`, `unit_price`, `line_total` | decimal | |

---

## 5. Quotes (~2 tables)

### `quotes`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `folio` | string | |
| `branch_id`, `user_id` | FK | |
| `customer_id` | FK → `customers` | |
| `transaction_id` | FK → `transactions` (nullable) | If converted to sale |
| `parent_quote_id` | FK → `quotes` | For version tracking |
| `expiry_date` | date | |
| `status` | enum | `QuoteStatus` |
| `subtotal`, `total_discount`, `total_tax`, `shipping_cost`, `total_amount` | decimal | |
| `tax_type`, `tax_rate` | string/decimal | |
| `notes`, `custom_fields`, `shipping_address` | text/json | |
| `recipient_name/email/phone` | string | |
| `version_number` | integer | |
| `status_changed_at` | datetime | |

### `quote_items`
| Key Columns | Notes |
|---|---|
| `quote_id` | FK → `quotes` | |
| `itemable_id` + `itemable_type` | polymorphic | `Product`, `ProductAttribute`, `Service`, `ServiceVariant` |
| `description` | string | |
| `quantity`, `unit_price`, `line_total` | decimal | |
| `variant_details` | json | |

---

## 6. Invoices / CFDI (~3 tables)

### `invoices`
Electronic invoices (CFDI 4.0 for Mexico).
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `branch_id` | FK → `branches` | |
| `customer_id` | FK → `customers` | |
| `series`, `folio` | string | Invoice numbering |
| `status` | enum | `InvoiceStatus`: `no_solicitada`, `solicitada`, `generada`, `borrador`, `pendiente`, `certificada`, `cancelada` |
| `uuid` | string | SAT UUID |
| `xml_url`, `pdf_url` | string | |
| `issued_at`, `canceled_at` | datetime | |
| `receiver_rfc/legal_name/tax_regime/postal_code` | | Receptor data |
| `cfdi_use`, `payment_form`, `payment_method` | string | SAT codes |
| `currency` | string | |
| `subtotal`, `discount_total`, `taxes_total`, `total` | decimal | |
| `cancellation_reason` | text | |

### `invoice_items`
| Key Columns | Notes |
|---|---|
| `invoice_id` | FK → `invoices` | |
| `product_id` | FK → `products` | |
| `description` | string | |
| `quantity`, `unit_price` | decimal | |
| `sat_unit_code`, `sat_product_code` | string | SAT catalog codes |
| `tax_type`, `tax_rate` | string/decimal | |
| `subtotal`, `discount_amount`, `tax_amount`, `total` | decimal | |

### `billing_settings`
Per-branch CFDI emission settings.
| Key Columns | Notes |
|---|---|
| `branch_id` | FK → `branches` | |
| `emitter_rfc/legal_name/tax_regime/postal_code` | string | Facturación data |
| `api_key` | encrypted | External PAC API key |

---

## 7. Cash Register (~3 tables)

### `cash_registers`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `branch_id` | FK → `branches` | |
| `name` | string | e.g., "Caja 1" |
| `is_active`, `in_use` | boolean | |

### `cash_register_sessions`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `cash_register_id` | FK → `cash_registers` | |
| `user_id` | FK → `users` | Owner (opener) |
| `opened_at`, `closed_at` | datetime | |
| `status` | enum | `CashRegisterSessionStatus` |
| `opening_cash_balance` | decimal | Initial float |
| `opening_bank_balances` | json | Snapshot of bank balances |
| `closing_cash_balance` | decimal | Counted at close |
| `calculated_cash_total` | decimal | Computed from transactions |
| `cash_difference` | decimal | `closing_cash_balance - calculated_cash_total` |
| `notes` | text | |

### `cash_register_session_user` (pivot)
Many-to-many between sessions and users (multi-user sessions).

### `session_cash_movements`
Cash inflows/outflows during a session.
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `cash_register_session_id` | FK | |
| `user_id` | FK → `users` | |
| `type` | enum | `SessionCashMovementType`: `inflow`, `outflow` |
| `amount` | decimal | |
| `description` | string | |

---

## 8. Expenses & Banking (~4 tables)

### `expenses`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `folio` | string | |
| `user_id` | FK → `users` | |
| `branch_id` | FK → `branches` | |
| `amount` | decimal | |
| `expense_category_id` | FK → `expense_categories` | |
| `expense_date` | date | |
| `status` | enum | `ExpenseStatus` |
| `description` | text | |
| `payment_method` | enum | `PaymentMethod` |
| `bank_account_id` | FK → `bank_accounts` (nullable) | |
| `session_cash_movement_id` | FK → `session_cash_movements` (nullable) | If paid from cash register |
| `is_external` | boolean | Non-operational expense |

### `expense_categories`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name` | string | e.g., "Renta", "Servicios" |
| `description` | text | |
| `subscription_id` | FK → `subscriptions` | |

### `bank_accounts`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `subscription_id` | FK → `subscriptions` | |
| `bank_name`, `owner_name`, `account_name` | string | |
| `account_number`, `card_number`, `clabe` | string | |
| `balance` | decimal | Tracked balance |

### `bank_account_transfers`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `folio` | string | |
| `subscription_id` | FK | |
| `from_account_id`, `to_account_id` | FK → `bank_accounts` | |
| `amount` | decimal | |
| `notes` | text | |
| `transfer_date` | datetime | |

### `bank_account_branch` (pivot) + `bank_account_user` (pivot)
Association of accounts with branches and users.

---

## 9. Promotions (~4 tables)

### `promotions`
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `description` | string | |
| `subscription_id` | FK → `subscriptions` | |
| `type` | enum | `PromotionType` |
| `start_date`, `end_date` | datetime | |
| `is_active`, `is_exclusive` | boolean | |
| `usage_limit` | integer | null = unlimited |
| `priority` | integer | For conflict resolution |

### `promotion_rules`
Conditions that trigger a promotion.
| Key Columns | Notes |
|---|---|
| `promotion_id` | FK → `promotions` | |
| `type` | enum | `PromotionRuleType` |
| `value` | string | Rule value |
| `itemable_id` + `itemable_type` | polymorphic | Target product/category |

### `promotion_effects`
What the promotion does when triggered.
| Key Columns | Notes |
|---|---|
| `promotion_id` | FK → `promotions` | |
| `type` | enum | `PromotionEffectType` |
| `value` | decimal | e.g., discount % or fixed amount |
| `itemable_id` + `itemable_type` | polymorphic | Affected product |

### `promotion_transaction` (pivot)
Tracks which promotions were applied to which transactions.

---

## 10. Online Store / E-Commerce (~4 tables)

### `store_configs`
One config per subscription (1:1).
| Key Columns | Notes |
|---|---|
| `subscription_id` | FK → `subscriptions` | |
| `slug` | string | Subdomain path |
| `is_active` | boolean | |
| `store_name`, `description`, `tagline` | string | |
| `primary_color`, `secondary_color`, `theme_mode` | | |
| `whatsapp_number` | string | |
| `accepts_pickup`, `accepts_delivery` | boolean | |
| `delivery_fee`, `free_shipping_minimum` | decimal | |
| `preparation_time_minutes` | integer | |
| `allow_out_of_stock_purchases`, `out_of_stock_extra_minutes` | boolean/int | |
| `delivery_policy`, `terms_policy`, `footer_note` | text | |
| `custom_domain` | string | |
| `mp_access_token/refresh_token/user_id/public_key` | | MercadoPago OAuth |
| `mp_token_expires_at` | datetime | |
| `payment_mp_enabled`, `payment_cash_enabled` | boolean | |
| `cash_instructions` | text | |
| `notify_email_enabled` | boolean | |
| `notification_emails` | json | |
| Media: `store-logo`, `store-banners` | Spatie | |

### `orders`
Online store orders.
| Key Columns | Notes |
|---|---|
| `subscription_id` | FK | Direct for indexing |
| `store_config_id` | FK → `store_configs` | |
| `transaction_id` | FK → `transactions` (nullable) | After POS processing |
| `order_number` | string | |
| `status` | enum | `OrderStatus`: `pending`, `reviewed`, `in_preparation`, `delivered`, `cancelled` |
| `delivery_type` | string | `pickup` or `delivery` |
| `payment_method` | string | `mercadopago` or `cash` |
| `customer_name/phone/email` | string | |
| `delivery_address` | text | |
| `customer_notes` | text | |
| `subtotal`, `delivery_fee`, `total` | decimal | |
| `delivered_at` | datetime | |

### `order_items`
| Key Columns | Notes |
|---|---|
| `order_id` | FK → `orders` | |
| `product_id` | FK → `products` | |
| `product_name`, `unit_price`, `quantity`, `subtotal` | | Snapshot at order time |

### `order_status_logs`
Audit trail of order status changes.

---

## 11. Subscriptions / Plans / Billing (~5 tables)

### `subscription_versions`
Each time a plan changes, a new version is created.
| Key Columns | Notes |
|---|---|
| `subscription_id` | FK → `subscriptions` | |
| `start_date`, `end_date` | datetime | Active period |

### `subscription_items`
Line items within a version (modules, features, etc.).
| Key Columns | Notes |
|---|---|
| `subscription_version_id` | FK → `subscription_versions` | |
| `item_key` | string | Machine name |
| `item_type` | string | |
| `name` | string | Display name |
| `quantity` | integer | |
| `unit_price` | decimal | |
| `billing_period` | enum | `BillingPeriod` |

### `subscription_payments`
| Key Columns | Notes |
|---|---|
| `subscription_version_id` | FK → `subscription_versions` | |
| `amount` | decimal | |
| `referral_discount_pct/amount` | decimal | |
| `payment_method` | string | |
| `invoiced` | boolean | |
| `invoice_status` | enum | |
| `status` | enum | `SubscriptionPaymentStatus`: `pendiente`, `aprobado`, `rechazado` |
| `payment_details` | json | |
| Media: `proof_of_payment` | Spatie | |

### `plan_items`
Plan catalog (super-admin defined).
| Key Columns | Notes |
|---|---|
| `key` | string | Machine name |
| `type` | enum | `PlanItemType` |
| `name`, `description` | string | |
| `monthly_price` | decimal | |
| `is_active` | boolean | |
| `meta` | json | |

---

## 12. Referrals (~4 tables)

### `referral_settings`
Global referral program config (singleton).
| Key Columns | Notes |
|---|---|
| `referred_discount_pct` | decimal | Discount for referred subscriber |
| `referrer_reward_pct` | decimal | One-time reward |
| `referrer_ongoing_discount_pct` | decimal | Recurring discount for referrer |

### `referral_codes`
Per-user referral codes.
| Key Columns | Notes |
|---|---|
| `user_id` | FK → `users` | |
| `code` | string | Unique |
| `is_active` | boolean | |

### `referral_usages`
When a code is used during subscription.
| Key Columns | Notes |
|---|---|
| `referral_code_id` | FK → `referral_codes` | |
| `referred_subscription_id` | FK → `subscriptions` | |
| `subscription_payment_id` | FK → `subscription_payments` | |
| `reward_status` | string | |
| `referred_discount_pct`, `referrer_reward_pct`, `referrer_ongoing_discount_pct` | decimal | Snapshot of rates |
| `monthly_base_amount`, `reward_amount` | decimal | |
| `reward_paid_at`, `seen_at` | datetime | |

### `referrer_bank_accounts`
Bank account for payouts to referrers.
| Key Columns | Notes |
|---|---|
| `user_id` | FK → `users` | |
| `clabe`, `bank_name`, `account_holder_name` | string | |

---

## 13. Settings & Miscellaneous (~6 tables)

### `setting_definitions` + `setting_values`
Dynamic settings system. `setting_values` uses polymorphic `settable` to attach to `User`, `Branch`, or `Subscription`.
| Key Columns (`setting_values`) | Notes |
|---|---|
| `setting_definition_id` | FK → `setting_definitions` | |
| `settable_id` + `settable_type` | polymorphic | |
| `value` | text | |

### `print_templates`
Customizable print/thermal templates.
| Key Columns | Notes |
|---|---|
| `subscription_id` | FK → `subscriptions` | |
| `name` | string | |
| `type` | enum | `TemplateType` |
| `context_type` | enum | `TemplateContextType` |
| `content` | json | Template structure |
| `is_default` | boolean | |

### `branch_print_template` (pivot)
Links templates to specific branches.

### `custom_field_definitions`
User-defined fields for entities (customers, products, service orders).
| Key Columns | Notes |
|---|---|
| `id` | PK |
| `name`, `key` | string | |
| `type` | string | `text`, `select`, `number`, etc. |
| `options` | json | For select type |
| `entity_type` | string | e.g., `customer`, `product` |
| `subscription_id` | FK → `subscriptions` | |

### `release_notes`
Changelog / product updates.
| Key Columns | Notes |
|---|---|
| `version`, `title`, `excerpt`, `content` | string/text | |
| `is_published`, `is_banner` | boolean | |
| `banner_title` | string | |
| `published_at` | datetime | |
| Media: `gallery`, `banner` | Spatie | |

### `release_note_user` (pivot)
Tracks which users have read which release notes.

### `activity_log` (Spatie Activitylog)
Standard Spatie table for model event logging.

### `waitlists`
Pre-launch email capture.
| Key Columns | Notes |
|---|---|
| `email` | string | Unique |

### `ai_usage_monthlies`
AI agent usage tracking per subscription.
| Key Columns | Notes |
|---|---|
| `subscription_id` | FK → `subscriptions` | |
| `year`, `month` | integer | |
| `credits_used`, `total_tokens` | integer | |
| `estimated_cost_usd` | decimal | |
