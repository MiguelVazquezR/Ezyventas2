<?php

use App\Http\Controllers\Api\V1\Printing\PrintController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 — Printing and WhatsApp tickets
|--------------------------------------------------------------------------
|
| The app never renders templates: it asks for the encoded document (ESC/POS,
| TSPL or HTML) or for the WhatsApp payload of a sale, an order or a receipt.
|
*/

Route::get('/print/templates', [PrintController::class, 'templates'])
    ->name('print.templates');

Route::post('/print/bluetooth-payload', [PrintController::class, 'bluetoothPayload'])
    ->name('print.bluetooth-payload');

Route::post('/print/payload', [PrintController::class, 'payload'])
    ->name('print.payload');

Route::post('/print/ticket-html', [PrintController::class, 'ticketHtml'])
    ->name('print.ticket-html');

Route::post('/print/whatsapp-ticket', [PrintController::class, 'whatsappTicket'])
    ->name('print.whatsapp-ticket');
