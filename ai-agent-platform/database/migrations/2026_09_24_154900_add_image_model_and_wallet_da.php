<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->string('image_model', 40)->default('gpt_image_2')->after('max_actions_per_hour');
        });

        // Old unit: 1 token = $0.10 = 25 DA. Convert balances and mark currency DZD.
        DB::table('users')
            ->where(function ($q) {
                $q->whereNull('wallet_currency')
                    ->orWhere('wallet_currency', 'USD')
                    ->orWhere('wallet_currency', '');
            })
            ->update([
                'wallet_tokens' => DB::raw('ROUND(wallet_tokens * 25, 2)'),
                'wallet_currency' => 'DZD',
            ]);
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn('image_model');
        });

        DB::table('users')
            ->where('wallet_currency', 'DZD')
            ->update([
                'wallet_tokens' => DB::raw('ROUND(wallet_tokens / 25, 2)'),
                'wallet_currency' => 'USD',
            ]);
    }
};
