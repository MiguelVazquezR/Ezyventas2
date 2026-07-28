# 02 — Auth & Users Module

---

## What It Does
Handles all authentication (login, register, password reset, 2FA, Google OAuth), user management CRUD, role & permission management, user profiles, API token management, and the onboarding wizard for new subscriptions.

---

## Key Files

### Backend
| File | Role |
|---|---|
| `app/Models/User.php` | User model with Sanctum, Jetstream, Spatie roles |
| `app/Models/Role.php` | Extends Spatie Role with branch scoping |
| `app/Actions/Fortify/CreateNewUser.php` | Registration logic |
| `app/Actions/Fortify/ResetUserPassword.php` | Password reset |
| `app/Actions/Fortify/UpdateUserPassword.php` | Password change |
| `app/Actions/Fortify/UpdateUserProfileInformation.php` | Profile update |
| `app/Actions/Jetstream/DeleteUser.php` | Account deletion |
| `app/Actions/User/StoreUserAction.php` | Create user (admin) |
| `app/Actions/User/UpdateUserAction.php` | Update user (admin) |
| `app/Http/Controllers/UserController.php` | User CRUD controller |
| `app/Http/Controllers/RolePermissionController.php` | Role & permission CRUD |
| `app/Http/Controllers/PermissionController.php` | Permission-only management |
| `app/Http/Controllers/OnboardingController.php` | Onboarding wizard |
| `app/Http/Controllers/OnboardingTourController.php` | In-app tour steps |
| `config/fortify.php` | Fortify config (2FA, etc.) |
| `config/jetstream.php` | Jetstream features (API, teams disabled) |
| `routes/web/users.php` | User route definitions |
| `routes/web/roles.php` | Role route definitions |
| `routes/web/permissions.php` | Permission route definitions |
| `routes/web/google-auth.php` | Google OAuth routes |

### Frontend
| File | Purpose |
|---|---|
| `Pages/Auth/Login.vue` | Login page |
| `Pages/Auth/Register.vue` | Registration page |
| `Pages/Auth/ForgotPassword.vue` | Password reset request |
| `Pages/Auth/ResetPassword.vue` | Set new password |
| `Pages/Auth/ConfirmPassword.vue` | Confirm password for sensitive actions |
| `Pages/Auth/TwoFactorChallenge.vue` | 2FA code entry |
| `Pages/Auth/VerifyEmail.vue` | Email verification prompt |
| `Pages/User/Index.vue` | User list (admin) |
| `Pages/User/Create.vue` | Create user form |
| `Pages/User/Edit.vue` | Edit user form |
| `Pages/Role/Index.vue` | Roles & permissions management |
| `Pages/Profile/Show.vue` | User profile page |
| `Pages/API/Index.vue` | API token management |
| `Pages/Onboarding/Setup.vue` | Onboarding wizard |

---

## Main Endpoints

### Auth (Laravel Fortify + Jetstream — handled automatically)
- `POST /login`, `POST /logout`, `POST /register`
- `POST /forgot-password`, `POST /reset-password`
- `POST /user/confirm-password`
- `POST /user/two-factor-authentication`
- `GET /auth/google` — Google OAuth redirect
- `GET /auth/google/callback` — Google OAuth callback

### Users (`/users`)
- `GET /users` — `users.index` — List all users (scoped to branch)
- `GET /users/create` — `users.create`
- `POST /users` — `users.store`
- `GET /users/{user}/edit` — `users.edit`
- `PUT /users/{user}` — `users.update`
- `DELETE /users/{user}` — `users.destroy`
- `PATCH /users/{user}/toggle-status` — Activate/deactivate user

### Roles & Permissions (`/roles-permissions`)
- `GET /roles-permissions` — `roles.index` — List roles with permissions
- `POST /roles-permissions` — `roles.store`
- `PUT /roles-permissions/{role}` — `roles.update`
- `DELETE /roles-permissions/{role}` — `roles.destroy`
- Also: `POST/DELETE /permissions` for direct permission management

### Onboarding (`/onboarding`)
- `GET /onboarding/setup` — `onboarding.setup` — Show wizard
- `POST /onboarding/step-1` — Business info
- `POST /onboarding/step-2` — Branch setup
- `POST /onboarding/step-3` — Product import
- `POST /onboarding/finish` — Complete onboarding

### Profile
- `GET /user/profile` — Show profile (Jetstream default)
- `PUT /user/profile-information` — Update name/email
- `PUT /user/password` — Change password

---

## Dependencies
- **Subscriptions**: Users belong to branches which belong to subscriptions. The `HasSubscription` trait on `User` resolves through the branch chain.
- **Spatie Permission**: All authorization depends on this package.

---

## Permission Convention
All permissions use kebab-case: `create service-orders`, `edit invoices`, `delete customers`. Permissions are checked in Form Requests via `$this->user()->can('permission-name')` — never by checking roles directly.

---

## Known Limitations / Technical Debt
1. **No Laravel Policies** — all authorization is done ad-hoc in Form Request `authorize()` methods using Spatie. There's no consistent policy layer.
2. **Branch-scoped roles** — `Role` model overrides `create()` to scope by `branch_id`, which means role names can be duplicated across branches. This may cause confusion.
3. **Google OAuth limited** — OAuth only handles login/link; no disconnect or multi-provider support.
4. **No team support** — Jetstream teams feature is disabled. All users are branch-scoped.
5. **Onboarding is one-shot** — Once `onboarding_completed_at` is set on the subscription, it cannot be re-triggered.
