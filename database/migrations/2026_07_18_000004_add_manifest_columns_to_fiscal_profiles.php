<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->timestamp('manifest_signed_at')->nullable()->after('key_file_path');
            $table->string('manifest_pdf_path')->nullable()->after('manifest_signed_at');
            $table->string('manifest_sent_to_email')->nullable()->after('manifest_pdf_path');
            $table->string('manifest_last_attempt_error')->nullable()->after('manifest_sent_to_email');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'manifest_signed_at',
                'manifest_pdf_path',
                'manifest_sent_to_email',
                'manifest_last_attempt_error',
            ]);
        });
    }
};
