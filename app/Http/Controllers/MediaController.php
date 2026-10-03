<?php

namespace App\Http\Controllers;

use App\Corpus\SetEditorData;
use App\Media\MediaProcessor;
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
}
