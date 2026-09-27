<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('content_mode', 32);
            $table->text('focus_prompt')->nullable();
            $table->date('starts_on');
            $table->unsignedTinyInteger('day_count');
            $table->string('timezone', 64)->default('Africa/Algiers');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('ai_campaign_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ai_campaign_id', 'social_account_id']);
        });

        Schema::create('ai_campaign_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_campaign_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_index');
            $table->string('slot_kind', 16);
            $table->timestamp('scheduled_at');
            $table->string('status', 24)->default('pending');
            $table->text('caption')->nullable();
            $table->string('socialapi_post_id')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('agent_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['ai_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_slots');
        Schema::dropIfExists('ai_campaign_assets');
        Schema::dropIfExists('ai_campaign_channels');
        Schema::dropIfExists('ai_campaigns');
    }
};
