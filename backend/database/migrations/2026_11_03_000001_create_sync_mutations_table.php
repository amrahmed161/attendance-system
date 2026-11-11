<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->id();
            $table->uuid('mutation_uuid')->unique();
            $table->char('payload_hash', 64);
            $table->json('receipt');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_mutations');
    }
};
