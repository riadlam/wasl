<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'last_message_at']);
        });

        Schema::table('agent_chat_messages', function (Blueprint $table) {
            $table->foreignId('agent_chat_id')
                ->nullable()
                ->after('business_id')
                ->constrained('agent_chats')
                ->cascadeOnDelete();
            $table->index(['agent_chat_id', 'id']);
        });

        // Backfill: one chat per business that already has messages.
        $businessIds = DB::table('agent_chat_messages')
            ->distinct()
            ->pluck('business_id');

        foreach ($businessIds as $businessId) {
            $first = DB::table('agent_chat_messages')
                ->where('business_id', $businessId)
                ->orderBy('id')
                ->first();
            $last = DB::table('agent_chat_messages')
                ->where('business_id', $businessId)
                ->orderByDesc('id')
                ->first();

            $title = 'Previous chat';
            if ($first && is_string($first->content ?? null) && trim($first->content) !== '') {
                $title = mb_substr(trim($first->content), 0, 60);
            }

            $chatId = DB::table('agent_chats')->insertGetId([
                'business_id' => $businessId,
                'user_id' => $first->user_id ?? null,
                'title' => $title,
                'last_message_at' => $last->created_at ?? now(),
                'created_at' => $first->created_at ?? now(),
                'updated_at' => $last->updated_at ?? now(),
            ]);

            DB::table('agent_chat_messages')
                ->where('business_id', $businessId)
                ->whereNull('agent_chat_id')
                ->update(['agent_chat_id' => $chatId]);
        }
    }

    public function down(): void
    {
        Schema::table('agent_chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_chat_id');
        });
        Schema::dropIfExists('agent_chats');
    }
};
