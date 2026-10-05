<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Media\MediaProcessor;
use App\Media\UploadQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // 每位老師的總量上限（docs/SPEC.md A-05、第 9 節）：任何人都能用 Google 註冊，所以要限制
        UploadQuota::of($request->user())->ensureNotFull();

        $attribution = [
            'authors' => [['name' => $data['author'] ?? $request->user()->name]],
            'license' => $data['license'] ?? null,
            'source' => $data['source'] ?? null,
        ];

        $media = $audio
            ? $processor->audio($request->file('file'), $request->user(), $attribution)
            : $processor->image($request->file('file'), $request->user(), $attribution);

        return response()->json([
            ...SetEditorData::media($media),
            // 編輯頁用這個更新「已上傳多少／上限」，不必重新載入（resources/js/lib/uploadQuota.ts）
            'quota' => UploadQuota::of($request->user())->toArray(),
        ], 201);
    }
}
