<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('kind', 32)->default('lead')->after('template_key');
            $table->index(['business_id', 'kind', 'status'], 'workflows_biz_kind_status_idx');
            $table->index(['business_id', 'template_key'], 'workflows_biz_template_idx');
        });

        // MySQL may refuse dropping the unique index if it backs another constraint;
        // drop by name after a covering non-unique index exists.
        try {
            Schema::table('workflows', function (Blueprint $table) {
                $table->dropUnique(['business_id', 'template_key']);
            });
        } catch (\Throwable) {
            DB::statement('ALTER TABLE workflows DROP INDEX workflows_business_id_template_key_unique');
        }

        Schema::create('workflow_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_post_id');
            $table->timestamps();

            $table->unique(
                ['workflow_id', 'social_account_id', 'platform_post_id'],
                'workflow_posts_wf_account_post_unique',
            );
            $table->index(
                ['business_id', 'social_account_id', 'platform_post_id'],
                'workflow_posts_biz_account_post_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_posts');

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex('workflows_biz_kind_status_idx');
            $table->dropIndex('workflows_biz_template_idx');
            $table->dropColumn('kind');
            $table->unique(['business_id', 'template_key']);
        });
    }
};
