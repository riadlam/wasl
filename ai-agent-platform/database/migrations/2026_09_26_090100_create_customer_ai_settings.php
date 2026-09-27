<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('llm_model', 60)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('tone', 40)->nullable();
            $table->text('persona')->nullable();
            $table->string('response_length', 10)->default('short');
            $table->boolean('allow_order_creation')->default(true);
            $table->unsignedSmallInteger('max_reply_chars')->default(600);
            $table->string('emoji_policy', 10)->default('light');
            $table->json('handoff_keywords')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('agent_settings')->orderBy('id')->chunk(200, function ($rows) use ($now) {
            $insert = [];
            foreach ($rows as $row) {
                $length = in_array($row->response_length ?? null, ['short', 'medium', 'long'], true) ? $row->response_length : 'short';
                $insert[] = [
                    'business_id' => $row->business_id,
                    'response_length' => $length,
                    'allow_order_creation' => (bool) ($row->allow_order_creation ?? true),
                    'max_reply_chars' => 600,
                    'emoji_policy' => 'light',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('customer_ai_settings')->insertOrIgnore($insert);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ai_settings');
    }
};
