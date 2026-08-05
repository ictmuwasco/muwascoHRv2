import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import EmployeeTabs from '../components/EmployeeTabs'
import { ArrowLeft, Mail, Phone, MapPin, Briefcase, Building2, FileText, Users, Heart, Download } from 'lucide-react'

const EmployeeProfile = () => {
  const { id } = useParams()
  const navigate = useNavigate()
  const [employee, setEmployee] = useState(null)
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState('details')

  useEffect(() => {
    fetchEmployee()
  }, [id])

  const fetchEmployee = async () => {
    try {
      const response = await api.get(`/employees/${id}`)
      setEmployee(response.data.data || response.data)
    } catch (error) {
      console.error('Failed to fetch employee:', error)
    } finally {
      setLoading(false)
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
            <p className="text-gray-500">Employee not found</p>
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

  const nextOfKin = safeParse(employee.next_of_kin)
  const dependants = safeParse(employee.dependants)
  const documents = safeParse(employee.documents)

  return (
    <div className="space-y-6">
      <EmployeeTabs />

      {/* Back button */}
      <button
        onClick={() => navigate('/employees')}
        className="flex items-center text-sm text-gray-600 hover:text-gray-900"
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
              <h1 className="text-2xl font-bold text-gray-900">
                {employee.first_name} {employee.last_name}
              </h1>
              <p className="text-gray-500">{employee.designation || 'No designation'}</p>
              <div className="flex items-center space-x-2 mt-1">
                <Badge variant={employee.employee_status === 'active' ? 'success' : 'danger'}>
                  {employee.employee_status || 'Active'}
                </Badge>
                <span className="text-sm text-gray-500">ID: {employee.employee_id}</span>
              </div>
            </div>
          </div>
          <Button variant="outline" onClick={() => navigate(`/employees/${id}/edit`)}>
            Edit Profile
          </Button>
        </div>
      </Card>

      {/* Detail tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-8" aria-label="Employee details">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center space-x-2 py-4 px-1 border-b-2 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? 'border-primary-600 text-primary-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.icon}
              <span>{tab.name}</span>
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
                <Mail className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">{employee.email || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Phone className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">{employee.phone || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <MapPin className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">{employee.address || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="Employment Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <Briefcase className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">{employee.position || employee.designation || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Building2 className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">{employee.department_name || employee.department || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <FileText className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">Employment Type: {employee.employment_type || employee.employee_type || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <Briefcase className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">Hire Date: {employee.hire_date || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="Personal Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Gender:</span>
                <span className="text-gray-900 capitalize">{employee.gender || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Date of Birth:</span>
                <span className="text-gray-900">{employee.date_of_birth || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">National ID:</span>
                <span className="text-gray-900">{employee.national_id || 'Not provided'}</span>
              </div>
            </div>
          </Card>

          <Card title="HR Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Section:</span>
                <span className="text-gray-900">{employee.section_name || employee.section_id || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Office:</span>
                <span className="text-gray-900">{employee.office_name || employee.office_id || 'Not provided'}</span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Scale:</span>
                <span className="text-gray-900">{employee.scale_id || 'Not provided'}</span>
              </div>
            </div>
          </Card>
        </div>
      )}

      {activeTab === 'documents' && (
        <Card title="Employee Documents">
          {documents.length > 0 ? (
            <div className="space-y-3">
              {documents.map((doc, index) => (
                <div key={index} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                  <div className="flex items-center">
                    <FileText className="h-5 w-5 mr-2 text-gray-400" />
                    <div>
                      <p className="text-sm font-medium text-gray-900">{doc.name || `Document ${index + 1}`}</p>
                      <p className="text-xs text-gray-500">{doc.type || 'Document'}</p>
                    </div>
                  </div>
                  <Button variant="outline" size="sm">
                    <Download className="h-4 w-4 mr-1" />
                    Download
                  </Button>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-center py-8">No documents uploaded for this employee.</p>
          )}
        </Card>
      )}

      {activeTab === 'nextofkin' && (
        <Card title="Next of Kin">
          {nextOfKin.length > 0 ? (
            <div className="space-y-3">
              {nextOfKin.map((kin, index) => (
                <div key={index} className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm font-medium text-gray-900">{kin.name || `Next of Kin ${index + 1}`}</p>
                  <p className="text-xs text-gray-500 mt-1">{kin.relationship || 'Relationship not specified'}</p>
                  <div className="flex items-center mt-2 text-sm text-gray-600">
                    <Phone className="h-3 w-3 mr-1 text-gray-400" />
                    {kin.phone || 'Phone not provided'}
                  </div>
                  <div className="flex items-center mt-1 text-sm text-gray-600">
                    <Mail className="h-3 w-3 mr-1 text-gray-400" />
                    {kin.email || 'Email not provided'}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-center py-8">No next of kin information on file.</p>
          )}
        </Card>
      )}

      {activeTab === 'dependants' && (
        <Card title="Dependants">
          {dependants.length > 0 ? (
            <div className="space-y-3">
              {dependants.map((dep, index) => (
                <div key={index} className="p-4 bg-gray-50 rounded-lg">
                  <p className="text-sm font-medium text-gray-900">{dep.name || `Dependant ${index + 1}`}</p>
                  <p className="text-xs text-gray-500 mt-1">{dep.relationship || 'Relationship not specified'}</p>
                  <p className="text-xs text-gray-500 mt-1">Date of Birth: {dep.date_of_birth || 'Not provided'}</p>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-center py-8">No dependants on record.</p>
          )}
        </Card>
      )}
    </div>
  )
}

export default EmployeeProfile