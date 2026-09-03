/**
 * Assistant answer presentation.
 *
 * The reporting assistant replies in Markdown — bold figures, bullet lists, an
 * italic caveat when a value could not be retrieved. The chat stream rendered
 * that through `{{ message.content }}`, so the reader saw literal
 * `**Total clicks:**` and `-` bullets. Rendering it is a legibility fix for a
 * management audience, not decoration.
 *
 * Model output is untrusted: it can echo text lifted from a tool result, which
 * itself came from an external API. So this deliberately does not hand the
 * source to a Markdown library that permits raw HTML. Every character is
 * escaped first and only then are the few tags below introduced, which makes
 * the result safe for v-html by construction rather than by sanitiser.
 */

const ESCAPES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/**
 * Placeholder sentinel for inline code, so its contents dodge emphasis parsing.
 * Angle brackets are already entities by the time this runs -- escapeHtml turned
 * every one into &lt; -- so a bracketed marker cannot collide with anything left
 * in the text.
 */
const CODE_MARK_OPEN = '<code-';

const CODE_MARK_CLOSE = '>';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

function renderInline(text) {
    const code = [];

    let inline = text.replace(/`([^`]+)`/g, (match, content) => {
        code.push(content);

        return `${CODE_MARK_OPEN}${code.length - 1}${CODE_MARK_CLOSE}`;
    });

    inline = inline
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>');

    return inline.replace(
        new RegExp(`${CODE_MARK_OPEN}(\\d+)${CODE_MARK_CLOSE}`, 'g'),
        (match, index) => `<code>${code[Number(index)]}</code>`,
    );
}

/** Bulleted and numbered items share a shape so a run of either can be grouped. */
function listItem(line) {
    const bullet = line.match(/^\s*[-*]\s+(.*)$/);

    if (bullet) return { type: 'ul', text: bullet[1] };

    const ordered = line.match(/^\s*\d+[.)]\s+(.*)$/);

    if (ordered) return { type: 'ol', text: ordered[1] };

    return null;
}

/**
 * Markdown subset to an HTML string. Covers the constructs the assistant
 * actually emits: headings, bullet and numbered lists, paragraphs, rules, bold,
 * italic and inline code. Anything else survives as escaped plain text.
 */
export function renderAnswer(content) {
    const lines = escapeHtml(content).replace(/\r\n?/g, '\n').split('\n');
    const html = [];
    let list = null;
    let paragraph = [];

    const closeList = () => {
        if (!list) return;

        const items = list.items.map((item) => `<li>${renderInline(item)}</li>`).join('');

        html.push(`<${list.type}>${items}</${list.type}>`);
        list = null;
    };

    const closeParagraph = () => {
        if (!paragraph.length) return;

        html.push(`<p>${paragraph.map(renderInline).join('<br>')}</p>`);
        paragraph = [];
    };

    for (const line of lines) {
        const trimmed = line.trim();

        if (trimmed === '') {
            closeParagraph();
            closeList();
            continue;
        }

        if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
            closeParagraph();
            closeList();
            html.push('<hr>');
            continue;
        }

        const heading = trimmed.match(/^#{1,6}\s+(.*)$/);

        if (heading) {
            closeParagraph();
            closeList();
            html.push(`<h4>${renderInline(heading[1])}</h4>`);
            continue;
        }

        const item = listItem(line);

        if (item) {
            closeParagraph();

            if (list && list.type !== item.type) closeList();
            if (!list) list = { type: item.type, items: [] };

            list.items.push(item.text);
            continue;
        }

        closeList();
        paragraph.push(trimmed);
    }

    closeParagraph();
    closeList();

    return html.join('');
}

/**
 * Response time for a business reader. The raw `6529 ms` badge was engineering
 * telemetry sitting beside the assistant's name; seconds to one decimal say the
 * same thing without inviting a performance conversation.
 */
export function formatLatency(milliseconds) {
    const value = Number(milliseconds);

    if (!Number.isFinite(value) || value <= 0) return '';
    if (value < 1000) return '<1s';

    return `${(value / 1000).toFixed(1)}s`;
}

export function useAnswerFormat() {
    return { renderAnswer, formatLatency };
}

export default useAnswerFormat;
