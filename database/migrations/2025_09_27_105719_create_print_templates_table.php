<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\TemplateType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->string('name')->comment('Nombre descriptivo, ej: "Ticket de Venta Estándar 80mm"');
            $table->string('type')->default(TemplateType::SALE_TICKET->value);
            $table->json('content')->comment('Almacena la estructura de operaciones ESC/POS en formato JSON');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};