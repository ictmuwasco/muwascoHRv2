import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { verifyEmployeeId, submitConsent } from '../api/services/consentService';
import {
  ShieldCheck,
  ChevronDown,
  ChevronUp,
  CheckCircle,
  XCircle,
  AlertCircle,
  UserCheck,
  LogOut,
} from 'lucide-react';

interface Section {
  id: string;
  title: string;
  content: React.ReactNode;
}

const sections: Section[] = [
  {
    id: 'collect',
    title: 'What information do we collect?',
    content: (
      <div className="space-y-2 text-sm text-gray-600">
        <p>
          The MUWASCO HR System collects and processes the following categories of
          personal information for legitimate HR and organizational purposes:
        </p>
        <ul className="list-disc list-inside space-y-1">
          <li>Employee identification information (name, employee number/ID, national ID)</li>
          <li>Contact information (email address, phone number)</li>
          <li>Employment information (department, section, job title, employment type)</li>
          <li>Attendance information (clock-in/out records, work hours)</li>
          <li>Location information when you use Clock In/Clock Out for attendance verification</li>
          <li>Leave information (leave applications, balances, approvals)</li>
          <li>Performance and appraisal information</li>
          <li>Login and authentication information (email, password hash)</li>
          <li>System activity and audit information (IP address, browser/device information where technically required)</li>
        </ul>
      </div>
    ),
  },
  {
    id: 'why',
    title: 'Why do we collect it?',
    content: (
      <div className="space-y-2 text-sm text-slate-600">
        <p>We process your information for the following purposes:</p>
        <ul className="list-disc list-inside space-y-1">
          <li>Employee identification and HR administration</li>
          <li>Attendance management and verification</li>
          <li>Leave management and processing</li>
          <li>Performance management and appraisals</li>
          <li>Internal reporting and organizational planning</li>
          <li>Security, access control, and preventing unauthorized system access</li>
          <li>Audit and accountability</li>
          <li>Compliance with applicable legal and organizational requirements</li>
        </ul>
        <p className="mt-2 text-xs text-slate-500">
          Some processing is based on your consent, while other processing is based on
          other lawful grounds such as the performance of your employment contract or
          compliance with legal obligations.
        </p>
      </div>
    ),
  },
  {
    id: 'location',
    title: 'Location information and attendance',
    content: (
      <div className="space-y-2 text-sm text-slate-600">
        <p>
          <strong>Important:</strong> When you use the Clock In or Clock Out feature,
          the system may request access to your device's location.
        </p>
        <ul className="list-disc list-inside space-y-1">
          <li>Your location is used to verify that you are within the permitted office radius.</li>
          <li>The system calculates the distance between your location and the registered office location.</li>
          <li>This information is used solely for attendance verification purposes.</li>
          <li>Your location is not collected unnecessarily outside the attendance process.</li>
        </ul>
      </div>
    ),
  },
  {
    id: 'rights',
    title: 'Your data protection rights',
    content: (
      <div className="space-y-2 text-sm text-slate-600">
        <p>
          Subject to applicable law, you have the following rights under the Kenya
          Data Protection Act, 2019:
        </p>
        <ul className="list-disc list-inside space-y-1">
          <li>Right to be informed about the use of your personal data</li>
          <li>Right to access your personal data</li>
          <li>Right to request correction of inaccurate or misleading data</li>
          <li>Right to request deletion/erasure where legally applicable</li>
          <li>Right to object to processing in circumstances provided by law</li>
          <li>Right to restriction of processing where applicable</li>
          <li>Right to data portability where applicable</li>
          <li>Right to withdraw consent where processing is based on consent, subject to applicable legal limitations</li>
          <li>Right to lodge a complaint with the Office of the Data Protection Commissioner (ODPC)</li>
        </ul>
        <p className="mt-2 text-xs text-slate-500">
          These rights are subject to applicable law and may be limited in certain
          circumstances. To exercise any of these rights, please contact your HR administrator.
        </p>
      </div>
    ),
  },
  {
    id: 'retention',
    title: 'Data retention and security',
    content: (
      <div className="space-y-2 text-sm text-slate-600">
        <p>
          Your personal data is stored securely and retained only for as long as
          necessary for the purposes described in this notice, or as required by
          applicable law. Access to your data is restricted to authorized personnel
          and is protected by appropriate technical and organizational measures.
        </p>
      </div>
    ),
  },
  {
    id: 'contact',
    title: 'Contact and complaints',
    content: (
      <div className="space-y-2 text-sm text-slate-600">
        <p>
          If you have any questions about this notice or how your personal data is
          handled, please contact your HR administrator or the MUWASCO HR Department.
        </p>
        <p>
          You also have the right to lodge a complaint with the Office of the Data
          Protection Commissioner (ODPC) at <span className="font-medium">www.odpc.go.ke</span>.
        </p>
      </div>
    ),
  },
];

const DataProtectionConsent = () => {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [openSection, setOpenSection] = useState<string | null>('collect');
  const [employeeId, setEmployeeId] = useState('');
  const [verified, setVerified] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [agreed, setAgreed] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [verifiedName, setVerifiedName] = useState('');

  const handleVerify = async () => {
    setError('');
    setSuccess('');
    if (!employeeId.trim()) {
      setError('Please enter your Employee ID.');
      return;
    }
    setVerifying(true);
    try {
      const result = await verifyEmployeeId(employeeId.trim());
      if (result.success) {
        setVerified(true);
        setVerifiedName(result.data?.employee_name || '');
        setSuccess(`Employee verified: ${result.data?.employee_name || ''}`);
      } else {
        setVerified(false);
        setError(result.message || 'Employee ID verification failed.');
      }
    } catch (err) {
      setVerified(false);
      setError('Unable to verify Employee ID. Please try again.');
    } finally {
      setVerifying(false);
    }
  };

  const handleSubmit = async () => {
    setError('');
    setSuccess('');
    if (!verified) {
      setError('Please verify your Employee ID first.');
      return;
    }
    if (!agreed) {
      setError('Please read and agree to the Data Protection Notice to continue.');
      return;
    }
    setSubmitting(true);
    try {
      const result = await submitConsent(employeeId.trim());
      if (result.success) {
        setSuccess('Consent recorded successfully. Redirecting to Dashboard...');
        setTimeout(() => navigate('/dashboard', { replace: true }), 1200);
      } else {
        setError(result.message || 'Failed to save consent. Please try again.');
      }
    } catch (err) {
      setError('Unable to save consent. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDecline = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  const toggleSection = (id: string) => {
    setOpenSection(openSection === id ? null : id);
  };

  return (
    <div className="min-h-screen bg-slate-50 py-8 px-4 sm:px-6">
      <div className="max-w-3xl mx-auto">
        {/* Header */}
        <div className="flex items-center justify-between mb-6">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-xl bg-primary-600 flex items-center justify-center">
              <ShieldCheck className="w-6 h-6 text-white" />
            </div>
            <div>
              <h1 className="text-xl font-bold text-slate-900">Data Protection & Consent</h1>
              <p className="text-sm text-slate-500">MUWASCO HR System</p>
            </div>
          </div>
          <button
            onClick={handleDecline}
            className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700"
          >
            <LogOut className="w-4 h-4" />
            Sign out
          </button>
        </div>

        {/* Notice intro */}
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
          <h2 className="text-lg font-semibold text-slate-900 mb-3">Data Protection Notice</h2>
          <p className="text-sm text-slate-600 leading-relaxed">
            MUWASCO collects and processes employee information for legitimate HR and
            organizational purposes in accordance with applicable Kenyan data protection
            requirements, including the <strong>Data Protection Act, 2019</strong> and
            applicable regulations. Please read this notice carefully before providing
            your consent.
          </p>
        </div>

        {/* Expandable sections */}
        <div className="space-y-3 mb-6">
          {sections.map((section) => (
            <div key={section.id} className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
              <button
                onClick={() => toggleSection(section.id)}
                className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-slate-50 transition"
              >
                <span className="font-medium text-slate-800">{section.title}</span>
                {openSection === section.id ? (
                  <ChevronUp className="w-5 h-5 text-slate-400" />
                ) : (
                  <ChevronDown className="w-5 h-5 text-slate-400" />
                )}
              </button>
              {openSection === section.id && (
                <div className="px-5 pb-5 border-t border-slate-100 pt-4">{section.content}</div>
              )}
            </div>
          ))}
        </div>

        {/* Employee ID verification */}
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
          <h3 className="font-semibold text-slate-900 mb-1">Verify Your Employee ID</h3>
          <p className="text-sm text-slate-500 mb-4">
            Enter your Employee ID to verify your identity before providing consent.
          </p>
          <div className="flex flex-col sm:flex-row gap-3">
            <input
              type="text"
              value={employeeId}
              onChange={(e) => {
                setEmployeeId(e.target.value);
                setVerified(false);
                setSuccess('');
              }}
              placeholder="Enter your Employee ID"
              className="flex-1 px-4 py-2.5 bg-white text-sm rounded-lg border border-slate-200 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500"
            />
            <button
              onClick={handleVerify}
              disabled={verifying || !employeeId.trim()}
              className="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
              {verifying ? (
                <>
                  <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                  Verifying...
                </>
              ) : (
                <>
                  <UserCheck className="w-4 h-4" />
                  Verify
                </>
              )}
            </button>
          </div>
          {verified && success && (
            <div className="mt-3 flex items-center gap-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
              <CheckCircle className="w-4 h-4 flex-shrink-0" />
              {success}
            </div>
          )}
        </div>

        {/* Error / Success messages */}
        {error && (
          <div className="mb-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
            <span>{error}</span>
          </div>
        )}
        {success && !verified && (
          <div className="mb-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
            <span>{success}</span>
          </div>
        )}

        {/* Consent checkbox */}
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
          <label className="flex items-start gap-3 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={agreed}
              onChange={(e) => setAgreed(e.target.checked)}
              className="mt-1 h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700 leading-relaxed">
              I have read and understood the Data Protection Notice and agree to the
              processing of my personal data for the purposes explained above.
            </span>
          </label>
        </div>

        {/* Actions */}
        <div className="flex flex-col sm:flex-row gap-3">
          <button
            onClick={handleSubmit}
            disabled={submitting || !verified || !agreed}
            className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
          >
            {submitting ? (
              <>
                <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                Saving consent...
              </>
            ) : (
              <>
                <CheckCircle className="w-4 h-4" />
                Continue to Dashboard
              </>
            )}
          </button>
          <button
            onClick={handleDecline}
            disabled={submitting}
            className="inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg text-sm font-semibold text-slate-600 bg-white border border-slate-300 hover:bg-slate-50 disabled:opacity-50 transition"
          >
            <XCircle className="w-4 h-4" />
            I do not agree
          </button>
        </div>

        <p className="mt-4 text-xs text-slate-400 text-center">
          If you do not agree, you will be returned to the login page and will not be
          able to access the HR system. Some processing may continue based on other
          lawful grounds.
        </p>
      </div>
    </div>
  );
};

export default DataProtectionConsent;