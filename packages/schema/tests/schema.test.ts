import Ajv2020 from 'ajv/dist/2020';
import type { ValidateFunction } from 'ajv/dist/2020';
import { describe, expect, it } from 'vite-plus/test';
import spec from '../../../docs/SPEC.md?raw';
import activitySchema from '../activity.v1.schema.json';
import setSchema from '../set.v1.schema.json';
import { findRuleViolations } from '../src';
import type { KancilActivity, KancilSet } from '../src';

// 與 tests/Unit/KancilFormatTest.php 共用同一批 fixture（docs/SPEC.md 第 12 節）。
// import.meta.glob 的路徑必須是字面值，所以每個目錄各寫一次。
const glob = {
    sets: import.meta.glob('../fixtures/sets/*/set.json', {
        eager: true,
        import: 'default',
    }),
    activities: import.meta.glob('../fixtures/activities/*.json', {
        eager: true,
        import: 'default',
    }),
    invalidSets: import.meta.glob('../fixtures/invalid/set/*.json', {
        eager: true,
        import: 'default',
    }),
    invalidActivities: import.meta.glob('../fixtures/invalid/activity/*.json', {
        eager: true,
        import: 'default',
    }),
    invalidRules: import.meta.glob('../fixtures/invalid/rules/*.json', {
        eager: true,
        import: 'default',
    }),
};

// 以相對於 fixtures 的路徑當測試名稱。
function cases<T>(files: Record<string, unknown>): [string, T][] {
    return Object.entries(files)
        .map(([file, data]): [string, T] => [
            file.replace('../fixtures/', ''),
            data as T,
        ])
        .sort(([a], [b]) => a.localeCompare(b));
}

const ajv = new Ajv2020({ allErrors: true });
ajv.addSchema(setSchema).addSchema(activitySchema);
const validateSet = ajv.getSchema(setSchema.$id) as ValidateFunction;
const validateActivity = ajv.getSchema(activitySchema.$id) as ValidateFunction;

function expectValid(validate: ValidateFunction, data: unknown): void {
    const valid = validate(data);
    expect(valid, ajv.errorsText(validate.errors)).toBe(true);
}

describe('合法的 fixture', () => {
    it.each(cases<KancilSet>(glob.sets))(
        '%s 符合題組格式與格式規則',
        (_file, set) => {
            expectValid(validateSet, set);
            expect(findRuleViolations(set)).toEqual([]);
        },
    );

    it.each(cases<KancilActivity>(glob.activities))(
        '%s 符合活動格式',
        (_file, activity) => {
            expectValid(validateActivity, activity);
            expect(findRuleViolations(activity.set)).toEqual([]);
        },
    );
});

describe('不合法的 fixture', () => {
    it.each(cases(glob.invalidSets))('%s 不符合題組格式', (_file, data) => {
        expect(validateSet(data)).toBe(false);
    });

    it.each(cases(glob.invalidActivities))(
        '%s 不符合活動格式',
        (_file, data) => {
            expect(validateActivity(data)).toBe(false);
        },
    );

    it.each(cases<KancilSet>(glob.invalidRules))(
        '%s 符合題組格式，但違反格式規則',
        (_file, set) => {
            expectValid(validateSet, set);
            expect(findRuleViolations(set)).not.toEqual([]);
        },
    );
});

// docs/SPEC.md 第 6 節的 JSON 範例必須與 schema 一致。
describe('docs/SPEC.md 的範例', () => {
    const examples = [...spec.matchAll(/```json\n([\s\S]*?)```/g)].map(
        ([, json]) => JSON.parse(json) as { format?: unknown },
    );
    const sets = examples.filter(
        (example): example is KancilSet => example.format === 'kancil-set',
    );
    const activities = examples.filter(
        (example): example is KancilActivity =>
            example.format === 'kancil-activity',
    );

    it('有題組與活動的範例', () => {
        expect(sets.length).toBeGreaterThanOrEqual(2);
        expect(activities.length).toBeGreaterThanOrEqual(1);
    });

    it.each(sets.map((set): [string, KancilSet] => [set.title, set]))(
        '題組範例「%s」符合題組格式',
        (_title, set) => {
            expectValid(validateSet, set);
            expect(findRuleViolations(set)).toEqual([]);
        },
    );

    // 活動範例省略了題組內容，補上同 ID 的題組範例後再驗證。
    it.each(
        activities.map((activity): [string, KancilActivity] => [
            activity.id,
            activity,
        ]),
    )('活動範例 %s 補上題組後符合活動格式', (_id, activity) => {
        const set = sets.find((s) => s.id === activity.set.id);
        expect(set).toBeDefined();
        expectValid(validateActivity, { ...activity, set });
    });
});
