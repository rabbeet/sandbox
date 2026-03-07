<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('definition');
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['airport_source_id', 'version']);
        });

        // Add FK constraint from airport_sources to parser_versions now that the table exists
        Schema::table('airport_sources', function (Blueprint $table) {
            $table->foreign('active_parser_version_id')
                ->references('id')
                ->on('parser_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('airport_sources', function (Blueprint $table) {
            $table->dropForeign(['active_parser_version_id']);
        });

        Schema::dropIfExists('parser_versions');
    }
};
