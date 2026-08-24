<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated platform / core tables:
     * permissions, roles, pivots, personal_access_tokens, media, activity_log,
     * settings, plan_items, onboarding_tours, waitlists + users 2FA/google auth columns.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        throw_if(empty($tableNames), new Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.'));

        // ─── Spatie permission tables (custom: roles.branch_id, roles unique by branch) ───
        Schema::create($tableNames['permissions'], static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('module');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['branch_id', 'name', 'guard_name']);
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');
            $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole) {
            $table->unsignedBigInteger($pivotRole);
            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');
            $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                'model_has_roles_role_model_type_primary');
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);
            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');
            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');
            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        // ─── Users 2FA + google auth (previously separate ALTER migrations) ───
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->string('google_id')->nullable()->after('id');
            $table->string('avatar')->nullable()->after('email');
        });

        // ─── Sanctum personal access tokens (custom: text name + expires_at index) ───
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->index('expires_at');
            $table->timestamps();
        });

        // ─── Spatie Media Library ───
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->timestamps();
            $table->index('order_column');
        });

        // ─── Activity log ───
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('event')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->char('batch_uuid', 36)->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->index(['causer_type', 'causer_id'], 'causer');
            $table->index('log_name', 'activity_log_log_name_index');
        });

        // ─── Setting definitions & values ───
        Schema::create('setting_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('module');
            $table->string('level')->default('branch');
            $table->string('type');
            $table->text('default_value')->nullable();
            $table->timestamps();
        });

        Schema::create('setting_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_definition_id')->constrained()->cascadeOnDelete();
            $table->string('configurable_type');
            $table->unsignedBigInteger('configurable_id');
            $table->text('value');
            $table->timestamps();
            $table->index(['configurable_type', 'configurable_id']);
        });

        // ─── Plan items ───
        Schema::create('plan_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Identificador único para el sistema (ej. module_pos, limit_users)');
            $table->string('type')->default('module');
            $table->string('name')->comment('Nombre legible para el usuario (ej. Punto de Venta)');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 8, 2)->default(0);
            $table->boolean('is_active')->default(true)->comment('Define si este ítem se puede contratar');
            $table->json('meta')->nullable()->comment('Propiedades adicionales (ej. icono para módulos, cantidad para límites)');
            $table->timestamps();
        });

        // ─── Onboarding tours ───
        Schema::create('onboarding_tours', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('module');
            $table->timestamps();
        });

        // ─── Waitlist ───
        Schema::create('waitlists', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        // ─── Seed: stamp large purchase threshold setting ───
        DB::table('setting_definitions')->insert([
            'key'           => 'stamp_large_purchase_threshold',
            'name'          => 'Umbral de revisión de compras grandes',
            'description'   => 'Cantidad de timbres a partir de la cual una compra por Mercado Pago requiere revisión manual del superadmin antes de aplicarse al PAC.',
            'module'        => 'billing',
            'level'         => 'platform',
            'type'          => 'integer',
            'default_value' => '1000',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        DB::table('setting_definitions')->where('key', 'stamp_large_purchase_threshold')->delete();

        Schema::dropIfExists('waitlists');
        Schema::dropIfExists('onboarding_tours');
        Schema::dropIfExists('plan_items');
        Schema::dropIfExists('setting_values');
        Schema::dropIfExists('setting_definitions');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('media');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'google_id',
                'avatar',
            ]);
        });

        Schema::dropIfExists('personal_access_tokens');
        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }
};