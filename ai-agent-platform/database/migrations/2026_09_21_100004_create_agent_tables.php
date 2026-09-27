<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('system_prompt')->nullable();
            $table->string('language')->default('Darija');
            $table->string('tone')->nullable();
            $table->boolean('ai_enabled')->default(true);
            $table->boolean('auto_reply_comments')->default(false);
            $table->boolean('auto_reply_dms')->default(true);
            $table->boolean('auto_reply_whatsapp')->default(true);
            $table->boolean('auto_publish')->default(false);
            $table->boolean('human_approval_required')->default(false);
            $table->timestamps();
        });

        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('language')->default('Darija');
            $table->string('tone')->nullable();
            $table->string('response_length')->default('medium');
            $table->boolean('auto_reply_comments')->default(false);
            $table->boolean('auto_reply_dms')->default(true);
            $table->boolean('auto_reply_reviews')->default(false);
            $table->boolean('allow_order_creation')->default(true);
            $table->boolean('allow_refunds')->default(false);
            $table->boolean('require_human_approval')->default(false);
            $table->unsignedInteger('max_actions_per_hour')->default(60);
            $table->timestamps();
        });

        Schema::create('agent_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('keyword');
            $table->json('condition');
            $table->json('action');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('running');
            $table->string('model')->nullable();
            $table->string('provider')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->string('tool');
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->default('ok');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_rules');
        Schema::dropIfExists('agent_settings');
        Schema::dropIfExists('agents');
    }
};
