import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  scrubStack,
  setRequestId,
  getRequestId,
  newFrontendErrorId,
  collectContext,
  reportClientError,
  friendlyError,
} from '../../utils/errorReporting';

describe('errorReporting utilities', () => {
  describe('scrubStack', () => {
    it('strips bearer tokens before leaving the browser', () => {
      const out = scrubStack('at fn (x.js:1)\nAuthorization: Bearer abc.def.hij');
      expect(out).not.toContain('abc.def.hij');
      expect(out).toContain('[REDACTED]');
    });

    it('strips credential-shaped query params', () => {
      const out = scrubStack('fetch /api?token=supersecret&page=2');
      expect(out).not.toContain('supersecret');
      expect(out).toContain('page=2');
    });

    it('bounds the payload size', () => {
      const out = scrubStack('x'.repeat(20000));
      expect(out.length).toBeLessThanOrEqual(8000);
    });
  });

  describe('correlation id handling', () => {
    it('adopts a valid server-issued request id (normalized uppercase)', () => {
      setRequestId('req_abcdefgh234567');
      expect(getRequestId()).toBe('req_ABCDEFGH234567'.toUpperCase());
    });

    it('rejects malformed ids (header injection attempts)', () => {
      setRequestId('evil\r\nX-Hack: 1' as unknown as string);
      const current = getRequestId();
      expect(/^req_/i.test(current)).toBe(true);
      expect(current.includes('\n')).toBe(false);

      // Restore a canonical value (stored uppercase, like the backend).
      setRequestId('req_TESTTESTTEST1');
      expect(getRequestId()).toBe('REQ_TESTTESTTEST1');
    });
  });

  describe('client context', () => {
    it('collects sanitized device/route context', () => {
      const ctx = collectContext() as Record<string, any>;
      expect(ctx.request_id).toMatch(/^req_/i);
      expect(ctx.frontend_error_id).toMatch(/^FE-/);
      expect(typeof ctx.route).toBe('string');
      expect(['mobile', 'tablet', 'desktop']).toContain(ctx.device_type);
    });
  });

  describe('reportClientError', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
      fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 201 }));
      vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
      vi.unstubAllGlobals();
    });

    it('ships a single report per unique signature', () => {
      reportClientError({ kind: 'react', message: 'UniqueBoom-1', stack: 'a\nframe-unique-1' });
      // Identical signature immediately afterwards is de-duplicated.
      reportClientError({ kind: 'react', message: 'UniqueBoom-1', stack: 'a\nframe-unique-1' });

      return new Promise<void>((resolve) => {
        setTimeout(() => {
          expect(fetchMock).toHaveBeenCalledTimes(1);
          const [url, init] = fetchMock.mock.calls[0];
          expect(url).toBe('/api/system/client-errors');
          const body = JSON.parse(init.body);
          expect(body.kind).toBe('react');
          expect(body.severity).toBe('HIGH');
          resolve();
        }, 10);
      });
    });

    it('never throws even when fetch rejects', () => {
      fetchMock.mockRejectedValueOnce(new Error('offline'));
      expect(() =>
        reportClientError({ kind: 'network', message: 'DistinctOffline-1', stack: 'q\nz-1' })
      ).not.toThrow();
    });
  });

  describe('friendlyError', () => {
    it('gives users a generic message plus reference code', () => {
      const f = friendlyError();
      expect(f.title).toContain('went wrong');
      expect(f.reference).toMatch(/^req_/i);
    });
  });

  describe('newFrontendErrorId', () => {
    it('is unique per call', () => {
      expect(newFrontendErrorId()).not.toBe(newFrontendErrorId());
    });
  });
});
