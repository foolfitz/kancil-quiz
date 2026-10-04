<?php

namespace App\Http\Controllers;

use App\Corpus\SetExport;
use App\Models\Set;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 下載題組的 zip（docs/SPEC.md T-15、6.2），權限依題組的可見性（3.2）：
 * 私人題組只有擁有者，未公開題組要有同事的分享連結，公開題組任何人都能下載（開放資料，不需登入）。
 */
class SetExportController extends Controller
{
    public function show(Set $set): BinaryFileResponse
    {
        Gate::authorize('export', $set);

        return $this->download($set);
    }

    /**
     * 同事的分享連結（T-17）。
     */
    public function shared(string $token): BinaryFileResponse
    {
        return $this->download(Set::where('share_token', $token)->where('visibility', 'unlisted')->firstOrFail());
    }

    /**
     * 開放資料：公開的題組不需登入；其他題組一律回 404，不透露題組是否存在。
     */
    public function openData(Set $set): BinaryFileResponse
    {
        abort_unless($set->isPublic(), 404);

        return $this->download($set);
    }

    private function download(Set $set): BinaryFileResponse
    {
        $revision = $set->currentRevision;
        abort_if($revision === null, 404, '題組還沒有內容');

        return response()
            ->download(SetExport::zip($revision), "set-{$set->id}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
