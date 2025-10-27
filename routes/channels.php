<?php

use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * NUEVO: Autoriza a un usuario a escuchar el canal de una sesión de caja.
 * Solo los usuarios que están actualmente en la sesión pueden escuchar.
 */
Broadcast::channel('cash-register-session.{session}', function ($user, CashRegisterSession $session) {
    // Comprueba si el ID del usuario existe en la tabla pivote de la sesión
    return $session->users()->where('user_id', $user->id)->exists();
});
