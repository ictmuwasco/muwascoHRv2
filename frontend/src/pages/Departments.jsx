import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Table from '../components/ui/Table'
import Button from '../components/ui/Button'
import { Plus, Edit2, Trash2 } from 'lucide-react'

const Departments = () => {
  const [departments, setDepartments] = useState([])
  const [sections, setSections] = useState([])
  const [subsections, setSubsections] = useState([])
  const [loading, setLoading] = useState(true)
  const [showDeptModal, setShowDeptModal] = useState(false)
  const [showSectionModal, setShowSectionModal] = useState(false)
  const [showSubsectionModal, setShowSubsectionModal] = useState(false)
  const [editingDept, setEditingDept] = useState(null)
  const [editingSection, setEditingSection] = useState(null)
  const [editingSubsection, setEditingSubsection] = useState(null)

  // Form states
  const [deptName, setDeptName] = useState('')
  const [deptDescription, setDeptDescription] = useState('')
  const [sectionName, setSectionName] = useState('')
  const [sectionDepartmentId, setSectionDepartmentId] = useState('')
  const [subsectionName, setSubsectionName] = useState('')
  const [subsectionSectionId, setSubsectionSectionId] = useState('')
  const [subsectionDepartmentId, setSubsectionDepartmentId] = useState('')
  const [availableSections, setAvailableSections] = useState([])

  useEffect(() => {
    fetchAllData()
  }, [])

  const fetchAllData = async () => {
    try {
      const [deptRes, sectionRes, subsectionRes] = await Promise.all([
        api.get('/departments'),
        api.get('/sections'),
        api.get('/subsections')
      ])

      // Debug logging
      console.log('Sections response:', sectionRes.data)
      console.log('Subsections response:', subsectionRes.data)

      setDepartments(deptRes.data.data || [])
      setSections(sectionRes.data.data || [])
      setSubsections(subsectionRes.data.data || [])
    } catch (error) {
      console.error('Failed to fetch data:', error)
    } finally {
      setLoading(false)
    }
  }

  const handleAddDepartment = async (e) => {
    e.preventDefault()
    try {
      if (editingDept) {
        await api.put(`/departments/${editingDept.id}`, {
          name: deptName,
          description: deptDescription
        })
      } else {
        await api.post('/departments', {
          name: deptName,
          description: deptDescription
        })
      }
      setShowDeptModal(false)
      resetDeptForm()
      fetchAllData()
    } catch (error) {
      console.error('Failed to save department:', error)
    }
  }

  const handleAddSection = async (e) => {
    e.preventDefault()
    try {
      const sectionData = {
        name: sectionName,
        department_id: sectionDepartmentId || null
      }
      
      console.log('Saving section:', editingSection ? 'UPDATE' : 'CREATE', sectionData)
      
      if (editingSection) {
        await api.put(`/sections/${editingSection.id}`, sectionData)
      } else {
        await api.post('/sections', sectionData)
      }
      setShowSectionModal(false)
      resetSectionForm()
      fetchAllData()
    } catch (error) {
      console.error('Failed to save section:', error)
      console.error('Error response:', error.response?.data)
      alert('Failed to save section: ' + (error.response?.data?.message || error.message))
    }
  }

  const handleAddSubsection = async (e) => {
    e.preventDefault()
    try {
      const subsectionData = {
        name: subsectionName,
        section_id: subsectionSectionId,
        department_id: subsectionDepartmentId || null
      }

      if (editingSubsection) {
        await api.put(`/subsections/${editingSubsection.id}`, subsectionData)
      } else {
        await api.post('/subsections', subsectionData)
      }
      setShowSubsectionModal(false)
      resetSubsectionForm()
      fetchAllData()
    } catch (error) {
      console.error('Failed to save subsection:', error)
    }
  }

  const handleEditDepartment = (dept) => {
    setEditingDept(dept)
    setDeptName(dept.name)
    setDeptDescription(dept.description || '')
    setShowDeptModal(true)
  }

  const handleEditSection = (section) => {
    setEditingSection(section)
    setSectionName(section.name)
    setSectionDepartmentId(section.department_id)
    setShowSectionModal(true)
  }

  const handleEditSubsection = (subsection) => {
    setEditingSubsection(subsection)
    setSubsectionName(subsection.name)
    setSubsectionSectionId(subsection.section_id)
    // Find the department for this section
    const section = sections.find(s => s.id === subsection.section_id)
    setSubsectionDepartmentId(section?.department_id || '')
    setShowSubsectionModal(true)
  }

  const handleDeleteDepartment = async (id) => {
    if (!confirm('Are you sure you want to delete this department?')) return
    try {
      await api.delete(`/departments/${id}`)
      fetchAllData()
    } catch (error) {
      console.error('Failed to delete department:', error)
    }
  }

  const handleDeleteSection = async (id) => {
    if (!confirm('Are you sure you want to delete this section?')) return
    try {
      await api.delete(`/sections/${id}`)
      fetchAllData()
    } catch (error) {
      console.error('Failed to delete section:', error)
    }
  }

  const handleDeleteSubsection = async (id) => {
    if (!confirm('Are you sure you want to delete this subsection?')) return
    try {
      await api.delete(`/subsections/${id}`)
      fetchAllData()
    } catch (error) {
      console.error('Failed to delete subsection:', error)
    }
  }

  const resetDeptForm = () => {
    setEditingDept(null)
    setDeptName('')
    setDeptDescription('')
  }

  const resetSectionForm = () => {
    setEditingSection(null)
    setSectionName('')
    setSectionDepartmentId('')
  }

  const resetSubsectionForm = () => {
    setEditingSubsection(null)
    setSubsectionName('')
    setSubsectionSectionId('')
    setSubsectionDepartmentId('')
    setAvailableSections([])
  }

  const getDepartmentName = (id) => {
    const dept = departments.find(d => d.id === parseInt(id))
    return dept?.name || 'Unknown'
  }

  const getSectionName = (id) => {
    const section = sections.find(s => s.id === parseInt(id))
    return section?.name || 'Unknown'
  }

  // Use enriched data from API if available
  const getDepartmentNameFromSection = (section) => {
    return section.department_name || getDepartmentName(section.department_id)
  }

  const getSectionNameFromSubsection = (subsection) => {
    return subsection.section_name || getSectionName(subsection.section_id)
  }

  // Fetch sections when department changes in subsection modal
  useEffect(() => {
    const fetchSectionsForDepartment = async () => {
      if (!subsectionDepartmentId || !showSubsectionModal) {
        setAvailableSections([])
        return
      }

      try {
        console.log('Fetching sections for department:', subsectionDepartmentId)
        const response = await api.get(`/sections?department_id=${subsectionDepartmentId}`)
        console.log('Sections response:', response.data)
        console.log('Sections data:', response.data.data)
        setAvailableSections(response.data.data || [])
      } catch (error) {
        console.error('Failed to fetch sections:', error)
        setAvailableSections([])
      }
    }

    fetchSectionsForDepartment()
  }, [subsectionDepartmentId, showSubsectionModal])

  const deptColumns = [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Department Name' },
    { key: 'description', label: 'Description' },
    {
      key: 'actions',
      label: 'Actions',
      render: (value, row) => {
        if (!row || !row.id) return null
        return (
          <div className="flex gap-2">
            <button
              onClick={() => handleEditDepartment(row)}
              className="text-blue-600 hover:text-blue-800"
              title="Edit"
            >
              <Edit2 className="h-4 w-4" />
            </button>
            <button
              onClick={() => handleDeleteDepartment(row.id)}
              className="text-red-600 hover:text-red-800"
              title="Delete"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        )
      }
    }
  ]

  const sectionColumns = [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Section Name' },
    {
      key: 'department_id',
      label: 'Department',
      render: (value, row) => row.department_name || getDepartmentName(value)
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (value, row) => {
        if (!row || !row.id) return null
        return (
          <div className="flex gap-2">
            <button
              onClick={() => handleEditSection(row)}
              className="text-blue-600 hover:text-blue-800"
              title="Edit"
            >
              <Edit2 className="h-4 w-4" />
            </button>
            <button
              onClick={() => handleDeleteSection(row.id)}
              className="text-red-600 hover:text-red-800"
              title="Delete"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        )
      }
    }
  ]

  const subsectionColumns = [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Subsection Name' },
    {
      key: 'section_id',
      label: 'Section',
      render: (value, row) => row.section_name || getSectionName(value)
    },
    {
      key: 'actions',
      label: 'Actions',
      render: (value, row) => {
        if (!row || !row.id) return null
        return (
          <div className="flex gap-2">
            <button
              onClick={() => handleEditSubsection(row)}
              className="text-blue-600 hover:text-blue-800"
              title="Edit"
            >
              <Edit2 className="h-4 w-4" />
            </button>
            <button
              onClick={() => handleDeleteSubsection(row.id)}
              className="text-red-600 hover:text-red-800"
              title="Delete"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        )
      }
    }
  ]

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Departments</h1>
        <p className="text-gray-500">Manage departments, sections, and subsections</p>
      </div>

      {/* Departments Section */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold text-gray-800">Departments</h2>
          <Button onClick={() => { resetDeptForm(); setShowDeptModal(true) }}>
            <Plus className="h-4 w-4 mr-2" />
            Add Department
          </Button>
        </div>
        <Table
          columns={deptColumns}
          data={departments}
          emptyMessage="No departments found"
        />
      </Card>

      {/* Sections Section */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold text-gray-800">Sections</h2>
          <Button onClick={() => { resetSectionForm(); setShowSectionModal(true) }}>
            <Plus className="h-4 w-4 mr-2" />
            Add Section
          </Button>
        </div>
        <Table
          columns={sectionColumns}
          data={sections}
          emptyMessage="No sections found"
        />
      </Card>

      {/* Subsections Section */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold text-gray-800">Subsections</h2>
          <Button onClick={() => { resetSubsectionForm(); setShowSubsectionModal(true) }}>
            <Plus className="h-4 w-4 mr-2" />
            Add Subsection
          </Button>
        </div>
        <Table
          columns={subsectionColumns}
          data={subsections}
          emptyMessage="No subsections found"
        />
      </Card>

      {/* Add/Edit Department Modal */}
      {showDeptModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4">
              {editingDept ? 'Edit Department' : 'Add Department'}
            </h2>
            <form onSubmit={handleAddDepartment}>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Department Name
                  </label>
                  <input
                    type="text"
                    value={deptName}
                    onChange={(e) => setDeptName(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Description
                  </label>
                  <textarea
                    value={deptDescription}
                    onChange={(e) => setDeptDescription(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    rows="3"
                  />
                </div>
              </div>
              <div className="flex justify-end gap-2 mt-6">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => { setShowDeptModal(false); resetDeptForm() }}
                >
                  Cancel
                </Button>
                <Button type="submit">
                  {editingDept ? 'Update' : 'Create'}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add/Edit Section Modal */}
      {showSectionModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4">
              {editingSection ? 'Edit Section' : 'Add Section'}
            </h2>
            <form onSubmit={handleAddSection}>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Section Name
                  </label>
                  <input
                    type="text"
                    value={sectionName}
                    onChange={(e) => setSectionName(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Department
                  </label>
                  <select
                    value={sectionDepartmentId}
                    onChange={(e) => setSectionDepartmentId(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                  >
                    <option value="">Select Department</option>
                    {departments.map(dept => (
                      <option key={dept.id} value={dept.id}>
                        {dept.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="flex justify-end gap-2 mt-6">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => { setShowSectionModal(false); resetSectionForm() }}
                >
                  Cancel
                </Button>
                <Button type="submit">
                  {editingSection ? 'Update' : 'Create'}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add/Edit Subsection Modal */}
      {showSubsectionModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4">
              {editingSubsection ? 'Edit Subsection' : 'Add Subsection'}
            </h2>
            <form onSubmit={handleAddSubsection}>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Subsection Name
                  </label>
                  <input
                    type="text"
                    value={subsectionName}
                    onChange={(e) => setSubsectionName(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Department
                  </label>
                  <select
                    value={subsectionDepartmentId}
                    onChange={(e) => {
                      setSubsectionDepartmentId(e.target.value)
                      setSubsectionSectionId('') // Reset section when department changes
                    }}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                  >
                    <option value="">Select Department</option>
                    {departments.map(dept => (
                      <option key={dept.id} value={dept.id}>
                        {dept.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Section
                  </label>
                  <select
                    value={subsectionSectionId}
                    onChange={(e) => setSubsectionSectionId(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-primary-500"
                    required
                    disabled={!subsectionDepartmentId}
                  >
                    <option value="">Select Section</option>
                    {availableSections.map(section => (
                      <option key={section.id} value={section.id}>
                        {section.name}
                      </option>
                    ))}
                  </select>
                  {availableSections.length === 0 && subsectionDepartmentId && (
                    <p className="mt-1 text-sm text-yellow-600">No sections found for this department</p>
                  )}
                </div>
              </div>
              <div className="flex justify-end gap-2 mt-6">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => { setShowSubsectionModal(false); resetSubsectionForm() }}
                >
                  Cancel
                </Button>
                <Button type="submit">
                  {editingSubsection ? 'Update' : 'Create'}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

export default Departments