<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32); // flexy | baridimob | ccp
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('priority')->nullable();
            $table->string('phone')->nullable();
            $table->string('ccp_cle', 16)->nullable();
            $table->string('ccp_number', 64)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'method']);
            $table->index(['business_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_payment_methods');
    }
};
