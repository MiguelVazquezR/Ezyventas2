<?php

use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;      // <-- Importante para depurar
use App\Actions\Fortify\CreateNewUser; // <-- ¡LA CLAVE ESTÁ AQUÍ!
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;              // <-- Para generar datos aleatorios
use Illuminate\Validation\ValidationException; // <-- Para manejar errores

// 1. Ruta para redirigir a Google
// Esta es la que tu botón de Vue llama ( route('auth.google') )
Route::get('/auth/google', function () {
    return Socialite::driver('google')->redirect();
})->name('auth.google');

// 2. Ruta de Callback (la que configuraste en Google)
Route::get('/auth/google/callback', function () {
    try {
        $googleUser = Socialite::driver('google')->user();

        // 1. Buscar si el usuario ya existe por su google_id
        $user = User::where('google_id', $googleUser->id)->first();

        if ($user) {
            // 2. Si existe, lo logueamos
            Auth::login($user);
            return redirect('/dashboard'); // Redirige a tu panel principal
        }

        // 3. Si no existe por google_id, buscar por email
        $user = User::where('email', $googleUser->email)->first();

        if ($user) {
            // 4. Si existe el email, vinculamos la cuenta (agregamos el google_id y avatar)
            $user->update([
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar
            ]);
            Auth::login($user);
            return redirect('/dashboard');
        }

        // 5. ¡NUEVA LÓGICA DE REGISTRO!
        // Si no existe de ninguna forma, creamos un nuevo usuario
        // USANDO LA ACCIÓN CreateNewUser PARA REUTILIZAR LA LÓGICA COMPLEJA.
        
        try {
            
            // --- INICIO DE LA CORRECCIÓN ---
            // Generamos un password aleatorio seguro y lo guardamos
            $generatedPassword = Hash::make($googleUser->email);

            // Preparamos el array de 'input' que CreateNewUser espera
            $input = [
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                // Asignamos un nombre de negocio por defecto.
                // Añadimos 4 caracteres aleatorios para evitar el error 'unique'
                'business_name' => "Negocio de " . $googleUser->name . ' ' . Str::random(4), 
                'password' => $generatedPassword, // <-- Usamos la variable
                'password_confirmation' => $generatedPassword, // <-- ¡AQUÍ ESTÁ LA SOLUCIÓN!
                // Aceptamos los términos automáticamente al usar un login social
                'terms' => true, 
            ];
            // --- FIN DE LA CORRECCIÓN ---
            
            // Instanciamos y llamamos a la acción de crear usuario
            $creator = new CreateNewUser();
            
            // create() ejecutará TODA la lógica de CreateNewUser.php
            // (crear Suscripción, Sucursal, Usuario, Plan, etc.)
            $newUser = $creator->create($input);

            // Ahora, actualizamos al usuario recién creado con su Google ID y Avatar
            // (ya que CreateNewUser no sabe nada de Google)
            $newUser->update([
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);

            // Logueamos al nuevo usuario
            Auth::login($newUser);
            // Idealmente, redirigir al onboarding si lo tienes
            return redirect()->route('onboarding.setup');


        } catch (ValidationException $e) {
            // Captura errores de validación (ej. email ya existe)
            Log::error('Error de VALIDACIÓN en Google Callback: ' . $e->getMessage(), $e->errors());
            return redirect('/login')->withErrors($e->errors());

        } catch (\Exception $e) {
            // Captura cualquier otro error (ej. Falla en la BD al crear suscripción)
            Log::error('Error al CREAR usuario con Google: Ubicación: ' . $e->getFile() . ' Línea: ' . $e->getLine() . ' Mensaje: ' . $e->getMessage());
            return redirect('/login')->withErrors(['email' => 'No se pudo crear tu cuenta con Google. Intenta manualmente.']);
        }
        

    } catch (\Exception $e) {
        // Captura error de Socialite (ej. token inválido, el usuario canceló)
        Log::error('Error en Google SOCIALITE: ' . $e->getMessage());
        return redirect('/login')->withErrors(['email' => 'Error al iniciar sesión con Google.']);
    }
});