<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->text('terms_policy')->nullable()->after('delivery_policy')->comment('Returns, terms and conditions policy');
            $table->string('theme_mode', 5)->nullable()->default('light')->after('secondary_color')->comment('Store theme: light or dark');
        });
    }

    public function down(): void
    {
        Schema::table('store_configs', function (Blueprint $table) {
            $table->dropColumn(['terms_policy', 'theme_mode']);
        });
    }
};
