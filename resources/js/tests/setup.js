/**
 * Vitest global setup.
 *
 * Keeps tests deterministic: no real network, no real timers leaking between
 * specs, and a stable window.location for router/deep-link assertions.
 */
import { vi } from 'vitest';

// Nothing in the unit suite may reach the network. Any component or service
// that tries to will fail loudly rather than silently hitting a real host.
vi.mock('axios', () => {
    const instance = {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        interceptors: {
            request: { use: vi.fn() },
            response: { use: vi.fn() },
        },
        defaults: { headers: { common: {} } },
    };

    return {
        default: {
            ...instance,
            create: vi.fn(() => instance),
        },
    };
});

// PrimeVue components are not under test; stub the CSS side effects.
vi.mock('primeicons/primeicons.css', () => ({}));
