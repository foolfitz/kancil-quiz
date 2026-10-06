<script setup lang="ts">
import { computed } from 'vue';
import type { ContributionCalendar } from '@/types/kancil';

// 貢獻日曆（docs/SPEC.md T-20）：像 GitHub 的 contribution graph，一欄一週、一列一個星期幾，
// 顏色深淺表示那一天的次數。只用純 CSS grid，不用圖表套件。顏色不是唯一的資訊：每一格有
// 日期與次數的文字（title 與 aria-label），表格外另有「最近一年 N 次」。手機上在自己的框內水平捲動。
const props = defineProps<{ calendar: ContributionCalendar }>();

interface Day {
    date: string;
    revisions: number;
    published: number;
    total: number;
    level: 0 | 1 | 2 | 3 | 4;
}

const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

// 不走 Date 的時區：日期都是台灣時間的 YYYY-MM-DD，以 UTC 的 Date 逐日加一只是為了算日曆
function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

function level(total: number): Day['level'] {
    if (total === 0) return 0;
    if (total <= 1) return 1;
    if (total <= 3) return 2;
    if (total <= 6) return 3;
    return 4;
}

// 一週一欄，從 from（星期日）到 to；to 之後的格子留空
const weeks = computed<(Day | null)[][]>(() => {
    const result: (Day | null)[][] = [];
    let date = props.calendar.from;
    while (date <= props.calendar.to) {
        const week: (Day | null)[] = [];
        for (let i = 0; i < 7; i++) {
            if (date > props.calendar.to) {
                week.push(null);
            } else {
                const day = props.calendar.days[date];
                const revisions = day?.revisions ?? 0;
                const published = day?.published ?? 0;
                week.push({
                    date,
                    revisions,
                    published,
                    total: revisions + published,
                    level: level(revisions + published),
                });
            }
            date = addDays(date, 1);
        }
        result.push(week);
    }
    return result;
});

function monthOf(week: (Day | null)[] | undefined): number | null {
    const first = week?.find((day) => day !== null);
    return first ? Number(first.date.slice(5, 7)) : null;
}

// 每個月的第一週標上月份；第一欄如果下一欄就換月，不標，免得兩個標籤擠在一起
const months = computed(() =>
    weeks.value.map((week, i) => {
        const month = monthOf(week);
        if (month === null) return '';
        if (i === 0) {
            return monthOf(weeks.value[1]) === month ? `${month} 月` : '';
        }
        return month !== monthOf(weeks.value[i - 1]) ? `${month} 月` : '';
    }),
);

function formatDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    return `${year} 年 ${month} 月 ${day} 日`;
}

function describe(day: Day): string {
    if (day.total === 0) {
        return `${formatDate(day.date)}：沒有貢獻`;
    }
    const parts = [];
    if (day.revisions > 0) parts.push(`修改公開題組 ${day.revisions} 次`);
    if (day.published > 0) parts.push(`公開 ${day.published} 個題組`);
    return `${formatDate(day.date)}：${parts.join('、')}`;
}
</script>

<template>
    <figure class="space-y-2" data-test="contribution-calendar">
        <figcaption class="text-sm">
            最近一年
            <strong class="font-semibold">{{ calendar.total }}</strong>
            次貢獻
            <span class="text-muted-foreground"
                >（修改公開題組的次數與公開題組的次數，依台灣時間）</span
            >
        </figcaption>
        <!-- 放不下整年時（手機、iPad 直向）在框內捲動。外層用 rtl、表格用 ltr：一開始就停在最右邊，
             先看到最近的日子，視窗大小改變時也一樣，不必用 JS 捲動 -->
        <div class="overflow-x-auto pb-1" dir="rtl">
            <table
                class="kq-calendar border-separate border-spacing-[3px] text-xs text-muted-foreground"
                dir="ltr"
            >
                <thead>
                    <tr>
                        <td class="sticky left-0 bg-background"></td>
                        <th
                            v-for="(label, i) in months"
                            :key="i"
                            scope="col"
                            class="h-4 overflow-visible text-left font-normal whitespace-nowrap"
                        >
                            {{ label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(weekday, row) in WEEKDAYS" :key="weekday">
                        <th
                            scope="row"
                            class="sticky left-0 bg-background pr-1 text-left font-normal"
                            :class="row % 2 === 0 ? 'text-transparent' : ''"
                            :aria-label="`星期${weekday}`"
                        >
                            {{ weekday }}
                        </th>
                        <td
                            v-for="(week, col) in weeks"
                            :key="col"
                            class="kq-calendar__day rounded-[2px]"
                            :data-level="week[row]?.level ?? null"
                            :data-date="week[row]?.date"
                            :title="week[row] ? describe(week[row]) : undefined"
                            :aria-label="
                                week[row] ? describe(week[row]) : undefined
                            "
                        >
                            <span v-if="week[row]" class="sr-only">{{
                                describe(week[row])
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            class="flex items-center justify-end gap-1 text-xs text-muted-foreground"
            aria-hidden="true"
        >
            少
            <span
                v-for="step in [0, 1, 2, 3, 4]"
                :key="step"
                class="kq-calendar__day inline-block rounded-[2px]"
                :data-level="step"
            ></span>
            多
        </div>
    </figure>
</template>

<style scoped>
.kq-calendar__day {
    --kq-cell: 11px;
    width: var(--kq-cell);
    min-width: var(--kq-cell);
    height: var(--kq-cell);
    background: var(--muted);
}
/* 一個色相由淺到深（docs/SPEC.md 第 11 節：回饋不只靠顏色，每格另有文字） */
.kq-calendar__day[data-level='1'] {
    background: #bbf7d0;
}
.kq-calendar__day[data-level='2'] {
    background: #4ade80;
}
.kq-calendar__day[data-level='3'] {
    background: #16a34a;
}
.kq-calendar__day[data-level='4'] {
    background: #14532d;
}
.dark .kq-calendar__day[data-level='1'] {
    background: #052e16;
}
.dark .kq-calendar__day[data-level='2'] {
    background: #15803d;
}
.dark .kq-calendar__day[data-level='3'] {
    background: #22c55e;
}
.dark .kq-calendar__day[data-level='4'] {
    background: #86efac;
}
.kq-calendar td.kq-calendar__day:not([data-level]) {
    background: transparent;
}
</style>
