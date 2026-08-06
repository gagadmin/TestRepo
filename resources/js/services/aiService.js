import http, { normalizeError } from './http';

/**
 * AI reporting workspace.
 */
export const aiService = {
    /** Status and conversation list are always needed together. */
    async workspace() {
        try {
            const [status, conversations] = await Promise.all([
                http.get('/api/ai/status'),
                http.get('/api/ai/conversations'),
            ]);

            return {
                status: status.data,
                conversations: conversations.data.data,
            };
        } catch (error) {
            const normalized = normalizeError(
                error,
                'The AI reporting workspace could not be loaded.',
            );

            if (normalized.isForbidden) {
                normalized.message = 'Your account is not authorized to use AI reporting.';
            }

            throw normalized;
        }
    },

    async conversation(conversationId) {
        try {
            const { data } = await http.get(`/api/ai/conversations/${conversationId}`);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The conversation could not be loaded.');
        }
    },

    async destroyConversation(conversationId) {
        try {
            await http.delete(`/api/ai/conversations/${conversationId}`);
        } catch (error) {
            throw normalizeError(error, 'The conversation could not be removed.');
        }
    },

    async chat(payload) {
        try {
            const { data } = await http.post('/api/ai/chat', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The assistant could not answer that request.');
        }
    },
};

export default aiService;
