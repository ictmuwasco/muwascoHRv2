import { describe, it, expect, beforeEach } from 'vitest'
import {
  urlBase64ToUint8Array,
  wasPermissionDenied,
  isPushSupported,
  getPermissionState,
} from '../pushNotifications'

describe('pushNotifications utils', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  describe('urlBase64ToUint8Array', () => {
    it('decodes base64url to raw bytes', () => {
      // 'QUJD' == base64("ABC")
      const bytes = urlBase64ToUint8Array('QUJD')
      expect(Array.from(bytes)).toEqual([65, 66, 67])
    })

    it('maps URL-safe characters (- and _) back to + and /', () => {
      const standard = btoa(String.fromCharCode(0xfb, 0xef, 0xbe))
      const urlSafe = standard.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
      const bytes = urlBase64ToUint8Array(urlSafe)
      expect(Array.from(bytes)).toEqual([0xfb, 0xef, 0xbe])
    })

    it('tolerates missing padding', () => {
      const withoutPadding = urlBase64ToUint8Array('QQ') // "A"
      expect(withoutPadding.length).toBe(1)
      expect(withoutPadding[0]).toBe(65)
    })
  })

  describe('permission denial memory', () => {
    it('defaults to not-denied', () => {
      expect(wasPermissionDenied()).toBe(false)
    })

    it('remembers a denial via localStorage', () => {
      localStorage.setItem('hr_push_permission_denied', '1')
      expect(wasPermissionDenied()).toBe(true)
    })
  })

  describe('capability detection under jsdom', () => {
    it('reports unsupported when service worker / PushManager are absent', () => {
      expect(isPushSupported()).toBe(false)
      expect(getPermissionState()).toBe('unsupported')
    })
  })
})
