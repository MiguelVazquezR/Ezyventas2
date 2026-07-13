<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setting_definitions', function (Blueprint $table) {
            $table->text('default_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('setting_definitions', function (Blueprint $table) {
            $table->string('default_value')->nullable()->change();
        });
    }
};
