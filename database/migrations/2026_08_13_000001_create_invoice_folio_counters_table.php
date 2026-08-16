<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folio counters per (branch, series).
     *
     * Fixes the race condition in the old generateFolio() (last id + 1
     * without any lock): two concurrent creations in the same branch could
     * compute the same folio. The counter is dedicated to folios and is
     * isolated from any other use of branches.
     */
    public function up(): void
    {
        Schema::create('invoice_folio_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('series')->nullable();
            $table->unsignedInteger('next_folio')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'series']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_folio_counters');
    }
};
