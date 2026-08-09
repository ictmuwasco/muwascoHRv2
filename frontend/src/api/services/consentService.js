import api from '../../utils/api';

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