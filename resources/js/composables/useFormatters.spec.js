import { describe, expect, it } from 'vitest';
import {
    ageSeverity, formatDuration, formatNumber, formatPercentage,
    humanise, scoreSeverity, severityFor, truncate,
} from './useFormatters';

describe('formatNumber', () => {
    it('renders an em dash for nullish rather than a misleading zero', () => {
        expect(formatNumber(null)).toBe('—');
        expect(formatNumber(undefined)).toBe('—');
        expect(formatNumber('not a number')).toBe('—');
    });

    it('keeps a real zero', () => {
        expect(formatNumber(0)).toBe('0');
    });

    it('separates thousands', () => {
        expect(formatNumber(1234567)).toBe((1234567).toLocaleString());
    });
});

describe('formatDuration', () => {
    it('handles the unknown case', () => {
        expect(formatDuration(null)).toBe('—');
    });

    it('picks the largest sensible unit', () => {
        expect(formatDuration(0.4)).toBe('<1 min');
        expect(formatDuration(45)).toBe('45 min');
        expect(formatDuration(150)).toBe('2.5 hrs');
        expect(formatDuration(2880)).toBe('2.0 days');
    });
});

describe('formatPercentage', () => {
    it('formats to one decimal by default', () => {
        expect(formatPercentage(96)).toBe('96.0%');
        expect(formatPercentage(82.35, 0)).toBe('82%');
        expect(formatPercentage(null)).toBe('—');
    });
});

describe('humanise', () => {
    it('converts snake_case into a title', () => {
        expect(humanise('credential_stuffing')).toBe('Credential Stuffing');
        expect(humanise('')).toBe('');
    });
});

describe('truncate', () => {
    it('leaves short text alone', () => {
        expect(truncate('short', 10)).toBe('short');
    });

    it('appends an ellipsis when cutting', () => {
        expect(truncate('abcdefghij', 5)).toBe('abcde…');
    });
});

describe('severity mapping', () => {
    it('is case-insensitive and falls back predictably', () => {
        expect(severityFor('securitySeverity', 'CRITICAL')).toBe('danger');
        expect(severityFor('connectionStatus', 'connected')).toBe('success');
        expect(severityFor('connectionStatus', 'unknown')).toBe('info');
        expect(severityFor('nonexistentMap', 'x', 'secondary')).toBe('secondary');
    });

    it('uses consistent age thresholds', () => {
        expect(ageSeverity(5)).toBe('success');
        expect(ageSeverity(14)).toBe('warn');
        expect(ageSeverity(30)).toBe('danger');
    });

    it('uses consistent score thresholds', () => {
        expect(scoreSeverity(95)).toBe('success');
        expect(scoreSeverity(80)).toBe('warn');
        expect(scoreSeverity(40)).toBe('danger');
        expect(scoreSeverity(null)).toBe('secondary');
    });
});
