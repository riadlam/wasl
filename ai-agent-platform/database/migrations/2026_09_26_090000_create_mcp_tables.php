<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('surface', 20);
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('agent_run_id')->nullable();
            $table->string('tool', 120);
            $table->json('arguments')->nullable();
            $table->string('status', 20)->default('ok');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
        Schema::dropIfExists('mcp_tokens');
    }
};
