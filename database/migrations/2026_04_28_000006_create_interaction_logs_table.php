<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('sender_phone', 20);
            $table->string('wasender_message_id')->nullable();
            $table->string('intent', 30)->nullable();
            $table->string('detected_language', 10)->nullable();
            $table->boolean('was_resolved')->default(false);
            $table->boolean('was_fallback')->default(false);
            $table->integer('processing_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_logs');
    }
};