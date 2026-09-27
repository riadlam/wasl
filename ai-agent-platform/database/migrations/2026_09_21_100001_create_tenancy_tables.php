<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('currency')->default('DZD');
            $table->string('timezone')->default('Africa/Algiers');
            $table->string('wilaya')->nullable();
            $table->string('city')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('business_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->json('permissions')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('simulator');
            $table->string('socialapi_account_id')->nullable();
            $table->string('socialapi_brand_id')->nullable();
            $table->string('platform')->default('simulator');
            $table->string('platform_account_id')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('status')->default('connected');
            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('business_users');
        Schema::dropIfExists('businesses');
    }
};
