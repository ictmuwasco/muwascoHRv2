import { useState, useEffect, useCallback } from 'react';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { User, Briefcase, Users, FileText, Key, Save, Loader2, Plus, Trash2, Upload, Eye, Download, UserRound } from 'lucide-react';
import type { EmployeeProfile } from '../../types';

// Base URL for direct file access (authenticated via httpOnly cookie) —
// centralized in src/config/api.ts so every consumer shares VITE_API_URL.
import { API_BASE_URL as API_BASE } from '../../config/api';

// Profile image URL helper - streams through the API (auth cookie is sent automatically)
const getProfileImageUrl = (profileImageUrl?: string | null): string | null => {
  if (!profileImageUrl) return null;
  if (profileImageUrl.startsWith('http')) return profileImageUrl;
  return `${API_BASE}/profile/profile-image?t=${Date.now()}`;
};

const Profile = () => {
  const [profile, setProfile] = useState<EmployeeProfile | null>(null);
  const [profileImage, setProfileImage] = useState<string | null>(null);
  const [profileImageUploading, setProfileImageUploading] = useState(false);
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

  // Next of Kin list state - the backend stores MULTIPLE rows per employee
  // (next_of_kin table), so records are added, listed and deleted individually.
  const [nextOfKinList, setNextOfKinList] = useState<Array<{ id?: number; name: string; relationship: string; contact: string }>>([]);
  const [nokForm, setNokForm] = useState({ name: '', relationship: '', contact: '' });

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
  const [documents, setDocuments] = useState<Array<{ id: number; name: string; type: string; uploaded_at: string; document_name?: string; category?: string }>>([]);
  const [newDocument, setNewDocument] = useState<{ name: string; category: string; file: File | null }>({
    name: '',
    category: 'other',
    file: null,
  });

  // Popup (modal) toggles - opened by the Add buttons in the card headers.
  const [nokModalOpen, setNokModalOpen] = useState(false);
  const [depModalOpen, setDepModalOpen] = useState(false);
  const [docModalOpen, setDocModalOpen] = useState(false);

  const fetchProfile = useCallback(async () => {
    try {
      const response = await apiClient.get('/profile');
      const data = response.data.data;
      setProfile(data);
      if (data) {
        setProfileImage(getProfileImageUrl(data.profile_image_url));
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
        // Next of kin comes back as an ARRAY (one row per record). Legacy
        // shapes (single object or JSON string) are normalised defensively
        // so nothing is silently dropped.
        let nokRows: any = data.next_of_kin;
        if (typeof nokRows === 'string') {
          try { nokRows = JSON.parse(nokRows || '[]'); } catch { nokRows = []; }
        }
        if (!Array.isArray(nokRows)) nokRows = nokRows ? [nokRows] : [];
        setNextOfKinList(nokRows.map((r: any) => ({
          id: r?.id ?? undefined,
          name: r?.name || '',
          relationship: r?.relationship || '',
          contact: r?.contact || r?.phone || '',
        })));
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
  }, []);

  useEffect(() => {
    fetchProfile();
  }, [fetchProfile]);

  const handleProfileImageChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
      setError('Please select a valid image file (JPG, PNG, GIF or WebP)');
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      setError('Image size exceeds 5MB limit');
      return;
    }

    setProfileImageUploading(true);
    setError('');
    setSuccess('');

    try {
      const formData = new FormData();
      formData.append('file', file);
      await apiClient.post('/profile/profile-image', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setSuccess('Profile picture uploaded successfully');
      // Refresh profile to get the latest profile_image_url
      await fetchProfile();
      // Force image refresh with cache-busting
      setProfileImage(`${API_BASE}/profile/profile-image?t=${Date.now()}`);
    } catch (err: any) {
      setError(err?.response?.data?.error || err?.response?.data?.message || 'Failed to upload profile picture');
      console.error('Failed to upload profile picture:', err);
    } finally {
      setProfileImageUploading(false);
      e.target.value = '';
    }
  };

  const handleNokFormChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setNokForm((prev) => ({ ...prev, [name]: value }));
  };

  /**
   * Persist the COMPLETE next-of-kin list. The backend implements
   * replace-all semantics, so the caller always sends every row it wants
   * kept - then re-fetches so the table mirrors the database exactly.
   */
  const saveNextOfKinList = async (
    list: Array<{ name: string; relationship: string; contact: string }>
  ): Promise<boolean> => {
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      await apiClient.put('/profile', { next_of_kin: list });
      await fetchProfile(); // re-sync with the server copy (ids, ordering)
      return true;
    } catch (err) {
      setError('Failed to update next of kin information');
      console.error('Failed to update next of kin:', err);
      return false;
    } finally {
      setSaving(false);
    }
  };

  const handleAddNextOfKin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!nokForm.name.trim()) {
      setError('Next of kin name is required');
      return;
    }
    const ok = await saveNextOfKinList([...nextOfKinList, {
      name: nokForm.name.trim(),
      relationship: nokForm.relationship.trim(),
      contact: nokForm.contact.trim(),
    }]);
    if (ok) {
      setSuccess('Next of kin added successfully');
      setNokForm({ name: '', relationship: '', contact: '' });
      setNokModalOpen(false);
    }
  };

  const handleDeleteNextOfKin = async (index: number) => {
    if (!confirm('Remove this next of kin record?')) return;
    const ok = await saveNextOfKinList(nextOfKinList.filter((_, i) => i !== index));
    if (ok) setSuccess('Next of kin removed successfully');
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
      setDepModalOpen(false);
      // Re-sync from the database so the visible list can never drift from
      // what is stored (e.g., HR-seeded rows are always preserved).
      await fetchProfile();
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
      await fetchProfile();
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
      setDocModalOpen(false);
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
      <div className="flex space-x-1 border-b overflow-x-auto scrollbar-thin">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`flex items-center px-3 py-2 sm:px-4 text-xs sm:text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
              activeTab === tab.id
                ? 'border-primary-600 text-primary-600'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <tab.icon className="h-3 w-3 sm:h-4 sm:w-4 mr-1 sm:mr-2" />
            <span className="hidden xs:inline">{tab.label}</span>
            <span className="xs:hidden">{tab.label.split(' ')[0]}</span>
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

      {/* Profile Picture Card */}
      {activeTab === 'personal' && profile && (
        <Card title="Profile Picture">
          <div className="flex flex-col sm:flex-row sm:items-center gap-4">
            <div className="h-20 w-20 rounded-full bg-primary-600 flex items-center justify-center overflow-hidden shrink-0">
              {profileImage ? (
                <img
                  src={profileImage}
                  alt="Profile"
                  className="h-full w-full object-cover"
                />
              ) : (
                <UserRound className="h-10 w-10 text-white" />
              )}
            </div>
            <div className="space-y-2">
              <p className="text-sm font-medium text-gray-900">
                {personal.first_name} {personal.last_name}
              </p>
              <p className="text-xs text-gray-500">Upload a professional photo (JPG, PNG, GIF or WebP, max 5MB)</p>
              <label className="inline-flex items-center px-3 py-2 text-xs font-medium border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-500 transition-colors">
                {profileImageUploading ? (
                  <>
                    <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                    Uploading...
                  </>
                ) : (
                  <>
                    <Upload className="h-3 w-3 mr-1" />
                    Upload Picture
                  </>
                )}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/gif,image/webp"
                  className="hidden"
                  onChange={handleProfileImageChange}
                  disabled={profileImageUploading}
                />
              </label>
            </div>
          </div>
        </Card>
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
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Next of Kin Records ({nextOfKinList.length})</h3>
            <Button size="sm" onClick={() => setNokModalOpen(true)}>
              <Plus className="h-4 w-4 mr-1" />Add
            </Button>
          </div>
          {nextOfKinList.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">#</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Name</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Relationship</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Contact</th>
                    <th className="px-4 py-2"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
                  {nextOfKinList.map((nok, idx) => (
                    <tr key={nok.id ?? idx} className="hover:bg-gray-50 dark:hover:bg-slate-700/30">
                      <td className="px-4 py-3 text-gray-500 dark:text-gray-400">{idx + 1}</td>
                      <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{nok.name || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{nok.relationship || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{nok.contact || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <Button variant="danger" size="sm" onClick={() => handleDeleteNextOfKin(idx)}>
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-gray-500 dark:text-gray-400 text-center py-8">No next of kin on record yet.</p>
          )}
        </Card>
      )}

{/* Dependants */}
      {activeTab === 'dependants' && (
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">My Dependants ({dependants.length})</h3>
            <Button size="sm" onClick={() => setDepModalOpen(true)}>
              <Plus className="h-4 w-4 mr-1" />Add
            </Button>
          </div>
          {dependants.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Name</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Relationship</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date of Birth</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Gender</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ID Number</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Contact</th>
                    <th className="px-4 py-2"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
                  {dependants.map((dep, index) => (
                    <tr key={(dep as any).id ?? index} className="hover:bg-gray-50 dark:hover:bg-slate-700/30">
                      <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{dep.name || `Dependant ${index + 1}`}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{dep.relationship || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">{dep.date_of_birth || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{dep.gender || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{(dep as any).id_no || '—'}</td>
                      <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{dep.contact || '—'}</td>
                      <td className="px-4 py-3 text-right">
                        <Button variant="danger" size="sm" onClick={() => handleDeleteDependant(index)}>
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-gray-500 dark:text-gray-400 text-center py-8">No dependants on record.</p>
          )}
        </Card>
      )}

{/* Documents */}
      {activeTab === 'documents' && (
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">My Documents ({documents.length})</h3>
            <Button size="sm" onClick={() => setDocModalOpen(true)}>
              <Plus className="h-4 w-4 mr-1" />Add
            </Button>
          </div>
          <div className="space-y-3">
            {documents.length > 0 ? (
              documents.map((doc) => (
                <div key={doc.id} className="flex items-center justify-between p-3 border border-gray-200 dark:border-slate-700 rounded-lg">
                  <div>
                    <p className="font-medium text-gray-900 dark:text-gray-100">{doc.name || doc.document_name}</p>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{doc.type || doc.category} · {doc.uploaded_at}</p>
                  </div>
                  <div className="flex items-center space-x-2">
                    <Button size="sm" variant="outline" onClick={() => window.open(`${API_BASE}/profile/documents/${doc.id}/view`, '_blank')} title="View document">
                      <Eye className="h-3 w-3 mr-1" />View
                    </Button>
                    <a href={`${API_BASE}/profile/documents/${doc.id}`} download className="inline-flex items-center px-2 py-1 text-xs font-medium border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-primary-500 transition-colors">
                      <Download className="h-3 w-3 mr-1" />Download
                    </a>
                    <Button size="sm" variant="danger" onClick={() => handleDeleteDocument(doc.id)}>
                      <Trash2 className="h-3 w-3" />
                    </Button>
                  </div>
                </div>
              ))
            ) : (
              <p className="text-gray-500 dark:text-gray-400 text-center py-8">No documents uploaded.</p>
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
      {/* Add Next of Kin popup */}
      <Modal isOpen={nokModalOpen} onClose={() => setNokModalOpen(false)} title="Add Next of Kin">
        <form onSubmit={handleAddNextOfKin}>
          <div className="grid grid-cols-1 gap-4">
            <Input label="Name" name="name" value={nokForm.name} onChange={handleNokFormChange} required />
            <Input label="Relationship" name="relationship" value={nokForm.relationship} onChange={handleNokFormChange} />
            <Input label="Contact" name="contact" value={nokForm.contact} onChange={handleNokFormChange} />
          </div>
          <div className="flex items-center justify-end gap-2 mt-6">
            <Button variant="outline" type="button" onClick={() => setNokModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving}>
              {saving ? (<><Loader2 className="h-4 w-4 mr-2 animate-spin" />Saving...</>) : (<><Plus className="h-4 w-4 mr-2" />Add Next of Kin</>)}
            </Button>
          </div>
        </form>
      </Modal>
      {/* Add Dependant popup */}
      <Modal isOpen={depModalOpen} onClose={() => setDepModalOpen(false)} title="Add Dependant">
        <form onSubmit={handleAddDependant} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Input label="Name" name="name" value={dependantForm.name} onChange={handleDependantChange} required />
            <Input label="Relationship" name="relationship" value={dependantForm.relationship} onChange={handleDependantChange} />
            <Input label="Date of Birth" name="date_of_birth" type="date" value={dependantForm.date_of_birth} onChange={handleDependantChange} />
            <Input label="Gender" name="gender" value={dependantForm.gender} onChange={handleDependantChange} />
            <Input label="ID Number" name="id_no" value={dependantForm.id_no} onChange={handleDependantChange} />
            <Input label="Contact" name="contact" value={dependantForm.contact} onChange={handleDependantChange} />
          </div>
          <div className="flex items-center justify-end gap-2 mt-2">
            <Button variant="outline" type="button" onClick={() => setDepModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving}>
              {saving ? (<><Loader2 className="h-4 w-4 mr-2 animate-spin" />Adding...</>) : (<><Plus className="h-4 w-4 mr-2" />Add Dependant</>)}
            </Button>
          </div>
        </form>
      </Modal>
      {/* Upload Document popup */}
      <Modal isOpen={docModalOpen} onClose={() => setDocModalOpen(false)} title="Upload Document">
        <form onSubmit={handleUploadDocument} className="space-y-4">
          <Input label="Document Name" name="document_name" value={newDocument.name} onChange={(e) => setNewDocument((prev) => ({ ...prev, name: e.target.value }))} placeholder="e.g. National ID, KRA PIN, Certificate" />
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
            <select
              value={newDocument.category}
              onChange={(e) => setNewDocument((prev) => ({ ...prev, category: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
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
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">File</label>
            <input
              type="file"
              onChange={handleDocumentFileChange}
              className="w-full text-sm text-gray-700 dark:text-gray-200 file:mr-3 file:rounded-md file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-primary-100"
            />
          </div>
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" type="button" onClick={() => setDocModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving || !newDocument.file}>
              {saving ? (<><Loader2 className="h-4 w-4 mr-2 animate-spin" />Uploading...</>) : (<><Upload className="h-4 w-4 mr-2" />Upload Document</>)}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
};

export default Profile;