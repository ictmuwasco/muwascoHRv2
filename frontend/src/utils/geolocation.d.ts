/**
 * Type declarations for the geolocation utility.
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

export function getCurrentPosition(options?: GeolocationOptions): Promise<GeolocationPosition>
export function isGeolocationSupported(): boolean