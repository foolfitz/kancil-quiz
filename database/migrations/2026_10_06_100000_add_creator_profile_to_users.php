<?php

use App\Support\Ulid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 創作者資料（docs/SPEC.md T-20、第 5 節）：署名名稱與網址、預設授權、學校、教的語言、簡介，
// 以及創作者頁面網址用的 public_id（公開識別碼一律用 ULID，不暴露遞增 ID）。既有的使用者在這裡補上 public_id。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->unique();
            $table->string('attribution_name', 100)->nullable();
            $table->string('attribution_url', 300)->nullable();
            $table->string('default_license', 32)->nullable();
            $table->string('school', 100)->nullable();
            $table->json('teaching_languages')->nullable();
            $table->text('bio')->nullable();
        });

        foreach (DB::table('users')->whereNull('public_id')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['public_id' => Ulid::make()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'attribution_name', 'attribution_url', 'default_license', 'school', 'teaching_languages', 'bio']);
        });
    }
};
