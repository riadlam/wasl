<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->string('delivery_type')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn('delivery_type');
        });
    }
};
