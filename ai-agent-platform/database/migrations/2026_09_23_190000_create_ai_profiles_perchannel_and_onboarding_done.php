<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_profiles_perchannel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform')->nullable();
            $table->json('profile');
            $table->string('model')->nullable();
            $table->json('source_counts')->nullable();
            $table->string('status')->default('ready');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'social_account_id']);
            $table->index(['social_account_id', 'status']);
        });

        // Existing shops that already finished corpus training unlock without regenerating.
        DB::table('businesses')
            ->where('onboarding_status', 'onboarding_phaseone')
            ->update(['onboarding_status' => 'onboarding_done']);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_profiles_perchannel');
    }
};
