import { useState, useEffect, useCallback } from 'react';
import api from '../../utils/api';
import { API_BASE_URL } from '../../config/api';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import { FileText, Download, Eye, Loader2, Paperclip } from 'lucide-react';

/**
 * LeaveDocuments — supporting evidence (sick / study leave notes, etc.)
 * for a single leave application.
 *
 * Backend:
 *   GET /leave/{id}/documents            → list metadata
 *   GET /leave/{id}/documents/{docId}    → streams the file (PDF/image inline)
 *
 * Auth is enforced server-side (LeaveDocumentService::canViewDocuments):
 * applicant, profile scope or the authorised approver — so supervisors /
 * MD / BOD chair see the evidence BEFORE deciding. A 403 here means
 * "no access", rendered as muted text rather than a red error.
 *
 * Documents only apply to Sick and Study leave (backend LeaveTypePolicy::
 * DOCUMENT_REQUIREMENTS — Sick id 2, Study id 5). For any other leave type
 * this component renders NOTHING (not even the "no documents" placeholder),
 * and callers should hide their Docs buttons via leaveSupportsDocuments().
 */
export const SICK_LEAVE_TYPE_ID = 2;
export const STUDY_LEAVE_TYPE_ID = 5;

/**
 * Whether a leave application row can carry supporting documents.
 * Matches by canonical leave_type_id (Sick 2 / Study 5), falling back to a
 * case-insensitive name check ("sick" / "study") for rows that only carry
 * leave_type_name. Unknown types → false (no document UI).
 */
export const leaveSupportsDocuments = (app) => {
  if (!app) return false;
  const id = Number(app.leave_type_id);
  if (id === SICK_LEAVE_TYPE_ID || id === STUDY_LEAVE_TYPE_ID) return true;
  const name = String(app.leave_type_name || app.leave_type || '').toLowerCase();
  return name.includes('sick') || name.includes('study');
};
const formatSize = (bytes) => {
  const n = Number(bytes) || 0;
  if (n <= 0) return '—';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(2)} MB`;
};

const LeaveDocuments = ({
  applicationId,
  compact = false,
  leaveTypeId = null,
  leaveTypeName = '',
}) => {
  const [docs, setDocs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [denied, setDenied] = useState(false);
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState(null);

  // Documents only exist for Sick / Study leave. For any other type render
  // NOTHING — not even the "no documents" placeholder (avoids noise like
  // "Supporting Documents / No supporting documents attached" on Annual leave).
  const supportsDocs = leaveSupportsDocuments({
    leave_type_id: leaveTypeId,
    leave_type_name: leaveTypeName,
  });

  const fetchDocs = useCallback(async () => {
    if (!applicationId) return;
    // Skip the network call entirely for leave types that never carry docs.
    if (!leaveSupportsDocuments({ leave_type_id: leaveTypeId, leave_type_name: leaveTypeName })) {
      setLoading(false);
      setDocs([]);
      return;
    }
    setLoading(true);
    setError('');
    setDenied(false);
    try {
      const res = await api.get(`/leave/${applicationId}/documents`, { dedupe: false });
      setDocs(res.data?.data || []);
    } catch (err) {
      const status = err.response?.status;
      if (status === 403 || status === 404) {
        setDenied(status === 403);
        setDocs([]);
      } else {
        setError(err.response?.data?.message || 'Failed to load documents.');
      }
    } finally {
      setLoading(false);
    }
  }, [applicationId, leaveTypeId, leaveTypeName]);

  useEffect(() => {
    fetchDocs();
  }, [fetchDocs]);

  const openDocument = async (doc, download = false) => {
    if (!doc || busyId) return;
    setBusyId(doc.id);
    try {
      const res = await api.get(`/leave/${applicationId}/documents/${doc.id}`, {
        responseType: 'blob',
        dedupe: false,
      });
      const blob = res.data instanceof Blob ? res.data : new Blob([res.data]);
      const url = window.URL.createObjectURL(blob);
      if (download) {
        const a = document.createElement('a');
        a.href = url;
        a.download = doc.original_filename || `leave-document-${doc.id}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => window.URL.revokeObjectURL(url), 5000);
      } else {
        window.open(url, '_blank', 'noopener,noreferrer');
        setTimeout(() => window.URL.revokeObjectURL(url), 5 * 60 * 1000);
      }
    } catch (err) {
      const status = err.response?.status;
      setError(
        status === 403
          ? 'You are not authorised to view this document.'
          : 'Failed to open document.',
      );
    } finally {
      setBusyId(null);
    }
  };

  const directUrl = (doc) => `${API_BASE_URL}/leave/${applicationId}/documents/${doc.id}`;

  // Non-sick/study leave: render nothing at all.
  if (!supportsDocs) return null;

  if (loading) {
    return (
      <div className="flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400 py-2">
        <Loader2 className="h-4 w-4 animate-spin" />
        <span>Loading documents…</span>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-sm text-red-600 dark:text-red-400 py-2">
        {error}{' '}
        <button type="button" className="underline" onClick={fetchDocs}>
          Retry
        </button>
      </div>
    );
  }

  if (denied) {
    return (
      <p className="text-sm text-gray-500 dark:text-gray-400 italic py-1">
        You are not authorised to view documents for this application.
      </p>
    );
  }

  if (!docs.length) {
    return (
      <p className="text-sm text-gray-500 dark:text-gray-400 italic py-1">
        <Paperclip className="h-3.5 w-3.5 inline mr-1" />
        No supporting documents attached.
      </p>
    );
  }

  return (
    <div className={compact ? '' : 'border border-gray-200 dark:border-slate-700 rounded-md'}>
      {!compact && (
        <div className="px-3 py-2 border-b border-gray-200 dark:border-slate-700 flex items-center space-x-2">
          <Paperclip className="h-4 w-4 text-gray-400" />
          <h5 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
            Supporting Documents ({docs.length})
          </h5>
        </div>
      )}
      <ul className="divide-y divide-gray-100 dark:divide-slate-700">
        {docs.map((doc) => (
          <li
            key={doc.id}
            className={`flex items-center space-x-3 ${compact ? 'py-1.5' : 'px-3 py-2.5'}`}
          >
            <div className="h-9 w-9 rounded-md bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
              <FileText className="h-4 w-4 text-blue-600 dark:text-blue-400" />
            </div>
            <div className="flex-1 min-w-0">
              <a
                href={directUrl(doc)}
                target="_blank"
                rel="noopener noreferrer"
                onClick={(e) => {
                  e.preventDefault();
                  openDocument(doc, false);
                }}
                className="text-sm font-medium text-primary-700 dark:text-primary-300 hover:underline truncate block"
                title={doc.original_filename || `Document #${doc.id}`}
              >
                {doc.original_filename || `Document #${doc.id}`}
              </a>
              <div className="flex items-center space-x-2 mt-0.5">
                {doc.document_type && doc.document_type !== 'other' && (
                  <Badge variant="default" className="text-[10px]">
                    {String(doc.document_type).replace(/_/g, ' ')}
                  </Badge>
                )}
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  {formatSize(doc.file_size)}
                </span>
              </div>
            </div>
            <div className="flex items-center space-x-1 flex-shrink-0">
              <Button
                size="sm"
                variant="outline"
                title="Preview document"
                onClick={() => openDocument(doc, false)}
                disabled={busyId === doc.id}
              >
                {busyId === doc.id ? (
                  <Loader2 className="h-3 w-3 animate-spin" />
                ) : (
                  <Eye className="h-3 w-3" />
                )}
                <span className="ml-1 hidden sm:inline">View</span>
              </Button>
              <Button
                size="sm"
                variant="outline"
                title="Download document"
                onClick={() => openDocument(doc, true)}
                disabled={busyId === doc.id}
              >
                <Download className="h-3 w-3" />
              </Button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
};

export default LeaveDocuments;
