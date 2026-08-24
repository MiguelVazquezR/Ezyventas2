<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated CFDI invoicing tables (final schema including
     * previously-pending August 2026 migrations):
     * fiscal_profiles, pac_accounts, invoices, invoice_items,
     * invoice_folio_counters, invoice stemping (stamp_pricing_tiers,
     * stamp_purchases, stamp_movements, stamp_reservations,
     * stamp_global_stats_snapshots, pac_call_logs), accept_reject_responses.
     */
    public function up(): void
    {
        // ─── PAC accounts (SW Sapien; shared "normal" accounts) ───
        Schema::create('pac_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_shared')->default(false)->comment('Cuenta normal compartida por varios suscriptores (pool común de timbres)');
            $table->string('provider')->default('sw_sapien');
            $table->enum('account_type', ['subaccount', 'shared']);
            $table->string('sw_user_id')->nullable()->comment('idUser que regresa el PAC, si lo tenemos');
            $table->string('login_email')->nullable()->comment('Credencial de login (subaccount: la generamos; shared: la da Conectia)');
            $table->string('password')->nullable()->comment('Cifrada, igual que fiscal_profiles.password');
            $table->enum('status', ['pending_request', 'pending_activation', 'active', 'inactive'])
                ->default('pending_request');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('admin_notes')->nullable()->comment('Bitácora de coordinación con el revendedor');
            $table->timestamps();

            $table->index('subscription_id');
            $table->index(['status', 'account_type']);
        });

        // ─── Fiscal profiles (emitter CSD + SW Sapien subaccount + manifest) ───
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pac_account_id')->nullable()->constrained('pac_accounts')->nullOnDelete();
            $table->string('rfc', 13)->comment('RFC del emisor para este perfil fiscal');
            $table->string('razon_social')->comment('Razón social o nombre fiscal');
            $table->string('regimen_fiscal', 10)->comment('Clave del régimen fiscal del SAT, e.g. "626"');
            $table->string('email')->nullable()->comment('Email de contacto para la subcuenta en SW Sapien');
            $table->string('password')->nullable()->comment('Contraseña autogenerada para la subcuenta del PAC');
            $table->string('postal_code', 5)->nullable()->comment('Código postal de expedición para CFDI');
            $table->string('sw_user_id')->nullable()->comment('Identificador del sub-usuario en SW Sapien');
            $table->string('certificate_number', 20)->nullable()->comment('Número de serie del certificado SAT (20 dígitos)');
            $table->timestamp('valid_from')->nullable()->comment('Fecha de inicio de vigencia del CSD');
            $table->timestamp('valid_to')->nullable()->comment('Fecha de vencimiento del CSD');
            $table->string('cer_file_path')->nullable()->comment('Ruta relativa al archivo .cer en storage');
            $table->string('key_file_path')->nullable()->comment('Ruta relativa al archivo .key en storage');
            $table->timestamp('manifest_signed_at')->nullable();
            $table->string('manifest_pdf_path')->nullable();
            $table->string('manifest_sent_to_email')->nullable();
            $table->string('manifest_last_attempt_error')->nullable();
            $table->text('manifest_text_b64')->nullable();
            $table->timestamp('manifest_text_shown_at')->nullable();
            $table->timestamp('manifest_text_accepted_at')->nullable();
            $table->string('sw_account_email')->nullable()->comment('Correo asignado a la subcuenta en el PAC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subscription_id', 'rfc'], 'fiscal_profiles_subscription_rfc_unique');
            $table->index('pac_account_id');
        });

        // ─── Invoice folio counters (race-condition safe) ───
        Schema::create('invoice_folio_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('series')->nullable();
            $table->unsignedInteger('next_folio')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'series']);
        });

        // ─── Invoices (CFDI 4.0 final schema) ───
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->boolean('prices_include_iva')->default(false);
            $table->string('series', 10)->nullable();
            $table->string('folio', 20);
            $table->string('status')->default('borrador');
            $table->boolean('requires_manual_review')->default(false);
            $table->string('uuid', 36)->nullable();
            $table->string('xml_url')->nullable();
            $table->string('pdf_url')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('fecha_timbrado')->nullable();
            $table->text('sello_cfdi')->nullable();
            $table->text('sello_sat')->nullable();
            $table->string('no_certificado_sat')->nullable();
            $table->string('rfc_prov_certif', 13)->nullable();
            $table->text('cadena_original_sat')->nullable();
            $table->longText('qr_code_base64')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('cancelation_requires_acceptance')->default(false);
            $table->string('cancelation_status')->nullable();
            $table->timestamp('cancelation_requested_at')->nullable();
            $table->timestamp('cancelation_last_checked_at')->nullable();
            $table->string('receiver_rfc', 13);
            $table->string('receiver_legal_name');
            $table->string('receiver_tax_regime', 10)->nullable();
            $table->string('receiver_postal_code', 5);
            $table->string('cfdi_use', 10);
            $table->string('exportacion', 5)->nullable()->default('01');
            $table->string('tipo_comprobante', 5)->nullable()->default('I');
            $table->timestamp('pago_fecha')->nullable();
            $table->string('pago_forma', 5)->nullable();
            $table->string('pago_moneda', 5)->nullable()->default('MXN');
            $table->decimal('pago_monto', 12, 2)->nullable();
            $table->decimal('pago_tipo_cambio', 8, 6)->nullable();
            $table->json('pago_documentos')->nullable();
            $table->string('tipo_relacion', 5)->nullable();
            $table->json('cfdi_relacionados')->nullable();
            $table->string('payment_form', 5)->nullable();
            $table->string('payment_method', 5)->nullable();
            $table->string('currency', 5)->default('MXN');
            $table->decimal('exchange_rate', 10, 6)->nullable()->comment('TipoCambio — required by SAT Anexo 20 when Moneda ≠ MXN');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('taxes_total', 12, 2)->default(0);
            $table->decimal('retained_taxes_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique('uuid');
            $table->index(['branch_id', 'series', 'folio'], 'invoices_branch_id_series_folio_index');
            $table->unique(['branch_id', 'series', 'folio']);
            $table->index('status');
            $table->index('fiscal_profile_id');
        });

        // ─── Invoice items ───
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->string('sat_unit_code', 10)->nullable();
            $table->string('unit_name', 50)->nullable()->comment('Commercial unit name, e.g. "Pieza", "Servicio"');
            $table->decimal('unit_price', 12, 4);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('sat_product_code', 15)->nullable();
            $table->string('no_identificacion', 100)->nullable()->comment('SKU or internal product identifier');
            $table->string('objeto_imp', 5)->nullable()->default('02');
            $table->string('tax_type', 5)->nullable();
            $table->decimal('tax_rate', 6, 4)->nullable();
            $table->string('retained_tax_type', 5)->nullable();
            $table->decimal('retained_tax_rate', 6, 6)->nullable();
            $table->decimal('retained_tax_amount', 12, 2)->default(0);
            $table->json('retentions')->nullable()->comment('Array of {type, rate, amount} objects for CFDI Retenciones node');
            $table->timestamps();
        });

        // ─── Stamp pricing tiers ───
        Schema::create('stamp_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity')->nullable();
            $table->decimal('unit_price', 10, 4);
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ─── Stamp purchases ───
        Schema::create('stamp_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->unsignedInteger('stamp_quantity');
            $table->decimal('unit_price', 10, 4);
            $table->decimal('amount_total', 10, 2);
            $table->foreignId('pricing_tier_id')->nullable()->constrained('stamp_pricing_tiers')->nullOnDelete();
            $table->string('payment_method');
            $table->string('status');
            $table->string('review_reason')->nullable();
            $table->string('mp_payment_id')->nullable();
            $table->string('mp_preference_id')->nullable();
            $table->string('proof_file_path')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->json('pac_stamps_response_raw')->nullable();
            $table->timestamp('stamps_applied_at')->nullable();
            $table->string('admin_note')->nullable();
            $table->string('adjustment_type')->nullable();
            $table->timestamps();

            $table->index('fiscal_profile_id');
            $table->index('status');
            $table->index('payment_method');
            $table->index('review_reason');
        });

        // ─── Stamp reserves (idempotency + shared pool protection) ───
        Schema::create('stamp_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('reference');
            $table->string('customid', 100)->unique();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('last_pac_response')->nullable();
            $table->timestamps();

            $table->index(['fiscal_profile_id', 'status']);
        });

        // ─── PAC call logs (audit trail; no passwords/CSD binaries) ───
        Schema::create('pac_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pac_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation');
            $table->string('customid')->nullable();
            $table->json('request_payload')->nullable();
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['fiscal_profile_id', 'created_at']);
        });

        // ─── Stamp movements (wallet ledger per fiscal profile) ───
        Schema::create('stamp_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('description');
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('fiscal_profile_id');
            $table->index('created_at');
        });

        // ─── Stamp global stats snapshots ───
        Schema::create('stamp_global_stats_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('total_stamps_assigned')->default(0);
            $table->unsignedInteger('total_stamps_used')->default(0);
            $table->unsignedInteger('active_issuers_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });

        // ─── SAT accept/reject responses ───
        Schema::create('accept_reject_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->nullable()->constrained('fiscal_profiles')->nullOnDelete();
            $table->string('rfc', 13);
            $table->uuid('uuid');
            $table->string('action', 20);
            $table->string('status', 20)->default('success');
            $table->text('acuse')->nullable();
            $table->string('estatus_uuid', 20)->nullable();
            $table->string('respuesta', 20)->nullable();
            $table->string('message')->nullable();
            $table->text('message_detail')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accept_reject_responses');
        Schema::dropIfExists('stamp_global_stats_snapshots');
        Schema::dropIfExists('stamp_movements');
        Schema::dropIfExists('pac_call_logs');
        Schema::dropIfExists('stamp_reservations');
        Schema::dropIfExists('stamp_purchases');
        Schema::dropIfExists('stamp_pricing_tiers');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_folio_counters');
        Schema::dropIfExists('pac_accounts');
        Schema::dropIfExists('fiscal_profiles');
    }
};