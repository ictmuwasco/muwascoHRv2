/**
 * Settings HrPolicies — HR administration module (Phase 8).
 *
 * Provides the complete policy management interface:
 *  - Policy list (versions) with status workflow
 *  - Upload new policy (DRAFT)
 *  - Edit metadata (title, version, description, dates, source_type)
 *  - Publish (archives previous active version in same transaction)
 *  - Archive / Delete (soft-delete)
 *  - View history (versions + audit trail)
 *  - Acknowkedgements compliance view
 *
 * All actions require hr_policies:manage or hr_policies:publish.
 * The backend enforces authorization; this component gates the UI.
 */
import { useState, useEffect, type FormEvent } from 'react';
import { hrPolicyService } from '../../api/services/hrPolicyService';
import type {
  HrPolicyDocument,
  HistoryAuditRow,
  AckRecord,
} from '../../api/services/hrPolicyService';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import {
  Upload,
  Edit3,
  Archive,
  History,
  Trash2,
  CheckCircle,
  Clock,
  AlertCircle,
  Loader2,
  FileText,
  ExternalLink,
  Send,
  Download,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import toast from 'react-hot-toast';

/**
 * Extract the server-provided message from an axios-style error if present,
 * falling back to Error.message, then the given default. Keeps catch blocks
 * type-safe (err is unknown) while still surfacing validation messages.
 */
const extractErrorMessage = (err: unknown, fallback: string): string => {
  if (err && typeof err === 'object' && 'response' in err) {
    const resp = (err as { response?: { data?: { message?: string } } }).response;
    if (resp?.data?.message) return resp.data.message;
  }
  if (err instanceof Error && err.message) return err.message;
  return fallback;
};

const STATUS_CONFIG = {
  draft: { label: 'Draft', icon: Edit3, color: 'bg-gray-100 text-gray-800 dark:bg-slate-700' },
  review: { label: 'Review', icon: Clock, color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30' },
  published: {
    label: 'Published',
    icon: CheckCircle,
    color: 'bg-green-100 text-green-800 dark:bg-green-900/30',
  },
  archived: {
    label: 'Archived',
    icon: Archive,
    color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30',
  },
};

const HrPolicies = () => {
  const { can } = useAuth();
  const [policies, setPolicies] = useState<HrPolicyDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [historyModalOpen, setHistoryModalOpen] = useState(false);
  const [ackModalOpen, setAckModalOpen] = useState(false);
  const [selectedPolicy, setSelectedPolicy] = useState<HrPolicyDocument | null>(null);
  const [historyData, setHistoryData] = useState<{
    document: HrPolicyDocument;
    versions: HrPolicyDocument[];
    audit: HistoryAuditRow[];
  } | null>(null);
  const [ackData, setAckData] = useState<{
    document: { id: number; title: string; version: string };
    items: AckRecord[];
    total_acks: number;
    active_users: number;
  } | null>(null);

  const canManage = can('hr_policies', 'manage');
  const canPublish = can('hr_policies', 'publish');

  const fetchPolicies = async () => {
    setLoading(true);
    try {
      const items = await hrPolicyService.adminList();
      setPolicies(items);
    } catch (err) {
      console.error('Failed to fetch policies:', err);
      toast.error('Failed to load policy list');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPolicies();
  }, []);

  const handleUpload = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // Named form controls are not declared HTMLFormElement properties —
    // narrow them with this cast so file/title/version/description are typed.
    const form = e.currentTarget as HTMLFormElement & {
      file: HTMLInputElement;
      title: HTMLInputElement;
      version: HTMLInputElement;
      description: HTMLTextAreaElement;
    };
    const file = form.file.files?.[0];
    if (!file) {
      toast.error('Please select a file to upload');
      return;
    }
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('title', form.title.value);
      formData.append('version', form.version.value || '1.0');
      formData.append('description', form.description.value || '');
      const id = await hrPolicyService.adminUpload(formData);
      toast.success(`Policy uploaded as Draft (ID: ${id})`);
      setUploadModalOpen(false);
      fetchPolicies();
    } catch (err) {
      console.error('Upload failed:', err);
      // Show the specific validation error from the server if available
      toast.error(extractErrorMessage(err, 'Failed to upload policy'));
    }
  };

  const handleEdit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!selectedPolicy) return;
    try {
      const form = e.currentTarget as HTMLFormElement & {
        title: HTMLInputElement;
        version: HTMLInputElement;
        description: HTMLTextAreaElement;
        effective_date: HTMLInputElement;
      };
      // Metadata ONLY — status transitions use the dedicated workflow
      // endpoints (POST /status for draft|review, POST /publish, POST
      // /archive). The backend HrPolicyValidator rejects published|archived
      // on the metadata PUT by design, so the select below routes through
      // handleStatusChange instead of this form submit.
      await hrPolicyService.adminUpdate(selectedPolicy.id, {
        title: form.title.value,
        version: form.version.value,
        description: form.description.value,
        effective_date: form.effective_date.value || null,
      });
      toast.success('Metadata updated');
      setEditModalOpen(false);
      fetchPolicies();
    } catch (err) {
      console.error('Update failed:', err);
      toast.error('Failed to update');
    }
  };

  /**
   * Status transitions route through the DEDICATED workflow endpoints:
   *  - draft|review → POST /settings/hr-policies/{id}/status
   *  - published    → POST /settings/hr-policies/{id}/publish (archives the
   *                   previous active version + announces to all employees)
   *  - archived     → POST /settings/hr-policies/{id}/archive
   * The metadata PUT (adminUpdate) never carries a status value.
   */
  const handleStatusChange = async (policy: HrPolicyDocument, newStatus: string) => {
    try {
      if (newStatus === 'published') {
        await hrPolicyService.adminPublish(policy.id);
        toast.success('Policy published. Previous active version archived.');
      } else if (newStatus === 'archived') {
        await hrPolicyService.adminArchive(policy.id);
        toast.success('Policy archived');
      } else {
        await hrPolicyService.adminSetStatus(policy.id, newStatus as 'draft' | 'review');
        toast.success(`Status set to ${newStatus}`);
      }
      fetchPolicies();
    } catch (err) {
      console.error('Status change failed:', err);
      toast.error('Failed to change status');
      fetchPolicies();
    }
  };

  /**
   * Publish a version. Accepts an explicit policy so table-row clicks never
   * read a stale selectedPolicy (React state updates are async).
   */
  const handlePublish = async (policy: HrPolicyDocument | null = selectedPolicy) => {
    if (!policy) return;
    try {
      await hrPolicyService.adminPublish(policy.id);
      toast.success('Policy published. Previous active version archived.');
      fetchPolicies();
    } catch (err) {
      console.error('Publish failed:', err);
      toast.error('Failed to publish');
    }
  };

  const handleArchive = async (policy: HrPolicyDocument | null = selectedPolicy) => {
    if (!policy) return;
    if (!confirm(`Archive "${policy.title}" v${policy.version}?`)) return;
    try {
      await hrPolicyService.adminArchive(policy.id);
      toast.success('Policy archived');
      fetchPolicies();
    } catch (err) {
      console.error('Archive failed:', err);
      toast.error('Failed to archive');
    }
  };

  const handleDelete = async (policy: HrPolicyDocument | null = selectedPolicy) => {
    if (!policy) return;
    if (
      !confirm(
        `Remove "${policy.title}" v${policy.version} from the list? The file is kept on disk and the action is audited.`,
      )
    )
      return;
    try {
      await hrPolicyService.adminDelete(policy.id);
      toast.success('Policy deleted');
      fetchPolicies();
    } catch (err) {
      console.error('Delete failed:', err);
      // Show the specific error message from the server if available
      toast.error(extractErrorMessage(err, 'Failed to delete'));
    }
  };

  const showHistory = async (policy: HrPolicyDocument) => {
    setSelectedPolicy(policy);
    setHistoryModalOpen(true);
    try {
      const data = await hrPolicyService.adminHistory(policy.id);
      setHistoryData(data);
    } catch (err) {
      toast.error('Failed to load history');
    }
  };

  const showAcknowledgements = async (policy: HrPolicyDocument) => {
    setSelectedPolicy(policy);
    setAckModalOpen(true);
    try {
      const data = await hrPolicyService.adminAcknowledgements(policy.id);
      setAckData(data);
    } catch (err) {
      toast.error('Failed to load acknowledgements');
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">HR Policies</h1>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Manage HR Policy &amp; Procedures Manual versions. Draft, Review, Published, Archived.
          </p>
        </div>
        {canManage && (
          <Button
            onClick={() => {
              setUploadModalOpen(true);
              setSelectedPolicy(null);
            }}
          >
            <Upload className="h-4 w-4 mr-2" />
            Upload New Policy
          </Button>
        )}
      </div>

      {/* Status legend */}
      <div className="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400">
        <span>Legend:</span>
        {Object.entries(STATUS_CONFIG).map(([key, cfg]) => {
          const Icon = cfg.icon;
          return (
            <span key={key} className="inline-flex items-center">
              <span className={`inline-flex items-center px-2 py-0.5 rounded-full ${cfg.color}`}>
                <Icon className="h-3 w-3 mr-1" />
                {cfg.label}
              </span>
            </span>
          );
        })}
      </div>

      {/* Policy table */}
      {loading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
        </div>
      ) : policies.length === 0 ? (
        <div className="text-center py-12 text-gray-500 dark:text-gray-400">
          <FileText className="h-12 w-12 mx-auto mb-4 opacity-50" />
          <p>No policy documents found. Upload the first version to get started.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border dark:border-slate-700">
          <table className="w-full border-collapse">
            <thead>
              <tr className="bg-gray-50 dark:bg-slate-800">
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Title
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Version
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Status
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Effective
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Published
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                  Uploaded By
                </th>
                <th className="px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-right">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
              {policies.map((policy) => {
                const config = STATUS_CONFIG[policy.status] || STATUS_CONFIG.draft;
                const Icon = config.icon;
                return (
                  <tr key={policy.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50">
                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                      {policy.is_active && policy.status === 'published' && (
                        <span title="Active version" className="inline-flex items-center">
                          <CheckCircle className="h-3 w-3 text-green-500 mr-1" />
                        </span>
                      )}
                      {policy.title}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                      {policy.version}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs ${config.color}`}
                      >
                        <Icon className="h-3 w-3 mr-1" />
                        {config.label}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                      {policy.effective_date
                        ? new Date(policy.effective_date).toLocaleDateString()
                        : '-'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                      {policy.published_at ? new Date(policy.published_at).toLocaleString() : '-'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                      {policy.uploaded_by_name || `ID: ${policy.uploaded_by}`}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end space-x-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          title="View policy"
                          onClick={() => window.open('/hr/policies', '_blank')}
                        >
                          <ExternalLink className="h-4 w-4" />
                        </Button>
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Edit metadata"
                            onClick={() => {
                              setSelectedPolicy(policy);
                              setEditModalOpen(true);
                            }}
                          >
                            <Edit3 className="h-4 w-4" />
                          </Button>
                        )}
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            title="History"
                            onClick={() => showHistory(policy)}
                          >
                            <History className="h-4 w-4" />
                          </Button>
                        )}
                        {canPublish && policy.status === 'published' && (
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Acknowledgements"
                            onClick={() => showAcknowledgements(policy)}
                          >
                            <CheckCircle className="h-4 w-4" />
                          </Button>
                        )}
                        {canPublish &&
                          (policy.status === 'draft' || policy.status === 'review') && (
                            <Button
                              variant="ghost"
                              size="sm"
                              title="Publish (archives the previous active version)"
                              onClick={() => handlePublish(policy)}
                            >
                              <Send className="h-4 w-4 text-primary-600" />
                            </Button>
                          )}
                        {canManage && policy.status === 'published' && (
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Archive"
                            onClick={() => handleArchive(policy)}
                          >
                            <Archive className="h-4 w-4" />
                          </Button>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          title="Download file"
                          onClick={() =>
                            window.open(hrPolicyService.fileUrl(policy.id, true), '_blank')
                          }
                        >
                          <Download className="h-4 w-4" />
                        </Button>
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Delete (soft — file kept, audited)"
                            onClick={() => handleDelete(policy)}
                          >
                            <Trash2 className="h-4 w-4 text-red-500" />
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Upload Modal */}
      <Modal
        isOpen={uploadModalOpen}
        onClose={() => setUploadModalOpen(false)}
        title="Upload New Policy"
      >
        <form onSubmit={handleUpload} className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Document File</label>
            <input
              type="file"
              name="file"
              accept=".pdf,.doc,.docx"
              required
              className="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border file:bg-primary-50 file:text-primary-700 dark:file:bg-primary-900/20 dark:file:text-primary-300"
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Title</label>
            <input
              type="text"
              name="title"
              required
              className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              placeholder="e.g. MUWASCO HR Policy &amp; Procedures Manual"
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Version</label>
            <input
              type="text"
              name="version"
              className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              placeholder="e.g. 1.0"
              defaultValue="1.0"
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Description</label>
            <textarea
              name="description"
              rows={3}
              className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              placeholder="Optional description..."
            />
          </div>
          <div className="flex justify-end space-x-3 pt-4">
            <Button variant="outline" onClick={() => setUploadModalOpen(false)}>
              Cancel
            </Button>
            <Button type="submit">Upload &amp; Create Draft</Button>
          </div>
        </form>
      </Modal>

      {/* Edit Metadata + Publish Modal */}
      {editModalOpen && selectedPolicy && (
        <Modal
          isOpen={editModalOpen}
          onClose={() => setEditModalOpen(false)}
          title={`Edit: ${selectedPolicy.title}`}
        >
          <form onSubmit={handleEdit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1">Title</label>
              <input
                type="text"
                name="title"
                required
                defaultValue={selectedPolicy.title}
                className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Version</label>
              <input
                type="text"
                name="version"
                defaultValue={selectedPolicy.version}
                className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Description</label>
              <textarea
                name="description"
                rows={2}
                defaultValue={selectedPolicy.description || ''}
                className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Effective Date</label>
              <input
                type="date"
                name="effective_date"
                defaultValue={selectedPolicy.effective_date?.split('T')[0] || ''}
                className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Status</label>
              {selectedPolicy.status === 'published' || selectedPolicy.status === 'archived' ? (
                <>
                  <input
                    type="text"
                    disabled
                    value={STATUS_CONFIG[selectedPolicy.status].label.toUpperCase()}
                    title="Published and archived versions are workflow-terminal. Archive the active version or upload a new version to supersede it."
                    className="w-full px-3 py-2 border rounded-md bg-gray-100 dark:bg-slate-700/50 text-gray-500 dark:text-gray-400 cursor-not-allowed"
                  />
                  <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    {selectedPolicy.status === 'published'
                      ? 'This version is published. Use Archive below (or upload a new version and publish it) to supersede it.'
                      : 'This version is archived and read-only.'}
                  </p>
                </>
              ) : (
                <>
                  <select
                    name="status"
                    defaultValue={selectedPolicy.status}
                    onChange={(e) => handleStatusChange(selectedPolicy, e.target.value)}
                    className="w-full px-3 py-2 border rounded-md dark:bg-slate-700 dark:border-slate-600"
                  >
                    <option value="draft">DRAFT</option>
                    <option value="review">REVIEW</option>
                  </select>
                  <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    Publishing (and archiving the previous active version) uses the button below.
                  </p>
                </>
              )}
            </div>
            {canPublish &&
              (selectedPolicy.status === 'draft' || selectedPolicy.status === 'review') && (
                <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                  <p className="text-sm text-blue-800 dark:text-blue-200 mb-3">
                    Ready to publish? This will archive the current active version and notify all
                    employees.
                  </p>
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={async (e) => {
                      e.preventDefault();
                      await handlePublish();
                      setEditModalOpen(false);
                    }}
                  >
                    Publish This Version
                  </Button>
                </div>
              )}
            <div className="flex justify-end pt-4">
              <Button variant="outline" onClick={() => setEditModalOpen(false)}>
                Save &amp; Close
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {/* History Modal */}
      {historyModalOpen && historyData && (
        <Modal
          isOpen={historyModalOpen}
          onClose={() => setHistoryModalOpen(false)}
          title={`Version History — ${historyData.document.title}`}
        >
          <div className="space-y-6 max-h-96 overflow-y-auto">
            <div>
              <h3 className="font-medium text-sm text-gray-500 dark:text-gray-400 mb-2">
                Versions
              </h3>
              <div className="space-y-2">
                {historyData.versions.map((v) => (
                  <div key={v.id} className="border dark:border-slate-700 rounded-lg p-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <span className="font-medium text-gray-900 dark:text-gray-100">
                          {v.version}
                        </span>
                        <span className="mx-2 text-gray-400">•</span>
                        <span
                          className={`text-xs px-2 py-0.5 rounded-full ${STATUS_CONFIG[v.status].color}`}
                        >
                          {STATUS_CONFIG[v.status].label}
                        </span>
                      </div>
                      {v.is_active && v.status === 'published' && (
                        <span title="Active version" className="inline-flex">
                          <CheckCircle className="h-4 w-4 text-green-500" />
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mt-1">
                      Published:{' '}
                      {v.published_at ? new Date(v.published_at).toLocaleString() : 'Not yet'}
                    </p>
                  </div>
                ))}
              </div>
            </div>
            <div>
              <h3 className="font-medium text-sm text-gray-500 dark:text-gray-400 mb-2">
                Audit Trail
              </h3>
              <div className="space-y-2">
                {historyData.audit.slice(0, 20).map((a) => (
                  <div key={a.id} className="border-l-2 border-primary-500 pl-3 py-1">
                    <div className="flex items-center justify-between">
                      <span className="font-medium text-xs text-gray-900 dark:text-gray-200">
                        {a.action} — {a.user_name_snapshot || 'Unknown'}
                      </span>
                      <span className="text-xs text-gray-500 dark:text-gray-400">
                        {a.created_at ? new Date(a.created_at).toLocaleString() : ''}
                      </span>
                    </div>
                    <p className="text-xs text-gray-600 dark:text-gray-300 mt-1">{a.description}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Modal>
      )}

      {/* Acknowledgements Modal */}
      {ackModalOpen && ackData && (
        <Modal
          isOpen={ackModalOpen}
          onClose={() => setAckModalOpen(false)}
          title={`Acknowledgements — ${ackData.document.title} v${ackData.document.version}`}
        >
          <div className="space-y-4 max-h-96 overflow-y-auto">
            <div className="flex items-center space-x-4 text-sm">
              <span className="text-gray-600 dark:text-gray-300">
                Total: <strong>{ackData.total_acks}</strong>
              </span>
              <span className="text-gray-400">•</span>
              <span className="text-gray-600 dark:text-gray-300">
                Active employees: <strong>{ackData.active_users}</strong>
              </span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 dark:bg-slate-800">
                    <th className="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                      Employee
                    </th>
                    <th className="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                      Acknowledged At
                    </th>
                    <th className="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase text-left">
                      IP
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
                  {ackData.items.map((item) => (
                    <tr key={item.id}>
                      <td className="px-3 py-2">{item.user_name || '-'}</td>
                      <td className="px-3 py-2">
                        {new Date(item.acknowledged_at).toLocaleString()}
                      </td>
                      <td className="px-3 py-2 text-gray-500 dark:text-gray-400">
                        {item.ip_address || '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </Modal>
      )}

      {/* Unauthorized notice */}
      {!canManage && !canPublish && (
        <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
          <AlertCircle className="inline h-5 w-5 text-yellow-600 dark:text-yellow-400 mr-2" />
          <span className="text-sm text-yellow-800 dark:text-yellow-200">
            You do not have permission to manage HR policies. Contact an HR administrator.
          </span>
        </div>
      )}
    </div>
  );
};

export default HrPolicies;
