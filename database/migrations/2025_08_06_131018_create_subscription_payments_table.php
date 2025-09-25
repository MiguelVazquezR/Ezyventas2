<?php

use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_version_id')->constrained('subscription_versions')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
             $table->string('invoice_status')->default(InvoiceStatus::NOT_REQUESTED->value);
            $table->string('payment_method');
            $table->boolean('invoiced')->default(false);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};