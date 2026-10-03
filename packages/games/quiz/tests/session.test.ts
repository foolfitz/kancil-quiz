import type { Round } from '@kancil-quiz/games-sdk';
import { describe, expect, it } from 'vite-plus/test';
import { QuizSession, mcqRounds } from '../src/session';

const rounds: Round[] = [
    {
        shape: 'mcq',
        entryId: 'e1',
        prompt: { text: '「謝謝」的越南語是？' },
        options: [
            { id: 'a', face: { text: 'Cảm ơn' }, correct: true },
            { id: 'b', face: { text: 'Xin chào' }, correct: false },
        ],
    },
    {
        shape: 'mcq',
        entryId: 'e2',
        prompt: { text: '「再見」的越南語是？' },
        options: [
            { id: 'a', face: { text: 'Cảm ơn' }, correct: false },
            { id: 'b', face: { text: 'Tạm biệt' }, correct: true },
        ],
    },
];

describe('QuizSession', () => {
    it('依序作答並計分', () => {
        const session = new QuizSession(mcqRounds(rounds));

        expect(session.answer('a')).toEqual({
            entryId: 'e1',
            selected: 'a',
            correct: true,
            correctOptionId: 'a',
        });
        session.next();
        expect(session.answer('a')).toMatchObject({
            entryId: 'e2',
            correct: false,
            correctOptionId: 'b',
        });
        session.next();

        expect(session.finished).toBe(true);
        expect(session.score).toBe(1);
    });

    it('每題只能作答一次', () => {
        const session = new QuizSession(mcqRounds(rounds));
        session.answer('b');
        expect(session.canAnswer).toBe(false);
        expect(session.answer('a')).toBeNull();
        expect(session.score).toBe(0);
    });

    it('還沒作答時不能跳到下一題', () => {
        const session = new QuizSession(mcqRounds(rounds));
        session.next();
        expect(session.index).toBe(0);
    });

    it('不認得的選項不算作答', () => {
        const session = new QuizSession(mcqRounds(rounds));
        expect(session.answer('z')).toBeNull();
        expect(session.canAnswer).toBe(true);
    });
});
