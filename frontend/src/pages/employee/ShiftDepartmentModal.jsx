import { useEffect, useState } from 'react';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import Button from '../../components/ui/Button';
import api from '../../utils/api';

/**
 * Shift Department dialog (Employees page, Actions column).
 *
 * Moves an employee to a different department / section / subsection. The
 * hierarchy cascades exactly as requested:
 *   - The Section dropdown only renders when the selected department actually
 *     HAS sections - a sectionless department shows a note instead.
 *   - The Subsection dropdown only renders when the selected section actually
 *     HAS subsections - same rule one level down.
 * Changing the department clears section + subsection; changing the section
 * clears the subsection.
 *
 * PUT /employees/{id} validates the FULL employee record at the controller
 * (EmployeeValidator), so the current record is fetched on open and the three
 * hierarchy ids are merged into it on save; the service strips server-owned
 * keys (id, created_at, *_name, ...) via EMPLOYEE_WRITABLE_FIELDS.
 */
const ShiftDepartmentModal = ({ employee, isOpen, onClose, onUpdated }) => {
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const [departments, setDepartments] = useState([]);
  const [sections, setSections] = useState([]);
  const [subsections, setSubsections] = useState([]);

  /** Full record from GET /employees/{id} - merged into on save. */
  const [record, setRecord] = useState(null);

  const [departmentId, setDepartmentId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [subsectionId, setSubsectionId] = useState('');

  // Load reference data + the employee's current record every time the modal
  // opens. A stale response after closing is dropped via `cancelled`.
  useEffect(() => {
    if (!isOpen || !employee?.id) return undefined;

    let cancelled = false;
    const load = async () => {
      setLoading(true);
      setError('');
      setSuccess('');
      setRecord(null);
      try {
        const [refRes, empRes] = await Promise.all([
          api.get('/employees/reference'),
          api.get(`/employees/${employee.id}`),
        ]);
        if (cancelled) return;

        const ref = refRes.data?.data;
        const emp = empRes.data?.data;
        if (!emp) {
          setError('Failed to load the employee record. Please try again.');
          return;
        }

        const deptList = Array.isArray(ref?.departments) ? ref.departments : [];
        const sectionList = Array.isArray(ref?.sections) ? ref.sections : [];
        const subList = Array.isArray(ref?.subsections) ? ref.subsections : [];

        const empDept = emp.department_id ? String(emp.department_id) : '';
        const empSection = emp.section_id ? String(emp.section_id) : '';
        const empSub = emp.subsection_id ? String(emp.subsection_id) : '';

        // Normalise the prefill: never start on a dangling value - a section
        // outside the employee's department (or a subsection outside that
        // section) is treated as unset so the selects stay consistent.
        const sectionOk =
          empSection &&
          sectionList.some(
            (s) => String(s.department_id) === empDept && String(s.id) === empSection,
          );
        const nextSection = sectionOk ? empSection : '';
        const subOk =
          empSub &&
          nextSection &&
          subList.some((ss) => String(ss.section_id) === nextSection && String(ss.id) === empSub);

        setRecord(emp);
        setDepartments(deptList);
        setSections(sectionList);
        setSubsections(subList);
        setDepartmentId(empDept);
        setSectionId(nextSection);
        setSubsectionId(subOk ? empSub : '');
      } catch (err) {
        if (cancelled) return;
        console.error('Failed to load shift-department data:', err);
        setError('Failed to load departments and employee data. Please try again.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    load();
    return () => {
      cancelled = true;
    };
  }, [isOpen, employee?.id]);

  // Cascade: everything below a changed level resets to empty.
  const handleDepartmentChange = (e) => {
    setDepartmentId(e.target.value);
    setSectionId('');
    setSubsectionId('');
  };

  const handleSectionChange = (e) => {
    setSectionId(e.target.value);
    setSubsectionId('');
  };

  // Dropdown availability - the heart of the "no children -> no dropdown"
  // rule: these lists drive both the options AND whether a select renders.
  const availableSections = departmentId
    ? sections.filter((s) => String(s.department_id) === String(departmentId))
    : [];
  const availableSubsections = sectionId
    ? subsections.filter((ss) => String(ss.section_id) === String(sectionId))
    : [];

  const handleSave = async () => {
    if (!record) return;
    if (!departmentId) {
      setError('Please select a department.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      // The fetched record carries the next_of_kin / dependants JSON columns.
      // They are NOT part of a hierarchy shift and must not be rewritten (the
      // service re-decodes them strictly - Json::decodeRequest throws a
      // JsonException on control characters present in some stored rows,
      // surfacing as 500). EmployeeForm's PUT never includes them either.
      const payload = { ...record };
      delete payload.next_of_kin;
      delete payload.dependants;

      await api.put(`/employees/${employee.id}`, {
        ...payload,
        department_id: Number(departmentId),
        section_id: sectionId ? Number(sectionId) : null,
        subsection_id: subsectionId ? Number(subsectionId) : null,
      });
      setSuccess('Employee assignment updated.');
      // Refresh the table behind the modal, then close on the next beat so
      // the confirmation is visible for a moment.
      onUpdated?.();
      setTimeout(() => onClose(), 700);
    } catch (err) {
      console.error('Failed to shift employee department:', err);
      setError(err?.response?.data?.message || 'Failed to update employee assignment.');
    } finally {
      setSaving(false);
    }
  };

  const employeeName =
    [employee?.first_name, employee?.last_name].filter(Boolean).join(' ') || 'this employee';

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Shift Department" size="md">
      <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Move <span className="font-semibold text-gray-800 dark:text-gray-200">{employeeName}</span>{' '}
        to a different department, section or subsection.
      </p>

      {loading && (
        <div className="flex items-center justify-center py-8">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
        </div>
      )}

      {!loading && (
        <div className="space-y-4">
          <Select
            label="Department"
            value={departmentId}
            onChange={handleDepartmentChange}
            options={departments.map((d) => ({ value: d.id, label: d.name }))}
          />

          {/* Section select exists only when the department HAS sections. */}
          {availableSections.length > 0 ? (
            <Select
              label="Section"
              value={sectionId}
              onChange={handleSectionChange}
              options={availableSections.map((s) => ({ value: s.id, label: s.name }))}
            />
          ) : (
            departmentId && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                This department has no sections.
              </p>
            )
          )}

          {/* Subsection select exists only when the section HAS subsections. */}
          {availableSubsections.length > 0 && (
            <Select
              label="Subsection"
              value={subsectionId}
              onChange={(e) => setSubsectionId(e.target.value)}
              options={availableSubsections.map((ss) => ({
                value: ss.id,
                label: ss.name,
              }))}
            />
          )}
          {sectionId && availableSubsections.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              This section has no subsections.
            </p>
          )}

          {error && (
            <p className="text-sm text-red-600 dark:text-red-400" role="alert">
              {error}
            </p>
          )}
          {success && (
            <p className="text-sm text-green-600 dark:text-green-400" role="status">
              {success}
            </p>
          )}

          <div className="flex justify-end space-x-2 pt-2">
            <Button variant="outline" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button onClick={handleSave} loading={saving} disabled={loading || !record}>
              Save
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
};

export default ShiftDepartmentModal;
