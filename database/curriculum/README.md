# 教材資料

每一冊一個目錄：`<語言代碼>/<冊>/`，內含 `volume.json` 與插圖。匯入後每一課產生一個教材題組（`docs/SPEC.md` 3.6）：

```
php artisan kancil:import-curriculum database/curriculum/id/1
```

## 來源與授權

- 課名與詞彙依據國教署[新住民子女教育資訊網](https://mkm.k12ea.gov.tw/textbook)的「新住民語文學習教材」，紙本採 CC BY-NC-ND 4.0。
- 這裡只收錄課名與詞彙（詞與中文意思），**不收錄課文、教材的插圖與音檔**（D-4）。教材原始檔中有課文也不要放進來。
- 插圖一律自製，作者、出處與授權寫在各冊 `volume.json` 的 `images`。

## volume.json

```json
{
    "language": "id",
    "volume": 1,
    "textbook": "新住民語文學習教材 印尼語第1冊",
    "authors": [{ "name": "Kancil Quiz" }],
    "license": "CC-BY-4.0",
    "images": {
        "authors": [{ "name": "Kancil Quiz" }],
        "license": "CC-BY-4.0",
        "source": "AI 生成"
    },
    "lessons": [
        {
            "lesson": 3,
            "title_native": "Keluarga Saya",
            "title_zh": "我的家人",
            "vocabulary": [
                {
                    "text": "ayah",
                    "translation_zh": "爸爸",
                    "page": 26,
                    "image": "images/ayah.webp"
                }
            ]
        }
    ]
}
```

- `language`、`volume`：語言代碼與冊。語言要已經在 `languages` 資料表中。
- `textbook`：教材名稱，寫進題組說明與詞條出處（例：「《新住民語文學習教材 印尼語第1冊》第 3 課，課本第 26 頁」）。
- `authors`、`license`：教材題組與詞條的作者與授權。作者寫本專案的名稱（Kancil Quiz），不寫個人。
- `images`：插圖的作者、授權與出處，記在每一張圖上。重新匯入時會更新已匯入圖片的這些資料，不必重新匯入圖檔。
- `lessons[].title_native`：目標語的課名，選填。
- `lessons[].vocabulary[]`：`text`（目標語）、`translation_zh`（中文意思）、`page`（課本頁碼，選填）、`image`（相對於這個目錄的路徑，選填）。同一課的詞不可重複，比對時不分大小寫、忽略多餘的空白。

## 插圖

- 匯入時會轉成長邊最多 1024 px 的 WebP，所以這裡直接放 1024 px、品質 85 的 WebP，避免 repo 太大。原始的 PNG 不放進 repo。
- 兩課都有的詞（例如第 1、4 課的 selamat pagi）指向同一個檔案，匯入時只轉檔一次。

## 重新匯入

- 可以重複執行。詞以目標語文字對應，題目 ID 不變，學生的作答紀錄仍對得上；內容沒變時不產生新版本。
- 審核者在網站上修正過的課會被略過，以免蓋掉修正。先把修正寫回 `volume.json`，再加上 `--force` 匯入。
- 插圖只在詞還沒有圖時匯入。換了圖檔之後要加上 `--refresh-images`。
