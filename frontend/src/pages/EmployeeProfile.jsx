import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import Input from '../components/ui/Input'
import EmployeeTabs from '../components/EmployeeTabs'
import { ArrowLeft, Mail, Phone, MapPin, Briefcase, Building2, FileText, Users, Heart, Download, Save, Loader2, Plus, Trash2, Upload } from 'lucide-react'

const EmployeeProfile = () => {
  const { id } = useParams()
  const navigate = useNavigate()
  const [employee, setEmployee] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [activeTab, setActiveTab] = useState('details')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Next of Kin form state
  const [nextOfKinForm, setNextOfKinForm] = useState({
    name: '',
    relationship: '',
    contact: '',
  })

  // Dependants form state
  const [dependants, setDependants] = useState([])
  const [dependantForm, setDependantForm] = useState({
    name: '',
    relationship: '',
    date_of_birth: '',
    gender: '',
    id_no: '',
    contact: '',
  })

  // Documents state
  const [documents, setDocuments] = useState([])
  const [newDocument, setNewDocument] = useState({
    name: '',
    category: 'other',
    file: null,
  })

  useEffect(() => {
    fetchEmployee()
  }, [id])

  const fetchEmployee = async () => {
    try {
      const response = await api.get(`/employees/${id}`)
      const data = response.data.data || response.data
      setEmployee(data)
      
      // Parse next of kin from next_of_kin_data (already parsed from separate table)
      const parsedNextOfKin = data.next_of_kin_data || safeParse(data.next_of_kin)
      if (parsedNextOfKin.length > 0) {
        setNextOfKinForm({
          name: parsedNextOfKin[0].name || '',
          relationship: parsedNextOfKin[0].relationship || '',
          contact: parsedNextOfKin[0].contact || parsedNextOfKin[0].phone || '',
        })
      }

      // Parse dependants from dependants_data (already parsed from separate table)
      const parsedDependants = data.dependants_data || safeParse(data.dependants)
      setDependants(parsedDependants)

      // Parse documents
      const parsedDocuments = safeParse(data.documents)
      setDocuments(parsedDocuments)
    } catch (error) {
      console.error('Failed to fetch employee:', error)
    } finally {
      setLoading(false)
    }
  }

  const safeParse = (value) => {
    if (Array.isArray(value)) return value
    if (typeof value === 'object' && value !== null) return [value]
    if (typeof value === 'string') {
      try {
        const parsed = JSON.parse(value)
        return Array.isArray(parsed) ? parsed : (parsed ? [parsed] : [])
      } catch {
        return []
      }
    }
    return []
  }

  const handleNextOfKinChange = (e) => {
    const { name, value } = e.target
    setNextOfKinForm((prev) => ({ ...prev, [name]: value }))
  }

  const handleSaveNextOfKin = async (e) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const nextOfKinData = [{
        name: nextOfKinForm.name,
        relationship: nextOfKinForm.relationship,
        contact: nextOfKinForm.contact,
      }]
      await api.put(`/employees/${id}`, { next_of_kin: nextOfKinData })
      setSuccess('Next of kin updated successfully')
      fetchEmployee()
    } catch (err) {
      setError('Failed to update next of kin')
      console.error('Failed to update next of kin:', err)
    } finally {
      setSaving(false)
    }
  }

  const handleDependantChange = (e) => {
    const { name, value } = e.target
    setDependantForm((prev) => ({ ...prev, [name]: value }))
  }

  const handleAddDependant = async (e) => {
    e.preventDefault()
    if (!dependantForm.name) {
      setError('Dependant name is required')
      return
    }
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const currentDependants = employee.dependants_data || dependants
      const updatedDependants = [...currentDependants, { ...dependantForm }]
      await api.put(`/employees/${id}`, { dependants: updatedDependants })
      setDependants(updatedDependants)
      setDependantForm({ name: '', relationship: '', date_of_birth: '', gender: '', id_no: '', contact: '' })
      setSuccess('Dependant added successfully')
    } catch (err) {
      setError('Failed to add dependant')
      console.error('Failed to add dependant:', err)
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteDependant = async (index) => {
    if (!confirm('Are you sure you want to delete this dependant?')) return
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const currentDependants = employee.dependants_data || dependants
      const updatedDependants = currentDependants.filter((_, i) => i !== index)
      await api.put(`/employees/${id}`, { dependants: updatedDependants })
      setDependants(updatedDependants)
      setSuccess('Dependant deleted successfully')
    } catch (err) {
      setError('Failed to delete dependant')
      console.error('Failed to delete dependant:', err)
    } finally {
      setSaving(false)
    }
  }

  const handleDocumentFileChange = (e) => {
    const file = e.target.files?.[0] || null
    setNewDocument((prev) => ({
      ...prev,
      file,
      name: file ? file.name : prev.name,
    }))
  }

  const handleUploadDocument = async (e) => {
    e.preventDefault()
    if (!newDocument.file) {
      setError('Please select a file to upload')
      return
    }
    setSaving(true)
    setError('')
    setSuccess('')
    try {
      const formData = new FormData()
      formData.append('employee_id', id)
      formData.append('document_name', newDocument.name)
      formData.append('category', newDocument.category)
      formData.append('file', newDocument.file)
      await api.post('/employees/documents', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setSuccess('Document uploaded successfully')
      setNewDocument({ name: '', category: 'other', file: null })
      fetchEmployee()
    } catch (err) {
      setError('Failed to upload document')
      console.error('Failed to upload document:', err)
    } finally {
      setSaving(false)
    }
  }

  const handleDeleteDocument = async (docId) => {
    if (!confirm('Are you sure you want to delete this document?')) return
    try {
      await api.delete(`/employees/documents/${docId}`)
      setSuccess('Document deleted successfully')
      fetchEmployee()
    } catch (err) {
      setError('Failed to delete document')
      console.error('Failed to delete document:', err)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  if (!employee) {
    return (
      <div className="space-y-6">
        <EmployeeTabs />
        <Card>
          <div className="text-center py-8">
            <p className="text-gray-500 dark:text-gray-400">Employee not found</p>
            <Button variant="outline" className="mt-4" onClick={() => navigate('/employees')}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Back to Employees
            </Button>
          </div>
        </Card>
      </div>
    )
  }

  const tabs = [
    { id: 'details', name: 'Personal Details', icon: <Briefcase className="h-4 w-4" /> },
    { id: 'documents', name: 'Documents', icon: <FileText className="h-4 w-4" /> },
    { id: 'nextofkin', name: 'Next of Kin', icon: <Users className="h-4 w-4" /> },
    { id: 'dependants', name: 'Dependants', icon: <Heart className="h-4 w-4" /> },
  ]

  const nextOfKin = employee.next_of_kin_data || safeParse(employee.next_of_kin)
  const dependantsList = employee.dependants_data || dependants

  return (
    <div className="space-y-6">
      <EmployeeTabs />

      {/* Back button */}
      <button
        onClick={() => navigate('/employees')}
        className="flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
      >
        <ArrowLeft className="h-4 w-4 mr-1" />
        Back to Employees
      </button>

      {/* Employee Header */}
      <Card>
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div className="flex items-center space-x-4">
            <div className="h-16 w-16 rounded-full bg-primary-600 flex items-center justify-center text-white text-2xl font-bold">
              {employee.first_name?.[0]}{employee.last_name?.[0]}
            </div>
            <div>
              <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {employee.first_name} {employee.last_name}
              </h1>
              <p className="text-gray-500 dark:text-gray-400">{employee.designation || 'No designation'}</p>
              <div className="flex items-center space-x-2 mt-1">
                <Badge variant={employee.employee_status === 'active' ? 'success' : 'danger'}>
                  {employee.employee_status || 'Active'}
                </Badge>
                <span className="text-sm text-gray-500 dark:text-gray-400">ID: {employee.employee_id}</span>
              </div>
            </div>
          </div>
          <Button variant="outline" onClick={() => navigate(`/employees/${id}/edit`)}>
            Edit Profile
          </Button>
        </div>
      </Card>

      {error && (
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-md">
          {success}
        </div>
      )}

      {/* Detail tabs */}
      <div className="border-b border-gray-200 dark:border-slate-700 overflow-x-auto scrollbar-thin">
        <nav className="-mb-px flex space-x-1 sm:space-x-8" aria-label="Employee details">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center space-x-1 sm:space-x-2 py-3 px-2 sm:px-1 border-b-2 text-xs sm:text-sm font-medium transition-colors whitespace-nowrap ${
                activeTab === tab.id
                  ? 'border-primary-600 text-primary-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.icon}
              <span className="hidden xs:inline">{tab.name}</span>
              <span className="xs:hidden">{tab.name.split(' ')[0]}</span>
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      {activeTab === 'details' && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <Card title="Contact Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <Mail className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">{employee.email || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Phone className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">{employee.phone || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <MapPin className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">{employee.address || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="Employment Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <Briefcase className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">{employee.position || employee.designation || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Building2 className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">{employee.department_name || employee.department || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <FileText className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">Employment Type: {employee.employment_type || employee.employee_type || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Briefcase className="h-4 w-4 mr-2 text-gray-400 dark:text-gray-500" />
                <span className="text-gray-600 dark:text-gray-400">Hire Date: {employee.hire_date || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="Personal Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Gender:</span>
                <span className="text-gray-900 dark:text-gray-100 capitalize">{employee.gender || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Date of Birth:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.date_of_birth || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">National ID:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.national_id || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="HR Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Section:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.section_name || employee.section_id || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Subsection:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.subsection_name || employee.subsection_id || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Office:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.office_name || employee.office_id || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500 dark:text-gray-400">Scale:</span>
                <span className="text-gray-900 dark:text-gray-100">{employee.scale_id || 'Not provided'}</span>
              </div>
            </div>
          </Card>
        </div>
      )}

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
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
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
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">File</label>
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

          <Card title="Employee Documents">
            {documents.length > 0 ? (
              <div className="space-y-3">
                {documents.map((doc, index) => (
                  <div key={index} className="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-900/40 rounded-lg">
                    <div className="flex items-center">
                      <FileText className="h-5 w-5 mr-2 text-gray-400 dark:text-gray-500" />
                      <div>
                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{doc.name || doc.document_name || `Document ${index + 1}`}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">{doc.type || doc.category || 'Document'}</p>
                      </div>
                    </div>
                    <div className="flex items-center space-x-2">
                      <Button variant="outline" size="sm">
                        <Download className="h-4 w-4 mr-1" />
                        Download
                      </Button>
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => handleDeleteDocument(doc.id)}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 dark:text-gray-400 text-center py-8">No documents uploaded for this employee.</p>
            )}
          </Card>
        </div>
      )}

      {activeTab === 'nextofkin' && (
        <div className="space-y-6">
          <Card title="Add / Update Next of Kin">
            <form onSubmit={handleSaveNextOfKin} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Input
                  label="Name"
                  name="name"
                  value={nextOfKinForm.name}
                  onChange={handleNextOfKinChange}
                  required
                />
                <Input
                  label="Relationship"
                  name="relationship"
                  value={nextOfKinForm.relationship}
                  onChange={handleNextOfKinChange}
                  required
                />
                <Input
                  label="Contact"
                  name="contact"
                  value={nextOfKinForm.contact}
                  onChange={handleNextOfKinChange}
                />
              </div>
              <div className="flex justify-end">
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

          <Card title="Current Next of Kin">
            {nextOfKin.length > 0 ? (
              <div className="space-y-3">
                {nextOfKin.map((kin, index) => (
                  <div key={index} className="p-4 bg-gray-50 dark:bg-slate-900/40 rounded-lg">
                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{kin.name || `Next of Kin ${index + 1}`}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{kin.relationship || 'Relationship not specified'}</p>
                    {kin.contact && (
                      <div className="flex items-center mt-2 text-sm text-gray-600 dark:text-gray-400">
                        <Phone className="h-3 w-3 mr-1 text-gray-400 dark:text-gray-500" />
                        {kin.contact}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 dark:text-gray-400 text-center py-8">No next of kin information on file.</p>
            )}
          </Card>
        </div>
      )}

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

          <Card title="Dependants">
            {dependantsList.length > 0 ? (
              <div className="space-y-3">
                {dependantsList.map((dep, index) => (
                  <div key={index} className="p-4 bg-gray-50 dark:bg-slate-900/40 rounded-lg flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{dep.name || `Dependant ${index + 1}`}</p>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{dep.relationship || 'Relationship not specified'}</p>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Date of Birth: {dep.date_of_birth || 'Not provided'}</p>
                      {dep.gender && <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Gender: {dep.gender}</p>}
                      {dep.id_no && <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">ID No: {dep.id_no}</p>}
                      {dep.contact && <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Contact: {dep.contact}</p>}
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
              <p className="text-gray-500 dark:text-gray-400 text-center py-8">No dependants on record.</p>
            )}
          </Card>
        </div>
      )}
    </div>
  )
}

export default EmployeeProfile