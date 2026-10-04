<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 教材題組（docs/SPEC.md 3.6）：每一課的目標語課名，以及由該課詞彙匯入的詞彙組。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->string('title_native')->nullable(); // 目標語的課名，例：Keluarga Saya
            $table->foreignUlid('set_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->dropForeign(['set_id']);
            $table->dropUnique(['set_id']);
            $table->dropColumn(['title_native', 'set_id']);
        });
    }
};
