import api from '../../utils/api';

export const getFinancialYears = async () => {
  const response = await api.get('/admin/financial-years');
  return response.data;
};

export const getFinancialYearStatus = async () => {
  const response = await api.get('/admin/financial-years/status');
  return response.data;
};

export const createFinancialYear = async (data) => {
  // Financial year creation triggers leave allocation for all employees,
  // which can take longer than the default 15s timeout.
  const response = await api.post('/admin/financial-year/add', data, {
    timeout: 120000, // 2 minutes
  });
  return response.data;
};

export const allocateLeaveToEmployee = async (data) => {
  const response = await api.post('/admin/financial-year/allocate', data);
  return response.data;
};

export const getLeaveTypes = async () => {
  const response = await api.get('/admin/financial-years/leave-types');
  return response.data;
};

export const getEmployees = async () => {
  const response = await api.get('/admin/financial-years/employees');
  return response.data;
};