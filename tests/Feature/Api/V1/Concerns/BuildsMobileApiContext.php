<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Enums\PlanItemType;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reusable context of the mobile API tests: an active POS subscription, the
 * POS permissions of the plan and helpers to authenticate owners and employees
 * exactly like the app does (Sanctum token from POST /auth/login).
 */
trait BuildsMobileApiContext
{
    protected Branch $branch;

    protected Subscription $subscription;

    /**
     * Permissions of the Punto de Venta module used by the read endpoints.
     *
     * @var array<int, string>
     */
    protected array $mobilePermissions = [
        'pos.access',
        'pos.create_sale',
        'system.branches.switch',
        'customers.access',
        'customers.see_details',
        'customers.create',
        'services.orders.access',
        'services.orders.see_details',
        'services.orders.create',
        'services.orders.edit',
        'services.orders.change_status',
        'services.orders.delete',
        'transactions.access',
        'transactions.see_details',
        'transactions.add_payment',
        'transactions.edit_payment',
        'transactions.cancel',
        'transactions.refund',
    ];

    protected function setUpMobileApiContext(): void
    {
        $this->branch = Branch::factory()->create();
        $this->subscription = $this->branch->subscription;
        $this->subscription->update(['onboarding_completed_at' => now()]);

        // Gate::before grants every ability to user id 1 (platform support), so
        // the context always starts with that user and the tests stay on regular
        // users from id 2 on, where permissions are really evaluated.
        User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'soporte@test.com',
            'password' => 'secreto123',
        ]);

        $version = SubscriptionVersion::create([
            'subscription_id' => $this->subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        SubscriptionItem::create([
            'subscription_version_id' => $version->id,
            'item_key' => 'module_pos',
            'item_type' => 'module',
            'name' => 'Punto de Venta',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        PlanItem::create([
            'key' => 'module_pos',
            'type' => PlanItemType::MODULE,
            'name' => 'Punto de Venta',
            'monthly_price' => 0,
            'is_active' => true,
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->mobilePermissions as $permission) {
            Permission::create(['name' => $permission, 'module' => 'Punto de Venta', 'guard_name' => 'web']);
        }
    }

    /**
     * Subscription owner: a user without roles receives every permission of
     * the contracted modules.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function ownerUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            // Unique by default: a test may create more than one owner.
            'email' => 'dueno-' . Str::lower(Str::random(6)) . '@test.com',
            'password' => 'secreto123',
        ], $attributes));
    }

    /**
     * Employee with only the given permissions.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    protected function employeeUser(array $permissions, array $attributes = []): User
    {
        $user = $this->ownerUser($attributes);

        // The guard is explicit on purpose: `config('auth.defaults.guard')` is
        // switched to "sanctum" by the API middleware of a previous request in
        // the same test, which would make the role look for the wrong guard.
        $role = Role::create([
            'name' => 'Vendedor ' . $user->id,
            'branch_id' => $this->branch->id,
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(
            Permission::whereIn('name', $permissions)->where('guard_name', 'web')->get()
        );
        $user->assignRole($role);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Token issued by the mobile login for the given user.
     *
     * The cached auth guards are dropped on purpose: within a single test the
     * Sanctum request guard keeps the user of the first authenticated request,
     * so switching tokens mid-test would silently keep using that first user.
     * In production every request boots a fresh app, so this is test-only.
     */
    protected function tokenFor(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secreto123',
            'device_name' => 'Pixel 7',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    /**
     * Uploaded image written to a real temp path.
     *
     * UploadedFile::fake() relies on an auto-deleting temporary handle that is
     * not always reliable on Windows, which made the upload tests flaky.
     */
    protected function evidenceImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence');
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path, 80);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * Uploaded file that is not an image (for validation tests).
     */
    protected function notAnImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'document');
        file_put_contents($path, '%PDF-1.4 documento de prueba');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
