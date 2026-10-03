import type {
    Face,
    GameContext,
    GameInstance,
    GameModule,
} from '@kancil-quiz/games-sdk';
import { meta } from './meta';
import type { QuizOptions } from './meta';
import { QuizSession, mcqRounds } from './session';
import './style.css';

export type { QuizOptions } from './meta';

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const ADVANCE_DELAY_MS = { correct: 1200, wrong: 2200 };

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
        const img = el('img', 'kq-quiz__image');
        img.src = face.image;
        img.alt = face.text ?? '';
        img.draggable = false;
        // 圖片載入失敗時只留文字，不讓畫面壞掉
        img.addEventListener('error', () => img.remove(), { once: true });
        box.append(img);
    }
    if (face.text) {
        box.append(el('span', 'kq-quiz__text', face.text));
    }
    if (face.romanization) {
        box.append(el('span', 'kq-quiz__romanization', face.romanization));
    }
    return box;
}

function mount(host: HTMLElement, ctx: GameContext<QuizOptions>): GameInstance {
    const options = { ...meta.defaultOptions, ...ctx.options };
    const session = new QuizSession(mcqRounds(ctx.rounds));
    const startedAt = performance.now();
    let roundStartedAt = startedAt;
    let advanceTimer: ReturnType<typeof setTimeout> | undefined;
    let paused = false;
    let pendingAdvance = false;

    const root = el('div', 'kq-quiz');
    root.lang = ctx.language;
    const progress = el('div', 'kq-quiz__progress');
    const prompt = el('div', 'kq-quiz__prompt');
    const choices = el('div', 'kq-quiz__options');
    const feedback = el('div', 'kq-quiz__feedback');
    feedback.setAttribute('role', 'status');
    const nextButton = el('button', 'kq-quiz__next', '下一題 →');
    nextButton.type = 'button';
    nextButton.hidden = true;
    root.append(progress, prompt, choices, feedback, nextButton);
    host.append(root);

    function playPrompt(): void {
        const audio = session.current?.prompt.audio;
        if (audio) {
            void ctx.audio.play(audio).catch(() => undefined);
        }
    }

    function showRound(): void {
        const round = session.current;
        if (!round) {
            return;
        }

        roundStartedAt = performance.now();
        progress.textContent = `第 ${session.index + 1} / ${session.rounds.length} 題`;
        feedback.textContent = '';
        feedback.className = 'kq-quiz__feedback';
        nextButton.hidden = true;

        prompt.replaceChildren(renderFace(round.prompt, 'kq-quiz__face'));
        if (round.prompt.audio) {
            const replay = el('button', 'kq-quiz__audio', '🔊 再聽一次');
            replay.type = 'button';
            replay.addEventListener('click', playPrompt);
            prompt.append(replay);
        }

        choices.replaceChildren(
            ...round.options.map((option, i) => {
                const button = el('button', 'kq-quiz__option');
                button.type = 'button';
                button.dataset.optionId = option.id;
                button.append(
                    el('span', 'kq-quiz__letter', LETTERS[i]),
                    renderFace(option.face, 'kq-quiz__face'),
                    el('span', 'kq-quiz__mark'),
                );
                button.addEventListener('click', () => choose(option.id));
                return button;
            }),
        );

        playPrompt();
    }

    function choose(optionId: string): void {
        if (paused || !session.canAnswer) {
            return;
        }
        const result = session.answer(optionId);
        if (!result) {
            return;
        }

        ctx.emit({
            type: 'answered',
            entryId: result.entryId,
            selected: [result.selected],
            correct: result.correct,
            durationMs: Math.round(performance.now() - roundStartedAt),
        });

        for (const button of choices.querySelectorAll<HTMLButtonElement>(
            '.kq-quiz__option',
        )) {
            const id = button.dataset.optionId;
            button.disabled = true;
            const mark = button.querySelector('.kq-quiz__mark');
            if (id === result.selected) {
                button.classList.add(
                    result.correct ? 'is-correct' : 'is-wrong',
                );
                if (mark) {
                    mark.textContent = result.correct ? '✓' : '✗';
                }
            } else if (id === result.correctOptionId && options.revealAnswer) {
                button.classList.add('is-answer');
                if (mark) {
                    mark.textContent = '✓';
                }
            }
        }

        feedback.textContent = result.correct ? '✓ 答對了！' : '✗ 答錯了';
        feedback.classList.add(result.correct ? 'is-correct' : 'is-wrong');

        if (options.autoAdvance) {
            scheduleAdvance(
                result.correct
                    ? ADVANCE_DELAY_MS.correct
                    : ADVANCE_DELAY_MS.wrong,
            );
        } else {
            nextButton.hidden = false;
            nextButton.focus();
        }
    }

    function scheduleAdvance(delay: number): void {
        pendingAdvance = true;
        if (!paused) {
            advanceTimer = setTimeout(advance, delay);
        }
    }

    function advance(): void {
        clearTimeout(advanceTimer);
        pendingAdvance = false;
        ctx.audio.stopAll();
        session.next();
        if (session.finished) {
            finish();
        } else {
            showRound();
        }
    }

    function finish(): void {
        progress.textContent = '';
        prompt.replaceChildren();
        choices.replaceChildren();
        nextButton.hidden = true;
        feedback.textContent = '完成！';
        ctx.emit({
            type: 'completed',
            gameScore: session.score,
            durationMs: Math.round(performance.now() - startedAt),
        });
    }

    function onKey(event: KeyboardEvent): void {
        if (paused) {
            return;
        }
        if (event.key === 'Enter' && !nextButton.hidden) {
            advance();
            return;
        }
        const index = /^[1-6]$/.test(event.key)
            ? Number(event.key) - 1
            : LETTERS.indexOf(event.key.toUpperCase());
        const option = session.current?.options[index];
        if (option) {
            choose(option.id);
        }
    }

    nextButton.addEventListener('click', advance);
    document.addEventListener('keydown', onKey);

    ctx.emit({ type: 'started' });
    if (session.finished) {
        finish();
    } else {
        showRound();
    }

    return {
        destroy() {
            clearTimeout(advanceTimer);
            document.removeEventListener('keydown', onKey);
            ctx.audio.stopAll();
            root.remove();
        },
        pause() {
            paused = true;
            clearTimeout(advanceTimer);
        },
        resume() {
            paused = false;
            if (pendingAdvance) {
                advanceTimer = setTimeout(advance, ADVANCE_DELAY_MS.correct);
            }
        },
    };
}

export const quiz: GameModule<QuizOptions> = { ...meta, mount };
export default quiz;
