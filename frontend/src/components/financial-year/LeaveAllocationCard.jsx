import React, { useState, useEffect } from 'react';
import Button from '../ui/Button';
import { getLeaveTypes, getEmployees, allocateLeaveToEmployee } from '../../api/services/financialYearService';

const LeaveAllocationCard = ({ financialYears }) => {
  const [leaveTypes, setLeaveTypes] = useState([]);
  const [employees, setEmployees] = useState([]);
  const [loading, setLoading] = useState(true);
  const [allocating, setAllocating] = useState(false);
  
  const [formData, setFormData] = useState({
    employee_id: '',
    financial_year_id: '',
    leave_types: [],
  });

  const [selectAll, setSelectAll] = useState(false);

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      const [typesRes, empRes] = await Promise.all([
        getLeaveTypes(),
        getEmployees(),
      ]);
      setLeaveTypes(typesRes.data || []);
      setEmployees(empRes.data || []);
    } catch (error) {
      console.error('Failed to fetch data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSelectAll = (checked) => {
    setSelectAll(checked);
    setFormData({
      ...formData,
      leave_types: checked ? leaveTypes.map(lt => lt.id) : [],
    });
  };

  const handleLeaveTypeChange = (leaveTypeId, checked) => {
    const newLeaveTypes = checked
      ? [...formData.leave_types, leaveTypeId]
      : formData.leave_types.filter(id => id !== leaveTypeId);
    
    setSelectAll(newLeaveTypes.length === leaveTypes.length);
    setFormData({ ...formData, leave_types: newLeaveTypes });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (!formData.employee_id || !formData.financial_year_id) {
      alert('Please select employee and financial year');
      return;
    }

    setAllocating(true);
    try {
      const result = await allocateLeaveToEmployee({
        employee_id: parseInt(formData.employee_id),
        financial_year_id: parseInt(formData.financial_year_id),
        leave_types: formData.leave_types.length > 0 ? formData.leave_types : null,
      });

      if (result.success) {
        alert(result.message);
        setFormData({
          employee_id: '',
          financial_year_id: '',
          leave_types: [],
        });
        setSelectAll(false);
      } else {
        alert('Failed: ' + result.message);
      }
    } catch (error) {
      alert('Error allocating leave');
      console.error(error);
    } finally {
      setAllocating(false);
    }
  };

  if (loading) {
    return (
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h3 className="text-lg font-semibold mb-4">Allocate Leave to Employee</h3>
        <div className="flex items-center justify-center h-32">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg shadow p-6 mb-6">
      <h3 className="text-lg font-semibold mb-2">Allocate Leave to Employee</h3>
      <p className="text-sm text-gray-500 mb-4">
        Use this for newly hired employees or to fill missing leave records. Existing records are automatically skipped.
      </p>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Select Employee <span className="text-red-500">*</span>
            </label>
            <select
              value={formData.employee_id}
              onChange={(e) => setFormData({ ...formData, employee_id: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500"
              required
            >
              <option value="">Select an employee</option>
              {employees.map((emp) => (
                <option key={emp.id} value={emp.id}>
                  {emp.full_name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Financial Year <span className="text-red-500">*</span>
            </label>
            <select
              value={formData.financial_year_id}
              onChange={(e) => setFormData({ ...formData, financial_year_id: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500"
              required
            >
              <option value="">Select financial year</option>
              {financialYears.map((fy) => (
                <option key={fy.id} value={fy.id}>
                  {fy.year_name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Leave Types <span className="text-xs text-gray-500">(leave unchecked to allocate all applicable types)</span>
          </label>
          <div className="border border-gray-300 rounded-md p-4">
            <div className="mb-3">
              <label className="flex items-center">
                <input
                  type="checkbox"
                  checked={selectAll}
                  onChange={(e) => handleSelectAll(e.target.checked)}
                  className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                />
                <span className="ml-2 text-sm font-medium text-gray-700">Select All</span>
              </label>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
              {leaveTypes.map((lt) => (
                <label key={lt.id} className="flex items-center">
                  <input
                    type="checkbox"
                    checked={formData.leave_types.includes(lt.id)}
                    onChange={(e) => handleLeaveTypeChange(lt.id, e.target.checked)}
                    className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                  />
                  <span className="ml-2 text-sm text-gray-700">{lt.name}</span>
                </label>
              ))}
            </div>
          </div>
        </div>

        <div className="flex items-center space-x-2">
          <Button type="submit" disabled={allocating} loading={allocating}>
            <svg className="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Allocate Leave
          </Button>
        </div>
      </form>
    </div>
  );
};

export default LeaveAllocationCard;