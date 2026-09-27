<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_users', function (Blueprint $table) {
            $table->json('channel_ids')->nullable()->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('business_users', function (Blueprint $table) {
            $table->dropColumn('channel_ids');
        });
    }
};
