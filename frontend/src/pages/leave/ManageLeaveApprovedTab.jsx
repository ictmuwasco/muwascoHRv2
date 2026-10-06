import { useState, useEffect } from 'react';
import api from '../../utils/api';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import { FileText } from 'lucide-react';
import LeaveDocuments, { leaveSupportsDocuments } from './LeaveDocuments.jsx';

import {
  badgeClass,
  formatDate,
  formatStatus,
  ROWS_PER_PAGE,
  Pagination,
} from './leaveManageShared.jsx';

const ApprovedTab = () => {
  const [rows, setRows] = useState([]);
  const [, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [offset, setOffset] = useState(0);
  const [count, setCount] = useState(0);
  // Sick / Study evidence viewer (only those types carry documents).
  const [docsRow, setDocsRow] = useState(null);

  useEffect(() => {
    fetchRows();
  }, [offset]);

  const fetchRows = async () => {
    setLoading(true);
    setError('');
    try {
      const response = await api.get('/leave/manage', {
        params: { limit: ROWS_PER_PAGE, approved_offset: offset },
      });
      const data = response.data?.data || {};
      setRows(data.approved || []);
      setCount(data.counts?.approved ?? 0);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load approved leaves.');
    } finally {
      setLoading(false);
    }
  };

  const renderRows = () => {
    if (!rows.length) {
      return (
        <tr>
          <td colSpan={7} className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
            No approved leaves on record.
          </td>
        </tr>
      );
    }
    return rows.map((row) => (
      <tr key={row.id} className="border-t border-gray-200 dark:border-slate-700">
        <td className="px-4 py-2">
          <div className="font-medium text-gray-900 dark:text-gray-100">
            {row.first_name} {row.last_name}
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            {row.emp_no || row.employee_id}
          </div>
        </td>
        <td className="px-4 py-2">{row.leave_type_name}</td>
        <td className="px-4 py-2 text-sm">
          {formatDate(row.start_date)} → {formatDate(row.end_date)}
        </td>
        <td className="px-4 py-2 text-sm">{row.days_requested || '—'}</td>
        <td className="px-4 py-2">
          <span className={`px-2 py-1 rounded-full text-xs font-medium ${badgeClass(row.status)}`}>
            {formatStatus(row.status)}
          </span>
        </td>
        <td className="px-4 py-2 text-sm">
          <div className="text-gray-900 dark:text-gray-100">{row.approver_name || 'System'}</div>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            {formatDate(row.action_date)}
          </div>
        </td>
        <td className="px-4 py-2">
          {/* Docs only for Sick / Study leave; other types render no cell. */}
          {leaveSupportsDocuments(row) ? (
            <Button size="sm" variant="outline" onClick={() => setDocsRow(row)}>
              <FileText className="h-3 w-3 mr-1" /> Docs
            </Button>
          ) : (
            <span className="text-gray-400 dark:text-gray-500">—</span>
          )}
        </td>
      </tr>
    ));
  };

  const pages = Math.max(1, Math.ceil(count / ROWS_PER_PAGE));

  return (
    <>
      <Card>
        {error && (
          <div className="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-md mb-4">
            {error}
          </div>
        )}
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-600 dark:text-gray-400">
                <th className="px-4 py-2">Employee</th>
                <th className="px-4 py-2">Leave Type</th>
                <th className="px-4 py-2">Dates</th>
                <th className="px-4 py-2">Days</th>
                <th className="px-4 py-2">Status</th>
                <th className="px-4 py-2">Final Approver</th>
                <th className="px-4 py-2">Docs</th>
              </tr>
            </thead>
            <tbody>{renderRows()}</tbody>
          </table>
        </div>
        <Pagination pages={pages} offset={offset} onChange={(newOffset) => setOffset(newOffset)} />
      </Card>

      {docsRow && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">
              Supporting Documents
            </h3>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-4">
              <strong>
                {docsRow.first_name} {docsRow.last_name}
              </strong>{' '}
              — {docsRow.leave_type_name}
              <br />
              {formatDate(docsRow.start_date)} → {formatDate(docsRow.end_date)}
            </p>
            <div className="mb-4">
              <LeaveDocuments
                applicationId={docsRow.id}
                leaveTypeId={docsRow.leave_type_id}
                leaveTypeName={docsRow.leave_type_name}
              />
            </div>
            <div className="flex justify-end">
              <Button variant="outline" onClick={() => setDocsRow(null)}>
                Close
              </Button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default ApprovedTab;
