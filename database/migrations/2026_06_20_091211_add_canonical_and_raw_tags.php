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
        Schema::table('tags', function (Blueprint $table) {
            // No index: the canonical set is tiny (tens of tags), so a scan is cheaper than the
            // index, and it keeps column rollback simple on SQLite (used in tests).
            $table->boolean('is_canonical')->default(false)->after('visibility');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->json('raw_tags')->nullable()->after('ai_tagged_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('is_canonical');
        });

        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('raw_tags');
        });
    }
};
