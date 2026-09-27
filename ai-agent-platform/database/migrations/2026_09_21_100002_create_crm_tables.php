<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('wilaya')->nullable();
            $table->string('commune')->nullable();
            $table->string('language')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'phone']);
        });

        Schema::create('customer_social_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('platform_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('profile_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('socialapi_conversation_id')->nullable();
            $table->string('platform')->default('simulator');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('ai_enabled')->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('socialapi_message_id')->nullable();
            $table->string('direction');
            $table->string('type')->default('text');
            $table->string('sender_type')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('text')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->boolean('ai_generated')->default(false);
            $table->string('ai_model')->nullable();
            $table->string('status')->default('sent');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('customer_social_profiles');
        Schema::dropIfExists('customers');
    }
};
