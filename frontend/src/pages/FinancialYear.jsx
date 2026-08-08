import React, { useState, useEffect } from 'react';
import {
  getFinancialYears,
  getFinancialYearStatus,
  createFinancialYear,
} from '../api/services/financialYearService';
import FinancialYearStatusCard from '../components/financial-year/FinancialYearStatusCard';
import CreateFinancialYearCard from '../components/financial-year/CreateFinancialYearCard';
import LeaveAllocationCard from '../components/financial-year/LeaveAllocationCard';
import FinancialYearTable from '../components/financial-year/FinancialYearTable';

const FinancialYear = () => {
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [financialYears, setFinancialYears] = useState([]);
  const [status, setStatus] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      const [yearsRes, statusRes] = await Promise.all([
        getFinancialYears(),
        getFinancialYearStatus(),
      ]);
      setFinancialYears(yearsRes.data || []);
      setStatus(statusRes.data?.status || null);
    } catch (error) {
      console.error('Failed to fetch data:', error);
      setError('Failed to load financial year data');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateFinancialYear = async (nextFY) => {
    setCreating(true);
    setError('');
    try {
      const result = await createFinancialYear({
        year_name: nextFY.year_name,
        start_date: nextFY.start_date,
        end_date: nextFY.end_date,
        total_days: nextFY.total_days,
      });

      if (result.success) {
        alert(result.message);
        await fetchData();
      } else {
        setError(result.message || 'Failed to create financial year');
      }
    } catch (error) {
      console.error('Create financial year error:', error);

      // Provide specific, actionable error messages
      let message = 'Failed to create financial year. Please try again.';

      if (error.code === 'ECONNABORTED') {
        message =
          'The request timed out. The financial year may have been created but leave allocation is still processing. Please refresh the page to check.';
      } else if (error.response) {
        const status = error.response.status;
        const data = error.response.data;

        if (status === 403) {
          message =
            data?.message || 'You do not have permission to create a financial year.';
        } else if (status === 422) {
          message = data?.message || 'Invalid data provided. Please check your input.';
        } else if (status === 500) {
          message =
            data?.message || 'Server error while creating the financial year. Please try again.';
        } else if (data?.message) {
          message = data.message;
        }
      } else if (!error.response) {
        message =
          'Cannot reach the server. Make sure XAMPP (Apache + MySQL) is running and try again.';
      }

      setError(message);
    } finally {
      setCreating(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Financial Year Management</h1>
          <p className="text-gray-500">Manage financial years and periods</p>
        </div>
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Financial Year Management</h1>
        <p className="text-gray-500">Manage financial years and periods</p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-400 rounded-lg p-4">
          <p className="text-sm text-red-800">{error}</p>
        </div>
      )}

      <FinancialYearStatusCard status={status} />

      <CreateFinancialYearCard
        canCreate={status?.exists ? false : true}
        nextFY={status?.next_financial_year}
        onCreate={handleCreateFinancialYear}
        creating={creating}
      />

      <LeaveAllocationCard financialYears={financialYears} />

      <FinancialYearTable financialYears={financialYears} />
    </div>
  );
};

export default FinancialYear;