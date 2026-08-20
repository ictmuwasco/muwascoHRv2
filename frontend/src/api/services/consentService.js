import api from '../../utils/api';

const CONSENT_CACHE_KEY = 'consent_status_cache';

/**
 * Cached consent helpers.
 *
 * The consent cache is keyed by user ID so that different users on the
 * same browser don't share each other's consent status.
 *
 * Once a user has successfully given consent, their status is cached here.
 * ProtectedRoute uses this cache to skip the redundant server-side consent
 * check on subsequent logins — avoiding the race condition where the
 * session cookie hasn't propagated yet, which previously caused users who
 * had already consented to be redirected back to the consent page.
 */

/**
 * Get the cached consent status for a user.
 *
 * @param {number|string} userId
 * @returns {{consented: boolean, version: string, timestamp: number}|null}
 */
export const getCachedConsent = (userId) => {
  if (!userId) return null;

  try {
    const raw = localStorage.getItem(CONSENT_CACHE_KEY);
    if (!raw) return null;

    const cache = JSON.parse(raw);
    const entry = cache[String(userId)];

    if (entry && entry.consented) {
      return entry;
    }

    return null;
  } catch {
    return null;
  }
};

/**
 * Save the cached consent status for a user.
 *
 * @param {number|string} userId
 * @param {string} version  The consent version that was accepted (e.g. '1.0')
 */
export const saveCachedConsent = (userId, version) => {
  if (!userId) return;

  try {
    const raw = localStorage.getItem(CONSENT_CACHE_KEY);
    const cache = raw ? JSON.parse(raw) : {};

    cache[String(userId)] = {
      consented: true,
      version,
      timestamp: Date.now(),
    };

    localStorage.setItem(CONSENT_CACHE_KEY, JSON.stringify(cache));
  } catch {
    // Ignore storage errors (e.g. private mode)
  }
};

/**
 * Remove the cached consent status for a user.
 *
 * @param {number|string} userId
 */
export const clearCachedConsent = (userId) => {
  if (!userId) return;

  try {
    const raw = localStorage.getItem(CONSENT_CACHE_KEY);
    if (!raw) return;

    const cache = JSON.parse(raw);
    delete cache[String(userId)];
    localStorage.setItem(CONSENT_CACHE_KEY, JSON.stringify(cache));
  } catch {
    // Ignore storage errors
  }
};

export const getConsentStatus = async () => {
  const response = await api.get('/consent/status');
  return response.data;
};

export const verifyEmployeeId = async (nationalId) => {
  try {
    const response = await api.post('/consent/verify-employee', { employee_id: nationalId });
    return response.data;
  } catch (err) {
    let errorMessage = 'Unable to verify National ID. Please try again.';

    if (err && typeof err === 'object' && 'response' in err) {
      const axiosError = err;
      if (axiosError.response && axiosError.response.data && axiosError.response.data.message) {
        errorMessage = axiosError.response.data.message;
      }
    }

    throw new Error(errorMessage);
  }
};

export const submitConsent = async (employeeId) => {
  const response = await api.post('/consent', { employee_id: employeeId, agreed: true });
  return response.data;
};

// HR Dashboard methods
export const getConsentDashboard = async () => {
  const response = await api.get('/consent/dashboard');
  return response.data;
};

export const getEmployeeConsentList = async (filters = {}) => {
  const params = new URLSearchParams();

  if (filters.status && filters.status !== 'all') params.append('status', filters.status);
  if (filters.department_id) params.append('department_id', filters.department_id);
  if (filters.section_id) params.append('section_id', filters.section_id);
  if (filters.search) params.append('search', filters.search);
  if (filters.date_from) params.append('date_from', filters.date_from);
  if (filters.date_to) params.append('date_to', filters.date_to);
  if (filters.page) params.append('page', filters.page);
  if (filters.per_page) params.append('per_page', filters.per_page);

  const response = await api.get(`/consent/employees?${params.toString()}`);
  return response.data;
};