<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Media\MediaProcessor;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 上傳音檔與圖片（docs/SPEC.md T-05、第 9 節）。轉檔後回傳媒體資料，由編輯畫面放進題組內容。
 */
class MediaController extends Controller
{
    public function store(Request $request, MediaProcessor $processor): JsonResponse
    {
        $request->validate(['kind' => ['required', 'in:audio,image']]);
        $audio = $request->input('kind') === 'audio';

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', $audio
                ? 'mimes:mp3,m4a,mp4,aac,wav,ogg,oga,webm'
                : 'mimes:jpg,jpeg,png,webp'],
            'rights' => ['accepted'],
            'author' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:300'],
            'license' => ['nullable', 'string', 'max:40'],
        ], [
            'rights.accepted' => '請確認你有權分享這個檔案。',
            'file.max' => '檔案不可超過 5 MB。',
            'file.mimes' => $audio ? '音檔格式必須是 mp3、m4a、aac、wav、ogg 或 webm。' : '圖片格式必須是 jpg、png 或 webp。',
        ]);

        $this->ensureWithinQuota($request->user());

        $attribution = [
            'authors' => [['name' => $data['author'] ?? $request->user()->name]],
            'license' => $data['license'] ?? null,
            'source' => $data['source'] ?? null,
        ];

        $media = $audio
            ? $processor->audio($request->file('file'), $request->user(), $attribution)
            : $processor->image($request->file('file'), $request->user(), $attribution);

        return response()->json(SetEditorData::media($media), 201);
    }

    /**
     * 每位老師上傳的總量上限（config('kancil.upload_quota_mb')），以轉檔後的大小計算。任何人都能用 Google
     * 註冊，所以要限制；管理員不受限制。沒有用到的媒體由 kancil:prune 清除後就不再計算（docs/SPEC.md 第 5、9 節）。
     */
    private function ensureWithinQuota(User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $quota = (int) config('kancil.upload_quota_mb');
        if (Media::where('uploaded_by', $user->id)->sum('bytes') >= $quota * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => "你上傳的檔案已經到達上限（{$quota} MB），請聯絡網站管理員。",
            ]);
        }
    }
}
