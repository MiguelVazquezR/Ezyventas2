<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('set null');

            // --- Line description ---
            $table->string('description');
            $table->decimal('quantity', 12, 4);
            $table->string('sat_unit_code', 10)->nullable();        // e.g. "H87" — pieza, "E48" — servicio
            $table->decimal('unit_price', 12, 4);

            // --- Amounts ---
            $table->decimal('subtotal', 12, 2)->default(0);        // quantity × unit_price − discount
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);      // e.g. 16 % IVA on subtotal
            $table->decimal('total', 12, 2)->default(0);            // subtotal − discount + tax_amount

            // --- SAT catalog references ---
            $table->string('sat_product_code', 15)->nullable();     // ClaveProdServ
            $table->string('tax_type', 5)->nullable();              // e.g. "002" IVA, "001" ISR
            $table->decimal('tax_rate', 6, 4)->nullable();          // e.g. 0.1600

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
