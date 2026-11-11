<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->uuid('entity_uuid');
            $table->string('operation');
            $table->unsignedInteger('entity_version');
            $table->json('payload');
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
