<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->string('llm_model', 40)->default('claude_sonnet')->after('image_model');
            $table->boolean('ai_auto_publish_posts')->default(false)->after('llm_model');
            $table->boolean('ai_auto_reply_comments')->default(false)->after('ai_auto_publish_posts');
            $table->boolean('ai_auto_send_dms')->default(false)->after('ai_auto_reply_comments');
            $table->boolean('ai_auto_reply_reviews')->default(false)->after('ai_auto_send_dms');
            $table->boolean('ai_auto_moderate')->default(false)->after('ai_auto_reply_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn([
                'llm_model',
                'ai_auto_publish_posts',
                'ai_auto_reply_comments',
                'ai_auto_send_dms',
                'ai_auto_reply_reviews',
                'ai_auto_moderate',
            ]);
        });
    }
};
