import type { Face, FaceField, FaceSlot, Round } from '@kancil-quiz/games-sdk';
import type {
    FaceFields,
    QuizOption,
    QuizStem,
    VocabItem,
} from '@kancil-quiz/schema';
import { normalizeForMatch } from '@kancil-quiz/text';

// 詞彙組依 faces 設定組出一面。text 與 translation_zh 都放進 Face.text，以 Face.lang 區分，
// 所以同一面不能同時用這兩個欄位（check() 會擋下）。
export function vocabFace(
    item: VocabItem,
    fields: FaceFields,
    language: string,
): Face {
    const face: Face = {};
    for (const field of fields) {
        switch (field) {
            case 'text':
                face.text = item.text;
                face.lang = language;
                break;
            case 'translation_zh':
                face.text = item.translation_zh;
                face.lang = 'zh-TW';
                break;
            case 'romanization':
                if (item.romanization) {
                    face.romanization = item.romanization;
                }
                break;
            case 'audio':
                if (item.audio?.[0]) {
                    face.audio = item.audio[0].src;
                }
                break;
            case 'image':
                if (item.image) {
                    face.image = item.image.src;
                }
                break;
        }
    }
    return face;
}

export function stemFace(stem: QuizStem): Face {
    return compact({
        text: stem.text ?? undefined,
        audio: stem.audio?.src,
        image: stem.image?.src,
    });
}

export function optionFace(option: QuizOption): Face {
    return compact({
        text: option.text ?? undefined,
        image: option.image?.src,
    });
}

function compact(face: Face): Face {
    return Object.fromEntries(
        Object.entries(face).filter(([, value]) => value),
    ) as Face;
}

export function isEmptyFace(face: Face): boolean {
    return Object.keys(face).length === 0;
}

// 遊戲在這個位置至少能呈現這一面的一個欄位。
export function isRenderable(
    face: Face,
    renders: FaceField[] | undefined,
): boolean {
    return renders === undefined || renders.some((field) => face[field]);
}

// 判斷兩面是否「看起來一樣」：干擾選項不可與正解相同，配對的右側不可重複。
export function faceKey(face: Face, language: string): string {
    return JSON.stringify([
        face.text === undefined
            ? null
            : normalizeForMatch(face.text, { language }),
        face.romanization ?? null,
        face.image ?? null,
        face.audio ?? null,
    ]);
}

// 每種形狀的兩個位置：[題目那一面, 答案那一面]
export const SLOTS: Record<Round['shape'], [FaceSlot, FaceSlot]> = {
    mcq: ['prompt', 'option'],
    pair: ['left', 'right'],
    card: ['front', 'back'],
};
