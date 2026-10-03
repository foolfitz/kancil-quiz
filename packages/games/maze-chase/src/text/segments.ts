/**
 * 切字的小工具。docs/SPEC.md 第 8 節規定切字一律用 @kancil-quiz/text 的 graphemes()；
 * 那個函式還在寫，所以先在這裡用同樣的做法（Intl.Segmenter）實作，之後換掉。
 * 絕對不要改成 split('')、[...text] 或用 .length 判斷寬度：泰文、越南文的組合字元會被拆開。
 */

export interface TextSegmenter {
    /** 字素（使用者看到的一個字）；泰文的上下標記、越南文的疊加聲調都和前面的字母算在一起 */
    graphemes(text: string): string[];
    /** 換行的候選位置：詞與詞之間（含空白），只用於畫面上的換行，不儲存 */
    words(text: string): string[];
}

/** 依題組語言建立；語言代碼不合法時退回瀏覽器預設語言 */
export function createSegmenter(language: string): TextSegmenter {
    const grapheme = segmenterFor(language, 'grapheme');
    const word = segmenterFor(language, 'word');
    return {
        // TODO: use graphemes() from @kancil-quiz/text
        graphemes: (text) =>
            Array.from(grapheme.segment(text), (part) => part.segment),
        words: (text) => Array.from(word.segment(text), (part) => part.segment),
    };
}

function segmenterFor(
    language: string,
    granularity: 'grapheme' | 'word',
): Intl.Segmenter {
    try {
        return new Intl.Segmenter(language, { granularity });
    } catch {
        // 例如空字串或格式錯誤的語言代碼
        return new Intl.Segmenter(undefined, { granularity });
    }
}
