<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16); // user|assistant|system
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'id']);
        });

        Schema::create('agent_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->text('summary')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_pending_actions');
        Schema::dropIfExists('agent_chat_messages');
    }
};
