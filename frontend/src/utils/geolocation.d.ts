/**
 * Type declarations for the geolocation utility.
 * Mirrors frontend/src/utils/geolocation.js
 */

export interface GeolocationPosition {
  lat: number
  lng: number
  accuracy: number
  timestamp: number
}

export interface GeolocationOptions {
  timeout?: number
  maximumAge?: number
  enableHighAccuracy?: boolean
}

/** Why acquisition failed. */
export type LocationFailureCode =
  | 'DENIED'
  | 'TIMEOUT'
  | 'UNAVAILABLE'
  | 'ERROR'
  | 'UNSUPPORTED'

export interface LocationSuccess {
  ok: true
  lat: number
  lng: number
  accuracy: number
  timestamp: number
}

export interface LocationFailure {
  ok: false
  code: LocationFailureCode
  message: string
}

/**
 * Discriminated union: narrow with `if (!result.ok)` to reach
 * `code` / `message`, otherwise `lat` / `lng` / `accuracy` are available.
 */
export type LocationResult = LocationSuccess | LocationFailure

/**
 * Request the device location without throwing.
 * Escalates GPS -> Wi-Fi/IP -> stale-fix attempts (~35 s worst case).
 */
export function requestLocation(): Promise<LocationResult>

/** Throwing convenience wrapper kept for backward compatibility. */
export function getCurrentPosition(options?: GeolocationOptions): Promise<GeolocationPosition>

export function isGeolocationSupported(): boolean