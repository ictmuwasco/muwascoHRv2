import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { ArrowRightLeft, Pencil, Plus, RefreshCw, Search, Wallet } from 'lucide-react';
import toast from 'react-hot-toast';
import { leaveService, type ManagedLeaveBalance } from '../../api/services/leaveService';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import { useAuth } from '../../context/AuthContext';

type ModalMode = 'update' | 'allocation' | 'carry-forward' | null;

const PAGE_SIZE = 20;

const errorMessage = (error: unknown, fallback: string): string => {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response;
    if (response?.data?.message) return response.data.message;
  }
  return error instanceof Error && error.message ? error.message : fallback;
};

const employeeName = (balance: ManagedLeaveBalance): string =>
  [balance.first_name, balance.last_name, balance.surname].filter(Boolean).join(' ').trim() ||
  `Employee #${balance.employee_id}`;

const formatDays = (value: number): string =>
  Number.isInteger(value) ? String(value) : value.toFixed(2);

const LeaveBalances = () => {
  const { user } = useAuth();
  const isSuperAdmin = String(user?.role || '').toLowerCase() === 'super_admin';
  const [balances, setBalances] = useState<ManagedLeaveBalance[]>([]);
  const [financialYears, setFinancialYears] = useState<
    Array<{ id: number; year_name: string; start_date: string; end_date: string; is_active: boolean }>
  >([]);
  const [leaveTypes, setLeaveTypes] = useState<Array<{ id: number; name: string; is_active: boolean }>>(
    [],
  );
  const [employees, setEmployees] = useState<
    Array<{
      id: number;
      employee_id: string | null;
      first_name: string | null;
      last_name: string | null;
      surname: string | null;
      employee_status: string;
    }>
  >([]);
  const [financialYearId, setFinancialYearId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [modalMode, setModalMode] = useState<ModalMode>(null);
  const [selectedBalance, setSelectedBalance] = useState<ManagedLeaveBalance | null>(null);
  const [allocatedDays, setAllocatedDays] = useState('');
  const [broughtForwardDays, setBroughtForwardDays] = useState('');
  const [usedDays, setUsedDays] = useState('');
  const [allocationEmployeeId, setAllocationEmployeeId] = useState('');
  const [allocationLeaveTypeId, setAllocationLeaveTypeId] = useState('');
  const [allocationDays, setAllocationDays] = useState('');

  const loadBalances = useCallback(async (yearId?: number) => {
    setLoading(true);
    try {
      const response = await leaveService.getManagedBalances(yearId);
      if (!response.success || !response.data) {
        throw new Error(response.message || 'The server returned no balance data.');
      }

      setBalances(response.data.balances);
      setFinancialYears(response.data.financial_years);
      setLeaveTypes(response.data.leave_types);
      setEmployees(response.data.employees);
      setFinancialYearId(response.data.selected_financial_year_id || null);
    } catch (error) {
      toast.error(errorMessage(error, 'Failed to load leave balances.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!isSuperAdmin) return;
    void loadBalances();
  }, [isSuperAdmin, loadBalances]);

  const selectedYear = financialYears.find((year) => year.id === financialYearId);
  const nextFinancialYear = useMemo(() => {
    if (!selectedYear) return undefined;
    return financialYears
      .filter((year) => year.start_date > selectedYear.start_date)
      .sort((left, right) => left.start_date.localeCompare(right.start_date))[0];
  }, [financialYears, selectedYear]);

  const filteredBalances = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return balances;
    return balances.filter((balance) =>
      [
        employeeName(balance),
        balance.employee_code ?? '',
        balance.leave_type ?? '',
        balance.employee_status ?? '',
      ]
        .join(' ')
        .toLowerCase()
        .includes(term),
    );
  }, [balances, search]);

  if (!isSuperAdmin) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
        Only Super Admin may manage leave balances.
      </div>
    );
  }

  const pageCount = Math.max(1, Math.ceil(filteredBalances.length / PAGE_SIZE));
  const visibleBalances = filteredBalances.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  const accumulatedPreview = Number(allocatedDays) + Number(broughtForwardDays);
  const remainingPreview = accumulatedPreview - Number(usedDays);
  const hasValidPreview =
    allocatedDays !== '' &&
    broughtForwardDays !== '' &&
    usedDays !== '' &&
    Number.isFinite(accumulatedPreview) &&
    Number.isFinite(remainingPreview);

  const openUpdate = (balance: ManagedLeaveBalance) => {
    setSelectedBalance(balance);
    setAllocatedDays(String(balance.allocated_days));
    setBroughtForwardDays(String(balance.brought_forward_days));
    setUsedDays(String(balance.used_days));
    setModalMode('update');
  };

  const openAllocation = () => {
    setAllocationEmployeeId('');
    setAllocationLeaveTypeId('');
    setAllocationDays('');
    setModalMode('allocation');
  };

  const closeModal = () => {
    if (saving) return;
    setModalMode(null);
    setSelectedBalance(null);
  };

  const handleUpdate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedBalance || financialYearId === null) return;

    setSaving(true);
    try {
      const response = await leaveService.updateManagedBalance({
        employee_id: selectedBalance.employee_id,
        leave_type_id: selectedBalance.leave_type_id,
        financial_year_id: financialYearId,
        allocated_days: Number(allocatedDays),
        brought_forward_days: Number(broughtForwardDays),
        used_days: Number(usedDays),
      });
      if (!response.success) throw new Error(response.message || 'Balance update failed.');
      toast.success('Leave balance updated.');
      setModalMode(null);
      setSelectedBalance(null);
      await loadBalances(financialYearId);
    } catch (error) {
      toast.error(errorMessage(error, 'Failed to update leave balance.'));
    } finally {
      setSaving(false);
    }
  };

  const handleAllocation = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (financialYearId === null) return;

    setSaving(true);
    try {
      const response = await leaveService.addManagedAllocation({
        employee_id: Number(allocationEmployeeId),
        leave_type_id: Number(allocationLeaveTypeId),
        financial_year_id: financialYearId,
        days: Number(allocationDays),
      });
      if (!response.success) throw new Error(response.message || 'Allocation failed.');
      toast.success('Leave allocation added.');
      setModalMode(null);
      await loadBalances(financialYearId);
    } catch (error) {
      toast.error(errorMessage(error, 'Failed to add leave allocation.'));
    } finally {
      setSaving(false);
    }
  };

  const handleCarryForward = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (financialYearId === null || !nextFinancialYear) return;

    setSaving(true);
    try {
      const response = await leaveService.carryForwardManagedBalances(financialYearId);
      if (!response.success || !response.data) {
        throw new Error(response.message || 'Carry-forward failed.');
      }
      toast.success(
        `Annual balances carried to ${response.data.target_financial_year}: ${response.data.total} employee balances.`,
      );
      setModalMode(null);
      await loadBalances(financialYearId);
    } catch (error) {
      toast.error(errorMessage(error, 'Failed to carry forward annual leave balances.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Leave Balances</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Manage allocations and annual leave carry-forward. Used days remain controlled by
            approved leave applications.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            onClick={() => setModalMode('carry-forward')}
            disabled={!selectedYear || !nextFinancialYear || saving}
          >
            <ArrowRightLeft className="mr-2 h-4 w-4" />
            Carry Annual Forward
          </Button>
          <Button onClick={openAllocation} disabled={!financialYearId || saving}>
            <Plus className="mr-2 h-4 w-4" />
            Add Allocation
          </Button>
        </div>
      </div>

      <Card>
        <div className="mb-5 space-y-4">
          <div className="w-full">
            <label
              className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
              htmlFor="leave-balance-search"
            >
              Search by first name, last name, surname, employee ID, or leave type
            </label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <Input
                id="leave-balance-search"
                aria-label="Search by first name, last name, surname, employee ID, or leave type"
                placeholder="Enter any part of an employee name or surname"
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setPage(1);
                }}
                className="pl-9"
              />
            </div>
          </div>
          <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div className="w-full md:max-w-xs">
              <Select
                label="Financial year"
                aria-label="Financial year"
                value={financialYearId ?? ''}
                options={financialYears.map((year) => ({
                  value: year.id,
                  label: `${year.year_name}${year.is_active ? ' (Active)' : ''}`,
                }))}
                onChange={(event) => {
                  const nextId = Number(event.target.value);
                  setPage(1);
                  if (nextId > 0) void loadBalances(nextId);
                }}
              />
            </div>
            <Button
              variant="outline"
              aria-label="Refresh balances"
              onClick={() => void loadBalances(financialYearId ?? undefined)}
              disabled={loading}
            >
              <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            </Button>
          </div>
        </div>

        {loading ? (
          <div className="flex h-40 items-center justify-center text-sm text-gray-500">
            Loading balances…
          </div>
        ) : visibleBalances.length === 0 ? (
          <div className="flex h-40 flex-col items-center justify-center gap-2 text-sm text-gray-500">
            <Wallet className="h-6 w-6" />
            No leave balances found for this financial year.
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    {[
                      'Employee',
                      'Leave type',
                      'Allocated',
                      'Brought forward',
                      'Accumulated',
                      'Used',
                      'Remaining',
                      'Actions',
                    ].map((heading) => (
                      <th
                        key={heading}
                        className="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
                      >
                        {heading}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white dark:divide-slate-700 dark:bg-slate-800">
                  {visibleBalances.map((balance) => (
                    <tr key={balance.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                        <div className="font-medium">{employeeName(balance)}</div>
                        <div className="text-xs text-gray-500">
                          {balance.employee_code || `#${balance.employee_id}`}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                        {balance.leave_type || `Type ${balance.leave_type_id}`}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm">{formatDays(balance.allocated_days)}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm">
                        {formatDays(balance.brought_forward_days)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm">
                        {formatDays(balance.accumulated_days)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm">{formatDays(balance.used_days)}</td>
                      <td
                        className={`whitespace-nowrap px-4 py-3 text-sm font-semibold ${
                          balance.remaining_days < 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-gray-900 dark:text-gray-100'
                        }`}
                      >
                        {formatDays(balance.remaining_days)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-sm">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openUpdate(balance)}
                          aria-label={`Update ${employeeName(balance)} ${balance.leave_type} balance`}
                        >
                          <Pencil className="mr-1 h-3.5 w-3.5" />
                          Edit
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="mt-4 flex items-center justify-between text-sm text-gray-500">
              <span>
                {filteredBalances.length === 0
                  ? '0'
                  : `${(page - 1) * PAGE_SIZE + 1}–${Math.min(page * PAGE_SIZE, filteredBalances.length)}`}{' '}
                of {filteredBalances.length}
              </span>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                  disabled={page <= 1}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((current) => Math.min(pageCount, current + 1))}
                  disabled={page >= pageCount}
                >
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      <Modal
        isOpen={modalMode === 'update'}
        onClose={closeModal}
        title="Update leave balance"
        persistent={saving}
      >
        {selectedBalance && (
          <form onSubmit={handleUpdate} className="space-y-4">
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {employeeName(selectedBalance)} · {selectedBalance.leave_type} ·{' '}
              {selectedBalance.year_name}
            </p>
            <Input
              label="Allocated days"
              aria-label="Allocated days"
              type="number"
              min="0"
              max="999.99"
              step="0.01"
              required
              value={allocatedDays}
              onChange={(event) => setAllocatedDays(event.target.value)}
            />
            <Input
              label="Brought-forward days"
              aria-label="Brought-forward days"
              type="number"
              min="-999.99"
              max="999.99"
              step="0.01"
              required
              value={broughtForwardDays}
              onChange={(event) => setBroughtForwardDays(event.target.value)}
            />
            <Input
              label="Used days"
              aria-label="Used days"
              type="number"
              min="-999.99"
              max="999.99"
              step="0.01"
              required
              value={usedDays}
              onChange={(event) => setUsedDays(event.target.value)}
            />
            <div
              className="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3 text-sm dark:bg-slate-900"
              aria-live="polite"
            >
              <div>
                <span className="block text-gray-500 dark:text-gray-400">Accumulated preview</span>
                <strong aria-label="Accumulated preview">
                  {hasValidPreview ? formatDays(accumulatedPreview) : '—'}
                </strong>
              </div>
              <div>
                <span className="block text-gray-500 dark:text-gray-400">Remaining preview</span>
                <strong aria-label="Remaining preview">
                  {hasValidPreview ? formatDays(remainingPreview) : '—'}
                </strong>
              </div>
            </div>
            <p className="text-xs text-gray-500">
              Negative values are allowed for used days. Accumulated is allocated plus brought
              forward; remaining is accumulated minus used. Both are recalculated when saved.
            </p>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={closeModal} disabled={saving}>
                Cancel
              </Button>
              <Button type="submit" loading={saving}>
                Save balance
              </Button>
            </div>
          </form>
        )}
      </Modal>

      <Modal
        isOpen={modalMode === 'allocation'}
        onClose={closeModal}
        title="Add leave allocation"
        persistent={saving}
      >
        <form onSubmit={handleAllocation} className="space-y-4">
          <Select
            label="Employee"
            aria-label="Employee"
            required
            value={allocationEmployeeId}
            onChange={(event) => setAllocationEmployeeId(event.target.value)}
            options={employees.map((employee) => ({
              value: employee.id,
              label: [employee.first_name, employee.last_name, employee.surname]
                .filter(Boolean)
                .join(' ')
                .trim()
                + ` (${employee.employee_id ?? employee.id})`
                + (employee.employee_status === 'active' ? '' : ` — ${employee.employee_status}`),
            }))}
          />
          <Select
            label="Leave type"
            aria-label="Leave type"
            required
            value={allocationLeaveTypeId}
            onChange={(event) => setAllocationLeaveTypeId(event.target.value)}
            options={leaveTypes.map((type) => ({
              value: type.id,
              label: `${type.name}${type.is_active ? '' : ' (Inactive)'}`,
            }))}
          />
          <Input
            label="Days to add"
            aria-label="Days to add"
            type="number"
            min="0.01"
            max="999.99"
            step="0.01"
            required
            value={allocationDays}
            onChange={(event) => setAllocationDays(event.target.value)}
          />
          <p className="text-xs text-gray-500">
            This adds to the selected employee’s allocation for {selectedYear?.year_name ?? 'the selected year'}.
            Used days are preserved.
          </p>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={closeModal} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              Add allocation
            </Button>
          </div>
        </form>
      </Modal>

      <Modal
        isOpen={modalMode === 'carry-forward'}
        onClose={closeModal}
        title="Carry annual leave forward"
        persistent={saving}
      >
        <form onSubmit={handleCarryForward} className="space-y-4">
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Carry each employee’s annual leave remaining in <strong>{selectedYear?.year_name}</strong>{' '}
            into brought-forward days for <strong>{nextFinancialYear?.year_name}</strong>.
          </p>
          <p className="text-sm text-amber-700 dark:text-amber-300">
            This overwrites brought-forward days on existing target annual balances. Target
            allocations and used days are preserved; accumulated and remaining days are recalculated.
            Other leave types are not changed.
          </p>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={closeModal} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" variant="primary" loading={saving}>
              Confirm carry-forward
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
};

export default LeaveBalances;
