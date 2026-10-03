import { isNfc } from '@kancil-quiz/text';
import type { KancilSet } from './generated';

// JSON Schema 無法表達的格式規則（docs/SPEC.md 6.5）。回傳違規說明，空陣列表示通過。
export function findRuleViolations(set: KancilSet): string[] {
    const violations: string[] = [];

    for (const [path, text] of strings(set)) {
        if (!isNfc(text)) {
            violations.push(`${path} 不是 NFC`);
        }
    }

    const entries: { id: string }[] = set.entries;
    violations.push(...duplicateIds(entries, '$.entries'));

    if (set.kind === 'quiz') {
        set.entries.forEach((entry, i) => {
            violations.push(
                ...duplicateIds(
                    entry.question.options,
                    `$.entries[${i}].question.options`,
                ),
            );
        });
    }

    return violations;
}

function duplicateIds(list: { id: string }[], path: string): string[] {
    const seen = new Set<string>();
    const violations: string[] = [];
    list.forEach(({ id }, i) => {
        if (seen.has(id)) {
            violations.push(`${path}[${i}].id 重複：${id}`);
        }
        seen.add(id);
    });
    return violations;
}

function* strings(value: unknown, path = '$'): Generator<[string, string]> {
    if (typeof value === 'string') {
        yield [path, value];
    } else if (Array.isArray(value)) {
        for (const [i, item] of value.entries()) {
            yield* strings(item, `${path}[${i}]`);
        }
    } else if (value !== null && typeof value === 'object') {
        for (const [key, item] of Object.entries(value)) {
            yield* strings(item, `${path}.${key}`);
        }
    }
}
