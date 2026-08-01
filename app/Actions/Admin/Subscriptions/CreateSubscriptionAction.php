<?php

namespace App\Actions\Admin\Subscriptions;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSubscriptionAction
{
    /**
     * Crea una nueva suscripción con sucursal principal, usuario administrador
     * y versión inicial con los plan items seleccionados.
     */
    public function execute(array $data): Subscription
    {
        return DB::transaction(function () use ($data) {

            // 1. Crear la suscripción
            $onboardingCompletedAt = ($data['complete_onboarding'] ?? false) ? now() : null;

            $subscription = Subscription::create([
                'business_name'          => $data['business_name'],
                'commercial_name'        => $data['commercial_name'],
                'contact_email'          => $data['contact_email'] ?? null,
                'contact_phone'          => $data['contact_phone'] ?? null,
                'tax_id'                 => $data['tax_id'] ?? null,
                'address'                => $data['address'] ?? null,
                'slug'                   => Str::slug($data['business_name']),
                'status'                 => 'activo',
                'onboarding_completed_at' => $onboardingCompletedAt,
            ]);

            // 2. Crear la sucursal principal
            $branch = $subscription->branches()->create([
                'name'     => 'Principal',
                'is_main'  => true,
                'timezone' => 'America/Mexico_City',
            ]);

            // 3. Crear el usuario administrador
            $emailVerifiedAt = ($data['verify_email'] ?? false) ? now() : null;

            $user = User::create([
                'name'              => $data['admin_name'],
                'email'             => $data['admin_email'],
                'password'          => Hash::make($data['admin_password']),
                'branch_id'         => $branch->id,
                'email_verified_at' => $emailVerifiedAt,
            ]);

            // 4. Crear la versión inicial (30 días de prueba)
            $version = $subscription->versions()->create([
                'start_date' => now(),
                'end_date'   => now()->addDays(30),
            ]);

            // 5. Asignar módulos activos
            $modulePlanItems = PlanItem::where('type', PlanItemType::MODULE)->get()->keyBy('key');

            foreach ($data['modules'] as $key => $isActive) {
                if (! $isActive) {
                    continue;
                }

                $planItem = $modulePlanItems->get($key);
                if (! $planItem) {
                    continue;
                }

                $version->items()->create([
                    'item_key'       => $key,
                    'item_type'      => PlanItemType::MODULE->value,
                    'name'           => $planItem->name,
                    'quantity'       => 1,
                    'unit_price'     => $planItem->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                ]);
            }

            // 6. Asignar límites
            $limitPlanItems = PlanItem::where('type', PlanItemType::LIMIT)->get()->keyBy('key');

            foreach ($data['limits'] as $key => $quantity) {
                $planItem = $limitPlanItems->get($key);
                if (! $planItem) {
                    continue;
                }

                $version->items()->create([
                    'item_key'       => $key,
                    'item_type'      => PlanItemType::LIMIT->value,
                    'name'           => $planItem->name,
                    'quantity'       => (int) $quantity,
                    'unit_price'     => $planItem->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                ]);
            }

            return $subscription;
        });
    }
}