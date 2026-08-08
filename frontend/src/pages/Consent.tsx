import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { getConsentStatus } from '../api/services/consentService';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import { ShieldCheck, CheckCircle, XCircle, FileText, History } from 'lucide-react';

const Consent = () => {
  const navigate = useNavigate();
  const [status, setStatus] = useState<{
    consented: boolean;
    consent_version: string;
  } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    fetchStatus();
  }, []);

  const fetchStatus = async () => {
    try {
      const response = await getConsentStatus();
      setStatus({
        consented: response.consented,
        consent_version: response.consent_version,
      });
    } catch (err) {
      console.error('Failed to fetch consent status:', err);
      setError('Failed to load consent status');
    } finally {
      setLoading(false);
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
        <h1 className="text-2xl font-bold text-gray-900">Data Protection & Consent</h1>
        <p className="text-gray-500">Your data protection consent status</p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-400 rounded-lg p-4">
          <p className="text-sm text-red-800">{error}</p>
        </div>
      )}

      {/* Status card */}
      <Card title="Consent Status">
        <div className="flex items-center gap-4 p-4">
          <div
            className={`w-14 h-14 rounded-full flex items-center justify-center ${
              status?.consented ? 'bg-green-100' : 'bg-yellow-100'
            }`}
          >
            {status?.consented ? (
              <CheckCircle className="w-7 h-7 text-green-600" />
            ) : (
              <XCircle className="w-7 h-7 text-yellow-600" />
            )}
          </div>
          <div>
            <p className="text-lg font-semibold text-gray-900">
              {status?.consented ? 'Consent Provided' : 'Consent Not Provided'}
            </p>
            <p className="text-sm text-gray-500">
              Consent Version: {status?.consent_version || 'N/A'}
            </p>
          </div>
        </div>
      </Card>

      {/* Actions */}
      <div className="flex flex-col sm:flex-row gap-3">
        <Button onClick={() => navigate('/data-protection-consent')}>
          <FileText className="w-4 h-4 mr-2" />
          View Data Protection Notice
        </Button>
        {!status?.consented && (
          <Button variant="success" onClick={() => navigate('/data-protection-consent')}>
            <ShieldCheck className="w-4 h-4 mr-2" />
            Provide Consent
          </Button>
        )}
      </div>

      {/* Consent history placeholder */}
      <Card title="Consent History">
        <div className="flex items-center gap-2 text-sm text-gray-500 py-4">
          <History className="w-4 h-4" />
          {status?.consented
            ? `You have accepted consent version ${status.consent_version}.`
            : 'No consent records found for the current version.'}
        </div>
      </Card>
    </div>
  );
};

export default Consent;