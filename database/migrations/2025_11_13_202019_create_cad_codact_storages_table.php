<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_codact_storage', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->increments('cas_id');
            $table->string('cas_codact', 3)->comment('Código ACT');
            $table->string('cas_storage', 4)->comment('Storage location');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_codact_storage');
    }
};
