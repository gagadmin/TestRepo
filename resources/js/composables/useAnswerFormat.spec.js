import { describe, expect, it } from 'vitest';
import { formatLatency, renderAnswer } from './useAnswerFormat';

describe('renderAnswer', () => {
    it('renders the bold-label bullet shape the assistant actually replies with', () => {
        const answer = [
            'For **Aboudcar** for **today**, I checked **Google Search Console**.',
            '',
            '- **Total clicks:** *Not available from the returned dataset*',
            '- **Impressions:** 0',
        ].join('\n');

        const html = renderAnswer(answer);

        expect(html).toContain('<strong>Aboudcar</strong>');
        expect(html).toContain('<li><strong>Total clicks:</strong> <em>Not available from the returned dataset</em></li>');
        expect(html).toContain('<ul>');
        expect(html).not.toContain('**');
    });

    it('groups a run of list items into one list and closes it at a blank line', () => {
        const html = renderAnswer('- one\n- two\n\nClosing note.');

        expect(html).toBe('<ul><li>one</li><li>two</li></ul><p>Closing note.</p>');
    });

    it('keeps bulleted and numbered runs in separate lists', () => {
        const html = renderAnswer('- bullet\n1. first\n2. second');

        expect(html).toBe('<ul><li>bullet</li></ul><ol><li>first</li><li>second</li></ol>');
    });

    it('renders headings, rules and inline code', () => {
        expect(renderAnswer('## Summary')).toBe('<h4>Summary</h4>');
        expect(renderAnswer('---')).toBe('<hr>');
        expect(renderAnswer('Pass `date_to` explicitly.')).toBe('<p>Pass <code>date_to</code> explicitly.</p>');
    });

    it('does not treat emphasis markers inside inline code as emphasis', () => {
        expect(renderAnswer('Use `a*b*c` verbatim.')).toBe('<p>Use <code>a*b*c</code> verbatim.</p>');
    });

    /*
     * The assistant can echo text lifted from a tool result, which came from an
     * external API. Markup in that text must reach the DOM as characters, never
     * as tags, because the rendered string is handed to v-html.
     */
    it('escapes markup rather than emitting it', () => {
        const html = renderAnswer('<img src=x onerror=alert(1)> and <script>alert(2)</script>');

        expect(html).not.toContain('<img');
        expect(html).not.toContain('<script');
        expect(html).toContain('&lt;img');
        expect(html).toContain('&lt;script');
    });

    it('cannot be tricked into forging a code placeholder', () => {
        const html = renderAnswer('literal <code-0> marker with `real code`');

        expect(html).toContain('&lt;code-0&gt;');
        expect(html).toContain('<code>real code</code>');
    });

    it('returns an empty string for empty or nullish content', () => {
        expect(renderAnswer('')).toBe('');
        expect(renderAnswer(null)).toBe('');
        expect(renderAnswer(undefined)).toBe('');
    });
});

describe('formatLatency', () => {
    it('reports seconds to one decimal', () => {
        expect(formatLatency(6529)).toBe('6.5s');
        expect(formatLatency(1000)).toBe('1.0s');
    });

    it('collapses sub-second responses', () => {
        expect(formatLatency(420)).toBe('<1s');
    });

    it('renders nothing when the measurement is missing', () => {
        expect(formatLatency(null)).toBe('');
        expect(formatLatency(0)).toBe('');
        expect(formatLatency('nope')).toBe('');
    });
});
