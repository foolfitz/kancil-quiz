// 從 JSON Schema 產生 TS 型別（docs/SPEC.md 6.1：型別不手寫）。
// 加上 --check 時只比對，產生結果與現有檔案不同就以非零碼結束，供 CI 使用。
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { compileFromFile } from 'json-schema-to-typescript';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const outFile = path.join(root, 'src/generated.ts');
const checkOnly = process.argv.includes('--check');

// 活動格式引用了題組格式，從它產生就會包含兩邊所有的型別。
const output = await compileFromFile(
    path.join(root, 'activity.v1.schema.json'),
    {
        cwd: root,
        additionalProperties: false,
        ignoreMinAndMaxItems: true,
        bannerComment:
            '/* 由 packages/schema/scripts/generate-types.mjs 從 JSON Schema 自動產生，請勿手動修改。 */',
    },
);

if (checkOnly) {
    const current = await readFile(outFile, 'utf8').catch(() => '');
    if (current !== output) {
        console.error(
            'src/generated.ts 與 JSON Schema 不同步，請執行 npm run schema:types',
        );
        process.exit(1);
    }
    console.log('src/generated.ts 與 JSON Schema 同步');
} else {
    await writeFile(outFile, output);
    console.log(`已產生 ${path.relative(process.cwd(), outFile)}`);
}
