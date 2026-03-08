<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->string('operating_flight_number', 20)->nullable()->after('flight_number');
        });
    }

    public function down(): void
    {
        Schema::table('flights_current', function (Blueprint $table) {
            $table->dropColumn('operating_flight_number');
        });
    }
};
