<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_post_id');
            $table->boolean('ai_comment_reply')->default(false);
            $table->boolean('ai_private_reply')->default(false);
            $table->timestamps();

            $table->unique(
                ['business_id', 'social_account_id', 'platform_post_id'],
                'post_ai_settings_biz_account_post_unique',
            );
            $table->index('platform_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_ai_settings');
    }
};
