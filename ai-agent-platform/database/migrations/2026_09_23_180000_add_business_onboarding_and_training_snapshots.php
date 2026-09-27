<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('onboarding_status')->default('onboarding')->after('status');
            $table->json('onboarding_meta')->nullable()->after('onboarding_status');
        });

        Schema::create('business_training_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source'); // page|post|comment|dm
            $table->string('external_id')->nullable();
            $table->string('platform')->nullable();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'source']);
            $table->index(['business_id', 'external_id']);
        });

        // Existing shops: phaseone if they already have a real connected channel.
        $idsWithChannel = DB::table('social_accounts')
            ->where('platform', '!=', 'simulator')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
            })
            ->distinct()
            ->pluck('business_id');

        if ($idsWithChannel->isNotEmpty()) {
            DB::table('businesses')
                ->whereIn('id', $idsWithChannel)
                ->update(['onboarding_status' => 'onboarding_phaseone']);
        }

        DB::table('businesses')
            ->whereNotIn('id', $idsWithChannel->all() ?: [0])
            ->update(['onboarding_status' => 'onboarding']);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_training_snapshots');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['onboarding_status', 'onboarding_meta']);
        });
    }
};
