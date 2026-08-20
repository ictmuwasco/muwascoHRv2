import { useState, useEffect } from 'react';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import { Plus, Building2, Calendar } from 'lucide-react';
import type { FinancialYear } from '../../types';

const Admin = () => {
  const [financialYears, setFinancialYears] = useState<FinancialYear[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({ start_date: '', end_date: '' });

  useEffect(() => {
    fetchFinancialYears();
  }, []);

  const fetchFinancialYears = async () => {
    try {
      const response = await apiClient.get('/admin/financial-years');
      setFinancialYears(response.data.data || []);
    } catch (error) {
      console.error('Failed to fetch financial years:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await apiClient.post('/admin/financial-year/add', formData);
      setShowForm(false);
      setFormData({ start_date: '', end_date: '' });
      fetchFinancialYears();
    } catch (error) {
      console.error('Failed to create financial year:', error);
    }
  };

  const columns = [
    { key: 'year', label: 'Year' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    { key: 'days', label: 'Days' },
    { key: 'status', label: 'Status' },
    { key: 'period', label: 'Period' },
  ];

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Admin Panel</h1>
          <p className="text-gray-500">Financial year management & leave allocation</p>
        </div>
        <Button onClick={() => setShowForm(!showForm)}>
          <Plus className="h-4 w-4 mr-2" />
          Add Financial Year
        </Button>
      </div>

      {showForm && (
        <Card>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                label="Start Date"
                type="date"
                value={formData.start_date}
                onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                required
              />
              <Input
                label="End Date"
                type="date"
                value={formData.end_date}
                onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                required
              />
            </div>
            <div className="flex justify-end space-x-3">
              <Button variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
              <Button type="submit">Create Financial Year</Button>
            </div>
          </form>
        </Card>
      )}

      <Card title="Financial Years">
        <Table columns={columns} data={financialYears} />
      </Card>
    </div>
  );
};

export default Admin;
