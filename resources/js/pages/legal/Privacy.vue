<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageMeta from '@/components/kancil/PageMeta.vue';
import { terms } from '@/routes';
import type { PageMeta as PageMetaData } from '@/types/kancil';

// 隱私權政策（docs/SPEC.md 第 11 節）。營運者、聯絡方式與保存期限來自設定（App\Http\Controllers\LegalController）。
// 內容要和實際的做法一致：改了蒐集或保存的方式，這一頁也要跟著改。
defineProps<{
    operator: string | null;
    contactEmail: string | null;
    sourceUrl: string;
    retention: { attempt_months: number; trashed_days: number };
    meta: PageMetaData;
}>();
</script>

<template>
    <PageMeta :meta="meta" />

    <article
        class="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-10 leading-relaxed [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-6"
    >
        <h1 class="text-3xl font-semibold tracking-tight">隱私權政策</h1>
        <p class="text-sm text-muted-foreground">
            最後更新：2026 年 10 月 5 日
        </p>

        <p>
            {{
                operator
                    ? `這個網站由${operator}營運（以下稱「我們」）`
                    : '這個網站的營運者（以下稱「我們」）依這份政策處理你的資料'
            }}。Kancil Quiz 是開放原始碼的軟體（<a
                :href="sourceUrl"
                class="underline underline-offset-4"
                >原始碼</a
            >），任何人都可以架設；這份政策說明的是這個網站怎麼處理你的資料。使用者多半是國小學生，我們只蒐集提供服務所需的最少資料。
        </p>

        <h2>老師</h2>
        <ul>
            <li>
                用 Google 帳號登入時，我們取得 Google 提供的名字、email 與
                Google 帳號的識別碼，只用來建立和辨識你的帳號。我們拿不到你的
                Google 密碼，也不會讀取你的信件、雲端硬碟等其他資料。
            </li>
            <li>
                你建立的題組、上傳的圖片與音檔、建立的活動。你的名字會作為作者，顯示在你公開的題組與上傳的檔案上。
            </li>
            <li>管理員另外可以用 email 與密碼登入，密碼以雜湊保存。</li>
        </ul>

        <h2>學生</h2>
        <ul>
            <li>學生不需要帳號，也不必提供 email。</li>
            <li>
                老師要求時，學生先輸入名字或座號（最多 20
                個字），讓老師對照成績。建議只填座號或暱稱。
            </li>
            <li>作答紀錄：每一題選了什麼、對錯與花費的時間，給老師看成績。</li>
            <li>不記錄 IP 位址，不使用第三方的追蹤碼、分析工具或廣告。</li>
        </ul>

        <h2>不登入試玩教材</h2>
        <p>
            只累計每一課、每個遊戲每天被開始與玩完的次數，不記錄是誰玩的。瀏覽器會在
            sessionStorage 記住「這個分頁已經算過」，關掉分頁就消失。
        </p>

        <h2>檢舉</h2>
        <p>只保存被檢舉的活動與你寫下的原因，不記錄你是誰。</p>

        <h2>Cookie</h2>
        <p>
            網站只使用必要的
            cookie：維持登入狀態、防止偽造的請求，以及記住深色或淺色的外觀與側邊欄的開關。工作階段不記錄
            IP 位址與瀏覽器的資訊。我們不使用分析或廣告的 cookie。
        </p>

        <h2>誰看得到</h2>
        <ul>
            <li>學生的名字、座號與成績：只有建立那個活動的老師。</li>
            <li>
                老師的題組：預設只有自己。用分享連結給同事時，拿到連結的老師看得到。公開到共備庫之後，所有登入的老師都看得到並能複製改編，也會以開放資料提供下載。
            </li>
            <li>網站管理員在維護、處理檢舉時，可以看到網站上的資料。</li>
            <li>
                我們不出售，也不提供你的資料給第三方。登入時會經過
                Google，Google 依它自己的隱私權政策處理。
            </li>
        </ul>

        <h2>保存多久</h2>
        <ul>
            <li>
                作答紀錄：從開始作答起保存
                {{ retention.attempt_months }} 個月，之後自動刪除。
            </li>
            <li>
                老師刪除的題組與活動：{{ retention.trashed_days }}
                天後連同學生的作答永久刪除。
            </li>
            <li>沒有任何題組使用的圖片與音檔：自動刪除。</li>
            <li>試玩的次數：只有次數，沒有個人資料，持續保留。</li>
            <li>
                網站每天備份；已經刪除的資料，要等備份輪替之後才會從備份中消失。
            </li>
        </ul>

        <h2>刪除帳號</h2>
        <p>
            老師可以在「設定 → 個人資料」刪除帳號：名字、email
            與登入方式立刻清除，活動連結立刻失效；私人題組、活動與學生的作答在
            {{ retention.trashed_days }}
            天後永久刪除。已經公開到共備庫的題組會留下，照舊署名你的名字，因為其他老師可能已經依授權使用（見<Link
                :href="terms()"
                class="underline underline-offset-4"
                >使用條款</Link
            >）。
        </p>

        <h2>聯絡我們</h2>
        <p v-if="contactEmail">
            想查詢、更正或刪除你的資料，或對這份政策有疑問，請寫信到
            <a
                :href="`mailto:${contactEmail}`"
                class="underline underline-offset-4"
                >{{ contactEmail }}</a
            >。
        </p>
        <p v-else>
            想查詢、更正或刪除你的資料，或對這份政策有疑問，請聯絡網站的營運者。
        </p>

        <p class="text-sm text-muted-foreground">
            這份政策修改時，會在這一頁公告並更新日期。
        </p>
    </article>
</template>
