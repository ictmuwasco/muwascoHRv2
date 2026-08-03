import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import { FileText, Download, BarChart3 } from 'lucide-react';

const Reports = () => {
  const [activeTab, setActiveTab] = useState('employees');

  const reportTypes = [
    { id: 'employees', label: 'Employee Reports', icon: FileText },
    { id: 'attendance', label: 'Attendance Reports', icon: BarChart3 },
    { id: 'leave', label: 'Leave Reports', icon: FileText },
    { id: 'appraisal', label: 'Appraisal Reports', icon: BarChart3 },
    { id: 'documentation', label: 'Documentation', icon: FileText },
  ];

  const handleExport = async (format: string) => {
    try {
      const response = await apiClient.get(`/reports/${activeTab}/export/${format}`, {
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `${activeTab}-report.${format}`);
      document.body.appendChild(link);
      link.click();
    } catch (error) {
      console.error('Failed to export report:', error);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Reports</h1>
        <p className="text-gray-500">Generate and view system reports</p>
      </div>

      {/* Report Type Tabs */}
      <div className="flex space-x-2 border-b">
        {reportTypes.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`flex items-center px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              activeTab === tab.id
                ? 'border-primary-600 text-primary-600'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <tab.icon className="h-4 w-4 mr-2" />
            {tab.label}
          </button>
        ))}
      </div>

      <Card title={`${reportTypes.find((t) => t.id === activeTab)?.label || 'Reports'}`}>
        <div className="space-y-4">
          <p className="text-gray-600">Generate reports for {activeTab} module.</p>
          <div className="flex space-x-3">
            <Button variant="outline" onClick={() => handleExport('pdf')}>
              <Download className="h-4 w-4 mr-2" />
              Export PDF
            </Button>
            <Button variant="outline" onClick={() => handleExport('csv')}>
              <Download className="h-4 w-4 mr-2" />
              Export CSV
            </Button>
            <Button variant="outline" onClick={() => handleExport('excel')}>
              <Download className="h-4 w-4 mr-2" />
              Export Excel
            </Button>
          </div>
        </div>
      </Card>
    </div>
  );
};

export default Reports;