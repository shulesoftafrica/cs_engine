<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->uuid('document_key')->nullable()->after('permissions');
            $table->unsignedInteger('chunk_index')->default(0)->after('document_key');

            $table->index('document_key');
            $table->index(['product_id', 'document_key', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'document_key', 'chunk_index']);
            $table->dropIndex(['document_key']);
            $table->dropColumn(['document_key', 'chunk_index']);
        });
    }
};