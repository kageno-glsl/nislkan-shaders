<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNullableFieldLastrun extends Migration
{
    public function up()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('last_run')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('last_run')->nullable(false)->change();
        });
    }
}
