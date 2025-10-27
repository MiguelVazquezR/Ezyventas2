<?php

namespace App\Actions\Fortify;

use App\Enums\BillingPeriod;
use App\Enums\PlanItemType;
use App\Mail\WelcomeEmail;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user and their subscription.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'business_name' => ['required', 'string', 'max:255', 'unique:subscriptions'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature() ? ['accepted', 'required'] : '',
        ], [
            'business_name.unique' => 'Este nombre de negocio ya ha sido registrado.',
            'email.unique' => 'Este correo electrónico ya ha sido registrado.',
        ])->validate();

        // Usamos una transacción para asegurar que todas las operaciones se completen con éxito.
        return DB::transaction(function () use ($input) {
            
            // 1. Crear la Suscripción (el negocio)
            $subscription = Subscription::create([
                'business_name' => $input['business_name'],
                'commercial_name' => $input['business_name'],
                'contact_email' => $input['email'],
                'slug' => Str::slug($input['business_name']),
                'status' => 'activo', // Asumiendo 'activo' como string. Usa un Enum si lo tienes.
            ]);

            // 2. Crear la Sucursal principal para la nueva suscripción
            $branch = $subscription->branches()->create([
                'name' => 'Principal',
                'is_main' => true,
                'timezone' => 'America/Mexico_City', // Default timezone
            ]);

            // 3. Crear el Usuario administrador y asociarlo a la sucursal
            // ¡ESTA ES LA CORRECCIÓN IMPORTANTE!
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'branch_id' => $branch->id, // Asociamos el usuario a su sucursal
            ]);

            // 4. Crear la versión inicial de la suscripción (Plan de Bienvenida / Prueba)
            $version = $subscription->versions()->create([
                'start_date' => now(),
                'end_date' => now()->addDays(30), // <-- 1. CAMBIADO a 30 días
            ]);

            // 5. Obtener y asignar los items del plan inicial
            $planItems = $this->getInitialPlanItems();
            
            $itemsData = $planItems->map(function ($item) {
                return [
                    'item_key' => $item->key,
                    'item_type' => $item->type,
                    'name' => $item->name,
                    // Si es un módulo, cantidad es 1. Si es límite, usa la cantidad de 'meta'.
                    'quantity' => $item->type == PlanItemType::MODULE ? 1 : ($item->meta['quantity'] ?? 1),
                    'unit_price' => $item->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY,
                ];
            });

            $version->items()->createMany($itemsData->toArray());

            // 7. Enviar email de bienvenida
            try {
                Mail::to($user->email)->send(new WelcomeEmail($user));
            } catch (\Exception $e) {
                // Si el email falla (ej. Mailgun no configurado), no revertir la transacción.
                // Solo registrar el error.
                \Illuminate\Support\Facades\Log::error("Error al enviar email de bienvenida: " . $e->getMessage());
            }

            return $user;
        });
    }

    /**
     * Obtiene los PlanItems que se asignarán a un nuevo suscriptor.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getInitialPlanItems()
    {
        $defaultModuleKeys = [
            'module_pos', 'module_financial_reports', 'module_transactions', 
            'module_products', 'module_expenses', 'module_customers', 
            'module_services', 'module_cash_registers', 'module_settings'
        ];
        
        $defaultLimitKeys = [
            'limit_branches', 'limit_users', 'limit_products', 
            'limit_cash_registers', 'limit_print_templates'
        ];
        
        $allKeys = array_merge($defaultModuleKeys, $defaultLimitKeys);

        // Busca en la BD los items que coincidan con las claves
        return PlanItem::whereIn('key', $allKeys)->get();
    }
}