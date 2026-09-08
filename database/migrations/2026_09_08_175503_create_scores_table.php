<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('points');
            $table->unsignedTinyInteger('hits');
            $table->unsignedTinyInteger('perfect');
            $table->unsignedTinyInteger('best_streak');
            $table->string('input_mode');
            $table->string('speed');
            $table->unsignedSmallInteger('bpm');
            $table->timestamps();

            $table->index('points');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
