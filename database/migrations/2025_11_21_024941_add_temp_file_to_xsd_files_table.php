<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('xsd_files', function (Blueprint $table) {
            $table->string('temp_file')->nullable()->after('path');
        });
    }

    public function down()
    {
        Schema::table('xsd_files', function (Blueprint $table) {
            $table->dropColumn('temp_file');
        });
    }

};
