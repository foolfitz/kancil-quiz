import type {
    Face,
    GameContext,
    GameInstance,
    GameModule,
} from '@kancil-quiz/games-sdk';
import { meta } from './meta';
import type { MatchUpOptions } from './meta';
import { MatchSession, pairRounds } from './session';
import './style.css';

export type { MatchUpOptions } from './meta';

// 移動超過這個距離（px）才算拖曳，否則當成點選
const DRAG_THRESHOLD = 8;
const WRONG_FLASH_MS = 700;
const NEXT_PAGE_DELAY_MS = 1000;

function el<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
    text?: string,
): HTMLElementTagNameMap[K] {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

function renderFace(face: Face, className: string): HTMLElement {
    const box = el('span', className);
    if (face.image) {
        const img = el('img', 'kq-match__image');
        img.src = face.image;
        img.alt = face.text ?? '';
        img.draggable = false;
        // 圖片載入失敗時只留文字，不讓畫面壞掉
        img.addEventListener('error', () => img.remove(), { once: true });
        box.append(img);
    }
    const words = el('span', 'kq-match__words');
    if (face.text) {
        words.append(el('span', 'kq-match__text', face.text));
    }
    if (face.romanization) {
        words.append(el('span', 'kq-match__romanization', face.romanization));
    }
    if (words.childElementCount > 0) {
        box.append(words);
    }
    return box;
}

interface Drag {
    card: HTMLButtonElement;
    pointerId: number;
    startX: number;
    startY: number;
    active: boolean;
}

function mount(
    host: HTMLElement,
    ctx: GameContext<MatchUpOptions>,
): GameInstance {
    const options = { ...meta.defaultOptions, ...ctx.options };
    const session = new MatchSession(
        pairRounds(ctx.rounds),
        options.pairsPerPage,
        ctx.rng,
    );
    const startedAt = performance.now();
    let lastAnswerAt = startedAt;
    let paused = false;
    let finished = false;
    let pendingNextPage = false;
    let nextPageTimer: ReturnType<typeof setTimeout> | undefined;
    let selected: HTMLButtonElement | null = null;
    let drag: Drag | null = null;
    const timers = new Set<ReturnType<typeof setTimeout>>();

    const later = (callback: () => void, ms: number) => {
        const id = setTimeout(() => {
            timers.delete(id);
            callback();
        }, ms);
        timers.add(id);
    };

    const root = el('div', 'kq-match');
    root.lang = ctx.language;
    const status = el('div', 'kq-match__status');
    const progress = el('span', 'kq-match__progress');
    const feedback = el('span', 'kq-match__feedback');
    feedback.setAttribute('role', 'status');
    status.append(progress, feedback);
    const board = el('div', 'kq-match__board');
    const rows = el('div', 'kq-match__rows');
    const pool = el('div', 'kq-match__pool');
    pool.setAttribute('aria-label', '答案卡片');
    board.append(rows, pool);
    root.append(status, board);
    host.append(root);

    function say(text: string, tone: 'correct' | 'wrong' | 'info'): void {
        feedback.textContent = text;
        feedback.className = `kq-match__feedback is-${tone}`;
    }

    function updateProgress(): void {
        const page = session.page;
        if (!page) {
            return;
        }
        const done = page.slots.filter((slot) =>
            session.isMatched(slot.entryId),
        ).length;
        const pages =
            session.pages.length > 1
                ? `第 ${session.pageIndex + 1} / ${session.pages.length} 頁｜`
                : '';
        progress.textContent = `${pages}配好 ${done} / ${page.slots.length} 組`;
    }

    function rowAt(x: number, y: number): HTMLElement | null {
        for (const row of rows.querySelectorAll<HTMLElement>(
            '.kq-match__row',
        )) {
            const rect = row.getBoundingClientRect();
            if (
                x >= rect.left &&
                x <= rect.right &&
                y >= rect.top &&
                y <= rect.bottom
            ) {
                return row;
            }
        }
        return null;
    }

    function highlight(row: HTMLElement | null): void {
        for (const other of rows.querySelectorAll('.kq-match__row.is-over')) {
            if (other !== row) {
                other.classList.remove('is-over');
            }
        }
        row?.classList.add('is-over');
    }

    function select(card: HTMLButtonElement | null): void {
        selected?.classList.remove('is-selected');
        selected?.setAttribute('aria-pressed', 'false');
        selected = card;
        card?.classList.add('is-selected');
        card?.setAttribute('aria-pressed', 'true');
        root.classList.toggle('has-selection', card !== null);
    }

    // 卡片回到原位：拖曳時以動畫滑回去，點選時搖一下
    function returnCard(card: HTMLButtonElement, dragged: boolean): void {
        if (dragged) {
            card.classList.add('is-returning');
            card.style.transform = '';
            later(() => card.classList.remove('is-returning'), 250);
        } else {
            card.classList.remove('is-shaking');
            void card.offsetWidth;
            card.classList.add('is-shaking');
            later(() => card.classList.remove('is-shaking'), 400);
        }
    }

    function attempt(
        card: HTMLButtonElement,
        row: HTMLElement,
        dragged: boolean,
    ): void {
        const cardId = card.dataset.entryId ?? '';
        const slotId = row.dataset.entryId ?? '';
        const result = session.place(cardId, slotId);
        select(null);
        if (!result) {
            returnCard(card, dragged);
            return;
        }

        const now = performance.now();
        ctx.emit({
            type: 'answered',
            entryId: result.entryId,
            selected: [result.selected],
            correct: result.correct,
            durationMs: Math.round(now - lastAnswerAt),
            presented: result.presented,
        });
        lastAnswerAt = now;

        if (!result.correct) {
            returnCard(card, dragged);
            row.classList.remove('is-wrong');
            void row.offsetWidth;
            row.classList.add('is-wrong');
            later(() => row.classList.remove('is-wrong'), WRONG_FLASH_MS);
            say('✗ 不對，再試一次', 'wrong');
            return;
        }

        // 配好的卡片放進那一題；原位留下看不見的空位，其他卡片不會跟著移動
        const placed = card.cloneNode(true) as HTMLButtonElement;
        placed.className = 'kq-match__card is-placed';
        placed.disabled = true;
        placed.removeAttribute('aria-pressed');
        placed.style.transform = '';
        const zone = row.querySelector('.kq-match__zone');
        zone?.replaceChildren(placed, el('span', 'kq-match__mark', '✓'));
        row.classList.add('is-matched');
        card.style.transform = '';
        card.classList.remove('is-dragging');
        card.classList.add('is-used');
        card.disabled = true;
        card.tabIndex = -1;
        card.setAttribute('aria-hidden', 'true');

        const audio = session.page?.slots.find(
            (slot) => slot.entryId === slotId,
        )?.left.audio;
        if (audio) {
            ctx.audio.stopAll();
            void ctx.audio.play(audio).catch(() => undefined);
        }

        updateProgress();
        if (result.pageComplete) {
            say(
                session.pageIndex + 1 < session.pages.length
                    ? '✓ 這一頁完成了！'
                    : '✓ 全部配好了！',
                'correct',
            );
            pendingNextPage = true;
            if (!paused) {
                nextPageTimer = setTimeout(nextPage, NEXT_PAGE_DELAY_MS);
            }
        } else {
            say('✓ 配對正確', 'correct');
        }
    }

    function nextPage(): void {
        pendingNextPage = false;
        session.nextPage();
        if (session.finished) {
            finish();
        } else {
            renderPage();
        }
    }

    function finish(): void {
        if (finished) {
            return;
        }
        finished = true;
        ctx.audio.stopAll();
        ctx.emit({
            type: 'completed',
            gameScore: session.score,
            durationMs: Math.round(performance.now() - startedAt),
        });
    }

    function renderRow(slot: { entryId: string; left: Face }): HTMLElement {
        const row = el('div', 'kq-match__row');
        row.dataset.entryId = slot.entryId;
        const prompt = renderFace(slot.left, 'kq-match__prompt');
        if (slot.left.audio) {
            const audio = slot.left.audio;
            const play = el('button', 'kq-match__audio', '🔊');
            play.type = 'button';
            play.setAttribute('aria-label', '播放發音');
            play.addEventListener('click', (event) => {
                event.stopPropagation();
                if (!paused) {
                    ctx.audio.stopAll();
                    void ctx.audio.play(audio).catch(() => undefined);
                }
            });
            prompt.append(play);
        }
        const zone = el('div', 'kq-match__zone');
        const drop = el('button', 'kq-match__drop', '放到這裡');
        drop.type = 'button';
        zone.append(drop);
        row.append(prompt, zone);
        // 點選模式：先點卡片，再點這一題（整列都可以點）
        row.addEventListener('click', () => {
            if (paused || finished || row.classList.contains('is-matched')) {
                return;
            }
            if (selected) {
                attempt(selected, row, false);
            } else {
                say('先點一張卡片，再點這裡；也可以直接把卡片拖過來', 'info');
            }
        });
        return row;
    }

    function renderCard(card: { entryId: string; right: Face }): HTMLElement {
        const button = el('button', 'kq-match__card');
        button.type = 'button';
        button.dataset.entryId = card.entryId;
        button.setAttribute('aria-pressed', 'false');
        button.append(renderFace(card.right, 'kq-match__face'));

        // 剛拖曳過：拖曳結束後瀏覽器可能還會對這張卡片送出 click，不要把它當成點選。
        // 下一次按下時清除；鍵盤觸發的 click（detail 為 0）不受影響
        let justDragged = false;

        button.addEventListener('click', (event) => {
            if (paused || finished) {
                return;
            }
            if (event.detail > 0 && justDragged) {
                justDragged = false;
                return;
            }
            select(selected === button ? null : button);
        });
        button.addEventListener('pointerdown', (event) => {
            justDragged = false;
            if (
                paused ||
                finished ||
                button.disabled ||
                drag ||
                (event.pointerType === 'mouse' && event.button !== 0)
            ) {
                return;
            }
            drag = {
                card: button,
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                active: false,
            };
            button.setPointerCapture?.(event.pointerId);
        });
        button.addEventListener('pointermove', (event) => {
            if (drag?.card !== button || drag.pointerId !== event.pointerId) {
                return;
            }
            const dx = event.clientX - drag.startX;
            const dy = event.clientY - drag.startY;
            if (!drag.active) {
                if (Math.hypot(dx, dy) < DRAG_THRESHOLD) {
                    return;
                }
                drag.active = true;
                select(null);
                button.classList.add('is-dragging');
            }
            button.style.transform = `translate(${dx}px, ${dy}px)`;
            highlight(rowAt(event.clientX, event.clientY));
        });
        const endDrag = (event: PointerEvent, cancelled: boolean) => {
            if (drag?.card !== button || drag.pointerId !== event.pointerId) {
                return;
            }
            const wasActive = drag.active;
            drag = null;
            if (!wasActive) {
                return;
            }
            justDragged = true;
            button.classList.remove('is-dragging');
            highlight(null);
            const row = cancelled ? null : rowAt(event.clientX, event.clientY);
            if (row && !paused) {
                attempt(button, row, true);
            } else {
                returnCard(button, true);
            }
        };
        button.addEventListener('pointerup', (event) => endDrag(event, false));
        button.addEventListener('pointercancel', (event) =>
            endDrag(event, true),
        );
        return button;
    }

    function renderPage(): void {
        const page = session.page;
        if (!page) {
            return;
        }
        select(null);
        rows.replaceChildren(...page.slots.map(renderRow));
        pool.replaceChildren(...page.cards.map(renderCard));
        say('把卡片拖到對應的題目旁邊', 'info');
        updateProgress();
        lastAnswerAt = performance.now();
    }

    function onKey(event: KeyboardEvent): void {
        if (event.key === 'Escape' && selected) {
            select(null);
        }
    }
    document.addEventListener('keydown', onKey);

    ctx.emit({ type: 'started' });
    if (session.finished) {
        finish();
    } else {
        renderPage();
    }

    return {
        destroy() {
            clearTimeout(nextPageTimer);
            for (const id of timers) {
                clearTimeout(id);
            }
            timers.clear();
            document.removeEventListener('keydown', onKey);
            ctx.audio.stopAll();
            root.remove();
        },
        pause() {
            paused = true;
            ctx.audio.stopAll();
            if (drag?.active) {
                drag.card.classList.remove('is-dragging');
                returnCard(drag.card, true);
                highlight(null);
            }
            drag = null;
            clearTimeout(nextPageTimer);
        },
        resume() {
            paused = false;
            if (pendingNextPage) {
                nextPageTimer = setTimeout(nextPage, NEXT_PAGE_DELAY_MS);
            }
        },
    };
}

export const matchUp: GameModule<MatchUpOptions> = { ...meta, mount };
export default matchUp;
