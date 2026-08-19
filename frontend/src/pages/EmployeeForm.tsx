import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
// @ts-ignore - JS module without types
import api from '../utils/api'
import Card from '../components/ui/Card'
import Input from '../components/ui/Input'
import Select from '../components/ui/Select'
import Button from '../components/ui/Button'
// @ts-ignore - JS module without types
import EmployeeTabs from '../components/EmployeeTabs'
import { ArrowLeft, Save, Loader2 } from 'lucide-react'

interface FormData {
  employee_id: string
  first_name: string
  last_name: string
  surname: string
  email: string
  phone: string
  national_id: string
  gender: string
  date_of_birth: string
  address: string
  designation: string
  department_id: string
  section_id: string
  subsection_id: string
  office_id: string
  position: string
  employment_type: string
  employee_type: string
  employee_status: string
  hire_date: string
  scale_id: string
  contract_start_date: string
  contract_end_date: string
}

interface ReferenceData {
  departments: Array<{ id: number; name: string }>
  sections: Array<{ id: number; name: string; department_id: number }>
  subsections: Array<{ id: number; name: string; section_id: number }>
  offices: Array<{ id: number; name: string }>
}

// Roles that don't need department/section/subsection
const NO_ORG_ROLES = ['managing_director', 'bod_chairman', 'super_admin']
// Roles that only need department (hr_manager is like dept_head but more powerful)
const DEPT_ONLY_ROLES = ['dept_head', 'hr_manager']
// Roles that need department + section
const SECTION_ROLES = ['section_head']
// Roles that need department + section + subsection
const SUBSECTION_ROLES = ['sub_section_head']

const EmployeeForm = () => {
  const { id } = useParams()
  const navigate = useNavigate()
  const isEdit = Boolean(id)

  const [formData, setFormData] = useState<FormData>({
    employee_id: '',
    first_name: '',
    last_name: '',
    surname: '',
    email: '',
    phone: '',
    national_id: '',
    gender: '',
    date_of_birth: '',
    address: '',
    designation: '',
    department_id: '',
    section_id: '',
    subsection_id: '',
    office_id: '',
    position: '',
    employment_type: 'permanent',
    employee_type: 'officer',
    employee_status: 'active',
    hire_date: '',
    scale_id: '',
    contract_start_date: '',
    contract_end_date: '',
  })

  const [referenceData, setReferenceData] = useState<ReferenceData>({
    departments: [],
    sections: [],
    subsections: [],
    offices: [],
  })
  const [availableSections, setAvailableSections] = useState<Array<{ id: number; name: string }>>([])
  const [availableSubsections, setAvailableSubsections] = useState<Array<{ id: number; name: string }>>([])
  const [loading, setLoading] = useState(isEdit)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  useEffect(() => {
    fetchReferenceData()
    if (isEdit) {
      fetchEmployee()
    }
  }, [id])

  // When department changes, filter sections
  useEffect(() => {
    if (formData.department_id) {
      const filtered = referenceData.sections.filter(
        (s) => String(s.department_id) === String(formData.department_id)
      )
      setAvailableSections(filtered)
      // If current section is not in the filtered list, reset it
      if (formData.section_id && !filtered.some((s) => String(s.id) === String(formData.section_id))) {
        setFormData((prev) => ({ ...prev, section_id: '', subsection_id: '' }))
      }
    } else {
      setAvailableSections([])
      setFormData((prev) => ({ ...prev, section_id: '', subsection_id: '' }))
    }
  }, [formData.department_id, referenceData.sections])

  // When section changes, filter subsections
  useEffect(() => {
    if (formData.section_id) {
      const filtered = referenceData.subsections.filter(
        (s) => String(s.section_id) === String(formData.section_id)
      )
      setAvailableSubsections(filtered)
      // If current subsection is not in the filtered list, reset it
      if (formData.subsection_id && !filtered.some((s) => String(s.id) === String(formData.subsection_id))) {
        setFormData((prev) => ({ ...prev, subsection_id: '' }))
      }
    } else {
      setAvailableSubsections([])
      setFormData((prev) => ({ ...prev, subsection_id: '' }))
    }
  }, [formData.section_id, referenceData.subsections])

  // When employee_type changes, apply role-based field visibility
  useEffect(() => {
    const type = formData.employee_type
    if (NO_ORG_ROLES.includes(type)) {
      // No department, section, or subsection needed
      setFormData((prev) => ({
        ...prev,
        department_id: '',
        section_id: '',
        subsection_id: '',
      }))
    } else if (DEPT_ONLY_ROLES.includes(type)) {
      // Only department needed
      setFormData((prev) => ({
        ...prev,
        section_id: '',
        subsection_id: '',
      }))
    } else if (SECTION_ROLES.includes(type)) {
      // Department + section needed
      setFormData((prev) => ({
        ...prev,
        subsection_id: '',
      }))
    }
    // sub_section_head and officer need all levels
  }, [formData.employee_type])

  // Contract dates are only relevant for contract employment type
  const isContract = formData.employment_type === 'contract'

  const fetchReferenceData = async () => {
    try {
      const response = await api.get('/employees/reference')
      const data = response.data?.data || {}
      setReferenceData({
        departments: data.departments || [],
        sections: data.sections || [],
        subsections: data.subsections || [],
        offices: data.offices || [],
      })
    } catch (err) {
      console.error('Failed to fetch reference data:', err)
    }
  }

  const fetchEmployee = async () => {
    try {
      const response = await api.get(`/employees/${id}`)
      const employee = response.data?.data || response.data
      if (employee) {
        setFormData({
          employee_id: employee.employee_id || '',
          first_name: employee.first_name || '',
          last_name: employee.last_name || '',
          surname: employee.surname || '',
          email: employee.email || '',
          phone: employee.phone || '',
          national_id: employee.national_id || '',
          gender: employee.gender || '',
          date_of_birth: employee.date_of_birth || '',
          address: employee.address || '',
          designation: employee.designation || '',
          department_id: employee.department_id || '',
          section_id: employee.section_id || '',
          subsection_id: employee.subsection_id || '',
          office_id: employee.office_id || '',
          position: employee.position || '',
          employment_type: employee.employment_type || 'permanent',
          employee_type: employee.employee_type || 'officer',
          employee_status: employee.employee_status || 'active',
          hire_date: employee.hire_date || '',
          scale_id: employee.scale_id || '',
          contract_start_date: employee.contract_start_date || '',
          contract_end_date: employee.contract_end_date || '',
        })
      }
    } catch (err) {
      console.error('Failed to fetch employee:', err)
      setError('Failed to load employee data')
    } finally {
      setLoading(false)
    }
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target
    setFormData((prev) => ({ ...prev, [name]: value }))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')

    try {
      const payload: Record<string, unknown> = {
        ...formData,
        department_id: formData.department_id ? Number(formData.department_id) : null,
        section_id: formData.section_id ? Number(formData.section_id) : null,
        subsection_id: formData.subsection_id ? Number(formData.subsection_id) : null,
        office_id: formData.office_id ? Number(formData.office_id) : null,
      }

      if (isContract) {
        payload.contract_start_date = formData.contract_start_date || null
        payload.contract_end_date = formData.contract_end_date || null
      }

      if (isEdit) {
        await api.put(`/employees/${id}`, payload)
        setSuccess('Employee updated successfully')
      } else {
        await api.post('/employees', payload)
        setSuccess('Employee created successfully')
      }

      setTimeout(() => {
        navigate('/employees')
      }, 1500)
    } catch (err) {
      const errorMessage = err && typeof err === 'object' && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined
      setError(errorMessage || 'Failed to save employee')
    } finally {
      setSaving(false)
    }
  }

  // Determine which org fields to show based on employee_type
  const employeeType = formData.employee_type
  const showDepartment = !NO_ORG_ROLES.includes(employeeType)
  const showSection = !NO_ORG_ROLES.includes(employeeType) && !DEPT_ONLY_ROLES.includes(employeeType)
  const showSubsection = !NO_ORG_ROLES.includes(employeeType) && !DEPT_ONLY_ROLES.includes(employeeType) && !SECTION_ROLES.includes(employeeType)

  if (loading) {
    return (
      <div className="space-y-6">
        <EmployeeTabs />
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <EmployeeTabs />

      <button
        onClick={() => navigate('/employees')}
        className="flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
      >
        <ArrowLeft className="h-4 w-4 mr-1" />
        Back to Employees
      </button>

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
            {isEdit ? 'Edit Employee' : 'Add Employee'}
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            {isEdit ? 'Update employee information' : 'Create a new employee record'}
          </p>
        </div>
      </div>

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

      <form onSubmit={handleSubmit}>
        <Card title="Personal Information">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Input
              label="Employee ID *"
              name="employee_id"
              value={formData.employee_id}
              onChange={handleChange}
              required
              disabled={isEdit}
            />
            <Input
              label="First Name *"
              name="first_name"
              value={formData.first_name}
              onChange={handleChange}
              required
            />
            <Input
              label="Last Name *"
              name="last_name"
              value={formData.last_name}
              onChange={handleChange}
              required
            />
            <Input
              label="Surname"
              name="surname"
              value={formData.surname}
              onChange={handleChange}
            />
            <Input
              label="Email *"
              name="email"
              type="email"
              value={formData.email}
              onChange={handleChange}
              required
            />
            <Input
              label="Phone"
              name="phone"
              value={formData.phone}
              onChange={handleChange}
            />
            <Input
              label="National ID *"
              name="national_id"
              value={formData.national_id}
              onChange={handleChange}
              required
            />
            <Select
              label="Gender"
              name="gender"
              value={formData.gender}
              onChange={handleChange}
              options={[
                { value: 'male', label: 'Male' },
                { value: 'female', label: 'Female' },
              ]}
            />
            <Input
              label="Date of Birth"
              name="date_of_birth"
              type="date"
              value={formData.date_of_birth}
              onChange={handleChange}
            />
            <Input
              label="Address"
              name="address"
              value={formData.address}
              onChange={handleChange}
            />
          </div>
        </Card>

        <Card title="Employment Information" className="mt-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <Select
              label="Employee Type"
              name="employee_type"
              value={formData.employee_type}
              onChange={handleChange}
              options={[
                { value: 'officer', label: 'Officer' },
                { value: 'dept_head', label: 'Department Head' },
                { value: 'section_head', label: 'Section Head' },
                { value: 'sub_section_head', label: 'Sub Section Head' },
                { value: 'managing_director', label: 'Managing Director' },
                { value: 'bod_chairman', label: 'BOD Chairman' },
                { value: 'super_admin', label: 'Super Admin' },
                { value: 'hr_manager', label: 'HR Manager' },
              ]}
            />

            {showDepartment && (
              <Select
                label="Department"
                name="department_id"
                value={formData.department_id}
                onChange={handleChange}
                options={[
                  ...referenceData.departments.map((d) => ({ value: d.id, label: d.name })),
                  { value: 'hr', label: 'HR' },
                  { value: 'admin', label: 'Admin' },
                ]}
              />
            )}

            {showSection && (
              <Select
                label="Section"
                name="section_id"
                value={formData.section_id}
                onChange={handleChange}
                options={availableSections.map((s) => ({ value: s.id, label: s.name }))}
                disabled={!formData.department_id}
              />
            )}

            {showSubsection && (
              <Select
                label="Subsection"
                name="subsection_id"
                value={formData.subsection_id}
                onChange={handleChange}
                options={availableSubsections.map((s) => ({ value: s.id, label: s.name }))}
                disabled={!formData.section_id}
              />
            )}

            <Select
              label="Office"
              name="office_id"
              value={formData.office_id}
              onChange={handleChange}
              options={referenceData.offices.map((o) => ({ value: o.id, label: o.name }))}
            />
            <Input
              label="Designation"
              name="designation"
              value={formData.designation}
              onChange={handleChange}
            />
            <Input
              label="Position"
              name="position"
              value={formData.position}
              onChange={handleChange}
            />
            <Select
              label="Employment Type"
              name="employment_type"
              value={formData.employment_type}
              onChange={handleChange}
              options={[
                { value: 'permanent', label: 'Permanent' },
                { value: 'contract', label: 'Contract' },
                { value: 'intern', label: 'Intern' },
              ]}
            />
            <Select
              label="Employee Status"
              name="employee_status"
              value={formData.employee_status}
              onChange={handleChange}
              options={[
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
                { value: 'resigned', label: 'Resigned' },
                { value: 'terminated', label: 'Terminated' },
              ]}
            />
            <Input
              label="Hire Date"
              name="hire_date"
              type="date"
              value={formData.hire_date}
              onChange={handleChange}
            />
            <Input
              label="Scale ID"
              name="scale_id"
              value={formData.scale_id}
              onChange={handleChange}
            />

            {isContract && (
              <>
                <Input
                  label="Contract Start Date *"
                  name="contract_start_date"
                  type="date"
                  value={formData.contract_start_date}
                  onChange={handleChange}
                  required={isContract}
                />
                <Input
                  label="Contract End Date *"
                  name="contract_end_date"
                  type="date"
                  value={formData.contract_end_date}
                  onChange={handleChange}
                  required={isContract}
                />
              </>
            )}
          </div>
        </Card>

        <div className="flex items-center justify-end space-x-3 mt-6">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/employees')}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={saving}>
            {saving ? (
              <>
                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                Saving...
              </>
            ) : (
              <>
                <Save className="h-4 w-4 mr-2" />
                {isEdit ? 'Update Employee' : 'Create Employee'}
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  )
}

export default EmployeeForm