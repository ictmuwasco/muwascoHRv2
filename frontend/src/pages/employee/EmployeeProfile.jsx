import { useState, useEffect, useMemo } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../utils/api';
import Card from '../../components/ui/Card';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';
import EmployeeTabs from '../../components/EmployeeTabs';
// Permission gate (§global rule): view never unlocks mutation. Every write on
// this page goes to employees:edit in the API — PUT /employees/{id},
// POST /employees/documents, DELETE /employees/documents/{id},
// POST /employees/{id}/contracts/{contractId}/renew,
// POST /employees/{id}/convert-to-permanent, POST /employees/{id}/profile-image.
// The route only needs employees:view, so each affordance is gated here.
import { CanEdit } from '../../components/ui/PermissionGate';
import {
  ArrowLeft,
  Mail,
  Phone,
  MapPin,
  Briefcase,
  Building2,
  FileText,
  Users,
  Heart,
  Download,
  Save,
  Loader2,
  Plus,
  Trash2,
  Upload,
  Camera,
  RefreshCw,
  Calendar,
  Clock,
} from 'lucide-react';

// Base URL for direct file access (authenticated via httpOnly cookie) —
// centralized in src/config/api.ts so every consumer shares VITE_API_URL.
import { API_BASE_URL as API_BASE } from '../../config/api';

// Tab definitions for the EmployeeProfile tab navigation, declared at MODULE
// level (single source of truth). This is required because the ?tab=
// deep-link effect below must validate the query parameter even during the
// loading early-return — at that point the component-scope `tabs` array is
// still uninitialised, and an effect referencing it throws
// "Cannot access 'tabs' before initialization" (the crash behind the
// ErrorBoundary on /employees/:id/profile?tab=contracts).
const PROFILE_TABS = [
  { id: 'details', name: 'Personal Details', icon: <Briefcase className="h-4 w-4" /> },
  { id: 'contracts', name: 'Contracts', icon: <RefreshCw className="h-4 w-4" /> },
  { id: 'documents', name: 'Documents', icon: <FileText className="h-4 w-4" /> },
  { id: 'nextofkin', name: 'Next of Kin', icon: <Users className="h-4 w-4" /> },
  { id: 'dependants', name: 'Dependants', icon: <Heart className="h-4 w-4" /> },
];

// Encrypted-document OTP gate (migration 106). The browser does no
// cryptography here: the SERVER gates the metadata, and this UI reflects the
// state it reports. `locked` means the names and categories were never sent, so
// hiding the tab is genuine rather than cosmetic.
const DOCUMENT_ACCESS_STATES = {
  NONE: 'none',
  OWNER: 'owner',
  GRANTED: 'granted',
  LOCKED: 'locked',
};

/** Format a Date as YYYY-MM-DD (local time) for date inputs and the API. */
const toYMD = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Add N calendar months to a Date, clamping day-overflow (Jan 31 + 1m → Feb 28). */
const addMonthsClamped = (date, months) => {
  const d = new Date(date.getTime());
  const day = d.getDate();
  d.setMonth(d.getMonth() + months);
  if (d.getDate() !== day) d.setDate(0);
  return d;
};

const EmployeeProfile = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [employee, setEmployee] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState('details');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  // Next of Kin form state
  const [nextOfKinForm, setNextOfKinForm] = useState({
    name: '',
    relationship: '',
    contact: '',
  });

  // Dependants form state
  const [dependants, setDependants] = useState([]);
  const [dependantForm, setDependantForm] = useState({
    name: '',
    relationship: '',
    date_of_birth: '',
    gender: '',
    id_no: '',
    contact: '',
  });

  // Documents state
  const [documents, setDocuments] = useState([]);
  const [newDocument, setNewDocument] = useState({
    name: '',
    category: 'other',
    file: null,
  });

  // Encrypted-document OTP gate (migration 106).
  //
  // SERVER-SIDE, NOT JUST VISUAL: for a viewer who is neither the owner nor
  // holds a verified approval, the API returns document PLACEHOLDERS with no
  // name, no category, no id and no filename - only the count. So the tab is
  // hidden because there is genuinely nothing to show, not because the markup
  // is conditionally rendered around data the browser already received.
  const [documentsAccess, setDocumentsAccess] = useState(DOCUMENT_ACCESS_STATES.NONE);
  //
  // FLOW, three steps, deliberately sequential:
  //   1. requestAccess  -> emails a 6-digit code to the DOCUMENT OWNER
  //   2. verifyCode     -> the owner types it; marks the approval verified
  //   3. openDocument   -> spends the approval and streams the plaintext
  //
  // Splitting verify from open is what makes the approval single-use: a correct
  // code cannot be replayed for a second download, because the file is only
  // ever returned by the call that consumes the approval.
  //
  // The code is never stored in component state beyond the moment of
  // submission, and `setOtpCode('')` clears it as soon as verification
  // succeeds, so it does not linger in a re-render or a React DevTools dump.
  const [otpDoc, setOtpDoc] = useState(null); // { docId, name, step, maskedEmail }
  const [otpCode, setOtpCode] = useState('');
  const [otpError, setOtpError] = useState('');
  const [otpBusy, setOtpBusy] = useState(false);

  // Profile picture state
  const [profileImageUrl, setProfileImageUrl] = useState(null);
  const [profileImageUploading, setProfileImageUploading] = useState(false);

  // Contracts state
  const [contracts, setContracts] = useState([]);
  const [contractCount, setContractCount] = useState(0);
  const [renewingContract, setRenewingContract] = useState(false);

  // Contract renewal modal state — a small term form (dates / months) instead
  // of the old confirm() dialog. A contract can only be renewed ONCE: the
  // backend enforces it and the UI disables already-renewed contracts.
  const [renewModal, setRenewModal] = useState(null); // { contract } | null
  const [renewForm, setRenewForm] = useState({ start_date: '', end_date: '' });
  const [renewDuration, setRenewDuration] = useState('12'); // '6' | '12' | 'custom'
  const [renewError, setRenewError] = useState('');

  // Contract → Permanent conversion (Contracts tab dropdown). The backend
  // action flips employment_type to 'permanent', clears the active contract
  // dates and preserves the contract history. On success a banner points HR
  // at the Financial Year page to allocate the employee's leave days.
  const [convertType, setConvertType] = useState('');
  const [converting, setConverting] = useState(false);
  const [convertError, setConvertError] = useState('');
  const [convertedEmployee, setConvertedEmployee] = useState(null); // { name } drives the next-step banner

  // contract id → contract number of the contract that renewed it (drives the
  // one-renewal-per-contract rule in the Contracts tab UI)
  const renewedByMap = useMemo(
    () =>
      new Map(
        contracts
          .filter((c) => c.renewed_from_contract_id)
          .map((c) => [c.renewed_from_contract_id, c.contract_number]),
      ),
    [contracts],
  );

  useEffect(() => {
    fetchEmployee();
  }, [id]);

  // ---- Deep-link support -----------------------------------------------------
  // /employees/:id/profile?tab=contracts opens the Contracts tab directly.
  // The HR Insights "Expired Contracts" dashboard card links here so HR can
  // jump straight to the employee's contracts and renew. The URL is kept in
  // sync with the active tab, making it bookmarkable.

  const requestedTab = searchParams.get('tab');

  // Apply a valid ?tab= deep link (e.g. ?tab=contracts from the HR Insights
  // "Expired Contracts" dashboard card). Validated against PROFILE_TABS — a
  // module-level constant, NOT the component `tabs` reference below: during
  // the loading early-return `tabs` is still uninitialised and an effect
  // touching it throws "Cannot access 'tabs' before initialization".
  useEffect(() => {
    if (
      requestedTab &&
      PROFILE_TABS.some((t) => t.id === requestedTab) &&
      requestedTab !== activeTab
    ) {
      setActiveTab(requestedTab);
    }
  }, [requestedTab]);

  // Mirror tab changes into the URL so refresh/back behave predictably.
  useEffect(() => {
    if (activeTab === 'details') {
      if (searchParams.get('tab')) setSearchParams({}, { replace: true });
    } else if (searchParams.get('tab') !== activeTab) {
      setSearchParams({ tab: activeTab }, { replace: true });
    }
  }, [activeTab]);

  const fetchEmployee = async () => {
    try {
      const response = await api.get(`/employees/${id}`);
      const data = response.data.data || response.data;
      setEmployee(data);

      // Parse next of kin from next_of_kin_data (already parsed from separate table)
      const parsedNextOfKin = data.next_of_kin_data || safeParse(data.next_of_kin);
      if (parsedNextOfKin.length > 0) {
        setNextOfKinForm({
          name: parsedNextOfKin[0].name || '',
          relationship: parsedNextOfKin[0].relationship || '',
          contact: parsedNextOfKin[0].contact || parsedNextOfKin[0].phone || '',
        });
      }

      // Parse dependants from dependants_data (already parsed from separate table)
      const parsedDependants = data.dependants_data || safeParse(data.dependants);
      setDependants(parsedDependants);

      // Parse documents.
      //
      // `documents_access` is the server's verdict, not a client guess:
      //   owner   - these are the caller's own documents
      //   granted - a verified approval is live for at least one of them
      //   locked  - placeholders only; no name/category/id was ever sent
      //   none    - the employee has no documents at all
      setDocumentsAccess(
        data.documents_access || DOCUMENT_ACCESS_STATES.NONE,
      );
      const parsedDocuments = safeParse(data.documents);
      setDocuments(parsedDocuments);

      // Set profile picture URL
      if (data.profile_image_url) {
        setProfileImageUrl(`${API_BASE}/employees/${id}/profile-image?t=${Date.now()}`);
      } else {
        setProfileImageUrl(null);
      }

      // Fetch contract information
      try {
        const contractRes = await api.get(`/employees/${id}/contracts`);
        const contractData = contractRes.data.data || contractRes.data;
        setContracts(contractData.contracts || []);
        setContractCount(contractData.count || (contractData.contracts || []).length);
      } catch (err) {
        console.error('Failed to fetch contracts:', err);
        setContracts([]);
        setContractCount(0);
      }
    } catch (error) {
      console.error('Failed to fetch employee:', error);
    } finally {
      setLoading(false);
    }
  };

  const safeParse = (value) => {
    if (Array.isArray(value)) return value;
    if (typeof value === 'object' && value !== null) return [value];
    if (typeof value === 'string') {
      try {
        const parsed = JSON.parse(value);
        return Array.isArray(parsed) ? parsed : parsed ? [parsed] : [];
      } catch {
        return [];
      }
    }
    return [];
  };

  const handleNextOfKinChange = (e) => {
    const { name, value } = e.target;
    setNextOfKinForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSaveNextOfKin = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const nextOfKinData = [
        {
          name: nextOfKinForm.name,
          relationship: nextOfKinForm.relationship,
          contact: nextOfKinForm.contact,
        },
      ];
      await api.put(`/employees/${id}`, { next_of_kin: nextOfKinData });
      setSuccess('Next of kin updated successfully');
      fetchEmployee();
    } catch (err) {
      setError('Failed to update next of kin');
      console.error('Failed to update next of kin:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDependantChange = (e) => {
    const { name, value } = e.target;
    setDependantForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleAddDependant = async (e) => {
    e.preventDefault();
    if (!dependantForm.name) {
      setError('Dependant name is required');
      return;
    }
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const currentDependants = employee.dependants_data || dependants;
      const updatedDependants = [...currentDependants, { ...dependantForm }];
      await api.put(`/employees/${id}`, { dependants: updatedDependants });
      setDependants(updatedDependants);
      setDependantForm({
        name: '',
        relationship: '',
        date_of_birth: '',
        gender: '',
        id_no: '',
        contact: '',
      });
      setSuccess('Dependant added successfully');
    } catch (err) {
      setError('Failed to add dependant');
      console.error('Failed to add dependant:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteDependant = async (index) => {
    if (!confirm('Are you sure you want to delete this dependant?')) return;
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const currentDependants = employee.dependants_data || dependants;
      const updatedDependants = currentDependants.filter((_, i) => i !== index);
      await api.put(`/employees/${id}`, { dependants: updatedDependants });
      setDependants(updatedDependants);
      setSuccess('Dependant deleted successfully');
    } catch (err) {
      setError('Failed to delete dependant');
      console.error('Failed to delete dependant:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDocumentFileChange = (e) => {
    const file = e.target.files?.[0] || null;
    setNewDocument((prev) => ({
      ...prev,
      file,
      name: file ? file.name : prev.name,
    }));
  };

  const handleUploadDocument = async (e) => {
    e.preventDefault();
    if (!newDocument.file) {
      setError('Please select a file to upload');
      return;
    }
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      const formData = new FormData();
      formData.append('employee_id', id);
      formData.append('document_name', newDocument.name);
      formData.append('category', newDocument.category);
      formData.append('file', newDocument.file);
      await api.post('/employees/documents', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setSuccess('Document uploaded successfully');
      setNewDocument({ name: '', category: 'other', file: null });
      fetchEmployee();
    } catch (err) {
      setError('Failed to upload document');
      console.error('Failed to upload document:', err);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteDocument = async (docId) => {
    if (!confirm('Are you sure you want to delete this document?')) return;
    try {
      await api.delete(`/employees/documents/${docId}`);
      setSuccess('Document deleted successfully');
      fetchEmployee();
    } catch (err) {
      setError('Failed to delete document');
      console.error('Failed to delete document:', err);
    }
  };

  // ---- Encrypted document OTP flow ---------------------------------------
  // The code is emailed to the EMPLOYEE WHO OWNS the document, not to whoever
  // clicked Download. If you are HR opening somebody's national ID, that
  // employee has to approve it, and the wording here says so up front rather
  // than surprising them with a code that never arrives.

  const requestDocumentAccess = async (doc) => {
    setOtpBusy(true);
    setOtpError('');
    setOtpCode('');
    setOtpDoc({
      docId: doc.id,
      name: doc.name || doc.document_name || 'this document',
      step: 'requested',
      maskedEmail: null,
    });

    try {
      const res = await api.post(`/profile/documents/${doc.id}/request-access`);
      const data = res.data?.data || res.data || {};
      setOtpDoc((prev) => ({
        ...prev,
        step: 'awaiting_code',
        maskedEmail: data.masked_email || null,
        ttlMinutes: data.ttl_minutes || 10,
      }));
    } catch (err) {
      const msg =
        err?.response?.data?.message ||
        'Could not request access to this document.';
      setOtpError(msg);
      setOtpDoc(null);
    } finally {
      setOtpBusy(false);
    }
  };

  const submitOtpCode = async (e) => {
    e.preventDefault();
    if (!otpDoc) return;

    const digits = otpCode.replace(/\D/g, '');
    if (digits.length !== 6) {
      setOtpError('Enter the 6-digit code from the email.');
      return;
    }

    setOtpBusy(true);
    setOtpError('');

    try {
      // Employee-scoped and document-scoped approvals use different verify
      // endpoints: the first unlocks the whole list, the second marks a single
      // document ready to open.
      const verifyUrl = otpDoc.employeeScoped
        ? `/profile/employees/${id}/documents/verify`
        : `/profile/documents/${otpDoc.docId}/verify`;

      await api.post(verifyUrl, { code: digits });

      // Clear the code the instant it is no longer needed, before the follow-up
      // request, so it is not sitting in state if that request fails.
      setOtpCode('');
      setOtpDoc((prev) => ({ ...prev, step: 'opening' }));

      if (otpDoc.employeeScoped) {
        // Employee-scoped approval unlocks the LIST. Re-fetch so the real names
        // and ids arrive from the server - they are never assembled client-side.
        await fetchEmployee();
        setOtpDoc(null);
        setOtpError('');
        setSuccess('Access granted. Documents are now visible.');
        return;
      }

      await openVerifiedDocument(otpDoc.docId, otpDoc.name);
    } catch (err) {
      const msg =
        err?.response?.data?.message || 'That code could not be verified.';
      setOtpError(msg);
      setOtpCode('');
      setOtpDoc((prev) => ({ ...prev, step: 'awaiting_code' }));
    } finally {
      setOtpBusy(false);
    }
  };

  /**
   * Fetch the plaintext through the approval-spending endpoint and save it.
   *
   * Uses fetch rather than api.get so the response can be read as a Blob:
   * api.get would try to parse a PDF as JSON and fail. credentials:'include'
   * is required because auth rides on the httpOnly session cookie.
   *
   * The approval is consumed by this request. A failed download therefore needs
   * a new code, which is the intended trade - a spent code is much cheaper than
   * a reusable one.
   */
  const openVerifiedDocument = async (docId, docName) => {
    const response = await fetch(
      `${API_BASE}/profile/documents/${docId}/open?download=1`,
      {
        method: 'GET',
        credentials: 'include',
        headers: { Accept: 'application/octet-stream' },
      },
    );

    if (!response.ok) {
      let message = 'This document could not be opened.';
      try {
        const payload = await response.json();
        if (payload?.message) message = payload.message;
      } catch {
        /* a non-JSON body is expected on some error paths */
      }
      throw new Error(message);
    }

    const blob = await response.blob();

    // Prefer the filename the server sent; fall back to the record's name.
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const fileName = match ? match[1] : docName || 'document';

    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    // Revoke on the next tick: revoking synchronously can cancel the download
    // in some browsers before it has started reading the blob.
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);

    setOtpDoc(null);
    setOtpError('');
    setSuccess(`Downloaded ${fileName}`);
  };

  const cancelOtp = () => {
    setOtpDoc(null);
    setOtpCode('');
    setOtpError('');
  };

  /**
   * Start a request when the document list is still locked.
   *
   * The placeholders deliberately carry NO id, so there is nothing to address a
   * per-document request with - and that is deliberate: naming a document in a
   * request would require already knowing its name, which is the thing being
   * protected.
   *
   * This asks the employee to unlock their documents for this session. The
   * server emails the owner a code; once approved the list is re-fetched and
   * the real names and ids appear. One request rather than one per document
   * also means the owner receives a single email instead of being flooded by
   * someone probing five separate documents.
   */
  const requestFirstDocumentAccess = async () => {
    setOtpBusy(true);
    setOtpError('');
    setOtpCode('');

    try {
      const res = await api.post(`/profile/employees/${id}/documents/request-access`);
      const data = res.data?.data || res.data || {};

      const ownerName =
        `${employee?.first_name || ''} ${employee?.last_name || ''}`.trim();

      setOtpDoc({
        docId: null, // employee-scoped: no single document chosen yet
        name: ownerName ? `${ownerName}'s documents` : "this employee's documents",
        step: 'awaiting_code',
        maskedEmail: data.masked_email || null,
        ttlMinutes: data.ttl_minutes || 10,
        employeeScoped: true,
      });
    } catch (err) {
      setOtpError(
        err?.response?.data?.message ||
          'Could not request access to these documents.',
      );
    } finally {
      setOtpBusy(false);
    }
  };

  // Contract renewal — open the small renewal form pre-filled from the
  // selected contract. Start defaults to the day after the current term ends
  // (seamless renewal) when that is in the future, otherwise today; the
  // period defaults to 12 months.
  const openRenewModal = (contract) => {
    const today = new Date();
    const prevEnd = contract.end_date ? new Date(`${contract.end_date}T00:00:00`) : null;
    let start = today;
    if (prevEnd && prevEnd > today) {
      start = new Date(prevEnd.getTime());
      start.setDate(start.getDate() + 1);
    }
    setRenewForm({
      start_date: toYMD(start),
      end_date: toYMD(addMonthsClamped(start, 12)),
    });
    setRenewDuration('12');
    setRenewError('');
    setRenewModal({ contract });
  };

  // 6/12-month presets recompute the end date; "custom" lets HR pick it
  // (editing the end date switches the selector back to custom).
  const handleRenewDurationChange = (value) => {
    setRenewDuration(value);
    if (value !== 'custom') {
      const start = new Date(`${renewForm.start_date}T00:00:00`);
      if (!Number.isNaN(start.getTime())) {
        setRenewForm((f) => ({
          ...f,
          end_date: toYMD(addMonthsClamped(start, parseInt(value, 10))),
        }));
      }
    }
  };

  const submitRenewContract = async () => {
    if (!renewModal) return;
    const { contract } = renewModal;
    if (!renewForm.start_date || !renewForm.end_date) {
      setRenewError('Please choose the new start and end dates.');
      return;
    }
    if (renewForm.end_date <= renewForm.start_date) {
      setRenewError('The end date must be after the start date.');
      return;
    }
    setRenewingContract(true);
    setRenewError('');
    try {
      await api.post(`/employees/${id}/contracts/${contract.id}/renew`, {
        start_date: renewForm.start_date,
        end_date: renewForm.end_date,
      });
      setRenewModal(null);
      setSuccess(
        `Contract #${contract.contract_number} renewed — new term ${renewForm.start_date} → ${renewForm.end_date}`,
      );
      fetchEmployee();
    } catch (err) {
      const msg = err?.response?.data?.message;
      setRenewError(typeof msg === 'string' && msg ? msg : 'Failed to renew contract');
      console.error('Failed to renew contract:', err);
    } finally {
      setRenewingContract(false);
    }
  };

  // Convert the employee's employment_type from contract → permanent via the
  // dedicated backend action (POST /employees/{id}/convert-to-permanent).
  // Contract history is kept; the active contract dates are cleared and no
  // new contract term is created. On success the leave-allocation next step
  // is surfaced (banner + jump to the Financial Year page).
  const submitConvertToPermanent = async () => {
    if (converting) return;
    const name = [employee?.first_name, employee?.last_name].filter(Boolean).join(' ');
    if (
      !confirm(
        `Convert ${name || 'this employee'} to Permanent employment?\n\n` +
          'Their employment type becomes "permanent", the active contract dates are cleared, ' +
          'and the contract history is preserved. No new contract term is created.\n\n' +
          'Next step after saving: allocate their leave days in Financial Year → Leave Allocation.',
      )
    )
      return;
    setConverting(true);
    setConvertError('');
    try {
      await api.post(`/employees/${id}/convert-to-permanent`);
      setConvertedEmployee({ name: name || 'Employee' });
      setSuccess(
        `${name || 'Employee'} converted to Permanent. Allocate their leave days in Financial Year.`,
      );
      setConvertType('');
      fetchEmployee();
    } catch (err) {
      const msg = err?.response?.data?.message;
      setConvertError(typeof msg === 'string' && msg ? msg : 'Failed to convert to permanent');
      console.error('Failed to convert employee to permanent:', err);
    } finally {
      setConverting(false);
    }
  };

  const handleProfileImageChange = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file type - only images allowed
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
      setError('Please select a valid image file (JPG, PNG, GIF or WebP)');
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      setError('Image size exceeds 5MB limit');
      return;
    }

    setProfileImageUploading(true);
    setError('');
    setSuccess('');

    try {
      const formData = new FormData();
      formData.append('file', file);
      await api.post(`/employees/${id}/profile-image`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setSuccess('Profile picture updated successfully');
      // Refresh employee data to get updated profile_image_url
      const response = await api.get(`/employees/${id}`);
      const data = response.data.data || response.data;
      setEmployee(data);
      if (data.profile_image_url) {
        setProfileImageUrl(`${API_BASE}/employees/${id}/profile-image?t=${Date.now()}`);
      }
    } catch (err) {
      setError('Failed to update profile picture');
      console.error('Failed to update profile picture:', err);
    } finally {
      setProfileImageUploading(false);
      e.target.value = '';
    }
  };

  // ---- Document-tab visibility (server-driven) ---------------------------
  //
  // These are HOOKS and must sit above the loading / not-found early returns
  // below. React requires the same hooks in the same order on every render, so
  // a useMemo placed after an early return would only be reached on some
  // renders and crash with "Rendered fewer hooks than expected".
  //
  // `none` hides the tab as well: an employee with no documents has nothing to
  // gate, and a tab that only ever renders an empty state is noise.
  //
  // `locked` removes the entry from the nav ENTIRELY rather than showing a
  // disabled tab. A tab that is present but un-clickable still tells the viewer
  // that documents exist, which is the very thing being protected. The
  // affordance to ask for access is a separate, explicit card instead.
  const documentsVisible =
    documentsAccess === DOCUMENT_ACCESS_STATES.OWNER ||
    documentsAccess === DOCUMENT_ACCESS_STATES.GRANTED ||
    documentsAccess === DOCUMENT_ACCESS_STATES.NONE;

  // Tabs actually rendered. PROFILE_TABS stays the module-level source of truth
  // for names/icons; this only filters it.
  const tabs = useMemo(
    () => PROFILE_TABS.filter((tab) => tab.id !== 'documents' || documentsVisible),
    [documentsVisible],
  );

  // A deep link to ?tab=documents must not strand someone on a tab that does
  // not exist, which is what would otherwise happen once the tab is filtered
  // out. Falling back to 'details' keeps the URL and the view consistent.
  useEffect(() => {
    if (activeTab === 'documents' && !documentsVisible) {
      setActiveTab('details');
    }
  }, [activeTab, documentsVisible]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
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
    );
  }

  const nextOfKin = employee.next_of_kin_data || safeParse(employee.next_of_kin);
  const dependantsList = employee.dependants_data || dependants;

  // Calculate days remaining until contract end date
  const calculateDaysRemaining = (endDateStr) => {
    if (!endDateStr) return null;
    const end = new Date(endDateStr);
    const now = new Date();
    if (isNaN(end.getTime())) return null;
    const diff = Math.ceil((end - now) / (1000 * 60 * 60 * 24));
    return diff > 0 ? diff : 0;
  };

  // True when the contract end date is in the past (expired contract).
  const isContractExpired = (endDateStr) => {
    if (!endDateStr) return false;
    const end = new Date(endDateStr);
    if (isNaN(end.getTime())) return false;
    return end < new Date();
  };

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
            <div className="relative shrink-0">
              <div className="h-16 w-16 rounded-full bg-primary-600 flex items-center justify-center text-white text-2xl font-bold overflow-hidden">
                {profileImageUrl ? (
                  <img
                    src={profileImageUrl}
                    alt={`${employee.first_name} ${employee.last_name}`}
                    className="h-full w-full object-cover"
                    onError={() => setProfileImageUrl(null)}
                  />
                ) : (
                  employee.first_name?.[0] || employee.last_name?.[0]
                )}
              </div>
              <CanEdit module="employees">
                <label
                  className="absolute -bottom-1 -right-1 inline-flex items-center p-1.5 rounded-full bg-white border border-gray-300 text-gray-600 hover:bg-gray-100 cursor-pointer shadow-sm"
                  title="Upload profile picture"
                >
                  {profileImageUploading ? (
                    <Loader2 className="h-3 w-3 animate-spin" />
                  ) : (
                    <Camera className="h-3 w-3" />
                  )}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    className="hidden"
                    onChange={handleProfileImageChange}
                    disabled={profileImageUploading}
                  />
                </label>
              </CanEdit>
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
          {/* opens /employees/:id/edit, which is route-gated by employees:edit */}
          <CanEdit module="employees">
            <Button variant="outline" onClick={() => navigate(`/employees/${id}/edit`)}>
              Edit Profile
            </Button>
          </CanEdit>
        </div>
      </Card>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md">
          {error}
        </div>
      )}

      {success && (
        <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md">
          {success}
        </div>
      )}

      {/* Detail tabs */}
      <div className="border-b border-gray-200 overflow-x-auto scrollbar-thin">
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
                <span className="text-gray-600">
                  {employee.position || employee.designation || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <Building2 className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">
                  {employee.department_name || employee.department || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <FileText className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">
                  Employment Type:{' '}
                  {employee.employment_type || employee.employee_type || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <Briefcase className="h-4 w-4 mr-2 text-gray-400" />
                <span className="text-gray-600">
                  Hire Date: {employee.hire_date || 'Not provided'}
                </span>
              </div>
            </div>
          </Card>

          <Card title="Personal Information">
            <div className="space-y-3">
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Gender:</span>
                <span className="text-gray-900 capitalize">
                  {employee.gender || 'Not provided'}
                </span>
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
                <span className="text-gray-900">
                  {employee.section_name || employee.section_id || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Subsection:</span>
                <span className="text-gray-900">
                  {employee.subsection_name || employee.subsection_id || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Office:</span>
                <span className="text-gray-900">
                  {employee.office_name || employee.office_id || 'Not provided'}
                </span>
              </div>
              <div className="flex items-center text-sm">
                <span className="w-32 text-gray-500">Scale:</span>
                <span className="text-gray-900">{employee.scale_id || 'Not provided'}</span>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Documents are LOCKED for this viewer.
          Shown instead of the Documents tab, which is filtered out of the nav
          above. The server sent placeholders only - no name, no category, no id -
          so this card can say how MANY documents exist without saying what they
          are. A viewer must be able to discover that access is requestable,
          otherwise the control is indistinguishable from "this employee has no
          documents" and nobody would ever ask. */}
      {documentsAccess === DOCUMENT_ACCESS_STATES.LOCKED && (
        <Card className="border-amber-200 bg-amber-50">
          <div className="flex flex-col sm:flex-row sm:items-center gap-4">
            <div className="flex-shrink-0">
              <div className="h-12 w-12 rounded-full bg-amber-100 flex items-center justify-center">
                <FileText className="h-6 w-6 text-amber-700" />
              </div>
            </div>
            <div className="flex-1">
              <h3 className="text-sm font-semibold text-amber-900">
                Encrypted documents
              </h3>
              <p className="text-sm text-amber-800 mt-1">
                {documents.length > 0 ? (
                  <>
                    This employee has{' '}
                    <span className="font-medium">{documents.length}</span> encrypted
                    document{documents.length === 1 ? '' : 's'}. Their contents and
                    filenames are hidden.
                  </>
                ) : (
                  'This employee has encrypted documents, hidden pending approval.'
                )}
              </p>
              <p className="text-xs text-amber-700 mt-1">
                Opening one sends a 6-digit code to the employee's own email address.
                They must approve it before anything becomes visible — you cannot
                see what you are requesting.
              </p>
            </div>
            <div className="flex-shrink-0">
              <Button
                onClick={requestFirstDocumentAccess}
                disabled={otpBusy}
              >
                {otpBusy ? (
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <FileText className="h-4 w-4 mr-2" />
                )}
                Request access
              </Button>
            </div>
          </div>
        </Card>
      )}

      {activeTab === 'documents' && (
        <div className="space-y-6">
          <CanEdit module="employees">
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
                    <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select
                      value={newDocument.category}
                      onChange={(e) =>
                        setNewDocument((prev) => ({ ...prev, category: e.target.value }))
                      }
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
                    <label className="block text-sm font-medium text-gray-700 mb-1">File</label>
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
          </CanEdit>

          <Card title="Employee Documents">
            {documents.length > 0 ? (
              <div className="space-y-3">
                {documents.map((doc, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                  >
                    <div className="flex items-center">
                      <FileText className="h-5 w-5 mr-2 text-gray-400" />
                      <div>
                        <p className="text-sm font-medium text-gray-900">
                          {doc.name || doc.document_name || `Document ${index + 1}`}
                        </p>
                        <p className="text-xs text-gray-500">
                          {doc.type || doc.category || 'Document'}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center space-x-2">
                      {/* Opening a document requires the OWNER's emailed code.
                          The button says so rather than failing silently. */}
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => requestDocumentAccess(doc)}
                        disabled={otpBusy}
                      >
                        {otpBusy ? (
                          <Loader2 className="h-4 w-4 mr-1 animate-spin" />
                        ) : (
                          <Download className="h-4 w-4 mr-1" />
                        )}
                        Open
                      </Button>
                      <CanEdit module="employees">
                        <Button
                          variant="danger"
                          size="sm"
                          onClick={() => handleDeleteDocument(doc.id)}
                        >
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </CanEdit>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 text-center py-8">
                No documents uploaded for this employee.
              </p>
            )}
          </Card>
        </div>
      )}
      {activeTab === 'contracts' && (
        <div className="space-y-6">
          <Card title={`Contracts (${contractCount})`}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-4">
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                  <div>
                    <p className="text-sm text-gray-500">Employment Type</p>
                    <p className="text-lg font-semibold text-gray-900 capitalize">
                      {employee.employment_type || 'Not specified'}
                    </p>
                    {employee.employee_type && (
                      <p className="text-xs text-gray-500 mt-0.5">
                        Role: {employee.employee_type.replace(/_/g, ' ')}
                      </p>
                    )}
                  </div>
                  <Badge
                    variant={
                      employee.employment_type === 'contract' ||
                      employee.employment_type === 'csuite'
                        ? 'warning'
                        : 'default'
                    }
                  >
                    {employee.employment_type === 'csuite'
                      ? 'C-suite (Contract)'
                      : employee.employment_type === 'contract'
                        ? 'Contract'
                        : 'Permanent'}
                  </Badge>
                </div>

                {(employee.employment_type === 'contract' ||
                  employee.employment_type === 'csuite') && (
                  <>
                    <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                      <div className="flex items-center text-sm text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-gray-400" />
                        <span>Contract Start</span>
                      </div>
                      <span className="font-medium text-gray-900">
                        {employee.contract_start_date || 'Not specified'}
                      </span>
                    </div>

                    <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                      <div className="flex items-center text-sm text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-gray-400" />
                        <span>Contract End</span>
                      </div>
                      <span
                        className={`font-medium ${isContractExpired(employee.contract_end_date) ? 'text-red-600' : 'text-gray-900'}`}
                      >
                        {employee.contract_end_date || 'Not specified'}
                      </span>
                    </div>

                    {employee.contract_end_date && (
                      <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div className="flex items-center text-sm text-gray-600">
                          <Clock className="h-4 w-4 mr-2 text-gray-400" />
                          <span>Status</span>
                        </div>
                        {isContractExpired(employee.contract_end_date) ? (
                          <span className="font-semibold text-red-600">
                            EXPIRED {Math.abs(calculateDaysRemaining(employee.contract_end_date))}{' '}
                            days ago
                          </span>
                        ) : (
                          <span className="font-medium text-gray-900">
                            {calculateDaysRemaining(employee.contract_end_date)} days remaining
                          </span>
                        )}
                      </div>
                    )}
                  </>
                )}

                {contracts.length > 0 && (
                  <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                      <p className="text-sm text-gray-500">Total Contracts Assigned</p>
                      <p className="text-2xl font-bold text-primary-700">{contractCount}</p>
                    </div>
                  </div>
                )}
              </div>

              {/* Contract History */}
              <div>
                {contracts.length > 0 ? (
                  <div className="space-y-3">
                    <h3 className="text-sm font-medium text-gray-700 mb-3">Contract History</h3>
                    {contracts.map((contract, index) => {
                      const endDate = contract.end_date ? new Date(contract.end_date) : null;
                      const isActive = !endDate || endDate > new Date();
                      const daysLeft = endDate
                        ? Math.ceil((endDate - new Date()) / (1000 * 60 * 60 * 24))
                        : null;
                      const isRenewed = renewedByMap.has(contract.id);
                      return (
                        <div
                          key={contract.id || index}
                          className={`p-4 rounded-lg border ${isActive ? 'border-green-200 bg-green-50' : 'border-gray-200 bg-gray-50'}`}
                        >
                          <div className="flex items-center justify-between mb-2">
                            <span className="text-sm font-medium text-gray-900">
                              {contract.name || contract.title || `Contract ${index + 1}`}
                            </span>
                            <Badge variant={isActive ? 'success' : 'default'}>
                              {isActive ? 'Active' : 'Ended'}
                            </Badge>
                          </div>
                          <div className="grid grid-cols-2 gap-2 text-xs text-gray-600">
                            <div>
                              <p>
                                Start:{' '}
                                <span className="font-medium text-gray-900">
                                  {contract.start_date || 'N/A'}
                                </span>
                              </p>
                            </div>
                            <div>
                              <p>
                                End:{' '}
                                <span className="font-medium text-gray-900">
                                  {contract.end_date || 'N/A'}
                                </span>
                              </p>
                            </div>
                            {daysLeft !== null && daysLeft > 0 && (
                              <div className="col-span-2">
                                <p className="text-amber-600">
                                  <Clock className="h-3 w-3 inline mr-1" />
                                  {daysLeft} days remaining
                                </p>
                              </div>
                            )}
                          </div>
                          {isActive && (
                            <div className="mt-3 flex justify-end">
                              {isRenewed ? (
                                <span className="text-xs text-gray-500 italic self-center">
                                  Renewed — see Contract #{renewedByMap.get(contract.id)}
                                </span>
                              ) : (
                                <CanEdit module="employees">
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => openRenewModal(contract)}
                                    disabled={renewingContract}
                                  >
                                    {renewingContract ? (
                                      <>
                                        <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                                        Renewing...
                                      </>
                                    ) : (
                                      <>
                                        <RefreshCw className="h-3 w-3 mr-1" />
                                        Renew Contract
                                      </>
                                    )}
                                  </Button>
                                </CanEdit>
                              )}
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <div className="text-center py-8">
                    <RefreshCw className="h-12 w-12 mx-auto text-gray-300 mb-3" />
                    <p className="text-gray-500">No contracts on record for this employee.</p>
                    {employee.employment_type !== 'contract' &&
                      employee.employment_type !== 'csuite' && (
                        <p className="text-sm text-gray-400 mt-1">
                          This employee is not on a contract employment type.
                        </p>
                      )}
                  </div>
                )}
              </div>
            </div>
          </Card>

          {/* Contract → Permanent conversion — contract-based employees only.
              The dropdown is the single intentional action (one-way change);
              the backend rejects conversions for non-contract employees. */}
          {(employee.employment_type === 'contract' || employee.employment_type === 'csuite') && (
            <CanEdit module="employees">
              <Card title="Employment Conversion" className="bg-white dark:bg-slate-800">
                <div className="space-y-3">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    <div className="md:col-span-2">
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Employment action
                      </label>
                      <select
                        value={convertType}
                        onChange={(e) => {
                          setConvertType(e.target.value);
                          setConvertError('');
                        }}
                        disabled={converting}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                      >
                        <option value="">— Select action —</option>
                        <option value="permanent">Convert to Permanent</option>
                      </select>
                    </div>
                    <Button
                      onClick={submitConvertToPermanent}
                      disabled={converting || convertType !== 'permanent'}
                    >
                      {converting ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Converting...
                        </>
                      ) : (
                        <>
                          <RefreshCw className="h-4 w-4 mr-2" />
                          Apply Conversion
                        </>
                      )}
                    </Button>
                  </div>
                  {convertError && (
                    <p className="text-sm text-red-600 dark:text-red-400">{convertError}</p>
                  )}
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    Converting sets employment_type to "permanent", clears the active contract dates
                    and keeps the contract history. No new contract term is created. Afterwards,
                    allocate the employee's leave days in Financial Year → Leave Allocation —
                    permanent employees receive the full leave package.
                  </p>
                </div>
              </Card>
            </CanEdit>
          )}

          {/* Post-conversion banner — next step: allocate leave in Financial Year */}
          {convertedEmployee && (
            <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <p className="text-sm font-medium text-green-900 dark:text-green-200">
                  {convertedEmployee.name} is now a Permanent employee.
                </p>
                <p className="text-sm text-green-700 dark:text-green-300 mt-0.5">
                  Next step: allocate their leave days for the financial year in Financial Year →
                  Leave Allocation.
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <Button
                  size="sm"
                  onClick={() =>
                    navigate('/financial_year', {
                      state: {
                        allocateEmployeeId: Number(id),
                        allocateEmployeeName: convertedEmployee.name,
                      },
                    })
                  }
                >
                  Go to Financial Year
                </Button>
                <Button variant="outline" size="sm" onClick={() => setConvertedEmployee(null)}>
                  Dismiss
                </Button>
              </div>
            </div>
          )}

          {/* Contract Renewal Info */}
          {(employee.employment_type === 'contract' || employee.employment_type === 'csuite') && (
            <Card title="Contract Renewal" className="bg-blue-50 border-blue-200">
              <div className="flex items-start space-x-3">
                <RefreshCw className="h-5 w-5 text-blue-600 mt-0.5 shrink-0" />
                <div>
                  <p className="text-sm font-medium text-blue-900">About Contract Renewal</p>
                  <p className="text-sm text-blue-700 mt-1">
                    When a contract is due to expire, click{' '}
                    <span className="font-medium">"Renew Contract"</span> on the contract card above
                    to set the new term (6 or 12 months, or a custom end date). Each contract can
                    only be renewed once — after a renewal, only the latest contract can be renewed
                    again.
                  </p>
                </div>
              </div>
            </Card>
          )}

          {/* Renew Contract — small term form (months / dates). The backend
              enforces the one-renewal-per-contract rule. */}
          <Modal
            isOpen={!!renewModal}
            onClose={() => {
              if (!renewingContract) setRenewModal(null);
            }}
            title="Renew Contract"
            size="sm"
          >
            {renewModal && (
              <div className="space-y-4">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  Set the new contract term. Each contract can only be renewed once.
                </p>
                <div className="rounded-lg border border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-900/40 p-3 text-xs text-gray-600 dark:text-gray-300">
                  <p>
                    <span className="font-medium">Current term:</span>{' '}
                    {renewModal.contract.start_date || '—'} →{' '}
                    {renewModal.contract.end_date || 'open-ended'}
                  </p>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Renewal period
                  </label>
                  <select
                    value={renewDuration}
                    onChange={(e) => handleRenewDurationChange(e.target.value)}
                    disabled={renewingContract}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                  >
                    <option value="6">6 months</option>
                    <option value="12">12 months (1 year)</option>
                    <option value="custom">Custom end date</option>
                  </select>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      Start date
                    </label>
                    <input
                      type="date"
                      value={renewForm.start_date}
                      onChange={(e) => setRenewForm((f) => ({ ...f, start_date: e.target.value }))}
                      disabled={renewingContract}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                      End date
                    </label>
                    <input
                      type="date"
                      value={renewForm.end_date}
                      min={renewForm.start_date || undefined}
                      onChange={(e) => {
                        setRenewDuration('custom');
                        setRenewForm((f) => ({ ...f, end_date: e.target.value }));
                      }}
                      disabled={renewingContract}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md shadow-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                    />
                  </div>
                </div>
                {renewForm.start_date &&
                  renewForm.end_date &&
                  (() => {
                    const s = new Date(`${renewForm.start_date}T00:00:00`);
                    const e = new Date(`${renewForm.end_date}T00:00:00`);
                    if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime()) || e <= s)
                      return null;
                    const days = Math.round((e - s) / 86400000);
                    let months =
                      (e.getFullYear() - s.getFullYear()) * 12 + (e.getMonth() - s.getMonth());
                    if (e.getDate() < s.getDate()) months -= 1;
                    return (
                      <p className="text-xs text-gray-600 dark:text-gray-300">
                        <Clock className="h-3 w-3 inline mr-1" />
                        Term:{' '}
                        <span className="font-medium">
                          {months} month{months !== 1 ? 's' : ''}
                        </span>{' '}
                        ({days} days)
                      </p>
                    );
                  })()}
                {renewError && (
                  <div className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md px-3 py-2">
                    {renewError}
                  </div>
                )}
                <div className="flex justify-end gap-2 pt-1">
                  <Button
                    variant="outline"
                    onClick={() => setRenewModal(null)}
                    disabled={renewingContract}
                  >
                    Cancel
                  </Button>
                  <Button onClick={submitRenewContract} disabled={renewingContract}>
                    {renewingContract ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Renewing...
                      </>
                    ) : (
                      <>
                        <RefreshCw className="h-4 w-4 mr-2" />
                        Renew Contract
                      </>
                    )}
                  </Button>
                </div>
              </div>
            )}
          </Modal>
        </div>
      )}

      {activeTab === 'nextofkin' && (
        <div className="space-y-6">
          <CanEdit module="employees">
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
          </CanEdit>

          <Card title="Current Next of Kin">
            {nextOfKin.length > 0 ? (
              <div className="space-y-3">
                {nextOfKin.map((kin, index) => (
                  <div key={index} className="p-4 bg-gray-50 rounded-lg">
                    <p className="text-sm font-medium text-gray-900">
                      {kin.name || `Next of Kin ${index + 1}`}
                    </p>
                    <p className="text-xs text-gray-500 mt-1">
                      {kin.relationship || 'Relationship not specified'}
                    </p>
                    {kin.contact && (
                      <div className="flex items-center mt-2 text-sm text-gray-600">
                        <Phone className="h-3 w-3 mr-1 text-gray-400" />
                        {kin.contact}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 text-center py-8">No next of kin information on file.</p>
            )}
          </Card>
        </div>
      )}

      {activeTab === 'dependants' && (
        <div className="space-y-6">
          <CanEdit module="employees">
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
          </CanEdit>

          <Card title="Dependants">
            {dependantsList.length > 0 ? (
              <div className="space-y-3">
                {dependantsList.map((dep, index) => (
                  <div
                    key={index}
                    className="p-4 bg-gray-50 rounded-lg flex items-center justify-between"
                  >
                    <div>
                      <p className="text-sm font-medium text-gray-900">
                        {dep.name || `Dependant ${index + 1}`}
                      </p>
                      <p className="text-xs text-gray-500 mt-1">
                        {dep.relationship || 'Relationship not specified'}
                      </p>
                      <p className="text-xs text-gray-500 mt-1">
                        Date of Birth: {dep.date_of_birth || 'Not provided'}
                      </p>
                      {dep.gender && (
                        <p className="text-xs text-gray-500 mt-1">Gender: {dep.gender}</p>
                      )}
                      {dep.id_no && (
                        <p className="text-xs text-gray-500 mt-1">ID No: {dep.id_no}</p>
                      )}
                      {dep.contact && (
                        <p className="text-xs text-gray-500 mt-1">Contact: {dep.contact}</p>
                      )}
                    </div>
                    <CanEdit module="employees">
                      <Button
                        variant="danger"
                        size="sm"
                        onClick={() => handleDeleteDependant(index)}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </CanEdit>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-gray-500 text-center py-8">No dependants on record.</p>
            )}
          </Card>
        </div>
      )}
      {/* Encrypted-document approval — the owner's emailed code.
          Rendered once, outside the tab panels, because the modal has to
          survive a tab switch while the user goes to their inbox. */}
      <Modal
        isOpen={!!otpDoc}
        onClose={() => {
          if (!otpBusy) cancelOtp();
        }}
        title="Document approval required"
        size="sm"
      >
        {otpDoc && (
          <form onSubmit={submitOtpCode} className="space-y-4">
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
              <p className="text-xs text-gray-600">
                <span className="font-medium">Document:</span> {otpDoc.name}
              </p>
            </div>

            {otpDoc.step === 'requested' && (
              <div className="flex items-center space-x-2 text-sm text-gray-600">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span>Sending a code to the document owner…</span>
              </div>
            )}

            {otpDoc.step === 'opening' && (
              <div className="flex items-center space-x-2 text-sm text-gray-600">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span>Code accepted. Opening the document…</span>
              </div>
            )}

            {otpDoc.step === 'awaiting_code' && (
              <>
                <p className="text-sm text-gray-700">
                  A 6-digit code was emailed to{' '}
                  <span className="font-medium">{otpDoc.maskedEmail || 'the document owner'}</span>
                  {otpDoc.ttlMinutes ? ` and expires in ${otpDoc.ttlMinutes} minutes` : ''}.
                </p>

                <p className="text-xs text-gray-500">
                  The code goes to the employee who owns this document, so you may need to ask
                  them for it. It can be used once.
                </p>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    6-digit code
                  </label>
                  <input
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={otpCode}
                    onChange={(e) => {
                      setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6));
                      setOtpError('');
                    }}
                    disabled={otpBusy}
                    autoFocus
                    placeholder="000000"
                    className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-500 text-center text-lg tracking-widest font-mono"
                  />
                </div>
              </>
            )}

            {otpError && (
              <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                {otpError}
              </div>
            )}

            <div className="flex justify-end gap-2 pt-1">
              <Button
                type="button"
                variant="outline"
                onClick={cancelOtp}
                disabled={otpBusy}
              >
                Cancel
              </Button>
              {otpDoc.step === 'awaiting_code' && (
                <Button type="submit" disabled={otpBusy || otpCode.length !== 6}>
                  {otpBusy ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Verifying...
                    </>
                  ) : (
                    'Verify and open'
                  )}
                </Button>
              )}
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
};

export default EmployeeProfile;
