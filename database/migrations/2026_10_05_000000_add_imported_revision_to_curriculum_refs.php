<?php

use App\Curriculum\Textbook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 教材題組最近一次匯入詞彙後的版本號（docs/SPEC.md 3.6）。之後有其他人的版本，就是審核者在網站上修正過；
// 在後台上傳插圖也會產生版本，但記在教材帳號名下，不影響這個判斷。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->unsignedInteger('imported_revision')->nullable();
        });

        // 已經匯入的課：教材帳號最後一個版本就是最近一次匯入
        $owner = DB::table('users')->where('email', Textbook::OWNER_EMAIL)->value('id');
        if ($owner === null) {
            return;
        }
        foreach (DB::table('curriculum_refs')->whereNotNull('set_id')->get(['id', 'set_id']) as $ref) {
            DB::table('curriculum_refs')->where('id', $ref->id)->update([
                'imported_revision' => DB::table('set_revisions')->where('set_id', $ref->set_id)->where('created_by', $owner)->max('number'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->dropColumn('imported_revision');
        });
    }
};
