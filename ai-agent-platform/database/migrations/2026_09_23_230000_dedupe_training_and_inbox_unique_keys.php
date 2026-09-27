<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prefer post-scoped comment keys: "{post_id}:{comment_id}".
        if (Schema::hasTable('business_training_snapshots')) {
            $comments = DB::table('business_training_snapshots')
                ->where('source', 'comment')
                ->whereNotNull('external_id')
                ->where('external_id', '!=', '')
                ->get(['id', 'external_id', 'payload']);

            foreach ($comments as $row) {
                $ext = (string) $row->external_id;
                if (str_contains($ext, ':')) {
                    continue;
                }
                $payload = is_string($row->payload)
                    ? json_decode($row->payload, true)
                    : (is_array($row->payload) ? $row->payload : null);
                $postId = is_array($payload) ? (string) ($payload['post_id'] ?? '') : '';
                if ($postId === '') {
                    continue;
                }
                DB::table('business_training_snapshots')
                    ->where('id', $row->id)
                    ->update(['external_id' => $postId.':'.$ext]);
            }

            $this->collapseDuplicates(
                'business_training_snapshots',
                ['business_id', 'source', 'external_id'],
            );
        }

        if (Schema::hasTable('conversations')) {
            $this->collapseConversationDuplicates();
        }

        if (Schema::hasTable('messages')) {
            $this->collapseDuplicates(
                'messages',
                ['conversation_id', 'socialapi_message_id'],
                requireNotNull: ['socialapi_message_id'],
            );
        }

        Schema::table('business_training_snapshots', function (Blueprint $table) {
            $table->unique(
                ['business_id', 'source', 'external_id'],
                'business_training_snapshots_biz_source_ext_unique',
            );
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(
                ['business_id', 'socialapi_conversation_id'],
                'conversations_biz_socialapi_conv_unique',
            );
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unique(
                ['conversation_id', 'socialapi_message_id'],
                'messages_conversation_socialapi_msg_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_conversation_socialapi_msg_unique');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique('conversations_biz_socialapi_conv_unique');
        });

        Schema::table('business_training_snapshots', function (Blueprint $table) {
            $table->dropUnique('business_training_snapshots_biz_source_ext_unique');
        });
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $requireNotNull
     */
    private function collapseDuplicates(string $table, array $columns, array $requireNotNull = []): void
    {
        $query = DB::table($table);
        foreach ($requireNotNull as $col) {
            $query->whereNotNull($col)->where($col, '!=', '');
        }

        $groups = $query
            ->select(array_merge($columns, [DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c')]))
            ->groupBy($columns)
            ->having('c', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $delete = DB::table($table)->where('id', '!=', $group->keep_id);
            foreach ($columns as $col) {
                $delete->where($col, $group->{$col});
            }
            $delete->delete();
        }
    }

    private function collapseConversationDuplicates(): void
    {
        $groups = DB::table('conversations')
            ->whereNotNull('socialapi_conversation_id')
            ->where('socialapi_conversation_id', '!=', '')
            ->select([
                'business_id',
                'socialapi_conversation_id',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('COUNT(*) as c'),
            ])
            ->groupBy('business_id', 'socialapi_conversation_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $dropIds = DB::table('conversations')
                ->where('business_id', $group->business_id)
                ->where('socialapi_conversation_id', $group->socialapi_conversation_id)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id');

            foreach ($dropIds as $dropId) {
                DB::table('messages')
                    ->where('conversation_id', $dropId)
                    ->update(['conversation_id' => $group->keep_id]);
                DB::table('conversations')->where('id', $dropId)->delete();
            }
        }
    }
};
