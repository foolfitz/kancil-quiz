<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageMeta from '@/components/kancil/PageMeta.vue';
import { privacy } from '@/routes';
import type { PageMeta as PageMetaData } from '@/types/kancil';

// 使用條款（docs/SPEC.md 第 11 節）。營運者、聯絡方式與上傳上限來自設定（App\Http\Controllers\LegalController）。
defineProps<{
    operator: string | null;
    contactEmail: string | null;
    sourceUrl: string;
    uploadQuotaMb: number;
    meta: PageMetaData;
}>();
</script>

<template>
    <PageMeta :meta="meta" />

    <article
        class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-10 leading-relaxed [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6"
    >
        <h1 class="text-3xl font-semibold tracking-tight">使用條款</h1>
        <p class="text-sm text-muted-foreground">
            最後更新：2026 年 10 月 5 日
        </p>

        <p>
            {{
                operator ? `這個網站由${operator}營運` : '這個網站的營運者'
            }}（以下稱「我們」），提供給新住民語文老師免費使用的互動練習平台。使用這個網站，表示你同意以下條款與<Link
                :href="privacy()"
                class="underline underline-offset-4"
                >隱私權政策</Link
            >。
        </p>

        <h2>服務</h2>
        <ul>
            <li>
                服務依現況提供。我們會盡力維持，但不保證不中斷、不出錯，也可能調整或停止部分功能。
            </li>
            <li>請自行保留重要題組的備份（題組可以匯出成 zip）。</li>
        </ul>

        <h2>帳號</h2>
        <ul>
            <li>任何 Google 帳號都能登入，第一次登入就建立老師帳號。</li>
            <li>你要為自己帳號下的題組、活動與上傳的檔案負責。</li>
        </ul>

        <h2>你上傳的內容</h2>
        <ul>
            <li>
                你保有自己內容的權利，但必須有權分享你上傳的文字、圖片與音檔，例如自己製作，或依授權使用並標明出處。
            </li>
            <li>
                不得上傳未經授權的教科書課文、插圖或音檔。國教署《新住民語文學習教材》採
                CC BY-NC-ND 4.0 授權，網站只引用各課的課名與詞彙。
            </li>
            <li>
                題組公開到共備庫時，以題組標示的授權（預設 CC BY
                4.0）提供給所有人使用，其他老師可以複製改編，也會以開放資料提供下載。公開前要經過審核。
            </li>
            <li>
                取自教材的課名與詞彙照原教材標示作者（國教署）與授權（CC
                BY-NC-ND
                4.0），複製到你的題組後，這些詞條仍保留原本的標示，不適用題組的授權。
            </li>
            <li>
                每位老師上傳的檔案總量上限是 {{ uploadQuotaMb }}
                MB，題組編輯頁會顯示已經用了多少；需要更多空間可以聯絡我們。
            </li>
        </ul>

        <h2>不可以做的事</h2>
        <ul>
            <li>放入不適合兒童的內容，例如色情、暴力、歧視或騷擾。</li>
            <li>侵害他人的著作權、肖像權或其他權利。</li>
            <li>用活動或分享連結散布廣告，或與教學無關的內容。</li>
            <li>
                蒐集學生的個人資料。請學生輸入名字時，只填名字、暱稱或座號。
            </li>
            <li>大量送出請求、灌入資料或以其他方式干擾網站運作。</li>
        </ul>

        <h2>檢舉與停用</h2>
        <ul>
            <li>
                每個活動的開始與結果畫面都有「檢舉這個活動」，檢舉會送給網站管理員。
            </li>
            <li>
                違反條款時，管理員可以下架題組或停用帳號。帳號停用後不能登入，活動連結與分享連結失效，公開的題組不再列在共備庫。
            </li>
        </ul>

        <h2>學生</h2>
        <p>
            學生不需要帳號。老師在課堂上使用時，請遵守學校與相關法規對學生資料的規定。
        </p>

        <h2>開放原始碼</h2>
        <p>
            Kancil Quiz 的程式碼以 AGPL-3.0-or-later 授權公開（<a
                :href="sourceUrl"
                class="underline underline-offset-4"
                >原始碼</a
            >）。這份條款只適用於這個網站。
        </p>

        <h2>條款的修改</h2>
        <p>
            條款修改時，會在這一頁公告並更新日期。修改後繼續使用，表示你同意新的條款。
        </p>

        <h2>聯絡我們</h2>
        <p v-if="contactEmail">
            有疑問請寫信到
            <a
                :href="`mailto:${contactEmail}`"
                class="underline underline-offset-4"
                >{{ contactEmail }}</a
            >。
        </p>
        <p v-else>有疑問請聯絡網站的營運者。</p>
    </article>
</template>
