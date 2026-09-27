<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_ai_settings', function (Blueprint $table) {
            $table->string('comment_mode', 16)->default('agent')->after('ai_private_reply');
            $table->text('fixed_comment_text')->nullable()->after('comment_mode');
            $table->string('fixed_comment_image_path')->nullable()->after('fixed_comment_text');
            $table->string('dm_mode', 16)->default('agent')->after('fixed_comment_image_path');
            $table->text('fixed_dm_text')->nullable()->after('dm_mode');
        });
    }

    public function down(): void
    {
        Schema::table('post_ai_settings', function (Blueprint $table) {
            $table->dropColumn([
                'comment_mode',
                'fixed_comment_text',
                'fixed_comment_image_path',
                'dm_mode',
                'fixed_dm_text',
            ]);
        });
    }
};
