<?php

namespace App\Actions\Fortify;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly SubscriptionPlanService $planService,
    ) {
    }

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

            // 4. Activate the free plan: open-ended version, fixed limits and
            //    essential modules declared in config/subscription.php.
            $this->planService->initializeFreePlan($subscription);


            return $user;
        });
    }
}
