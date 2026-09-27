<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('lifecycle')->default('contact')->after('language');
            $table->string('lead_status')->nullable()->after('lifecycle');
            $table->timestamp('lead_marked_at')->nullable()->after('lead_status');
            $table->string('lead_source')->nullable()->after('lead_marked_at');
            $table->index(['business_id', 'lifecycle', 'lead_marked_at']);
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('template_key');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'template_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'lifecycle', 'lead_marked_at']);
            $table->dropColumn(['lifecycle', 'lead_status', 'lead_marked_at', 'lead_source']);
        });
    }
};
