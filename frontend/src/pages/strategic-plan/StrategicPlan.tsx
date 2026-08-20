import { useState, useEffect } from 'react';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import { Plus, Target, CheckCircle } from 'lucide-react';
import type { StrategicPlan, Workplan, KPI } from '../../types';

const StrategicPlan = () => {
  const [plans, setPlans] = useState<StrategicPlan[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedPlan, setSelectedPlan] = useState<number | null>(null);
  const [workplans, setWorkplans] = useState<Workplan[]>([]);

  useEffect(() => {
    fetchPlans();
  }, []);

  const fetchPlans = async () => {
    try {
      const response = await apiClient.get('/strategic-plans');
      setPlans(response.data.data || []);
    } catch (error) {
      console.error('Failed to fetch strategic plans:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchWorkplans = async (planId: number) => {
    try {
      const response = await apiClient.get(`/strategic-plans/${planId}/workplans`);
      setWorkplans(response.data.data || []);
      setSelectedPlan(planId);
    } catch (error) {
      console.error('Failed to fetch workplans:', error);
    }
  };

  const columns = [
    { key: 'name', label: 'Plan Name' },
    { key: 'description', label: 'Description' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    {
      key: 'status',
      label: 'Status',
      render: (value: string) => (
        <Badge variant={value === 'Active' ? 'success' : value === 'Draft' ? 'warning' : 'default'}>
          {value}
        </Badge>
      ),
    },
  ];

  const workplanColumns = [
    { key: 'objective', label: 'Objective' },
    { key: 'activities', label: 'Activities' },
    { key: 'target_date', label: 'Target Date' },
    {
      key: 'status',
      label: 'Status',
      render: (value: string) => (
        <Badge variant={value === 'Completed' ? 'success' : value === 'In Progress' ? 'warning' : 'default'}>
          {value}
        </Badge>
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
          <h1 className="text-2xl font-bold text-gray-900">Strategic Plan</h1>
          <p className="text-gray-500">Manage strategic plans, workplans, and KPIs</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          New Plan
        </Button>
      </div>

      <Card title="Strategic Plans">
        <Table columns={columns} data={plans} />
      </Card>

      {selectedPlan && (
        <Card title={`Workplans - ${plans.find((p) => p.id === selectedPlan)?.name || ''}`}>
          <Table columns={workplanColumns} data={workplans} />
        </Card>
      )}
    </div>
  );
};

export default StrategicPlan;
