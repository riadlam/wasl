<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\ConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupeInboxAfterReconnectCommand extends Command
{
    protected $signature = 'inbox:dedupe-reconnect {business? : Business id (omit for all)}';

    protected $description = 'Merge duplicate social accounts / conversations / messages left by disconnect+reconnect';

    public function handle(ConversationService $conversations): int
    {
        $ids = $this->argument('business')
            ? [(int) $this->argument('business')]
            : Business::query()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $businessId) {
            $business = Business::query()->find($businessId);
            if (! $business) {
                continue;
            }

            $mergedAccounts = $this->mergeDuplicateAccounts($business);
            $mergedThreads = $this->mergeDuplicateThreads($business);
            $dedupedMessages = 0;

            $conversationIds = Conversation::query()
                ->where('business_id', $business->id)
                ->pluck('id');
            foreach ($conversationIds as $conversationId) {
                $before = Message::query()->where('conversation_id', $conversationId)->count();
                $conversations->dedupeConversationMessages((int) $conversationId);
                $after = Message::query()->where('conversation_id', $conversationId)->count();
                $dedupedMessages += max(0, $before - $after);
            }

            $this->info("business {$businessId}: accounts={$mergedAccounts} threads={$mergedThreads} messages={$dedupedMessages}");
        }

        return self::SUCCESS;
    }

    private function mergeDuplicateAccounts(Business $business): int
    {
        $merged = 0;
        $groups = SocialAccount::query()
            ->where('business_id', $business->id)
            ->where('platform', '!=', 'simulator')
            ->whereNotNull('platform_account_id')
            ->where('platform_account_id', '!=', '')
            ->select('platform', 'platform_account_id', DB::raw('COUNT(*) as c'), DB::raw('MAX(id) as keep_id'))
            ->groupBy('platform', 'platform_account_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $keep = SocialAccount::query()->find($group->keep_id);
            if (! $keep) {
                continue;
            }
            // Prefer a connected row when available.
            $connected = SocialAccount::query()
                ->where('business_id', $business->id)
                ->where('platform', $group->platform)
                ->where('platform_account_id', $group->platform_account_id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'disconnected');
                })
                ->orderByDesc('id')
                ->first();
            if ($connected) {
                $keep = $connected;
            }

            $dupes = SocialAccount::query()
                ->where('business_id', $business->id)
                ->where('platform', $group->platform)
                ->where('platform_account_id', $group->platform_account_id)
                ->where('id', '!=', $keep->id)
                ->get();

            foreach ($dupes as $dupe) {
                Conversation::query()->where('social_account_id', $dupe->id)->update(['social_account_id' => $keep->id]);
                \App\Models\AiProfilePerChannel::query()->where('social_account_id', $dupe->id)->update(['social_account_id' => $keep->id]);
                \App\Models\ChannelProfileInterview::query()->where('social_account_id', $dupe->id)->update(['social_account_id' => $keep->id]);
                \App\Models\PostAiSetting::query()->where('social_account_id', $dupe->id)->update(['social_account_id' => $keep->id]);
                \App\Models\BusinessTrainingSnapshot::query()->where('social_account_id', $dupe->id)->update(['social_account_id' => $keep->id]);
                $dupe->delete();
                $merged++;
            }
        }

        return $merged;
    }

    private function mergeDuplicateThreads(Business $business): int
    {
        $merged = 0;
        $groups = Conversation::query()
            ->where('business_id', $business->id)
            ->whereNotNull('customer_id')
            ->whereNotNull('social_account_id')
            ->select('social_account_id', 'customer_id', DB::raw('COUNT(*) as c'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('social_account_id', 'customer_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $keepId = (int) $group->keep_id;
            $dropIds = Conversation::query()
                ->where('business_id', $business->id)
                ->where('social_account_id', $group->social_account_id)
                ->where('customer_id', $group->customer_id)
                ->where('id', '!=', $keepId)
                ->pluck('id')
                ->all();
            if ($dropIds === []) {
                continue;
            }
            Message::query()->whereIn('conversation_id', $dropIds)->update(['conversation_id' => $keepId]);
            Conversation::query()->whereIn('id', $dropIds)->delete();
            $merged += count($dropIds);
        }

        return $merged;
    }
}
