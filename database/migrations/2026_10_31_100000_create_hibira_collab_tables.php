<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('hibira-collab.connection');
    }

    public function up(): void
    {
        Schema::create(config('hibira-collab.tables.documents'), function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('subject')->nullable();
            $table->unsignedInteger('generation')->default(1);
            $table->boolean('seeded')->default(false);
            $table->string('source_hash', 64)->nullable();
            $table->unsignedInteger('pending_updates')->default(0);
            $table->timestamps();
        });

        Schema::create(config('hibira-collab.tables.updates'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id');
            $table->unsignedInteger('generation');
            $table->longText('update');
            $table->string('author')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['document_id', 'generation', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('hibira-collab.tables.updates'));
        Schema::dropIfExists(config('hibira-collab.tables.documents'));
    }
};
