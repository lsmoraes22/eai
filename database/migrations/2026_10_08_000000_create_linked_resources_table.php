<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('endpoint_id')->constrained('cad_endpoints')->restrictOnDelete();
            $table->char('identity_hash', 64);
            $table->text('external_id');
            $table->text('revision')->nullable();
            $table->text('source_path');
            $table->text('download_url'); // Laravel encrypted cast; never serialize/log.
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->string('staging_path')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'endpoint_id', 'identity_hash'], 'linked_resources_identity_unique');
            $table->index(['endpoint_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_resources');
    }
};
