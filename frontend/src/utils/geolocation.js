/**
 * Geolocation Utility
 *
 * Wraps the HTML5 Geolocation API with a Promise-based interface,
 * proper error handling, and configurable timeouts.
 *
 * Uses a two-stage fallback strategy:
 *   1. First tries high-accuracy GPS (fast timeout)
 *   2. Falls back to low-accuracy (network-based) if high-accuracy times out
 */

const HIGH_ACCURACY_TIMEOUT = 8000
const LOW_ACCURACY_TIMEOUT = 10000
const LAST_RESORT_TIMEOUT = 15000
const MAXIMUM_AGE = 60000 // Allow cached positions up to 1 minute old
const STALE_FIX_MAX_AGE = 900000 // Last resort: accept fixes up to 15 min old

/**
 * Escalating acquisition plan tuned for ALL devices. Stage 1 satisfies GPS
 * radios (phones); desktops usually fall through to Wi-Fi / IP-based stages
 * with increasingly generous stale-fix tolerance. Worst case ~33 s before we
 * surface the failure - callers pair this with a visible "getting location"
 * spinner so users understand the wait.
 */
const ACQUISITION_ATTEMPTS = [
  { enableHighAccuracy: true, timeout: HIGH_ACCURACY_TIMEOUT, maximumAge: MAXIMUM_AGE },
  { enableHighAccuracy: false, timeout: LOW_ACCURACY_TIMEOUT, maximumAge: 300000 },
  { enableHighAccuracy: false, timeout: LAST_RESORT_TIMEOUT, maximumAge: STALE_FIX_MAX_AGE },
]

/**
 * Translate a GeolocationPositionError into a stable reason code plus
 * user-friendly guidance.
 */
const describeLocationError = (error) => {
  switch (error.code) {
    case error.PERMISSION_DENIED:
      return {
        code: 'DENIED',
        message:
          'Location permission denied. Allow location for this site in your browser address bar, then try again.',
      }
    case error.POSITION_UNAVAILABLE:
      return {
        code: 'UNAVAILABLE',
        message: 'Your device could not determine its position right now.',
      }
    case error.TIMEOUT:
      return {
        code: 'TIMEOUT',
        message: 'No location fix was received in time.',
      }
    default:
      return { code: 'ERROR', message: 'Unable to determine your location.' }
  }
}

/**
 * Request the device location WITHOUT throwing.
 *
 * Two-stage strategy tuned for ALL devices:
 *   Stage 1 - high accuracy (GPS radios on phones/tablets), short window.
 *   Stage 2 - low accuracy (Wi-Fi / IP based - what desktops rely on),
 *             longer window and tolerance for slightly stale fixes.
 *
 * Resolves either:
 *   { ok:true,  lat, lng, accuracy, timestamp }
 *   { ok:false, code:'DENIED'|'TIMEOUT'|'UNAVAILABLE'|'ERROR'|'UNSUPPORTED', message }
 *
 * Desktops without GPS/Wi-Fi can still fail both stages; callers should
 * offer their own fallback (see Dashboard's "continue without location").
 */
export const requestLocation = () => {
  return new Promise((resolve) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      resolve({
        ok: false,
        code: 'UNSUPPORTED',
        message: 'Geolocation is not supported by this browser',
      })
      return
    }

    let settled = false
    const finish = (result) => {
      if (!settled) {
        settled = true
        resolve(result)
      }
    }

    const onSuccess = (position) => {
      finish({
        ok: true,
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        accuracy: position.coords.accuracy,
        timestamp: position.timestamp,
      })
    }

    // Remember why the last attempt failed so the final reason is accurate.
    let lastReason = { code: 'TIMEOUT', message: 'No location fix was received in time.' }

    const attempt = (index) => {
      if (settled) return

      if (index >= ACQUISITION_ATTEMPTS.length) {
        finish({ ok: false, code: lastReason.code, message: lastReason.message })
        return
      }

      navigator.geolocation.getCurrentPosition(
        onSuccess,
        (error) => {
          const info = describeLocationError(error)

          // A denied permission will never improve by retrying.
          if (info.code === 'DENIED') {
            finish({ ok: false, ...info })
            return
          }

          lastReason = info
          attempt(index + 1)
        },
        ACQUISITION_ATTEMPTS[index]
      )
    }

    attempt(0)
  })
}

/**
 * Throwing variant kept for backward compatibility with existing callers.
 *
 * @returns {Promise<{lat: number, lng: number, accuracy: number, timestamp: number}>}
 */
export const getCurrentPosition = async () => {
  const result = await requestLocation()
  if (!result.ok) {
    throw new Error(result.message)
  }
  return result
}

/**
 * Check if geolocation is available.
 */
export const isGeolocationSupported = () => {
  return typeof navigator !== 'undefined' && !!navigator.geolocation
}