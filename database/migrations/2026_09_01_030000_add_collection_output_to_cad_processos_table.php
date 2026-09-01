<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_processos', function (Blueprint $table) {
            $table->string('input_collection_path')->nullable()->after('output_endpoint_id');
            $table->string('output_mode')->nullable()->after('input_collection_path');
        });
    }

    public function down(): void
    {
        Schema::table('cad_processos', function (Blueprint $table) {
            $table->dropColumn(['input_collection_path', 'output_mode']);
        });
    }
};
