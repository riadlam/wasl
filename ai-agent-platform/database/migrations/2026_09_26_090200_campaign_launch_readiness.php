<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_campaigns', 'warnings')) {
                $table->json('warnings')->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('ai_campaigns', 'estimated_da')) {
                $table->decimal('estimated_da', 12, 2)->default(0)->after('warnings');
            }
            if (! Schema::hasColumn('ai_campaigns', 'spent_da')) {
                $table->decimal('spent_da', 12, 2)->default(0)->after('estimated_da');
            }
            if (! Schema::hasIndex('ai_campaigns', ['status', 'starts_on'])) {
                $table->index(['status', 'starts_on']);
            }
        });

        Schema::table('ai_campaign_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_campaign_slots', 'cost_da')) {
                $table->decimal('cost_da', 12, 2)->default(0)->after('agent_asset_id');
            }
            if (! Schema::hasColumn('ai_campaign_slots', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('cost_da');
            }
            if (! Schema::hasColumn('ai_campaign_slots', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('ai_campaign_slots', 'client_request_key')) {
                $table->string('client_request_key', 80)->nullable()->after('dispatched_at');
            }
            if (! Schema::hasIndex('ai_campaign_slots', ['status', 'scheduled_at'])) {
                $table->index(['status', 'scheduled_at']);
            }
        });

        if (! Schema::hasTable('ai_campaign_slot_targets')) {
            Schema::create('ai_campaign_slot_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_campaign_slot_id')->constrained()->cascadeOnDelete();
                $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
                $table->string('platform', 30);
                $table->string('status', 20)->default('pending');
                $table->string('socialapi_post_id')->nullable();
                $table->text('caption')->nullable();
                $table->foreignId('agent_asset_id')->nullable()->constrained('agent_assets')->nullOnDelete();
                $table->text('error')->nullable();
                $table->timestamps();
                $table->unique(['ai_campaign_slot_id', 'social_account_id'], 'ai_slot_targets_slot_account_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_slot_targets');

        Schema::table('ai_campaign_slots', function (Blueprint $table) {
            if (Schema::hasIndex('ai_campaign_slots', ['status', 'scheduled_at'])) {
                $table->dropIndex(['status', 'scheduled_at']);
            }
            $cols = array_values(array_filter(
                ['cost_da', 'attempts', 'dispatched_at', 'client_request_key'],
                fn (string $col) => Schema::hasColumn('ai_campaign_slots', $col)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('ai_campaigns', function (Blueprint $table) {
            if (Schema::hasIndex('ai_campaigns', ['status', 'starts_on'])) {
                $table->dropIndex(['status', 'starts_on']);
            }
            $cols = array_values(array_filter(
                ['warnings', 'estimated_da', 'spent_da'],
                fn (string $col) => Schema::hasColumn('ai_campaigns', $col)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
