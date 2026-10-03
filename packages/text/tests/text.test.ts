import { describe, expect, it } from 'vite-plus/test';
import { graphemes, isMatch, isNfc, nfc } from '../src';

describe('nfc()', () => {
    // 越南文輸入法可能產生 NFD（docs/SPEC.md 附錄 A）
    const composed = 'quả dưa hấu';
    const decomposed = composed.normalize('NFD');

    it('把 NFD 的越南文轉成 NFC', () => {
        expect(decomposed).not.toBe(composed);
        expect(nfc(decomposed)).toBe(composed);
    });

    it('isNfc() 能分辨兩種形式', () => {
        expect(isNfc(composed)).toBe(true);
        expect(isNfc(decomposed)).toBe(false);
    });

    it('不改動泰文的組合字元', () => {
        expect(nfc('องุ่น')).toBe('องุ่น');
    });
});

describe('graphemes()', () => {
    it('越南文一個字母連同聲調符號算一個字', () => {
        expect(graphemes('hấu', 'vi')).toEqual(['h', 'ấ', 'u']);
    });

    it('NFD 的輸入也切成同樣的結果', () => {
        expect(graphemes('hấu'.normalize('NFD'), 'vi')).toEqual([
            'h',
            'ấ',
            'u',
        ]);
    });

    it('泰文的上下附加符號跟著前面的子音', () => {
        expect(graphemes('องุ่น', 'th')).toEqual(['อ', 'งุ่', 'น']);
        expect(graphemes('กล้วย', 'th')).toEqual(['ก', 'ล้', 'ว', 'ย']);
    });

    it('高棉文的下加子音不被拆開', () => {
        // ខ្មែរ（高棉）：ខ + ្ម（下加子音）+ ែ + រ
        const parts = graphemes('ខ្មែរ', 'km');
        expect(parts.join('')).toBe('ខ្មែរ');
        expect(parts.length).toBeLessThan('ខ្មែរ'.length);
    });

    it('語言代碼不合法時退回預設語言，不丟出錯誤', () => {
        expect(graphemes('hấu', 'not a language')).toEqual(['h', 'ấ', 'u']);
        expect(graphemes('hấu', '')).toEqual(['h', 'ấ', 'u']);
    });
});

describe('isMatch()', () => {
    it('忽略 NFC／NFD 差異、頭尾空白與連續空白', () => {
        expect(
            isMatch('  quả   chuối ', 'quả chuối'.normalize('NFD'), {
                language: 'vi',
            }),
        ).toBe(true);
    });

    it('拉丁字母語言不分大小寫', () => {
        expect(
            isMatch('Terima Kasih', 'terima kasih', { language: 'id' }),
        ).toBe(true);
        expect(isMatch('Cảm ơn', 'cảm ơn', { language: 'vi' })).toBe(true);
    });

    it('預設區分聲調：越南語的聲調有辨義作用', () => {
        // ma（鬼）、má（媽媽）、mã（馬）是不同的字
        expect(isMatch('má', 'ma', { language: 'vi' })).toBe(false);
        expect(isMatch('má', 'mã', { language: 'vi' })).toBe(false);
    });

    it('寬鬆模式忽略聲調與變音符號，đ 視為 d', () => {
        const loose = { language: 'vi', loose: true };
        expect(isMatch('má', 'ma', loose)).toBe(true);
        expect(isMatch('Đu đủ', 'du du', loose)).toBe(true);
        expect(isMatch('dưa hấu', 'dua hau', loose)).toBe(true);
    });

    it('寬鬆模式不影響非拉丁字母語言', () => {
        expect(isMatch('ส้ม', 'สม', { language: 'th', loose: true })).toBe(
            false,
        );
    });
});
