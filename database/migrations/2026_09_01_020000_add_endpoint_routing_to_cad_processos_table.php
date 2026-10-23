<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_processos', function (Blueprint $table) {
            $table->foreignId('input_endpoint_id')
                ->nullable()
                ->after('id')
                ->constrained('cad_endpoints')
                ->restrictOnDelete();
            $table->foreignId('output_endpoint_id')
                ->nullable()
                ->after('input_endpoint_id')
                ->constrained('cad_endpoints')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cad_processos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_endpoint_id');
            $table->dropConstrainedForeignId('input_endpoint_id');
        });
    }
};
