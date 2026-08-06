import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import Card from '../components/ui/Card';
import Input from '../components/ui/Input';
import Button from '../components/ui/Button';
import { User, Briefcase, Users, FileText, Key, Save, Loader2 } from 'lucide-react';
import type { EmployeeProfile } from '../types';

const Profile = () => {
  const [profile, setProfile] = useState<EmployeeProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState('personal');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // Editable form state
  const [personal, setPersonal] = useState({
    first_name: '',
    last_name: '',
    surname: '',
    email: '',
    phone: '',
    national_id: '',
    gender: '',
    marital_status: '',
    address: '',
  });

  const [employment, setEmployment] = useState({
    department: '',
    section: '',
    office: '',
    designation: '',
    employee_type: '',
    employee_status: '',
    employment_date: '',
  });

  useEffect(() => {
    fetchProfile();
  }, []);

  const fetchProfile = async () => {
    try {
      const response = await apiClient.get('/profile');
      const data = response.data.data;
      setProfile(data);
      if (data) {
        setPersonal({
          first_name: data.personal?.first_name || '',
          last_name: data.personal?.last_name || '',
          surname: data.personal?.surname || '',
          email: data.personal?.email || '',
          phone: data.personal?.phone || '',
          national_id: data.personal?.national_id || '',
          gender: data.personal?.gender || '',
          marital_status: data.personal?.marital_status || '',
          address: data.personal?.address || '',
        });
        setEmployment({
          department: data.employment?.department || '',
          section: data.employment?.section || '',
          office: data.employment?.office || '',
          designation: data.employment?.designation || '',
          employee_type: data.employment?.employee_type || '',
          employee_status: data.employment?.employee_status || '',
          employment_date: data.employment?.employment_date || '',
        });
      }
    } catch (error) {
      console.error('Failed to fetch profile:', error);
    } finally {
      setLoading(false);
    }
  };

  const handlePersonalChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setPersonal((prev) => ({ ...prev, [name]: value }));
  };

  const handleEmploymentChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setEmployment((prev) => ({ ...prev, [name]: value }));
  };

  const handleSavePersonal = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      await apiClient.put('/profile', { personal });
      setSuccess('Personal information updated successfully');
    } catch (err) {
      setError('Failed to update personal information');
      console.error('Failed to update personal info:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleSaveEmployment = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      await apiClient.put('/profile', { employment });
      setSuccess('Employment information updated successfully');
    } catch (err) {
      setError('Failed to update employment information');
      console.error('Failed to update employment info:', err);
    } finally {
      setSaving(false);
    }
  };

  const tabs = [
    { id: 'personal', label: 'Personal Info', icon: User },
    { id: 'employment', label: 'Employment', icon: Briefcase },
    { id: 'next_of_kin', label: 'Next of Kin', icon: Users },
    { id: 'documents', label: 'Documents', icon: FileText },
    { id: 'password', label: 'Password', icon: Key },
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
      <div>
        <h1 className="text-2xl font-bold text-gray-900">My Profile</h1>
        <p className="text-gray-500">View and manage your profile information</p>
      </div>

      {/* Profile Tabs */}
      <div className="flex space-x-2 border-b overflow-x-auto">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`flex items-center px-4 py-2 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
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

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md">
          {success}
        </div>
      )}

      {/* Personal Information */}
      {activeTab === 'personal' && profile && (
        <Card title="Personal Information">
          <form onSubmit={handleSavePersonal}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                label="First Name"
                name="first_name"
                value={personal.first_name}
                onChange={handlePersonalChange}
              />
              <Input
                label="Last Name"
                name="last_name"
                value={personal.last_name}
                onChange={handlePersonalChange}
              />
              <Input
                label="Surname"
                name="surname"
                value={personal.surname}
                onChange={handlePersonalChange}
              />
              <Input
                label="Email"
                name="email"
                type="email"
                value={personal.email}
                onChange={handlePersonalChange}
              />
              <Input
                label="Phone"
                name="phone"
                value={personal.phone}
                onChange={handlePersonalChange}
              />
              <Input
                label="National ID"
                name="national_id"
                value={personal.national_id}
                onChange={handlePersonalChange}
              />
              <Input
                label="Gender"
                name="gender"
                value={personal.gender}
                onChange={handlePersonalChange}
              />
              <Input
                label="Marital Status"
                name="marital_status"
                value={personal.marital_status}
                onChange={handlePersonalChange}
              />
              <Input
                label="Address"
                name="address"
                value={personal.address}
                onChange={handlePersonalChange}
                className="md:col-span-2"
              />
            </div>
            <div className="flex justify-end mt-4">
              <Button type="submit" disabled={saving}>
                {saving ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Save className="h-4 w-4 mr-2" />
                    Save Changes
                  </>
                )}
              </Button>
            </div>
          </form>
        </Card>
      )}

      {/* Employment Information */}
      {activeTab === 'employment' && profile && (
        <Card title="Employment Information">
          <form onSubmit={handleSaveEmployment}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                label="Department"
                name="department"
                value={employment.department}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Section"
                name="section"
                value={employment.section}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Office"
                name="office"
                value={employment.office}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Designation"
                name="designation"
                value={employment.designation}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Employee Type"
                name="employee_type"
                value={employment.employee_type}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Status"
                name="employee_status"
                value={employment.employee_status}
                onChange={handleEmploymentChange}
              />
              <Input
                label="Employment Date"
                name="employment_date"
                type="date"
                value={employment.employment_date}
                onChange={handleEmploymentChange}
              />
            </div>
            <div className="flex justify-end mt-4">
              <Button type="submit" disabled={saving}>
                {saving ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Save className="h-4 w-4 mr-2" />
                    Save Changes
                  </>
                )}
              </Button>
            </div>
          </form>
        </Card>
      )}

      {/* Next of Kin */}
      {activeTab === 'next_of_kin' && profile && (
        <Card title="Next of Kin">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input label="Name" value={profile.next_of_kin.name} readOnly />
            <Input label="Relationship" value={profile.next_of_kin.relationship} readOnly />
            <Input label="Phone" value={profile.next_of_kin.phone} readOnly />
            <Input label="Email" value={profile.next_of_kin.email} readOnly />
            <Input label="Address" value={profile.next_of_kin.address} readOnly className="md:col-span-2" />
          </div>
        </Card>
      )}

      {/* Documents */}
      {activeTab === 'documents' && (
        <Card title="Documents">
          <div className="space-y-3">
            {profile?.documents.map((doc) => (
              <div key={doc.id} className="flex items-center justify-between p-3 border rounded-lg">
                <div>
                  <p className="font-medium">{doc.name}</p>
                  <p className="text-sm text-gray-500">{doc.type} - {doc.uploaded_at}</p>
                </div>
                <Button size="sm" variant="outline">Download</Button>
              </div>
            ))}
            {(!profile?.documents || profile.documents.length === 0) && (
              <p className="text-gray-500 text-center py-4">No documents uploaded</p>
            )}
          </div>
        </Card>
      )}

      {/* Password Change */}
      {activeTab === 'password' && (
        <Card title="Change Password">
          <form className="space-y-4 max-w-md">
            <Input label="Current Password" type="password" placeholder="Enter current password" />
            <Input label="New Password" type="password" placeholder="Enter new password" />
            <Input label="Confirm Password" type="password" placeholder="Confirm new password" />
            <Button type="submit">Update Password</Button>
          </form>
        </Card>
      )}
    </div>
  );
};

export default Profile;