/**
 * Stable, opaque per-browser device identifier for attendance clock-in.
 *
 * PRIVACY: this is a RANDOM value generated once and kept in localStorage. It
 * deliberately contains no hardware, fingerprinting or personal data - no
 * canvas, audio, screen or hardware probing. It is a label, not a tracker, and
 * it leaves the browser only as part of the clock-in request.
 *
 * The server stores only a keyed hash of this value, so it cannot be used to
 * identify a person or a device from a database dump. It exists solely so the
 * backend can tell "same device clocked in twice" from "two different devices".
 *
 * Because it lives in localStorage, clearing site data or using a private
 * window produces a new ID and therefore bypasses the device lock. That is a
 * known limitation of any browser-only approach; the lock is a friction and
 * audit control, not a security boundary.
 */
const DEVICE_ID_STORAGE_KEY = 'hrdemo_device_id';

export const getDeviceId = (): string => {
  try {
    const existing = localStorage.getItem(DEVICE_ID_STORAGE_KEY);
    if (existing) return existing;
  } catch {
    // localStorage can throw in private modes or when storage is disabled.
    // Fall through and return a per-session id rather than breaking clock-in.
  }

  const generated =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `dev-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;

  try {
    localStorage.setItem(DEVICE_ID_STORAGE_KEY, generated);
  } catch {
    // Non-fatal: the id still works for this session.
  }

  return generated;
};

export default getDeviceId;
