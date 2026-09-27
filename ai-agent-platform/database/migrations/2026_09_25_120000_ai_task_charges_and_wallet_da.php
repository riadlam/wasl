<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('wallet_tokens', 'wallet_balance_da');
        });

        DB::table('users')->where(function ($q) {
            $q->whereNull('wallet_currency')->orWhere('wallet_currency', '!=', 'DZD');
        })->update(['wallet_currency' => 'DZD']);

        Schema::table('wallet_ledger', function (Blueprint $table) {
            $table->renameColumn('tokens', 'amount_da');
        });

        Schema::table('agent_image_jobs', function (Blueprint $table) {
            $table->decimal('cost_usd', 12, 6)->nullable()->after('price_da');
            $table->unsignedInteger('cost_da')->nullable()->after('cost_usd');
        });

        Schema::create('ai_task_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_type', 32);
            $table->string('model_key', 64);
            $table->string('provider_model', 160)->nullable();
            $table->decimal('cost_usd', 12, 6);
            $table->decimal('cost_da', 12, 2);
            $table->unsignedInteger('usd_to_da');
            $table->string('status', 24)->default('charged');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('wallet_ledger_id')->nullable()->constrained('wallet_ledger')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index('wallet_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_charges');

        Schema::table('agent_image_jobs', function (Blueprint $table) {
            $table->dropColumn(['cost_usd', 'cost_da']);
        });

        Schema::table('wallet_ledger', function (Blueprint $table) {
            $table->renameColumn('amount_da', 'tokens');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('wallet_balance_da', 'wallet_tokens');
        });
    }
};
