# 18 — Miscellaneous & Infrastructure Module

---

## What It Does
Covers cross-cutting infrastructure and smaller features: activity/audit logging, media/file management, release notes/changelog, waitlist (pre-launch email capture), help center, welcome/landing pages, and the app layout/shell.

---

## 1. Activity Log (Spatie)

### Key Files
| File | Role |
|---|---|
| `app/Services/ActivityLogService.php` | Activity log query service |
| `config/activitylog.php` | Spatie Activitylog config |

### What It Does
Tracks model changes across the application. Models that use the `LogsActivity` trait:
- `Transaction` — logs `status` and `delivery_status` changes
- `Product` — logs product changes
- `Service` — logs service changes
- `ServiceOrder` — logs status changes
- `Quote` — logs status changes
- `Expense` — logs changes
- `Invoice` — logs status changes

Events are translated to Spanish in the log description (e.g., "La transacción ha sido actualizada").

### Frontend
`Components/ActivityHistory.vue` — Renders activity log entries.

---

## 2. Media Library (Spatie)

### Key Files
| File | Role |
|---|---|
| `config/media-library.php` | Spatie Media Library config |
| `app/Services/LocalImageOptimizerService.php` | Local image optimization |
| `app/Services/TinifyService.php` | TinyPNG integration |
| `app/Traits/OptimizeMediaLocal.php` | Trait for local optimization |

### What It Does
Handles all file uploads and image management. Models using `InteractsWithMedia`:
- `Product` — `product-general-images`, `product-variant-images`
- `Service` — service images
- `ServiceOrder` — `initial-service-order-evidence`, `closing-service-order-evidence`
- `StoreConfig` — `store-logo`, `store-banners`
- `Subscription` — `fiscal-documents`
- `SubscriptionPayment` — `proof_of_payment`
- `PrintTemplate` — template images
- `ReleaseNote` — `gallery`, `banner`
- `GlobalProduct` — `product-general-images`

Image optimization uses TinyPNG when available, with a local fallback.

---

## 3. Release Notes

### Key Files
| File | Role |
|---|---|
| `app/Models/ReleaseNote.php` | Release note with version, content, banner |
| `app/Http/Controllers/ReleaseNoteController.php` | User-facing + admin CRUD |
| `routes/web/release-notes.php` | User routes |
| `routes/web/super-admin.php` | Admin routes |

### Endpoints (User)
- `GET /release-notes` — List published notes
- `POST /release-notes/{note}/mark-read` — Mark as read
- `POST /release-notes/mark-all-read` — Mark all read
- `GET /release-notes/{note}` — Show release note

### Endpoints (Admin)
- Full CRUD at `/admin/release-notes`
- `POST /admin/release-notes/{note}/toggle-publish` — Publish/unpublish

### Features
- Markdown content
- Banner mode (prominent announcement)
- Read tracking per user (pivot: `release_note_user`)
- Gallery images via Spatie Media

---

## 4. Waitlist

### Key Files
| File | Role |
|---|---|
| `app/Models/Waitlist.php` | Email capture |
| `routes/web.php` | `POST /unirse-lista` route |

### What It Does
Pre-launch email capture on the landing page. Simple: validate email uniqueness, store in `waitlists` table.

---

## 5. Help Center

### Key Files
| File | Role |
|---|---|
| `Pages/HelpCenter.vue` | Help center page |
| `routes/web.php` | `GET /centro-ayuda` → `help-center` route |

### What It Does
Static help center page. Route: `GET /centro-ayuda` renders `HelpCenter.vue`.

---

## 6. Welcome / Landing Pages

### Key Files
| File | Role |
|---|---|
| `Pages/Welcome.vue` | Desktop landing page |
| `Pages/WelcomeMobile.vue` | Mobile landing page |
| `Pages/PrivacyPolicy.vue` | Privacy policy |
| `Pages/TermsOfService.vue` | Terms of service |

### What It Does
Public-facing landing pages. The root route (`/`) detects device type and renders either desktop or mobile welcome page. These pages are accessible without authentication.

---

## 7. App Layout / Shell

### Key Files
| File | Role |
|---|---|
| `Layouts/AppLayout.vue` | Main app layout (sidebar, topbar, toast) |
| `Layouts/AppSidebar.vue` | Navigation sidebar |
| `Layouts/AppTopbar.vue` | Top bar (branch switcher, user menu, release notes bell) |
| `Layouts/AppMenu.vue` | Menu configuration |
| `Layouts/AppMenuItem.vue` | Individual menu item |
| `Layouts/AppFooter.vue` | App footer |
| `Layouts/AppConfigurator.vue` | UI configurator (theme toggle) |
| `Layouts/StoreLayout.vue` | Separate layout for public online store |
| `Layouts/Partials/` | Layout sub-components |

### What It Does
The main application shell with:
- Responsive sidebar navigation
- Branch switcher in topbar
- Release notes notification bell
- User dropdown menu
- Global toast notification handler (picks up Inertia flash messages)
- Dark mode toggle
- Tesla UI design system (`#232323` dark panels, `#1a1a1a` inputs, rounded-3xl, etc.)

---

## 8. Pattern Lock

### Key Files
| File | Role |
|---|---|
| `Components/PatternLock.vue` | Pattern lock component |

Used for quick cash register access or other secure operations within the app.

---

## Known Limitations / Technical Debt (Cross-Cutting)

1. **No error tracking service** — No Sentry, Bugsnag, or similar. Errors only go to Laravel logs.
2. **No automated backups** — Database and media backups are not automated.
3. **No rate limiting on most endpoints** — Only Laravel's default throttling applies.
4. **No comprehensive test suite** — Tests exist in `tests/` but coverage is unknown. Check `phpunit.xml` for configuration.
5. **Help center is static** — No CMS or dynamic content. Updates require code changes.
6. **No feature flags** — Features are enabled/disabled via settings or plan limits, not a dedicated feature flag system.
7. **Welcome page is not localized** — Spanish-only; no i18n on public pages.
