<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->text('manifest_text_b64')->nullable()->after('manifest_last_attempt_error');
            $table->timestamp('manifest_text_shown_at')->nullable()->after('manifest_text_b64');
            $table->timestamp('manifest_text_accepted_at')->nullable()->after('manifest_text_shown_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'manifest_text_b64',
                'manifest_text_shown_at',
                'manifest_text_accepted_at',
            ]);
        });
    }
};
