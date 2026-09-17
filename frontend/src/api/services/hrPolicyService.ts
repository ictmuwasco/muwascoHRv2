import apiClient from '../client';
import type { ApiResponse } from '../../types';
import { API_BASE_URL } from '../../config/api';

/**
 * hrPolicyService — HR Policy & Procedures module (migration 081).
 * Employee surface: current policy, section tree/reader, search, file
 * streaming, acknowledgement, bookmarks, recently viewed.
 * Admin surface (/settings/hr-policies): versions, upload, workflow,
 * history, acknowledgements — all require hr_policies:manage/publish.
 */

export type PolicyStatus = 'draft' | 'review' | 'published' | 'archived';

export interface HrPolicyDocument {
  id: number;
  title: string;
  description?: string | null;
  version: string;
  status: PolicyStatus;
  source_type: string;
  effective_date?: string | null;
  published_at?: string | null;
  archived_at?: string | null;
  file_name: string;
  mime_type: string;
  file_size: number;
  page_count?: number | null;
  section_count: number;
  is_active: 0 | 1;
  uploaded_by?: number;
  uploaded_by_name?: string | null;
  created_at?: string;
}

export interface PolicySectionNode {
  id: number;
  parent_id: number | null;
  section_number: string | null;
  title: string;
  page_start?: number | null;
  page_end?: number | null;
  sort_order: number;
}

export interface PolicySectionDetail extends PolicySectionNode {
  document_id: number;
  content: string | null;
}

export interface PolicyBreadcrumb {
  id: number;
  parent_id: number | null;
  section_number: string | null;
  title: string;
}

export interface PolicySearchHit {
  section_id: number;
  document_id: number;
  document_title: string;
  document_version: string;
  section_number: string | null;
  title: string;
  excerpt: string;
  page_start: number | null;
  match_type: string;
  parent_title: string | null;
}

export interface BookmarkItem {
  bookmark_id: number;
  bookmarked_at: string;
  section_id: number;
  section_number: string | null;
  title: string;
  page_start: number | null;
  document_id: number;
  document_title: string;
}

export interface RecentItem {
  section_id: number;
  viewed_at: string;
  section_number: string | null;
  title: string;
  page_start: number | null;
  document_id: number;
  document_title: string;
}

export interface AckRecord {
  id: number;
  user_id: number;
  employee_id: number | null;
  acknowledged_at: string;
  ip_address: string | null;
  user_name?: string;
  email?: string;
}

export interface HistoryAuditRow {
  id: number;
  action: string;
  description: string;
  status: string;
  user_name_snapshot: string | null;
  user_role_snapshot: string | null;
  old_values: unknown;
  new_values: unknown;
  created_at: string;
}

export interface CurrentPolicyResponse {
  policy: (Omit<HrPolicyDocument, 'status'> & { acknowledgement_message: string }) | null;
  acknowledged_at: string | null;
  bookmarks: BookmarkItem[];
  recent: RecentItem[];
}

export interface SectionResponse {
  section: PolicySectionDetail;
  breadcrumbs: PolicyBreadcrumb[];
  document: { id: number; title: string; version: string; status: PolicyStatus };
  bookmarked: boolean;
}

export const hrPolicyService = {
  // ------------------------------------------------------------------
  // Employee surface
  // ------------------------------------------------------------------
  getCurrent: async (): Promise<CurrentPolicyResponse> => {
    const res = await apiClient.get<ApiResponse<CurrentPolicyResponse>>('/hr-policies/current');
    return res.data.data;
  },

  listPublished: async (): Promise<HrPolicyDocument[]> => {
    const res = await apiClient.get<ApiResponse<{ items: HrPolicyDocument[] }>>('/hr-policies');
    return res.data.data.items;
  },

  getDocument: async (id: number): Promise<HrPolicyDocument> => {
    const res = await apiClient.get<ApiResponse<{ policy: HrPolicyDocument }>>(`/hr-policies/${id}`);
    return res.data.data.policy;
  },

  getSections: async (id: number): Promise<PolicySectionNode[]> => {
    const res = await apiClient.get<ApiResponse<{ items: PolicySectionNode[] }>>(`/hr-policies/${id}/sections`);
    return res.data.data.items;
  },

  getSection: async (id: number): Promise<SectionResponse> => {
    const res = await apiClient.get<ApiResponse<SectionResponse>>(`/hr-policies/sections/${id}`);
    return res.data.data;
  },

  search: async (q: string, limit = 25): Promise<PolicySearchHit[]> => {
    const res = await apiClient.get<ApiResponse<{ query: string; items: PolicySearchHit[] }>>(
      '/hr-policies/search',
      { params: { q, limit } }
    );
    return res.data.data.items;
  },

  /**
   * Direct (cookie-authenticated) URL to stream the ORIGINAL manual inline,
   * or to download it with ?download=1.
   */
  fileUrl: (documentId: number, download = false): string =>
    `${API_BASE_URL}/hr-policies/${documentId}/file${download ? '?download=1' : ''}`,

  acknowledge: async (id: number): Promise<{ acknowledged: boolean; first_time: boolean; message: string }> => {
    const res = await apiClient.post<ApiResponse<{
      acknowledged: boolean; first_time: boolean; message: string;
    }>>(`/hr-policies/${id}/acknowledge`);
    return res.data.data;
  },

  listBookmarks: async (): Promise<BookmarkItem[]> => {
    const res = await apiClient.get<ApiResponse<{ items: BookmarkItem[] }>>('/hr-policies/bookmarks');
    return res.data.data.items;
  },

  addBookmark: async (sectionId: number): Promise<void> => {
    await apiClient.post('/hr-policies/bookmarks', { section_id: sectionId });
  },

  removeBookmark: async (sectionId: number): Promise<void> => {
    await apiClient.delete(`/hr-policies/bookmarks/${sectionId}`);
  },

  listRecent: async (): Promise<RecentItem[]> => {
    const res = await apiClient.get<ApiResponse<{ items: RecentItem[] }>>('/hr-policies/recent');
    return res.data.data.items;
  },

  // ------------------------------------------------------------------
  // HR administration surface (/settings/hr-policies)
  // ------------------------------------------------------------------
  adminList: async (): Promise<HrPolicyDocument[]> => {
    const res = await apiClient.get<ApiResponse<{ items: HrPolicyDocument[] }>>('/settings/hr-policies');
    return res.data.data.items;
  },

  adminUpload: async (form: FormData): Promise<number> => {
    const res = await apiClient.post<ApiResponse<{ id: number }>>('/settings/hr-policies', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return res.data.data.id;
  },

  adminUpdate: async (id: number, payload: Record<string, unknown>): Promise<void> => {
    await apiClient.put(`/settings/hr-policies/${id}`, payload);
  },

  adminSetStatus: async (id: number, status: 'draft' | 'review'): Promise<void> => {
    await apiClient.post(`/settings/hr-policies/${id}/status`, { status });
  },

  adminPublish: async (id: number): Promise<void> => {
    await apiClient.post(`/settings/hr-policies/${id}/publish`);
  },

  adminArchive: async (id: number): Promise<void> => {
    await apiClient.post(`/settings/hr-policies/${id}/archive`);
  },

  adminDelete: async (id: number): Promise<void> => {
    await apiClient.delete(`/settings/hr-policies/${id}`);
  },

  adminHistory: async (id: number): Promise<{
    document: HrPolicyDocument;
    versions: HrPolicyDocument[];
    audit: HistoryAuditRow[];
  }> => {
    const res = await apiClient.get<ApiResponse<{
      document: HrPolicyDocument; versions: HrPolicyDocument[]; audit: HistoryAuditRow[];
    }>>(`/settings/hr-policies/${id}/history`);
    return res.data.data;
  },

  adminAcknowledgements: async (id: number): Promise<{
    document: { id: number; title: string; version: string };
    items: AckRecord[];
    total_acks: number;
    active_users: number;
  }> => {
    const res = await apiClient.get<ApiResponse<{
      document: { id: number; title: string; version: string };
      items: AckRecord[];
      total_acks: number;
      active_users: number;
    }>>(`/settings/hr-policies/${id}/acknowledgements`);
    return res.data.data;
  },
};

export default hrPolicyService;
