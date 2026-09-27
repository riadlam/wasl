<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_campaigns', function (Blueprint $table) {
            $table->json('warnings')->nullable()->after('timezone');
            $table->decimal('estimated_da', 12, 2)->default(0)->after('warnings');
            $table->decimal('spent_da', 12, 2)->default(0)->after('estimated_da');
            $table->index(['status', 'starts_on']);
        });

        Schema::table('ai_campaign_slots', function (Blueprint $table) {
            $table->decimal('cost_da', 12, 2)->default(0)->after('agent_asset_id');
            $table->unsignedTinyInteger('attempts')->default(0)->after('cost_da');
            $table->timestamp('dispatched_at')->nullable()->after('attempts');
            $table->string('client_request_key', 80)->nullable()->after('dispatched_at');
            $table->index(['status', 'scheduled_at']);
        });

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
            $table->unique(['ai_campaign_slot_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_slot_targets');

        Schema::table('ai_campaign_slots', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['cost_da', 'attempts', 'dispatched_at', 'client_request_key']);
        });

        Schema::table('ai_campaigns', function (Blueprint $table) {
            $table->dropIndex(['status', 'starts_on']);
            $table->dropColumn(['warnings', 'estimated_da', 'spent_da']);
        });
    }
};
