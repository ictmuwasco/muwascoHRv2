import api from '../../utils/api';

export const getConsentStatus = async () => {
  const response = await api.get('/consent/status');
  return response.data;
};

export const verifyEmployeeId = async (employeeId) => {
  const response = await api.post('/consent/verify-employee', { employee_id: employeeId });
  return response.data;
};

export const submitConsent = async (employeeId) => {
  const response = await api.post('/consent', { employee_id: employeeId, agreed: true });
  return response.data;
};