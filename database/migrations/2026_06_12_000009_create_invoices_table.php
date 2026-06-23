<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');

            // --- CFDI identification ---
            $table->string('series', 10)->nullable();               // e.g. "A", "F"
            $table->string('folio', 20);                            // Consecutive folio within the series
            $table->string('status')->default(\App\Enums\InvoiceStatus::DRAFT->value);

            // --- SAT identifiers (populated after certification) ---
            $table->string('uuid', 36)->nullable()->unique();       // SAT folio fiscal (UUID)
            $table->string('xml_url')->nullable();
            $table->string('pdf_url')->nullable();

            // --- Dates ---
            $table->timestamp('issued_at')->nullable();             // When the CFD was issued to SAT
            $table->timestamp('canceled_at')->nullable();

            // --- Receiver (receptor) data ---
            $table->string('receiver_rfc', 13);
            $table->string('receiver_legal_name');
            $table->string('receiver_tax_regime', 10)->nullable();
            $table->string('receiver_postal_code', 5);
            $table->string('cfdi_use', 10);                         // e.g. "G03" — Gastos en general

            // --- Payment form & method ---
            $table->string('payment_form', 5)->nullable();          // e.g. "01" Efectivo, "03" Transferencia
            $table->string('payment_method', 5)->nullable();        // "PUE" single payment, "PPD" installments
            $table->string('currency', 5)->default('MXN');

            // --- Amounts (SAT breakdown) ---
            $table->decimal('subtotal', 12, 2)->default(0);        // Sum before taxes
            $table->decimal('discount_total', 12, 2)->default(0);  // Total discounts applied
            $table->decimal('taxes_total', 12, 2)->default(0);     // Sum of all transferred taxes (IVA, IEPS, etc.)
            $table->decimal('total', 12, 2)->default(0);            // subtotal - discount_total + taxes_total

            // --- Cancellation ---
            $table->string('cancellation_reason')->nullable();      // SAT cancellation motivo

            $table->timestamps();

            // Indexes
            $table->index(['branch_id', 'series', 'folio']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
