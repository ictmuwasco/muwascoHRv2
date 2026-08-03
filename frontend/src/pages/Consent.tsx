import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import { CheckCircle, XCircle } from 'lucide-react';
import type { Consent } from '../types';

const Consent = () => {
  const [consents, setConsents] = useState<Consent[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchConsents();
  }, []);

  const fetchConsents = async () => {
    try {
      const response = await apiClient.get('/consents');
      setConsents(response.data.data || []);
    } catch (error) {
      console.error('Failed to fetch consents:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleToggle = async (id: number, currentStatus: boolean) => {
    try {
      await apiClient.put(`/consents/${id}`, { status: !currentStatus });
      fetchConsents();
    } catch (error) {
      console.error('Failed to update consent:', error);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Consent Management</h1>
        <p className="text-gray-500">Manage user consent and agreements</p>
      </div>

      <Card title="User Consents">
        <div className="space-y-4">
          {consents.map((consent) => (
            <div key={consent.id} className="flex items-center justify-between p-4 border rounded-lg">
              <div>
                <p className="font-medium text-gray-900">{consent.type}</p>
                <p className="text-sm text-gray-500">
                  Status: {consent.status ? 'Agreed' : 'Not Agreed'} | 
                  IP: {consent.ip_address} | 
                  Date: {consent.agreed_at}
                </p>
              </div>
              <Button
                variant={consent.status ? 'success' : 'secondary'}
                onClick={() => handleToggle(consent.id, consent.status)}
              >
                {consent.status ? (
                  <><CheckCircle className="h-4 w-4 mr-2" /> Agreed</>
                ) : (
                  <><XCircle className="h-4 w-4 mr-2" /> Pending</>
                )}
              </Button>
            </div>
          ))}
          {consents.length === 0 && (
            <p className="text-gray-500 text-center py-4">No consents found</p>
          )}
        </div>
      </Card>
    </div>
  );
};

export default Consent;