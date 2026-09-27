<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_image_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_chat_message_id')->nullable()->constrained('agent_chat_messages')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fal_request_id', 80)->unique();
            $table->string('model_key', 40);
            $table->string('endpoint', 120);
            $table->string('image_size', 40)->default('square_hd');
            $table->text('prompt');
            $table->string('status', 16)->default('queued');
            $table->string('status_url', 500)->nullable();
            $table->string('response_url', 500)->nullable();
            $table->foreignId('asset_id')->nullable()->constrained('agent_assets')->nullOnDelete();
            $table->unsignedInteger('price_da')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_image_jobs');
    }
};
