import { useState, useEffect } from 'react';
import apiClient from '../api/client';
import Card from '../components/ui/Card';
import Input from '../components/ui/Input';
import Button from '../components/ui/Button';
import { User, Briefcase, Users, FileText, Key, Save, Loader2, Plus, Trash2, Upload } from 'lucide-react';
import type { EmployeeProfile } from '../types';

const Profile = () => {
  const [profile, setProfile] = useState<EmployeeProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState('personal');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // View-only state for personal and employment info (managed by HR)
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

  // Next of Kin form state
  const [nextOfKin, setNextOfKin] = useState({
    name: '',
    relationship: '',
    contact: '',
  });

  // Dependants form state
  const [dependants, setDependants] = useState<Array<{ name: string; relationship: string; date_of_birth: string; gender: string; id_no: string; contact: string }>>([]);
  const [dependantForm, setDependantForm] = useState({
    name: '',
    relationship: '',
    date_of_birth: '',
    gender: '',
    id_no: '',
    contact: '',
  });

  // Documents state
  const [documents, setDocuments] = useState<Array<{ id: number; name: string; type: string; uploaded_at: string }>>([]);
  const [newDocument, setNewDocument] = useState<{ name: string; category: string; file: File | null }>({
    name: '',
    category: 'other',
    file: null,
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
        // Handle next_of_kin as array (from separate table) or single object
        const nok = Array.isArray(data.next_of_kin) ? data.next_of_kin[0] : data.next_of_kin;
        setNextOfKin({
          name: nok?.name || '',
          relationship: nok?.relationship || '',
          contact: nok?.contact || nok?.phone || '',
        });
        setDocuments(data.documents || []);
        
        // Parse dependants (from separate table via dependants_data)
        if (data.dependants) {
          const parsed = Array.isArray(data.dependants) ? data.dependants : JSON.parse(data.dependants || '[]');
          setDependants(parsed);
        }
      }
    } catch (error) {
      console.error('Failed to fetch profile:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleNextOfKinChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setNextOfKin((prev) => ({ ...prev, [name]: value }));
  };

  const handleSaveNextOfKin = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      await apiClient.put('/profile', { next_of_kin: nextOfKin });
      setSuccess('Next of kin information updated successfully');
    } catch (err) {
      setError('Failed to update next of kin information');
      console.error('Failed to update next of kin:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDependantChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setDependantForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleAddDependant = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!dependantForm.name) {
      setError('Dependant name is required');
      return;
    }
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const updatedDependants = [...dependants, { ...dependantForm }];
      await apiClient.put('/profile', { dependants: updatedDependants });
      setDependants(updatedDependants);
      setDependantForm({ name: '', relationship: '', date_of_birth: '', gender: '', id_no: '', contact: '' });
      setSuccess('Dependant added successfully');
    } catch (err) {
      setError('Failed to add dependant');
      console.error('Failed to add dependant:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteDependant = async (index: number) => {
    if (!confirm('Are you sure you want to delete this dependant?')) return;
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const updatedDependants = dependants.filter((_, i) => i !== index);
      await apiClient.put('/profile', { dependants: updatedDependants });
      setDependants(updatedDependants);
      setSuccess('Dependant deleted successfully');
    } catch (err) {
      setError('Failed to delete dependant');
      console.error('Failed to delete dependant:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDocumentFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;
    setNewDocument((prev) => ({
      ...prev,
      file,
      name: file ? file.name : prev.name,
    }));
  };

  const handleUploadDocument = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newDocument.file) {
      setError('Please select a file to upload');
      return;
    }
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const formData = new FormData();
      formData.append('document_name', newDocument.name);
      formData.append('category', newDocument.category);
      formData.append('file', newDocument.file);
      await apiClient.post('/profile/documents', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setSuccess('Document uploaded successfully');
      setNewDocument({ name: '', category: 'other', file: null });
      fetchProfile();
    } catch (err) {
      setError('Failed to upload document');
      console.error('Failed to upload document:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteDocument = async (docId: number) => {
    if (!confirm('Are you sure you want to delete this document?')) return;
    try {
      await apiClient.delete(`/profile/documents/${docId}`);
      setSuccess('Document deleted successfully');
      fetchProfile();
    } catch (err) {
      setError('Failed to delete document');
      console.error('Failed to delete document:', err);
    }
  };

  const tabs = [
    { id: 'personal', label: 'Personal Info', icon: User },
    { id: 'employment', label: 'Employment', icon: Briefcase },
    { id: 'next_of_kin', label: 'Next of Kin', icon: Users },
    { id: 'dependants', label: 'Dependants', icon: Users },
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
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="First Name"
              name="first_name"
              value={personal.first_name}
              disabled
            />
            <Input
              label="Last Name"
              name="last_name"
              value={personal.last_name}
              disabled
            />
            <Input
              label="Surname"
              name="surname"
              value={personal.surname}
              disabled
            />
            <Input
              label="Email"
              name="email"
              type="email"
              value={personal.email}
              disabled
            />
            <Input
              label="Phone"
              name="phone"
              value={personal.phone}
              disabled
            />
            <Input
              label="National ID"
              name="national_id"
              value={personal.national_id}
              disabled
            />
            <Input
              label="Gender"
              name="gender"
              value={personal.gender}
              disabled
            />
            <Input
              label="Marital Status"
              name="marital_status"
              value={personal.marital_status}
              disabled
            />
            <Input
              label="Address"
              name="address"
              value={personal.address}
              disabled
              className="md:col-span-2"
            />
          </div>
          <p className="text-sm text-gray-500 mt-4">Personal information is managed by HR department.</p>
        </Card>
      )}

      {/* Employment Information */}
      {activeTab === 'employment' && profile && (
        <Card title="Employment Information">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              label="Department"
              name="department"
              value={employment.department}
              disabled
            />
            <Input
              label="Section"
              name="section"
              value={employment.section}
              disabled
            />
            <Input
              label="Office"
              name="office"
              value={employment.office}
              disabled
            />
            <Input
              label="Designation"
              name="designation"
              value={employment.designation}
              disabled
            />
            <Input
              label="Employee Type"
              name="employee_type"
              value={employment.employee_type}
              disabled
            />
            <Input
              label="Status"
              name="employee_status"
              value={employment.employee_status}
              disabled
            />
            <Input
              label="Employment Date"
              name="employment_date"
              type="date"
              value={employment.employment_date}
              disabled
            />
          </div>
          <p className="text-sm text-gray-500 mt-4">Employment information is managed by HR department.</p>
        </Card>
      )}

      {/* Next of Kin */}
      {activeTab === 'next_of_kin' && profile && (
        <Card title="Next of Kin">
          <form onSubmit={handleSaveNextOfKin}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                label="Name"
                name="name"
                value={nextOfKin.name}
                onChange={handleNextOfKinChange}
              />
              <Input
                label="Relationship"
                name="relationship"
                value={nextOfKin.relationship}
                onChange={handleNextOfKinChange}
              />
              <Input
                label="Contact"
                name="contact"
                value={nextOfKin.contact}
                onChange={handleNextOfKinChange}
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
                    Save Next of Kin
                  </>
                )}
              </Button>
            </div>
          </form>
        </Card>
      )}

      {/* Dependants */}
      {activeTab === 'dependants' && (
        <div className="space-y-6">
          <Card title="Add Dependant">
            <form onSubmit={handleAddDependant} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Input
                  label="Name"
                  name="name"
                  value={dependantForm.name}
                  onChange={handleDependantChange}
                  required
                />
                <Input
                  label="Relationship"
                  name="relationship"
                  value={dependantForm.relationship}
                  onChange={handleDependantChange}
                />
                <Input
                  label="Date of Birth"
                  name="date_of_birth"
                  type="date"
                  value={dependantForm.date_of_birth}
                  onChange={handleDependantChange}
                />
                <Input
                  label="Gender"
                  name="gender"
                  value={dependantForm.gender}
                  onChange={handleDependantChange}
                />
                <Input
                  label="ID Number"
                  name="id_no"
                  value={dependantForm.id_no}
                  onChange={handleDependantChange}
                />
                <Input
                  label="Contact"
                  name="contact"
                  value={dependantForm.contact}
                  onChange={handleDependantChange}
                />
              </div>
              <div className="flex justify-end">
                <Button type="submit" disabled={saving}>
                  {saving ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Adding...
                    </> 
                  ) : (
                    <>
                      <Plus className="h-4 w-4 mr-2" />
                      Add Dependant
                    </>
                  )}
                </Button>
              </div>
            </form>
          </Card>

          <Card title="My Dependants">
            {dependants.length > 0 ? (
              <div className="space-y-3">
                {dependants.map((dep, index) => (
                  <div key={index} className="p-4 bg-gray-50 rounded-lg flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-gray-900">{dep.name || `Dependant ${index + 1}`}</p>
                      <p className="text-xs text-gray-500 mt-1">{dep.relationship || 'Relationship not specified'}</p>
                      <p className="text-xs text-gray-500 mt-1">Date of Birth: {dep.date_of_birth || 'Not provided'}</p>
                      {dep.gender && <p className="text-xs text-gray-500 mt-1">Gender: {dep.gender}</p>}
                      {dep.id_no && <p className="text-xs text-gray-500 mt-1">ID No: {dep.id_no}</p>}
                      {dep.contact && <p className="text-xs text-gray-500 mt-1">Contact: {dep.contact}</p>}
                    </div>
                    <Button
                      variant="danger"
                      size="sm"
                      onClick={() => handleDeleteDependant(index)}
                    >
                      <Trash2 className="h-3 w-3" />
                    </Button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 text-center py-8">No dependants on record.</p>
            )}
          </Card>
        </div>
      )}

      {/* Documents */}
      {activeTab === 'documents' && (
        <div className="space-y-6">
          <Card title="Upload Document">
            <form onSubmit={handleUploadDocument} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input
                  label="Document Name"
                  name="document_name"
                  value={newDocument.name}
                  onChange={(e) => setNewDocument((prev) => ({ ...prev, name: e.target.value }))}
                  placeholder="e.g. National ID, KRA PIN, Certificate"
                />
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
                  <select
                    value={newDocument.category}
                    onChange={(e) => setNewDocument((prev) => ({ ...prev, category: e.target.value }))}
                    className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                  >
                    <option value="id">National ID</option>
                    <option value="kra_pin">KRA PIN</option>
                    <option value="certificate">Certificate</option>
                    <option value="diploma">Diploma</option>
                    <option value="professional">Professional</option>
                    <option value="nssf">NSSF</option>
                    <option value="sha">SHA</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700 mb-1">File</label>
                  <input
                    type="file"
                    onChange={handleDocumentFileChange}
                    className="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                  />
                </div>
              </div>
              <div className="flex justify-end">
                <Button type="submit" disabled={saving || !newDocument.file}>
                  {saving ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Uploading...
                    </> 
                  ) : (
                    <>
                      <Upload className="h-4 w-4 mr-2" />
                      Upload Document
                    </>
                  )}
                </Button>
              </div>
            </form>
          </Card>

          <Card title="My Documents">
            <div className="space-y-3">
              {documents.length > 0 ? (
                documents.map((doc) => (
                  <div key={doc.id} className="flex items-center justify-between p-3 border rounded-lg">
                    <div>
                      <p className="font-medium">{doc.name}</p>
                      <p className="text-sm text-gray-500">{doc.type} - {doc.uploaded_at}</p>
                    </div>
                    <div className="flex items-center space-x-2">
                      <Button size="sm" variant="outline">Download</Button>
                      <Button
                        size="sm"
                        variant="danger"
                        onClick={() => handleDeleteDocument(doc.id)}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  </div>
                ))
              ) : (
                <p className="text-gray-500 text-center py-4">No documents uploaded</p>
              )}
            </div>
          </Card>
        </div>
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