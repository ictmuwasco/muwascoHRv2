import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import LeaveBalances from '../pages/settings/LeaveBalances';

const mocks = vi.hoisted(() => ({
  getManagedBalances: vi.fn(),
  updateManagedBalance: vi.fn(),
  addManagedAllocation: vi.fn(),
  carryForwardManagedBalances: vi.fn(),
  user: { role: 'super_admin' },
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}));

vi.mock('../api/services/leaveService', () => ({
  leaveService: {
    getManagedBalances: mocks.getManagedBalances,
    updateManagedBalance: mocks.updateManagedBalance,
    addManagedAllocation: mocks.addManagedAllocation,
    carryForwardManagedBalances: mocks.carryForwardManagedBalances,
  },
}));

vi.mock('../context/AuthContext', () => ({
  useAuth: () => ({ user: mocks.user }),
}));

vi.mock('react-hot-toast', () => ({
  default: { error: mocks.toastError, success: mocks.toastSuccess },
}));

const balanceData = {
  financial_years: [
    {
      id: 39,
      year_name: 'FY 2024/25',
      start_date: '2024-07-01',
      end_date: '2025-06-30',
      is_active: false,
    },
    {
      id: 40,
      year_name: 'FY 2025/26',
      start_date: '2025-07-01',
      end_date: '2026-06-30',
      is_active: true,
    },
  ],
  leave_types: [{ id: 1, name: 'Annual Leave', is_active: true }],
  employees: [
    {
      id: 392,
      employee_id: 'EMP-392',
      first_name: 'Ada',
      last_name: 'Doe',
      surname: 'Smith',
      employee_status: 'active',
    },
  ],
  selected_financial_year_id: 39,
  balances: [
    {
      id: 1,
      employee_id: 392,
      employee_code: 'EMP-392',
      first_name: 'Ada',
      last_name: 'Doe',
      surname: 'Smith',
      employee_status: 'active',
      leave_type_id: 1,
      leave_type: 'Annual Leave',
      financial_year_id: 39,
      year_name: 'FY 2024/25',
      allocated_days: 30,
      brought_forward_days: 4,
      accumulated_days: 34,
      used_days: 1,
      remaining_days: 33,
    },
  ],
};

describe('LeaveBalances', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.user.role = 'super_admin';
    mocks.getManagedBalances.mockResolvedValue({
      success: true,
      message: 'OK',
      data: balanceData,
    });
    mocks.updateManagedBalance.mockResolvedValue({ success: true, message: 'OK', data: {} });
    mocks.addManagedAllocation.mockResolvedValue({ success: true, message: 'OK', data: {} });
    mocks.carryForwardManagedBalances.mockResolvedValue({
      success: true,
      message: 'OK',
      data: {
        source_financial_year: 'FY 2024/25',
        target_financial_year: 'FY 2025/26',
        updated: 0,
        created: 1,
        total: 1,
      },
    });
  });

  it('refuses to load or show balance data to non-super-admins', () => {
    mocks.user.role = 'hr_manager';

    render(<LeaveBalances />);

    expect(screen.getByText('Only Super Admin may manage leave balances.')).toBeInTheDocument();
    expect(mocks.getManagedBalances).not.toHaveBeenCalled();
  });

  it('updates used days including negative adjustments and previews derived totals', async () => {
    render(<LeaveBalances />);

    expect(await screen.findByText('Ada Doe Smith')).toBeInTheDocument();
    fireEvent.change(
      screen.getByRole('textbox', {
        name: 'Search by first name, last name, surname, employee ID, or leave type',
      }),
      { target: { value: 'Smith' } },
    );
    expect(screen.getByText('Ada Doe Smith')).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole('button', { name: 'Update Ada Doe Smith Annual Leave balance' }),
    );

    expect(screen.getByLabelText('Allocated days')).toHaveValue(30);
    expect(screen.getByLabelText('Brought-forward days')).toHaveValue(4);
    expect(screen.getByLabelText('Used days')).toHaveValue(1);
    expect(screen.getByLabelText('Accumulated preview')).toHaveTextContent('34');
    expect(screen.getByLabelText('Remaining preview')).toHaveTextContent('33');

    fireEvent.change(screen.getByLabelText('Allocated days'), { target: { value: '32' } });
    fireEvent.change(screen.getByLabelText('Used days'), { target: { value: '-2' } });
    expect(screen.getByLabelText('Accumulated preview')).toHaveTextContent('36');
    expect(screen.getByLabelText('Remaining preview')).toHaveTextContent('38');
    fireEvent.click(screen.getByRole('button', { name: 'Save balance' }));

    await waitFor(() => {
      expect(mocks.updateManagedBalance).toHaveBeenCalledWith({
        employee_id: 392,
        leave_type_id: 1,
        financial_year_id: 39,
        allocated_days: 32,
        brought_forward_days: 4,
        used_days: -2,
      });
    });
  });

  it('confirms annual-only carry-forward for the selected year', async () => {
    render(<LeaveBalances />);

    await screen.findByText('Ada Doe Smith');
    fireEvent.click(screen.getByRole('button', { name: /Carry Annual Forward/ }));
    expect(await screen.findByText(/overwrites brought-forward days/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm carry-forward' }));

    await waitFor(() => {
      expect(mocks.carryForwardManagedBalances).toHaveBeenCalledWith(39);
    });
  });
});
