<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_tokens', 12, 2)->default(0)->after('platform_role');
            $table->string('wallet_currency', 8)->default('DZD')->after('wallet_tokens');
        });

        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 16); // debit|credit
            $table->decimal('tokens', 12, 2);
            $table->decimal('usd_amount', 12, 4)->nullable();
            $table->string('reason', 64);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('meta')->nullable();
            $table->decimal('balance_after', 12, 2);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wallet_tokens', 'wallet_currency']);
        });
    }
};
