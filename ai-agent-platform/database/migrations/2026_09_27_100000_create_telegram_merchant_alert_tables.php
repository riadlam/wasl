<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('telegram_chat_id')->nullable()->index();
            $table->string('telegram_username')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_new_orders')->default(true);
            $table->boolean('notify_ai_needs_human')->default(true);
            $table->boolean('notify_post_events')->default(true);
            $table->timestamps();
        });

        Schema::create('telegram_outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 64);
            $table->string('chat_id');
            $table->string('message_id');
            $table->text('last_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'kind'], 'telegram_outbound_subject_kind_unique');
            $table->index(['business_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbound_messages');
        Schema::dropIfExists('business_telegram_settings');
    }
};
