<?php

namespace App\Actions\Fortify;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;
use Spatie\Permission\Models\Role;

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
        ])->validate();

        // Usamos una transacción para asegurar que todas las operaciones se completen con éxito.
        // Si algo falla, se revierte todo para mantener la consistencia de los datos.
        return DB::transaction(function () use ($input) {
            // 1. Crear la Suscripción (el negocio)
            $subscription = Subscription::create([
                'business_name' => $input['business_name'],
                'commercial_name' => $input['business_name'], // Inicialmente puede ser el mismo
                'contact_email' => $input['email'],
                'slug' => Str::slug($input['business_name']),
                // En tu DB Diagrama vi 'activo'. Si tienes un Enum, es mejor usarlo.
                // Ejemplo: 'status' => \App\Enums\SubscriptionStatus::ACTIVE,
                'status' => 'activo',
            ]);

            // 2. Crear la Sucursal principal para la nueva suscripción
            // Asumo que el modelo Subscription tiene una relación hasMany 'branches'
            $subscription->branches()->create([
                'name' => 'Principal', // Nombre por defecto para la primera sucursal
                'is_main' => true,
            ]);

            // 3. Crear el Usuario administrador y asociarlo a la suscripción
            // Asumo que el modelo Subscription tiene una relación hasMany 'users'
            $user = $subscription->users()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            // 4. Asignar un rol de 'Admin' o 'Propietario' al nuevo usuario.
            // Esto asume que tienes configurado spatie/laravel-permissions.
            // El rol se crea si no existe.
            $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
            $user->assignRole($role);

            return $user;
        });
    }
}