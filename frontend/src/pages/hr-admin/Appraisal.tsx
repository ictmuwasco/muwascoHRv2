import { useState, useEffect } from 'react';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { Plus, CheckCircle, XCircle } from 'lucide-react';
import type { Appraisal } from '../../types';

const Appraisal = () => {
  const [appraisals, setAppraisals] = useState<Appraisal[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchAppraisals();
  }, []);

  const fetchAppraisals = async () => {
    try {
      const response = await apiClient.get('/appraisals');
      setAppraisals(response.data.data || []);
    } catch (error) {
      console.error('Failed to fetch appraisals:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleApprove = async (id: number) => {
    try {
      await apiClient.put(`/appraisals/${id}/approve`);
      fetchAppraisals();
    } catch (error) {
      console.error('Failed to approve appraisal:', error);
    }
  };

  const columns = [
    { key: 'employee_name', label: 'Employee' },
    { key: 'cycle_name', label: 'Cycle' },
    { key: 'supervisor_name', label: 'Supervisor' },
    { key: 'overall_score', label: 'Score' },
    {
      key: 'status',
      label: 'Status',
      render: (value: string) => (
        <Badge variant={value === 'Completed' ? 'success' : value === 'In Progress' ? 'warning' : 'default'}>
          {value}
        </Badge>
      ),
    },
    {
      key: 'id',
      label: 'Actions',
      render: (_: any, row: Appraisal) => (
        <div className="flex space-x-2">
          {row.status === 'Pending' && (
            <Button size="sm" variant="success" onClick={() => handleApprove(row.id)}>
              <CheckCircle className="h-4 w-4" />
            </Button>
          )}
        </div>
      ),
    },
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
          <h1 className="text-2xl font-bold text-gray-900">Appraisal Management</h1>
          <p className="text-gray-500">Manage appraisal cycles and employee evaluations</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          New Appraisal
        </Button>
      </div>

      <Card title="Appraisals">
        <Table columns={columns} data={appraisals} />
      </Card>
    </div>
  );
};

export default Appraisal;
