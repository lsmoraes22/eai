<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    	Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->string('extensao', 10)->default('xml')->after('url');
    	});
    }

    public function down()
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
    	    $table->dropColumn('extensao');
        });
    }
};
