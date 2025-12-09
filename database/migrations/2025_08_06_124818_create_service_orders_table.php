<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->onDelete('set null');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('customer_address')->nullable();
            $table->string('technician_name')->nullable();
            $table->string('technician_commission_type')->nullable(); // 'percentage', 'fixed'
            $table->decimal('technician_commission_value', 10, 2)->nullable();
            $table->string('status');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->string('item_description');
            $table->text('reported_problems');
            $table->text('technician_diagnosis')->nullable();
            $table->decimal('final_total', 10, 2)->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
