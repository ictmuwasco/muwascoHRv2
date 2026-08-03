import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import Card from '../components/ui/Card';
import Input from '../components/ui/Input';
import Button from '../components/ui/Button';
import { User, Briefcase, Users, FileText, Key } from 'lucide-react';
import type { EmployeeProfile } from '../types';

const Profile = () => {
  const [profile, setProfile] = useState<EmployeeProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('personal');

  useEffect(() => {
    fetchProfile();
  }, []);

  const fetchProfile = async () => {
    try {
      const response = await apiClient.get('/profile');
      setProfile(response.data.data);
    } catch (error) {
      console.error('Failed to fetch profile:', error);
    } finally {
      setLoading(false);
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

      {/* Personal Information */}
      {activeTab === 'personal' && profile && (
        <Card title="Personal Information">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input label="First Name" value={profile.personal.first_name} readOnly />
            <Input label="Last Name" value={profile.personal.last_name} readOnly />
            <Input label="Surname" value={profile.personal.surname} readOnly />
            <Input label="Email" value={profile.personal.email} readOnly />
            <Input label="Phone" value={profile.personal.phone} readOnly />
            <Input label="National ID" value={profile.personal.national_id} readOnly />
            <Input label="Gender" value={profile.personal.gender} readOnly />
            <Input label="Marital Status" value={profile.personal.marital_status} readOnly />
            <Input label="Address" value={profile.personal.address} readOnly className="md:col-span-2" />
          </div>
        </Card>
      )}

      {/* Employment Information */}
      {activeTab === 'employment' && profile && (
        <Card title="Employment Information">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input label="Department" value={profile.employment.department} readOnly />
            <Input label="Section" value={profile.employment.section} readOnly />
            <Input label="Office" value={profile.employment.office} readOnly />
            <Input label="Designation" value={profile.employment.designation} readOnly />
            <Input label="Employee Type" value={profile.employment.employee_type} readOnly />
            <Input label="Status" value={profile.employment.employee_status} readOnly />
            <Input label="Employment Date" value={profile.employment.employment_date} readOnly />
          </div>
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