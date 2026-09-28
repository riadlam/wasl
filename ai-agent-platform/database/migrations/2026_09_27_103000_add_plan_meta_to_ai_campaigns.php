<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_campaigns', 'plan_meta')) {
                $table->json('plan_meta')->nullable()->after('warnings');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('ai_campaigns', 'plan_meta')) {
                $table->dropColumn('plan_meta');
            }
        });
    }
};
