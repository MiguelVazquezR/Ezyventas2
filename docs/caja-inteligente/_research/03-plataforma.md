# 03 — Plataforma Ezyventas2 (multi-tenancy, permisos, planes, impresión, pruebas, paquete local)

> Investigación de solo lectura sobre `C:\Users\windows 11\Desktop\Proyectos web\Ezyventas2`.
> Fecha de la corrida: 2026-11-27. Rama git: `Miguel`, último commit `e3de442`.
> Todas las afirmaciones se citan como `archivo:línea`. Cuando algo **no existe**, se dice explícitamente y se indica la búsqueda realizada.

---

## 1. Versiones y entorno

| Componente | Valor real medido | Evidencia |
|---|---|---|
| PHP CLI | **8.3.37** (NTS, VC++ 2019 x64, OPcache activo) | salida de `php -v` |
| Requisito declarado de PHP | `^8.2` | `composer.json:18` |
| Laravel Framework | **12.63.0** (`v12.63.0`) | `composer.lock:1826-1827`; `php artisan --version` |
| Inertia Laravel | v2.0.24 | `composer.lock` (entrada `inertiajs/inertia-laravel`) |
| spatie/laravel-permission | **6.25.0** | `composer.lock` (entrada `spatie/laravel-permission`); `composer.json:34` |
| PHPUnit | 11.5.56 | `composer.lock`; `composer.json:45` |
| Laravel Pint | v1.29.3 | `composer.lock`; `composer.json:42` |
| spatie/laravel-medialibrary | 11.23.2 | `composer.lock`; `composer.json:33` |
| pusher/pusher-php-server | 7.2.8 | `composer.lock`; `composer.json:31` |
| Composer | 2.10.3 | `composer --version` |
| Node.js | **v26.5.0** | `node -v` |
| npm | 11.17.0 | `cmd /c "npm -v"` |
| pnpm | 11.17.0 | `node "C:\Program Files\nodejs\node_modules\pnpm\bin\pnpm.mjs" --version` |

**Gestor de paquetes: los dos conviven (mixto).** Existen `package-lock.json` y `pnpm-lock.yaml` (ambos con la misma marca de tiempo, `27/11/2026 12:27`), y en `node_modules` están **a la vez** `node_modules/.pnpm` y `node_modules/.package-lock.json`. También hay `pnpm-workspace.yaml` (solo `allowBuilds` para `@parcel/watcher` y `esbuild`). Los scripts de `package.json` son agnósticos (`vite build` / `vite`, `package.json:5-8`), y `composer dev` usa `npx concurrently ... "npm run dev"` (`composer.json:78`), es decir, **la ruta oficial documentada en composer es npm**. Conclusión práctica: npm es el denominador común; pnpm funciona porque el árbol está instalado con ambos.

### Base de datos

| Dato | Valor | Evidencia |
|---|---|---|
| Driver | **mysql** | `.env:23` (`DB_CONNECTION=mysql`) |
| Host / puerto | 127.0.0.1:3306 | `.env:24-25` |
| Nombre de BD | **ezyventas2** | `.env:26` |
| Versión del servidor | MySQL **8.0.39** | `php artisan db:show` |
| Accesible | **Sí**. 1 conexión abierta | `php artisan db:show` |
| Tablas en `ezyventas2` | **105** | `DB::table('information_schema.tables')->where('table_schema','ezyventas2')->count()` |

Solo se ejecutaron lecturas (`db:show`, `count()`, `information_schema`). **No se escribió nada en la BD.**

Conteos leídos (útiles como referencia de estado del entorno de desarrollo): `subscriptions=15`, `branches=16`, `products=12`, `transactions=47`, `permissions=117`, `roles=11`, `plan_items=19`, `ai_conversations` existe.

---

## 2. MULTI-TENANCY (crítico)

### 2.1 Veredicto: **una sola base de datos, aislamiento por columna**. El "tenant" es `Subscription`.

- No existe paquete de tenancy (no hay `stancl/tenancy` en `composer.json:17-37`; búsqueda en `composer.json` y `config/` sin resultados).
- No hay base por tenant, ni schema por tenant, ni `tenancy.php`.
- El modelo tenant es **`App\Models\Subscription`** (`app/Models/Subscription.php:21`), y el modelo que cuelga de él y representa la sucursal operativa es **`App\Models\Branch`** (`app/Models/Branch.php:13`).

Cadena de resolución del tenant actual (la pieza que hay que memorizar):

```
auth()->user()            users.branch_id            (app/Models/User.php:47)
   -> branch              branches.subscription_id    (app/Models/Branch.php:18,38-41)
      -> subscription     = TENANT
```

Evidencia:
- `users.branch_id` está en `$fillable` de `User` (`app/Models/User.php:47`) y la relación es `belongsTo(Branch::class,'branch_id')` (`app/Models/User.php:101-103`).
- `User::subscription()` es un **`HasOneThrough`** (`app/Models/User.php:108-118`): `Subscription` ← `Branch` vía `users.branch_id` → `branches.subscription_id`.
- `Branch::subscription()` (`app/Models/Branch.php:38-41`).

**Patrón canónico en el código para saber "mi tenant":** `$user->branch->subscription_id` (p. ej. `app/Http/Middleware/EnsureSubscriptionScope.php:29,38`; `app/Http/Middleware/HandleInertiaRequests.php:28`; `app/Services/Printing/PrintDataSourceResolver.php:42`).

**No hay `subscription_id` en sesión.** La "sucursal actual" sí es implícita: es `users.branch_id`. Cambiar de sucursal (`PUT /switch-branch/{branch}` → `branch.switch`, `routes/web/switch-branch.php:7-9`, permiso `system.branches.switch`) implica reasignar `branch_id` al usuario, y por tanto cambia el tenant efectivo cuando las sucursales son de otra suscripción (caso superadmin).

### 2.2 Trait existente: `HasSubscription` (no es un GlobalScope)

`app/Traits/HasSubscription.php` es un trait **delgado**, no un scope automático:

| Miembro | Comportamiento | Evidencia |
|---|---|---|
| `getSubscriptionId(): ?int` | 1º columna directa `subscription_id`; 2º ruta `branch()` (BelongsTo) → `branch.subscription_id`; si no, `null` | `app/Traits/HasSubscription.php:25-48` |
| `scopeBySubscription($query, int $id)` | si el modelo tiene `subscription_id` (fillable o atributo) → `where('subscription_id', $id)`; si tiene `branch_id` → `whereHas('branch', ...)`; si no, devuelve la query intacta | `app/Traits/HasSubscription.php:53-66` |

**Búsquedas negativas explícitas:** no existen `BelongsToSubscription`, `HasSubscriptionScope`, `TenantScope`, ni ninguna clase que extienda `Illuminate\Database\Eloquent\Scope` / use `addGlobalScope`. Búsqueda realizada: `grep -r "GlobalScope|BelongsToSubscription|HasSubscriptionScope|addGlobalScope|#[A-Za-z]*Scope" app/` → **0 coincidencias**. Tampoco hay listeners `creating` que inyecten el `subscription_id`.

> Consecuencia de diseño: **el filtrado por tenant NO es automático**. Cada consulta debe acotarse a mano, y además hay una red de seguridad por middleware sobre route-model binding (§2.4).

### 2.3 Columnas de tenant en las tablas principales

| Tabla | `subscription_id` | `branch_id` | Evidencia |
|---|---|---|---|
| `categories` | **Sí** (nullable, FK cascade) | No | `database/migrations/2026_08_22_000002_create_catalog_tables.php:30` |
| `brands` | **Sí** (nullable) | No | `..._catalog_tables.php:20` |
| `providers` | **Sí** (NOT NULL) | No | `..._catalog_tables.php:51` |
| `attribute_definitions` | **Sí** (NOT NULL) | No | `..._catalog_tables.php:166` |
| `products` | **No** | **Sí** (nullable, `nullOnDelete`) + índice `idx_products_branch` | `..._catalog_tables.php:70,100` |
| `transactions` | **No** | **Sí** (NOT NULL, cascade) + índice `idx_transactions_branch_created` | `database/migrations/2026_08_22_000003_create_inventory_and_pos_tables.php:141,164` |
| `customers` | No | **Sí** (nullable) + índice | `..._inventory_and_pos_tables.php:119,132` |
| `services` | No | **Sí** (NOT NULL) | `..._catalog_tables.php:140` |
| `cash_registers`, `cash_register_sessions` | No | Sí / vía `cash_register` | `..._inventory_and_pos_tables.php:73,81` |
| `bank_accounts`, `bank_account_transfers`, `print_templates`, `store_config`, `expense_categories`, `custom_field_definitions`, `subscription_versions` | **Sí** | — | `..._inventory_and_pos_tables.php:22,227`; `Subscription.php:387,417-418` |
| `ai_conversations` (paquete) | **Sí** (NOT NULL, cascade) | No | `packages/ai-agent/database/migrations/0001_create_ai_conversations_table.php:13` |

Verificado contra la BD viva (no solo migraciones):

```
products.sub=no  products.branch=yes  tx.sub=no  tx.branch=yes  cat.sub=yes
```

**Regla empírica del repo:** *lo global del catálogo* (categorías, marcas, proveedores, atributos, cuentas bancarias, plantillas) lleva `subscription_id`; *lo operativo de sucursal* (productos, ventas, clientes, servicios, cajas) lleva `branch_id` y hereda tenant por join.

### 2.4 Middleware de aislamiento: `EnsureSubscriptionScope` (global en `web`)

- Se registra **para todas las peticiones web** al final del grupo `web`: `bootstrap/app.php:26-32` (línea 31).
- Comportamiento (`app/Http/Middleware/EnsureSubscriptionScope.php`):
  - Si no hay usuario, no hay `branch` o no hay `branch->subscription_id` → pasa (líneas 26-31).
  - **Excepción dura:** `$user->id === 1` pasa siempre ("superadmin de soporte", líneas 33-36).
  - Recorre `$request->route()->parameters()`; para cada valor que sea `Model` **y** use el trait `HasSubscription` (`class_uses_recursive`, línea 46), compara `getSubscriptionId()` con el del usuario y, si no coincide, `abort(403, 'Acceso denegado. Este recurso no pertenece a tu suscripción.')` (líneas 50-59).
  - Modelos que no usan el trait se **saltan** (línea 47) — es decir, la protección solo cubre modelos anotados con el trait.
- Otros middlewares relevantes: `CheckSubscriptionStatus`, `CheckOnboardingStatus` (también globales, `bootstrap/app.php:29-30`), `CheckSuperAdmin` (solo rutas `/admin/*`, ver §3.5).

### 2.5 Cómo se filtran realmente las consultas (patrón manual, verificado)

Ejemplo modélico — `ProductController@index` (`app/Http/Controllers/ProductController.php`):

```php
$branchId     = $user->branch_id;                 // :46
$subscription = $user->branch->subscription;      // :47
...->where('branch_product.branch_id', $branchId) // :90,97,107,114,157
...->where('subscription_id', $subscription->id)  // :180
```

Otro ejemplo con verificación explícita de tenant antes de operar: `PrintController` compara `$template->subscription_id !== $user->branch->subscription_id → abort(403)` (`app/Http/Controllers/PrintController.php:34-36`, `:74-76`, `:110-112`).

### 2.6 **Patrón exacto para crear un modelo nuevo aislado por tenant**

1. **Migración**: añadir `$table->foreignId('subscription_id')->constrained()->cascadeOnDelete();` (si el dato es del negocio) **o** `$table->foreignId('branch_id')->constrained()->cascadeOnDelete();` (si el dato es de sucursal). Referencia: `..._catalog_tables.php:61-101` para el caso `branch_id`, `..._inventory_and_pos_tables.php:22` para `subscription_id`.
2. **Modelo**: `use HasSubscription;` y declarar la columna en `$fillable` (`app/Models/Branch.php:15,18`). Si la ruta es por `branch`, definir `branch(): BelongsTo`.
3. **Aislamiento de consultas (obligatorio, manual)**: en cada query, `->where('subscription_id', $user->branch->subscription_id)` (si la columna existe) o `->where('branch_id', $user->branch_id)`, o el scope `->bySubscription($id)` (`app/Traits/HasSubscription.php:53-66`). No hay red automática: si se olvida, hay fuga de datos entre tenants.
4. **Rutas**: si se usan route-model binding, el middleware global ya valida el tenant **siempre que el modelo use `HasSubscription`** (`EnsureSubscriptionScope:46`). No hay que registrar nada.
5. **Factory** (convención del repo): el factory del hijo referencia al padre, p. ej. `'subscription_id' => Subscription::factory()` (`database/factories/BranchFactory.php:13`) o `'branch_id' => Branch::factory()` (`database/factories/TransactionFactory.php:31`).
6. **Tests**: `RefreshDatabase` + `actingAs`; para aislar auth conviene `withoutMiddleware([CheckOnboardingStatus, CheckSubscriptionStatus, EnsureSubscriptionScope])` como en `tests/Feature/FinancialReportAccessTest.php:22-26` (los tres suelen estorbar en tests de controlador).

---

## 3. PERMISOS Y ROLES

Stack: `spatie/laravel-permission` 6.25.0 (`composer.json:34`), con **tablas y modelos custom**.

### 3.1 Dónde se definen los permisos

**Fuente autoritativa (código):** `database/seeders/PermissionSeeder.php`.

- Estructura: array `$permissionsByModule` con **grupo legible (español) → [nombre => descripción]** (`database/seeders/PermissionSeeder.php:20-166`).
- Se ejecuta desde `DatabaseSeeder` (`database/seeders/DatabaseSeeder.php:42-48`).
- Crea con `Permission::create([...])` guard `web` (`PermissionSeeder.php:169-178`).
- **Total: 117 permisos** en el seeder (= 117 filas en la BD de desarrollo).

Tabla `permissions` custom: incluye `description` y `module` además de lo estándar de Spatie (`database/migrations/2026_08_22_000001_create_core_platform_tables.php:25-33`), único por `['name','guard_name']` (`:32`).

### 3.2 Formato y nomenclatura EXACTA de los nombres

El formato real es **`<recurso>.<acción>` en snake_case, minúsculas, separado por punto** (no kebab-case, no espacios). Hay 3 niveles cuando aplica: `recurso.subrecurso.accion`.

20+ ejemplos reales copiados del seeder:

| Permiso | Grupo (`module`) | Línea |
|---|---|---|
| `pos.access` | Punto de Venta | `PermissionSeeder.php:22` |
| `pos.create_sale` | Punto de Venta | `:23` |
| `pos.edit_prices` | Punto de Venta | `:24` |
| `transactions.access` | Historial de Ventas | `:27` |
| `transactions.refund` | Historial de Ventas | `:29` |
| `transactions.delete` | Historial de Ventas | `:34` |
| `products.access` | Productos | `:37` |
| `products.see_cost_price` | Productos | `:45` |
| `products.manage_stock` | Productos | `:42` |
| `expenses.change_status` | Gastos | `:56` |
| `invoices.stamp` | Facturación | `:65` |
| `invoices.settings.upload_csd` | Facturación | `:72` |
| `stamps.purchase` | Facturación | `:75` |
| `customers.see_financial_info` | Clientes | `:85` |
| `services.catalog.import_export` | Servicios | `:93` |
| `services.orders.change_status` | Servicios | `:100` |
| `services.print_tickets` | Servicios | `:104` |
| `quotes.create_sale` | Cotizaciones | `:115` |
| `financial_reports.access` | Reportes financieros | `:119` |
| `cash_registers.sessions.delete_movements` | Cajas | `:127` |
| `settings.roles_permissions.manage` | Configuraciones | `:134` |
| `settings.templates.delete` | Configuraciones | `:144` |
| `online_store.orders.change_status` | Tienda en línea | `:151` |
| `system.branches.switch` | Sistema | `:154` |
| `dashboard.see_inventory_details` | Sistema | `:160` |
| `ai_agent.access` | Agente IA | `:163` |

Los 14 valores reales de `permissions.module` en la BD (agrupadores de la UI de roles):

```
Punto de Venta | Historial de Ventas | Productos | Gastos | Facturación | Clientes |
Servicios | Cotizaciones | Reportes financieros | Cajas | Configuraciones |
Tienda en línea | Sistema | Agente IA
```

> ⚠️ **Discrepancia documental importante.** `docs/context/00-overview.md:81,184` y `.clinerules/ezyventas-rules.md:169-170` afirman que los permisos son **kebab-case con espacios** (`create service-orders`, `edit invoices`). **El código real no usa ese formato en ningún sitio**: usa `recurso.accion` con punto. Para un módulo nuevo hay que imitar el **seeder**, no la documentación.

### 3.3 Cómo se registran los roles (¡ojo: por SUCURSAL, no por suscripción!)

- La tabla `roles` es custom: tiene **`branch_id` obligatorio** con FK cascade y único `['branch_id','name','guard_name']` (`..._create_core_platform_tables.php:35-42`).
- `App\Models\Role extends SpatieRole` sobreescribe `create()` para incluir `branch_id` en la comprobación de unicidad y lanzar `RoleAlreadyExists` si ya existe en esa sucursal (`app/Models/Role.php:19-50`, especialmente `:38-46`).
- **No hay seeder de roles.** `DatabaseSeeder` no crea roles (búsqueda: `grep "Role::create|assignRole" database/seeders/` → 0). Los roles se crean en runtime:
  - `POST /roles` → `RolePermissionController@store` (`app/Http/Controllers/RolePermissionController.php:76-80`), con `branch_id = $user->branch_id` y unicidad por sucursal (`:69-72`).
  - `POST /quick-create/roles` → `app/Http/Controllers/QuickCreateController.php:136`.
  - Asignación a usuario: `app/Actions/User/StoreUserAction.php:25` (`assignRole`), `app/Actions/User/UpdateUserAction.php:28` (`syncRoles`).
- En la BD de desarrollo hay **11 roles** (creados por uso, no por seeder).
- **No existe el rol `super-admin`.** El "superadmin" es una **convención por ID/suscripción**: `user->id === 1` (`app/Providers/AppServiceProvider.php:57`; `app/Http/Middleware/EnsureSubscriptionScope.php:34`) y `branch->subscription_id === 1` (`app/Http/Middleware/CheckSuperAdmin.php:24`, `resources/js/Layouts/AppMenu.vue:178`).
- **"Dueño" = usuario SIN roles.** `User::isOwner()` devuelve `!$this->roles()->exists()` (`app/Models/User.php:156-159`). Esto tiene efectos profundos en autorización (§3.4).

Tabla pivote: **la estándar** `role_has_permissions` (PK compuesta, FKs cascade) — `..._create_core_platform_tables.php:70-82`; `model_has_roles` `:57-68`; `model_has_permissions` `:44-55`. No hay pivote custom. `config/permission.php:135` → `'teams' => false` (no se usa el modo teams de Spatie; el equivalente funcional es `roles.branch_id` gestionado a mano).

### 3.4 Cómo se comprueban los permisos

**Backend — 3 mecanismos conviviendo:**

1. **`Gate::before` global** (`app/Providers/AppServiceProvider.php:54-94`) — es el corazón del modelo:
   - Si `user->id === 1` → `true` (acceso irrestricto, `:57-59`).
   - Si el usuario **no tiene roles** (dueño): concede **cualquier** permiso que exista en `permissions` y cuyo `module` esté entre los módulos contratados o sea `'Sistema'` (`:63-76`).
   - Si tiene roles: si el permiso pertenece a un módulo **no** contratado y no es `'Sistema'` → `false` (`:80-91`).
   
   ➜ **Un permiso nuevo solo es efectivo si su `module` está en los módulos activos de la suscripción o es `'Sistema'`.**

2. **Middleware `can:` por controlador** (vía `HasMiddleware`), p. ej. `app/Http/Controllers/ProductController.php:32-39`:
   ```php
   new Middleware('can:products.access', only: ['index']),
   new Middleware('can:products.create', only: ['create','store']),
   ```
   Y en `RolePermissionController:17-24` (`can:settings.roles_permissions.*`).

3. **`authorize()` de FormRequest** — 103 FormRequests lo implementan (búsqueda `grep "public function authorize\(\): bool" app/Http/Requests`). Dos estilos:
   - `return $this->user()->can('...')`.
   - **"cualquiera de estos permisos"** vía trait `AuthorizesAnyPermission` (`app/Http/Requests/Api/V1/Concerns/AuthorizesAnyPermission.php:14-29`), usado por ejemplo en `app/Http/Requests/Api/V1/Printing/PrintRequest.php:19,24-33`.
   - Ojo: hay FormRequests con `authorize() { return true; }` cuando la protección vive en el middleware — p. ej. `app/Http/Requests/StoreProductRequest.php:12-15`.

**Frontend — props compartidos.** `HandleInertiaRequests::share()` (`app/Http/Middleware/HandleInertiaRequests.php:20-134`):

| Prop | Contenido | Línea |
|---|---|---|
| `auth.permissions` | array de **nombres** de permiso efectivos (dueño: todos los de sus módulos; empleado: los asignados filtrados por módulo; sin suscripción activa: `[]`) | `:33-42`, `:46` |
| `auth.is_subscription_owner` | bool | `:47` |
| `auth.active_modules` | `getActiveModuleKeys()` → p. ej. `['module_pos', ...]` | `:52` |
| `auth.current_branch`, `auth.subscription`, `auth.available_branches` | contexto | `:48-65` |
| `flash` | success/error/warning/info/print_data/show_payment_modal | `:94-101` |

Consumo en Vue: `usePermissions()` (`resources/js/Composables/usePermissions.js:4-10`, `hasPermission(name)`), `resources/js/Layouts/AppTopbar.vue:16,105,184`. **Aviso:** ese composable **no es reactivo** (lee `usePage().props.auth.permissions` una vez en setup, sin `computed`), así que no reacciona a cambios de permisos sin recargar.

**Tercera copia de la misma lógica (API móvil):** `app/Services/Auth/UserAccessContextService.php:81-108` replica exactamente las reglas del `Gate::before` y del middleware de Inertia. Si se añade un permiso/módulo, hay que tocar **los tres sitios** conceptualmente (aunque todos leen de `permissions.module` + `getActiveModuleNames()`, así que en la práctica basta con que el permiso esté bien etiquetado).

### 3.5 ¿Los permisos se asignan por suscripción?

**No.** Los permisos son **globales** (no tienen `subscription_id`; `..._create_core_platform_tables.php:25-33`). Lo que se "asigna" por suscripción es el **módulo** (`PlanItem` + `SubscriptionItem`, §4), y ese módulo **filtra** qué permisos son visibles/efectivos. Los **roles** sí son por sucursal (`roles.branch_id`).

### 3.6 Cómo crear un permiso nuevo SIN alterar los existentes

Hay dos vías. La **segura** y la **peligrosa**:

| Vía | Procedimiento | Riesgo |
|---|---|---|
| **A. Runtime (recomendada, no destructiva)** | `POST /permissions` con `{name, description, module}` → `app/Http/Controllers/PermissionController.php:11-22` (valida único en `permissions.name`, línea 14; fuerza `guard_name='web'`, línea 19). Ya hay UI en `resources/js/Pages/Role/Index.vue:78` (`createPermissionForm.post(route('permissions.store'))`). Alternativa programática idempotente: `Permission::firstOrCreate(['name'=>..., 'guard_name'=>'web'], [...])`. | Ninguno sobre permisos existentes. |
| **B. Editar `PermissionSeeder`** | Añadir la entrada al array y `db:seed --class=PermissionSeeder`. | ⚠️ **Destructivo**: el seeder hace `Permission::query()->delete()` al inicio (`database/seeders/PermissionSeeder.php:17`) y recrea los 117. Como `role_has_permissions` tiene FK `cascadeOnDelete` (`..._create_core_platform_tables.php:73-80`), **se borran todas las asignaciones rol↔permiso de todas las sucursales**. Los IDs cambian. No ejecutar en un entorno con datos reales. |

Recomendación para "smart-checkout": añadir los permisos nuevos al seeder **para entornos limpios** *y* crearlos por la vía A en entornos existentes. Nunca re-ejecutar el seeder completo en producción.

---

## 4. PLANES / FEATURE FLAGS

### 4.1 Sí existe un concepto de plan: `PlanItem` + versiones de suscripción

No hay tabla `plans` ni `features` ni `limits`. El modelo es **catálogo de ítems contratables + versiones congeladas**:

| Pieza | Modelo / tabla | Campos exactos | Evidencia |
|---|---|---|---|
| Catálogo | `plan_items` (`App\Models\PlanItem`) | `id`, `key` (unique), `type` ('module'\|'limit'), `name`, `description`, `monthly_price` (decimal 8,2), `is_active`, `meta` (JSON: `icon`, `quantity`), timestamps | migración `..._create_core_platform_tables.php:170-180`; `app/Models/PlanItem.php:14-29` |
| Enum de tipo | `App\Enums\PlanItemType` | `MODULE = 'module'`, `LIMIT = 'limit'` | `app/Enums/PlanItemType.php:7-8` |
| Versión contratada | `subscription_versions` (`App\Models\SubscriptionVersion`) | `subscription_id`, `start_date`, `end_date` | `app/Models/Subscription.php:418`; `Subscription.php:90-97` |
| Ítems de la versión | `subscription_items` (`App\Models\SubscriptionItem`) | `subscription_version_id`, `item_key`, `item_type`, `name`, `quantity`, `unit_price`, `billing_period` | `app/Models/SubscriptionItem.php:16-30` |

**No hay columna `plan`, `plan_id`, `features`, `limits` ni `trial` en `subscriptions`.** Columnas reales de `subscriptions` (`app/Models/Subscription.php:25-42`): `business_name`, `business_type_id`, `commercial_name`, `status`, `contact_phone`, `contact_email`, `tax_id`, `address`, `slug`, `onboarding_completed_at`, `inactivity_warned_at`, `data_purged_at`, `referrer_discount_active`, y `facturacion_habilitada` **marcada LEGACY** (`:39-41,50`: "ya no se usa como gate", sustituida por `billingEnabled()`).

### 4.2 Catálogo real de `plan_items` (leído de la BD: 19 ítems)

Módulos (13): `module_pos`, `module_financial_reports`, `module_transactions`, `module_products`, `module_expenses`, `module_customers`, `module_services`, `module_quotes`, `module_billing`, `module_cash_registers`, `module_settings`, `module_online_store`, `module_ai_agent`.

Límites (6): `limit_branches`, `limit_users`, `limit_products`, `limit_services`, `limit_cash_registers`, `limit_print_templates`.

### 4.3 Cómo se evalúa un módulo/límite (la API que hay que reutilizar)

`app/Models/Subscription.php`:

| Método | Qué hace | Línea |
|---|---|---|
| `currentVersion()` | versión con `start_date <= now <= end_date` más reciente | `:90-97` |
| `getActiveModuleKeys(): array` | claves `item_key` con `item_type='module'` de la **última versión que tenga items** (fallback a `currentVersion()`) | `:113-130` |
| `getAvailableModuleNames(): array` | **nombres legibles** de esos keys vía `PlanItem.name` | `:99-107` |
| `billingEnabled(): bool` | `in_array('module_billing', getActiveModuleKeys())` | `:140-143` |
| `hasReachedProductLimit()` / `hasReachedServiceLimit()` / `hasReachedUserLimit()` | leen `item_key = limit_products/limit_services/limit_users` (`quantity`; `-1` = ilimitado; defaults 50/100) | `:145-154`, `:159-170`, `:175-204` |
| `hasAiAgentModule(): bool` | `in_array('module_ai_agent', ...)` | `:542-545` |
| `getAiCreditLimitData()` | límite de tokens IA por suscripción | `:490-519` |

Dónde se consume: `HandleInertiaRequests.php:31,52` (props), `AppServiceProvider.php:65,82` (Gate), `RolePermissionController.php:38-43` (filtra permisos por módulo), `UserAccessContextService.php:84,115` (API), `PointOfSaleController.php:128` (ejemplo de feature concreta).

### 4.4 ¿Dónde encajan `smart_checkout_enabled` y `scale_enabled`?

Hay **tres** sitios naturales. Comparativa:

| Opción | Mecanismo | Pros | Contras | Evidencia |
|---|---|---|---|---|
| **A. Módulos de plan** (recomendada si son funcionalidad vendible) | Crear `PlanItem` con `key = 'module_smart_checkout'` y `key = 'module_scale'`, `type='module'`, `meta.icon`; asignarlos a la versión de la suscripción (`subscription_items`). Gate/menu/propm ya lo respetan. | Cero migraciones. Se activa **por UI de admin** (`/admin/plan-items`, `routes/web/super-admin.php:76-83`; reglas `app/Http/Requests/Admin/StorePlanItemRequest.php:20-29`). Gating automático en menú y `Gate::before`. Coherente con `module_billing`/`module_ai_agent`. | Requiere que el ítem esté en la **última versión con items**; es un flag de catálogo comercial (semántica "módulo contratado"), no un toggle operativo fino. | `Subscription.php:113-130,140-143,542-545` |
| **B. Settings por suscripción** (recomendada si es un toggle operativo) | `SettingDefinition` con `key='smart_checkout.enabled'`, `level='subscription'`, `type='boolean'` + `SettingValue` polimórfico apuntando a la `Subscription`; leer con `Subscription::getSettingValue($key, $default)`. | Diseñado exactamente para "flag por tenant". Ya se usa así para `ai.token_limit` (`Subscription.php:503`). Sin migración de columnas. | ⚠️ `SettingDefinitionSeeder` **borra todas las definiciones** al correr (`database/seeders/SettingDefinitionSeeder.php:17`) → hay que añadir las nuevas ahí y **no re-ejecutarlo a ciegas**. La BD actual solo tiene 5 definiciones (`*_table_row_click_action`), es decir, ya divergió del seeder. | `setting_definitions` `..._create_core_platform_tables.php:147-157`; `setting_values` `:159-167` (polimórfico `configurable_type/configurable_id`); `SettingValue.php:29-32`; `Subscription.php:524-537` |
| **C. Columnas nuevas en `subscriptions`** | Migración `add_smart_checkout_enabled_and_scale_enabled_to_subscriptions`. | Explícito, indexable, trivial de leer. | Rompe el patrón del repo (ya hay un flag legacy `facturacion_habilitada` declarado deuda técnica, `Subscription.php:39-41`); dos flags booleanos rara vez se quedan en dos. | — |

**Recomendación concreta y justificada:**
1. Si "smart-checkout" y "báscula" son **módulos comercializables** → **Opción A** (`module_smart_checkout`, `module_scale`), porque el repo ya tiene resuelto el triple gating (Gate + menú + props) sin escribir código nuevo, y porque así aparecen en `/admin/plan-items` y en la asignación de versiones.
2. Si además se necesita un **interruptor operativo por tenant** (activar la báscula en esta sucursal aunque el módulo esté contratado) → añadir **B** en paralelo, con `level='subscription'` (o `'branch'` si es por sucursal), leído por `getSettingValue()`.
3. **Opción C solo** si se necesita consultar el flag en SQL masivo (reportes/filtros). No es el patrón del repo.

Combinación recomendada: **A + B** — módulo para el derecho comercial, setting para el toggle fino.

---

## 5. MENÚ Y NAVEGACIÓN

- Menú lateral: `resources/js/Layouts/AppMenu.vue` (modelo declarativo) renderizado por `AppMenuItem.vue`, dentro de `AppLayout.vue` → `AppSidebar.vue`.
- **El modelo del menú es un array de objetos** con esta forma (`AppMenu.vue:6-140`):

  ```js
  { label: 'Productos', icon: 'pi pi-barcode', to: route('products.index'),
    routeName: 'products.*', permission: 'products.access' }
  // y para ítems con submenú:
  { label: 'Cajas', icon: 'pi pi-dollar', module: 'module_cash_registers',
    items: [ { label: '...', to: route(...), routeName: '...', permission: '...', module: '...' } ] }
  ```

- **Filtrado por permiso Y por módulo** (`AppMenu.vue:148-172`):
  - `hasPermission = !item.permission || userPermissions.value.includes(item.permission)` (`:152`)
  - `hasModule = !item.module || activeModules.value.includes(item.module)` (`:156`)
  - Si un padre tiene hijos, solo se pinta si al menos un hijo sobrevive (`:159-167`).
- Fuentes: `usePage().props.auth.permissions` (`:143`) y `props.auth.active_modules` (`:146`) — provistos por `HandleInertiaRequests.php:46,52`.
- Los ítems de superadmin están en un **segundo modelo** `adminModel` (`AppMenu.vue:181-289`) que solo se renderiza si `auth.user.branch.subscription_id === 1` (`:178`, template `:299-306`); **no** pasan por `filterMenu`, no llevan `permission`.
- Estado activo del ítem: `route().current(item.routeName)` con soporte de comodín `users.*` (`AppMenuItem.vue:34-44`).

**Patrón para agregar una entrada al menú:**
1. Crear la ruta (archivo `routes/web/<kebab-case>.php` + `require` en `routes/web.php`, ver `routes/web.php:45-86`).
2. Añadir el objeto al array `model` con `label` (español), `icon` (`pi pi-*`), `to: route('...')`, `routeName: '<prefijo>.*'`, `permission: '<recurso>.access'` y, si aplica, `module: 'module_x'`.
3. Crear el permiso con `module` = el grupo legible del seeder (si no, el `Gate::before` del dueño lo negará).
4. Para un **botón** dentro de una página: `const { hasPermission } = usePermissions()` y `v-if="hasPermission('...')"` (`AppTopbar.vue:16,105`).

---

## 6. TRADUCCIONES

| Aspecto | Estado real | Evidencia |
|---|---|---|
| Locale | `es` (fallback `en`, faker `es_MX`) | `.env:7-9`; `config/app.php:81-85` |
| `lang/es.json` | **753 claves**, formato "texto inglés" → "texto español" | `lang/es.json:1-755` (p. ej. `"Add": "Añadir"`, `:21`) |
| Contenido de `es.json` | **Traducciones de Jetstream/Breeze/Fortify** (equipo, 2FA, tokens API, países, meses). **No contiene textos del dominio POS.** | `lang/es.json:1-60` |
| `lang/es/` | `auth.php`, `pagination.php`, `passwords.php`, `validation.php` (13.5 KB → validaciones en español) | `lang/es/*` |
| `lang/en/` | mismos 4 archivos | `lang/en/*` |
| Uso en PHP | **Solo 9 llamadas a `__()` en todo el proyecto**, y **ninguna del dominio**: 1 en `app/Actions/Fortify/UpdateUserPassword.php:25`, 7 en `resources/views/emails/team-invitation.blade.php`, 1 en `resources/views/vendor/mail/html/message.blade.php:24` | `grep "__\('" app resources/views` |
| `trans_choice` / `Lang::get` | **0 usos** | `grep "trans_choice\|Lang::get" app/` |
| i18n en JS | **No existe**. `grep "\b__\(" resources/js` → **0 coincidencias**. No hay `vue-i18n` en `package.json:9-46`. | — |
| Estrategia real del frontend | **Textos literales en español, hardcodeados** en cada `.vue` (p. ej. `resources/js/Components/PrintModal.vue:400,419`; `AppMenu.vue:9-13`). Único texto "traducible" de facto: lo que devuelve el backend en `flash` y en `validation.php`. | — |

**Nomenclatura de claves:** la de `es.json` es "frase en inglés tal cual" (clave = texto original), no `snake_case`. Como el dominio no usa i18n, **para un módulo nuevo la convención real es escribir español literal en el `.vue` y mensajes en español en el backend**, respetando sentence case (`.clinerules/ezyventas-rules.md:1`: "All user-facing text ... must be in Spanish and use sentence case").

---

## 7. IMPRESIÓN / SERVICIO LOCAL

### 7.1 Rutas y controladores

| Archivo | Contenido | Evidencia |
|---|---|---|
| `routes/web/print.php` | 4 POST bajo `auth`: `print.payload`, `print.bluetooth-payload`, `print.ticket-html`, `print.whatsapp-ticket` | `routes/web/print.php:6-11` |
| `routes/web/print-templates.php` | `POST /print-templates/media`, `PATCH .../toggle-default`, `Route::resource('print-templates')` | `routes/web/print-templates.php:6-12` |
| `routes/api/v1/printing.php` | equivalentes para la app móvil | `routes/api/v1/printing.php:16-29` |
| `app/Http/Controllers/PrintController.php` | `generatePayload` (:20-57), `bluetoothPayload` (:63-94), `ticketHtml` (:100-127), `whatsappTicket` (:133-174). Verifica tenant en `:34-36`, `:74-76`, `:110-112` | — |
| `app/Http/Controllers/Api/V1/Printing/PrintController.php` | versión móvil | — |
| `app/Services/PrintEncoderService.php` | **37 KB**: genera ESC/POS y TSPL | `docs/context/15-module-admin-settings.md:26` |
| `app/Services/Printing/` | `CashRegisterCutTemplate`, `EscPosImageRasterizer`, `MonochromeBitmap`, `PrintDataSourceResolver`, `TsplImageRasterizer` | — |

`PrintEncoderService` devuelve **"operaciones"** (JSON de operaciones de impresión) + `paperWidth` + `feedLines`; para Bluetooth devuelve **comandos ESC/POS crudos en Base64** (`PrintController.php:52-56`, `:90-93`).

### 7.2 **Cómo se comunica el navegador con el servicio local (patrón exacto a reutilizar)**

**No hay WebSocket, ni Pusher, ni Laravel Echo, ni QZ Tray, ni extensión de navegador.** Es **HTTP plano a `localhost:8000`** contra un *plugin/servicio local* que corre en la máquina del cajero (el código de ese servicio **no está en este repositorio**: `grep -r "localhost:8000" .` solo lo encuentra en el composable; no hay carpeta `plugin/`, `print-service/` ni similar en la raíz).

**Contrato HTTP del servicio local** (`resources/js/Composables/usePrintPlugin.js`):

| Verbo | Endpoint | Request | Response | Línea |
|---|---|---|---|---|
| `GET` | `http://localhost:8000/impresoras` | — | array de nombres de impresora | `usePrintPlugin.js:5,19-29` |
| `POST` | `http://localhost:8000/imprimir` | `{ nombreImpresora, operaciones, anchoImpresora }` (JSON) | `{ ok: boolean, message? }` | `usePrintPlugin.js:59-76` |

La URL es una **constante hardcodeada** (`usePrintPlugin.js:5`), no configurable por env; no se envía token ni cabecera de autenticación (el servicio local confía en el origen/CORS).

**Cadena completa (desktop)** — `resources/js/Components/PrintModal.vue`:

```
1. usuario pulsa imprimir → print()                    (:475-480)
2. printViaPlugin()                                     (:424-472)
3. POST route('print.payload')  → Laravel               (:451)
      body: { template_id, data_source_type, data_source_id, offset_x/y, open_drawer }
      resp: { operations, paperWidth, feedLines }        (PrintController.php:52-56)
4. sendToPlugin(printer, operations, paperWidth)        (:454)
      → POST http://localhost:8000/imprimir             (usePrintPlugin.js:59-67)
5. el servicio local imprime en la impresora del SO
```

**Cadena alternativa (móvil, Web Bluetooth)** — mismo patrón pero sustituyendo el paso 4 por una API del navegador:

```
1. printMode = isMobile ? 'bluetooth' : 'plugin'         (PrintModal.vue:210)
2. POST route('print.bluetooth-payload')                 (PrintController.php:63-94)
      resp: { commands_base64, paperWidth }
3. btSendRaw(commands_base64)                            (PrintModal.vue:407)
      → navigator.bluetooth.requestDevice({acceptAllDevices:true, optionalServices:[...]})
      → gatt.connect() → localizar característica escribible
      → writeValueWithoutResponse en chunks de 20 bytes, pausa 25 ms
                                    (useBluetoothPrinter.js:87-148, :151-187, :168, :179)
```

**Por qué esto importa para la báscula por Web Serial:** el repo **no tiene ningún precedente de Web Serial** (`grep -r "navigator\.serial|requestPort|Web Serial" resources/` → **0 coincidencias**). El precedente más cercano y directamente análogo es `useBluetoothPrinter.js`, que ya resuelve el *shape* correcto para hardware del navegador:

- detección de soporte y de contexto seguro (`isSupported`/`isSecure`, `:40-43`, `:90-102`),
- `ref`s de estado (`device`, `isScanning`, `isConnecting`, `error`),
- `toast` de PrimeVue para feedback (`:93,100,130,142,193`),
- decodificación Base64 → `Uint8Array` (`:157-161`),
- envío por chunks con pausa (`:168-184`),
- `disconnect()` + limpieza en `onUnmounted` (`:202-220`),
- *fallback* cuando el navegador no soporta la API (aquí: HTML/PDF, `:14`).

**Dos patrones, entonces, para smart-checkout:**
- **Si la báscula se lee en el navegador** (Web Serial): clonar la estructura de `useBluetoothPrinter.js` (un composable `useSerialScale.js`), y **no** hace falta tocar Laravel salvo para persistir la lectura (endpoint POST normal, con `auth` + permiso + scoping de tenant igual que `print.payload`).
- **Si se prefiere servicio local** (báscula conectada al puerto serie del PC): replicar el contrato `GET /dispositivos` + `POST /leer` sobre `http://localhost:PUERTO`, exactamente como `usePrintPlugin.js`. Recordar que hoy la URL está hardcodeada (`usePrintPlugin.js:5`), lo que es deuda técnica a no repetir.

**Nota:** `docs/context/15-module-admin-settings.md:128` afirma que el frontend envía el payload "via WebUSB or network". **WebUSB no aparece en el código**; los modos reales son *plugin local HTTP* y *Web Bluetooth*. Documentación desactualizada.

---

## 8. NOTIFICACIONES / TOASTS BACKEND

Conviven **tres** mecanismos, con una regla clara de cuándo usar cada uno:

| Mecanismo | Dónde | Evidencia |
|---|---|---|
| **1. Session flash → Inertia shared prop → toast (el estándar)** | Controlador hace `->with('success' \| 'error' \| 'warning' \| 'info', '...')`; `HandleInertiaRequests` los expone en `flash`; `AppLayout.vue` los convierte en toasts PrimeVue | `app/Http/Middleware/HandleInertiaRequests.php:94-101`; `resources/js/Layouts/AppLayout.vue:66-90` (mapeo severidad/summary en `:71-82`, `life: 6000`) |
| **2. Broadcast (Pusher + Echo) — solo un caso** | Evento `App\Events\SessionClosed` en el canal privado `cash-register-session.{id}`; el frontend se suscribe y abre un modal + `router.reload()` | `routes/channels.php` (canal + autorización por pivote de sesión); `app/Events/SessionClosed.php`; `resources/js/Layouts/AppLayout.vue:146-191`; `resources/js/bootstrap.js:12-25` |
| **3. JSON uniforme para `/api/*`** | `ApiExceptionRenderer` fuerza JSON solo en `api/*`, forma `{ message, errors }`, mensajes en español | `app/Exceptions/Api/ApiExceptionRenderer.php:22,40-46,48-60`; registrado en `bootstrap/app.php:42` |

**Centralización:** el punto único de toasts web es **`AppLayout.vue`** (escucha `router.on('success', handleFlashMessages)`, `:87`). La regla del proyecto está escrita: **no añadir `<Toast/>` ni `toast.add()` en las páginas cuando el controlador ya devuelve flash** (`.clinerules/ezyventas-rules.md:127`, párrafo "Never add onSuccess() toasts..."), porque se duplicaría el mensaje. Excepciones legítimas: acciones que **no** recargan la página, y errores de hardware (por eso `PrintModal.vue` y `useBluetoothPrinter.js` sí llaman a `toast.add` directamente).

Configuración de broadcast: `.env:36` define `BROADCAST_CONNECTION=log` y `.env:67` lo redefine a `pusher` (clave duplicada; gana la última). `.env.example`/`config/broadcasting.php` no modificados.

---

## 9. PAQUETE LOCAL EXISTENTE: `packages/ai-agent`

### 9.1 Inventario real (16 archivos)

```
packages/ai-agent/
├── composer.json
├── config/ai-agent.php
├── database/migrations/0001_create_ai_conversations_table.php
│                      0002_create_ai_messages_table.php
│                      0003_create_ai_tool_executions_table.php
│                      0004_create_ai_usage_monthly_table.php
├── routes/ai-agent.php
└── src/
    ├── AiAgentServiceProvider.php
    ├── Contracts/AiToolProvider.php
    ├── Http/Controllers/AiChatController.php
    ├── Http/Requests/SendAiMessageRequest.php
    ├── Models/AiConversation.php, AiMessage.php, AiToolExecution.php
    └── Support/AiAgentManager.php, ToolRegistry.php
```

**No tiene carpeta `tests/` propias** (`Test-Path packages/ai-agent/tests` → `False`). Su cobertura proviene de los tests del host: `tests/Feature/AiAgentToolsTest.php` (19 tests), `AiAgentSubscriptionToolsTest.php` (6), `AiAgentHelpGuideToolsTest.php` (7).

### 9.2 Registro en el proyecto host (el patrón a imitar)

| Paso | Cómo está hecho | Evidencia |
|---|---|---|
| **Repositorio `path` + symlink** | `"repositories": [{ "type": "path", "url": "packages/ai-agent", "options": { "symlink": true } }]` | `composer.json:8-16` |
| **Dependencia** | `"ezyventas/ai-agent": "@dev"` | `composer.json:19` |
| **Autoload PSR-4 del host** | `"Ezyventas\\AiAgent\\": "packages/ai-agent/src/"` (además del autoload propio del paquete) | `composer.json:50` |
| **Instalación real** | `vendor/ezyventas/ai-agent` es un **Junction** → `packages/ai-agent` | `Get-ChildItem vendor\ezyventas` (LinkType=Junction) |
| **Lock** | entrada `ezyventas/ai-agent` `1.0.0-dev`, `dist.type=path` | `composer.lock:1041-1045` |
| **Descubrimiento** | auto-discovery de Laravel vía `extra.laravel.providers` | `packages/ai-agent/composer.json:16-21` |
| **Metadatos del paquete** | `name`, `version: 1.0.0-dev`, `type: library`, requiere `php ^8.2`, `illuminate/support ^11|^12`, `guzzlehttp/guzzle ^7.2` | `packages/ai-agent/composer.json:2-15` |

### 9.3 ServiceProvider (`packages/ai-agent/src/AiAgentServiceProvider.php`)

| Responsabilidad | Implementación | Línea |
|---|---|---|
| Config | `mergeConfigFrom(__DIR__.'/../config/ai-agent.php', 'ai-agent')` | `:14-16` |
| Bindings | `singleton(ToolRegistry::class)`, `scoped(WriteModeGate::class)` | `:18-24` |
| **Migraciones** | `loadMigrationsFrom(__DIR__.'/../database/migrations')` → se ejecutan con `php artisan migrate` **sin publicar** | `:33` |
| **Publish config** | tag `ai-agent-config` | `:35-37` |
| **Publish migraciones** | tag `ai-agent-migrations` | `:39-41` |
| **Rutas: NO las carga el provider** | `loadRoutesFrom` está **comentado** (`:29-31`) con esta justificación textual: "Routes are loaded from routes/web.php (to inherit the web middleware group) so they go through EnsureFrontendRequestsAreStateful for Sanctum SPA auth" | `:29-31` |

**Consecuencia práctica (importante para smart-checkout):** el paquete **no** registra sus rutas; el host las incluye a mano:

```php
require base_path('packages/ai-agent/routes/ai-agent.php');
```

`routes/web.php:89` (justo después de los 42 `require` de `routes/web/*.php`, `routes/web.php:45-86`).

### 9.4 Rutas del paquete (`packages/ai-agent/routes/ai-agent.php`)

- Grupo: `middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])`, `prefix('ai-agent')`, `as('ai-agent.')` (`:16-19`).
- Endpoints: `GET/POST /conversations`, `GET /conversations/{c}`, `POST /conversations/{c}/messages`, `DELETE /conversations` y `/conversations/{c}`, `GET /usage` (`:20-40`).
- Descarga protegida por **URL firmada** además de sesión: `->middleware('signed')` (`:43-47`).

### 9.5 Multi-tenancy dentro del paquete (ejemplo a copiar)

- La tabla lleva `subscription_id` NOT NULL con cascade (`packages/ai-agent/database/migrations/0001_create_ai_conversations_table.php:13`).
- El FK es **configurable**: `'tenant_fk_column' => 'subscription_id'` (`packages/ai-agent/config/ai-agent.php:95`), pensado para reutilizar el paquete en proyectos con `company_id`/`team_id` (`:88-94`).

### 9.6 Config publicable (`packages/ai-agent/config/ai-agent.php`)

Claves reales: `default_provider` (:15), `default_model` (:26), `default_api_key` (:37), `max_tool_steps` = 6 (:49), `request_timeout` = 60 (:61), `download_url_ttl` = 15 (:72), `export_disk` (:83), `tenant_fk_column` (:95), `default_monthly_tokens` 2 000 000 (:108), `pricing_usd_per_million_tokens` (:120-123).

### 9.7 Assets / frontend: **el paquete no publica ni compila assets**

- No hay `resources/`, `public/`, `vite.config` ni `package.json` dentro de `packages/ai-agent`.
- El frontend vive **en el host**, en rutas fijas:
  - `resources/js/Composables/useAiChat.js` (axios a `/ai-agent/conversations`, `/ai-agent/usage`; líneas `:61,90,146,161,202,212,239`).
  - `resources/js/Components/AiChatDrawer.vue` (`:5` importa el composable; `:49,62` consumo de `/ai-agent/usage`; `:181` descarga firmada).
  - Se monta desde `resources/js/Layouts/AppLayout.vue:53-54` (`aiDrawerVisible`).
  - Admin: `resources/js/Pages/Admin/AiAgent/Settings.vue:28` → `admin.ai-agent.update`.
- Detalle de estilo: `AiChatDrawer.vue:5` importa `'@/composables/useAiChat'` en minúscula mientras la carpeta real es `Composables`. Funciona en Windows (FS case-insensitive) pero **rompería en Linux/CI**.

### 9.8 ¿Conviene replicar este patrón para `smart-checkout`?

**Sí, con dos matices.** Lo que debe copiarse tal cual:

1. `packages/<nombre>/composer.json` con `name`, `type: library`, PSR-4 propio y `extra.laravel.providers` (auto-discovery).
2. `repositories: [{type: path, url: packages/<nombre>, options: {symlink: true}}]` + `require: "ezyventas/<nombre>": "@dev"` + PSR-4 en el autoload del host (`composer.json:8-16,19,50`).
3. `ServiceProvider` con `mergeConfigFrom` + `loadMigrationsFrom` + dos `publishes` (config y migraciones).
4. Migraciones dentro del paquete con `subscription_id` (o `branch_id`) + FK cascade.
5. Rutas en `packages/<nombre>/routes/<nombre>.php` **incluidas desde `routes/web.php`**, no desde el provider, para heredar el grupo `web` (Sanctum/estado de sesión) — replicar el comentario y la decisión.
6. Tests: **no** dentro del paquete (el precedente no los tiene); se escriben en `tests/Feature/` del host.

Matices a mejorar respecto al precedente:
- El precedente **no tiene tests propios** → para smart-checkout conviene añadirlos, o al menos tests de host desde el día 1.
- El precedente **no publica assets** → si smart-checkout necesita un composable Vue, hay que decidir conscientemente: dejarlo en `resources/js/Composables/` del host (como `useAiChat.js`) o añadir un publish de assets (no hay precedente de esto en el repo).
- `config('ai-agent.tenant_fk_column')` es un buen patrón (FK configurable) que sí conviene heredar.

---

## 10. PRUEBAS

### 10.1 Estructura

```
tests/
├── TestCase.php                     (10 líneas, extiende Illuminate\Foundation\Testing\TestCase, sin traits)
├── Unit/ExampleTest.php             (1 test)
├── Feature/                         45 archivos
│   ├── Api/V1/…                     (14 archivos + Concerns/BuildsMobileApiContext.php + BuildsPosApiContext.php)
│   ├── Billing/                     (4)
│   ├── Lifecycle/                   (1)
│   └── Admin/                       (1)
```

### 10.2 Configuración (`phpunit.xml`)

| Variable | Valor | Línea |
|---|---|---|
| `APP_ENV` | `testing` | `:21` |
| **`DB_CONNECTION`** | **`sqlite`** | `:25` |
| **`DB_DATABASE`** | **`:memory:`** | `:26` |
| `CACHE_STORE` | `array` | `:24` |
| `QUEUE_CONNECTION` | `sync` | `:28` |
| `SESSION_DRIVER` | `array` | `:29` |
| `MAIL_MAILER` | `array` | `:27` |
| `BCRYPT_ROUNDS` | 4 | `:23` |

➜ **Los tests NO tocan MySQL**: usan SQLite en memoria. Ejecutarlos es seguro (no escribe en la BD de desarrollo). Suites: `Unit` y `Feature` (`phpunit.xml:7-14`).

### 10.3 Cuántas pruebas hay y cómo se escriben

- **419 tests** (conteo de atributos `#[Test]` en `tests/`).
- Estilo: **PHPUnit 11 con atributos**, no Pest y **no** prefijo `test_`:

  ```php
  use PHPUnit\Framework\Attributes\Test;
  #[Test]
  public function user_without_financial_permission_cannot_open_the_report(): void
  ```
  (`tests/Feature/FinancialReportAccessTest.php:10,29-30`)

- Uso de `RefreshDatabase` (`FinancialReportAccessTest.php:16`) y `withoutMiddleware` para los middlewares globales que estorban (`:22-26`).
- Archivos más grandes: `ReferralSystemTest.php` (53 tests), `AiAgentToolsTest.php` (19), `TransactionWriteApiTest.php` (19), `PrintingApiTest.php` (18), `PosApiTest.php` (17).

### 10.4 Cómo se ejecutan

- `php artisan test` (script `composer test` hace `config:clear` + `artisan test`, `composer.json:80-83`).
- `vendor/bin/phpunit` también funciona (PHPUnit 11.5.56).

### 10.5 Resultado real de la suite (ejecutada en modo lectura)

```
Tests:    24 failed, 395 passed (2460 assertions)
Duration: 56.86s
```

(Tiempo de pared medido con cronómetro: **58.22 s**, incluye arranque de PHP.)

**La suite NO está en verde.** Los 24 fallos se agrupan en **3 causas**, todas diagnosticables:

| # fallos | Test | Causa exacta | ¿Afecta a smart-checkout? |
|---|---|---|---|
| 11 | `tests/Feature/AiAgentToolsTest.php` | **Clases inexistentes**: `Class "Database\Factories\PromotionFactory" not found` (2), `Class "Database\Factories\TransactionItemFactory" not found` (6), `Class "App\Models\Invoice" not found` (3 → el modelo real es `App\Models\Billing\Invoice`). Faltan esos factories en `database/factories/`. | No, pero avisa de que **no hay `PromotionFactory` ni `TransactionItemFactory`**: si smart-checkout necesita promociones o líneas de venta en tests, hay que crearlos. |
| 3 | `tests/Feature/PosHelpArticlesSeedTest.php` | La migración **`2026_10_18_000001_seed_pos_help_articles.php` no existe** en `database/migrations/` (`require(...): Failed to open stream`), y por eso la BD de test tiene 0 artículos donde espera 12. | No. |
| 6 | `PrintTemplateControllerTest`, `ProductControllerTest`, `QuoteControllerTest`, `ServiceControllerTest`, `ServiceOrderControllerTest`, `TransactionControllerTest` — todas `it denies access without permissions` | Asertan `403` y reciben **200**. **No es un bug de permisos: es el diseño del `Gate::before`.** El test hace `$this->user->roles()->detach()` (`QuoteControllerTest.php:585`, `ServiceOrderControllerTest.php:475`) para "quitar permisos", pero **quitar los roles convierte al usuario en DUEÑO** (`User::isOwner()` = sin roles, `app/Models/User.php:156-159`) y el dueño recibe **todos** los permisos de sus módulos contratados (`AppServiceProvider.php:63-76`). | **Sí, conceptualmente**: cualquier verificación de permiso es inoperante frente al dueño de la suscripción y frente al `user id 1`. El gating real de una feature debe ser **módulo/plan**, no solo permiso. |

**Conclusión operativa:** los 6 fallos de "denies access" son **tests mal planteados**, no regresiones; y el resultado confirma una regla de oro del sistema: **para bloquear algo de verdad hay que usar el módulo del plan (o un check explícito), porque el dueño siempre pasa los permisos.**

### 10.6 Factories disponibles (30)

Existen y son utilizables: `ProductFactory`, `TransactionFactory`, `SubscriptionFactory`, `BranchFactory`, `UserFactory`, `CustomerFactory`, `ServiceFactory`, `ServiceOrderFactory`, `QuoteFactory`, `CashRegisterFactory`, `CashRegisterSessionFactory`, `PrintTemplateFactory`, `PaymentFactory`, `CategoryFactory`, `BrandFactory`, `ProviderFactory`, `ExpenseCategoryFactory`, `ExpenseFactory`, `GlobalProductFactory`, `BankAccountFactory`, `AttributeDefinitionFactory`, `AttributeOptionFactory`, `CustomFieldDefinitionFactory`, `SessionCashMovementFactory`, `SubscriptionPaymentFactory`, `SubscriptionVersionFactory`, `Referral*Factory` (4).

Patrón de anidamiento: `BranchFactory` → `'subscription_id' => Subscription::factory()` (`database/factories/BranchFactory.php:13`); `TransactionFactory` → `Branch::factory()` + `User::factory()` + `Customer::factory()` + `CashRegisterSession::factory()` (`database/factories/TransactionFactory.php:30-33`). `ProductFactory` **no** fija tenant: el stock se ata después con el estado `withStock($branchId, ...)` que hace `attach` al pivote `branch_product` (`database/factories/ProductFactory.php:37-47`) — coherente con que `products` no tenga `subscription_id`.

**No existen** (relevantes para un módulo de caja/báscula): `PromotionFactory`, `TransactionItemFactory`, `PromotionRuleFactory`, `PromotionEffectFactory`.

---

## 11. CONVENCIONES

### 11.1 Estilo de código: Pint está configurado pero **NO se cumple**

- `laravel/pint ^1.13` en dev (`composer.json:42`), **sin `pint.json`** (búsqueda en raíz → no existe) ⇒ preset `laravel` por defecto.
- **Medición real:** `vendor/bin/pint --test app` → **`525 files, 395 style issues`** (`FAIL`).
- Reglas que más fallan (muestra): `not_operator_with_successor_space`, `concat_space`, `binary_operator_spaces`, `method_chaining_indentation`, `no_superfluous_phpdoc_tags`, `fully_qualified_strict_types`, `single_quote`, `unary_operator_spaces`.
- Implicación: **no ejecutar `pint` sobre el repo entero** (generaría un diff gigante). Si se usa, limitarlo a los archivos nuevos: `vendor/bin/pint app/Services/SmartCheckout packages/smart-checkout`.

### 11.2 Arquitectura y nombres (lo que el código respeta de verdad)

| Capa | Convención | Ejemplos |
|---|---|---|
| Controladores | `app/Http/Controllers/{Module}/…Controller.php`, **finos**, implementan `HasMiddleware` con `can:` | `ProductController.php:30-39`; `RolePermissionController.php:15-24` |
| Actions | `app/Actions/{Module}/VerbEntityAction.php`, **un método público `execute()`** | `app/Actions/Admin/PlanItems/CreatePlanItemAction.php:13` (`execute(array $data): PlanItem`); 74 archivos |
| Services | `app/Services/{Module}/EntityService.php`, inyectados por constructor | `app/Services/Printing/PrintDataSourceResolver.php:40`; `app/Services/Auth/UserAccessContextService.php:20`; 60 archivos |
| FormRequests | `app/Http/Requests/{Module}/Store*Request.php`, toda la validación + `authorize()` | 103 con `authorize()` |
| Rutas | un archivo por feature en `routes/web/kebab-case.php` + `require` en `routes/web.php`; nombres `modulo.accion` | `routes/web.php:45-86`; `routes/web/print.php:7-10` |
| Modelos | 74 archivos; `$fillable` explícito (nunca `$guarded = []`), casts a Enum, scopes | `app/Models/PlanItem.php:14-42` |
| Enums | **28** backed enums `string` en `app/Enums` | `app/Enums/PlanItemType.php`, `SubscriptionStatus`, `TransactionStatus`, `PaymentMethod`, `TemplateType`… |
| Pages Vue | `resources/js/Pages/{Module}/Index.vue`, **239 `.vue`** en 24 carpetas de módulo | — |
| Docs | `docs/context/*.md` (21 archivos, §12) | — |

**Enums:** `app/Enums` tiene 28 archivos, todos `enum X: string` con `case CONSTANT = 'valor'`. Uso típico: casts en modelos (`Subscription.php:51` → `SubscriptionStatus::class`; `PlanItem.php:25` → `PlanItemType::class`) y validación con `Rule::enum(...)` (`app/Http/Requests/Admin/StorePlanItemRequest.php:21`).

**Observers / Events / Listeners (muy escasos, 1 de cada):**
- `app/Observers/StampMovementObserver.php` (y `app/Observers/Billing/`), registrados en `AppServiceProvider.php:97-98`.
- `app/Events/SessionClosed.php` (broadcast, §8).
- `app/Listeners/RecordUserLastLogin.php`, registrado con `Event::listen(Login::class, ...)` en `AppServiceProvider.php:103`.
- **No hay `EventServiceProvider` ni `ObserverServiceProvider`**: todo el cableado va en `AppServiceProvider::boot()`.

**Jobs / scheduler:** `routes/console.php` (backup diario 01:00, stats de timbres cada 30 min, conciliación 04:00, cancelaciones horarias, alertas 08:00, ciclo de vida 09:00/09:30). Cola `database` (`.env:38`).

**Reglas de estilo declaradas vs. realidad (`.clinerules/ezyventas-rules.md`):**

| Regla declarada | ¿Se cumple? |
|---|---|
| Código/comentarios/nombres en **inglés**; UI en **español** sentence case (`.clinerules:1`) | **Parcial**: hay comentarios y mensajes en español por todo el código (`Subscription.php:39-41`, `ProductController.php:46`, `EnsureSubscriptionScope.php:33,59`), y nombres de variables en español (`$ropaCategory`, `$electronicaCategory` en `DatabaseSeeder.php:93,97`). La UI sí es español consistentemente. |
| Permisos kebab-case (`:169-170`) | **No**: son `recurso.accion` con punto (§3.2). |
| `$guarded = []` prohibido | Sí (todos usan `$fillable`). |
| `$request->validate()` solo en FormRequests (`:87`) | **No siempre**: hay controladores que validan inline (`PrintController.php:22-30,65-70`; `RolePermissionController.php:61-74`). |
| Nunca chequeos por rol, siempre permisos (`:169-173`) | Sí en la práctica (no hay `hasRole()` en `app/`). |
| No `<Toast/>` por página, usar flash (`:127`) | Sí (centralizado en `AppLayout.vue`). |

---

## 12. `docs/context` — contenido real (21 archivos)

La salida anterior no mostraba nombres; este es el listado exacto con tamaños:

| Archivo | Bytes | Tema |
|---|---|---|
| `00-overview.md` | 10 427 | Stack, estructura, decisiones de arquitectura, índice de módulos |
| `01-database-schema.md` | 24 788 | Esquema de BD |
| `02-module-auth-users.md` | 5 220 | Auth, 2FA, OAuth, usuarios, roles y permisos |
| `03-module-subscriptions.md` | 8 069 | Suscripciones, ítems de plan, pagos, sucursales |
| `04-module-products-inventory.md` | 7 105 | Productos, catálogo, variantes, stock |
| `05-module-pos-sales.md` | 6 866 | POS, transacciones, pagos, apartados |
| `06-module-customers.md` | 4 180 | Clientes, saldos, crédito |
| `07-module-services-orders.md` | 5 857 | Servicios y órdenes |
| `08-module-quotes.md` | 3 737 | Cotizaciones |
| `09-module-invoices.md` | 4 446 | CFDI 4.0 |
| `10-module-expenses-banking.md` | 4 531 | Gastos y bancos |
| `11-module-cash-register.md` | 5 272 | Cajas y sesiones |
| `12-module-promotions.md` | 3 880 | Promociones |
| `13-module-online-store.md` | 5 958 | Tienda en línea |
| `14-module-reports-analytics.md` | 4 633 | Reportes y dashboard |
| `15-module-admin-settings.md` | 6 913 | Settings, **plantillas e impresión**, admin, superadmin |
| `16-module-referrals.md` | 5 054 | Referidos |
| `17-module-ai-agent.md` | 5 797 | Agente IA |
| `17b-ai-agent-tools-inventory.md` | 32 854 | Inventario de herramientas de IA (el más grande) |
| `18-module-misc.md` | 6 879 | Release notes, activity log, media, help center |
| `fase-3.md` | 8 287 | Planificación de facturación fase 3 |

**Lo más útil para smart-checkout:** `00-overview.md` (§2 estructura, §3 decisiones, §6 patrones de Controller→Action→Service y flash→toast) y `15-module-admin-settings.md` (settings polimórficos en `:107-128` y sistema de impresión).

**Discrepancias docs↔código detectadas (no fiarse de los docs en estos puntos):**

| Afirmación en `docs/context` | Realidad en código |
|---|---|
| Permisos en kebab-case (`00-overview.md:81,184`) | `recurso.accion` con punto (`PermissionSeeder.php:20-166`) |
| `CheckSuperAdmin`: "ensures user has super-admin role" (`00-overview.md:160`) | No hay rol; comprueba `branch->subscription_id === 1` (`CheckSuperAdmin.php:24`) |
| Impresión "via WebUSB or network" (`15-module-admin-settings.md:128`) | Plugin local HTTP `localhost:8000` o Web Bluetooth (`usePrintPlugin.js:5,59`; `useBluetoothPrinter.js:113`) |
| "23 PHP backed enums", "61 Eloquent models" (`00-overview.md:38,45`) | **28 enums**, **74 archivos en `app/Models`** (medido) |
| `SettingValue` con relación `settable` (`15-module-admin-settings.md:111`) | Se renombró a `configurable` (`app/Models/SettingValue.php:23-32`) |

---

## 13. Síntesis accionable para "smart-checkout"

| Decisión | Recomendación respaldada por evidencia |
|---|---|
| ¿Base por tenant? | **No.** BD única, `subscription_id`/`branch_id` + trait `HasSubscription` + middleware global (`bootstrap/app.php:31`). |
| Columna del módulo nuevo | Si el dato es del negocio → `subscription_id`; si es de la caja/sucursal (venta, pesaje, sesión) → **`branch_id`** como `products` y `transactions`. Nunca ambos salvo necesidad. |
| Aislamiento | Declarar `use HasSubscription`, añadir a `$fillable`, y **acotar cada query a mano** (`ProductController.php:46,90`). El middleware solo cubre route-model binding. |
| Permisos | Nombres `<recurso>.<accion>` (`smart_checkout.open`, `smart_checkout.weigh`, `scale.manage`), `module` = nuevo grupo legible (p. ej. `Caja Inteligente`) en `PermissionSeeder`, creados en runtime vía `POST /permissions` para no romper asignaciones. |
| Flags por tenant | **`module_smart_checkout` / `module_scale` como `PlanItem`** (módulo) + opcionalmente `SettingDefinition` `level='subscription'` booleanos leídos con `getSettingValue()`. Evitar columnas nuevas en `subscriptions`. |
| Menú | Añadir el ítem al array de `AppMenu.vue` con `permission` + `module`. |
| Comunicación con hardware | Precedente: **composable Vue** (`useBluetoothPrinter.js` para APIs del navegador; `usePrintPlugin.js` para servicio local HTTP). **No existe Web Serial todavía**: hay que crearlo. |
| Empaquetado | `packages/smart-checkout` con el patrón de `packages/ai-agent` (path+symlink, provider con migraciones y config publicable, **rutas incluidas desde `routes/web.php`**). |
| Notificaciones | `->with('success'\|'error', ...)`; el toast es automático (`AppLayout.vue:66-90`). No añadir toasts propios si la acción recarga. |
| Tests | `RefreshDatabase` (SQLite `:memory:`), `#[Test]`, `withoutMiddleware([CheckOnboardingStatus, CheckSubscriptionStatus, EnsureSubscriptionScope])`. Base actual: 419 tests, 24 fallos preexistentes por factories/modelo/migración ausentes y por el comportamiento "dueño sin roles". |
