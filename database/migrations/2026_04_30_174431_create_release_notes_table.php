<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla principal de novedades
        Schema::create('release_notes', function (Blueprint $table) {
            $table->id();
            $table->string('version')->nullable()->comment('Ej. v1.2.0');
            $table->string('title');
            $table->text('excerpt')->nullable()->comment('Breve descripción para listas');
            $table->longText('content')->comment('Contenido HTML textual (Media gestionada por Spatie MediaLibrary)');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Tabla pivote para registrar lecturas por usuario
        Schema::create('release_note_user', function (Blueprint $table) {
            $table->foreignId('release_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            
            // Llave primaria compuesta para evitar registros duplicados
            $table->primary(['release_note_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_note_user');
        Schema::dropIfExists('release_notes');
    }
};