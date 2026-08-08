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

const HIGH_ACCURACY_TIMEOUT = 5000
const LOW_ACCURACY_TIMEOUT = 10000
const MAXIMUM_AGE = 60000 // Allow cached positions up to 1 minute old

/**
 * Get the user's current GPS position with fallback.
 *
 * @param {Object} options - Geolocation options
 * @returns {Promise<{lat: number, lng: number, accuracy: number}>}
 */
export const getCurrentPosition = (options = {}) => {
    const { maximumAge = MAXIMUM_AGE } = options

  return new Promise((resolve, reject) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      reject(new Error('Geolocation is not supported by this browser'))
      return
    }

    let settled = false

    const handleSuccess = (position) => {
      if (settled) return
      settled = true
      resolve({
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        accuracy: position.coords.accuracy,
        timestamp: position.timestamp,
      })
    }

    const handleError = (error) => {
      if (settled) return
      settled = true
      let message
      switch (error.code) {
        case error.PERMISSION_DENIED:
          message = 'Location permission denied. Please enable location access to clock in/out.'
          break
        case error.POSITION_UNAVAILABLE:
          message = 'Location unavailable. Please check your GPS signal and try again.'
          break
        case error.TIMEOUT:
          message = 'Location request timed out. Please move to an open area and try again.'
          break
        default:
          message = 'Unable to determine your location. Please try again.'
      }
      reject(new Error(message))
    }

    // Stage 1: Try high-accuracy GPS first (fast timeout)
    navigator.geolocation.getCurrentPosition(
      handleSuccess,
      (highAccuracyError) => {
        // If high-accuracy fails with timeout, fall back to low-accuracy
        if (highAccuracyError.code === highAccuracyError.TIMEOUT) {
          // Stage 2: Fall back to low-accuracy (network-based) with longer timeout
          navigator.geolocation.getCurrentPosition(
            handleSuccess,
            handleError,
            { enableHighAccuracy: false, timeout: LOW_ACCURACY_TIMEOUT, maximumAge }
          )
        } else {
          // Permission denied or position unavailable - no point retrying
          handleError(highAccuracyError)
        }
      },
      { enableHighAccuracy: true, timeout: HIGH_ACCURACY_TIMEOUT, maximumAge }
    )
  })
}

/**
 * Check if geolocation is available.
 */
export const isGeolocationSupported = () => {
  return typeof navigator !== 'undefined' && !!navigator.geolocation
}