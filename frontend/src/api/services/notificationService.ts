import apiClient from '../client';
import type { ApiResponse } from '../../types';

export interface PushDevice {
  id: number;
  device_name: string;
  platform: string | null;
  last_used_at: string | null;
  created_at: string | null;
}

export interface NotificationPreferencesView {
  push_enabled: boolean;
  sms_enabled: boolean;
  effective_push_enabled: boolean;
  effective_sms_enabled: boolean;
  reminders_mandatory: boolean;
  reminder_time: string;
  sms_fallback_delay_minutes: number;
  phone_masked: string | null;
  has_active_push: boolean;
}

export interface SubscribePayload {
  endpoint: string;
  keys: { p256dh: string; auth: string };
  device_name?: string;
  platform?: string;
}

/** One row in the in-app inbox. */
export interface InboxNotification {
  id: number;
  title: string;
  message: string;
  type: string;
  category: string | null;
  priority: string | null;
  action_url: string | null;
  is_read: number;
  trigger_type: string | null;
  related_entity: string | null;
  related_id: number | null;
  created_at: string;
}

export type InboxFilter = 'all' | 'unread' | 'read';

export interface InboxPage {
  notifications: InboxNotification[];
  unread_count: number;
  total: number;
  page: number;
  per_page: number;
  pages: number;
  filter: InboxFilter;
  category: string | null;
  categories: string[];
}

export const notificationService = {
  getVapidPublicKey: async (): Promise<ApiResponse<{ public_key: string }>> => {
    const response =
      await apiClient.get<ApiResponse<{ public_key: string }>>('/push/vapid-public-key');
    return response.data;
  },

  subscribe: async (
    payload: SubscribePayload,
  ): Promise<ApiResponse<{ subscription_id: number; devices: PushDevice[] }>> => {
    const response = await apiClient.post<
      ApiResponse<{ subscription_id: number; devices: PushDevice[] }>
    >('/push/subscribe', payload);
    return response.data;
  },

  unsubscribe: async (endpoint: string): Promise<ApiResponse<{ devices: PushDevice[] }>> => {
    const response = await apiClient.delete<ApiResponse<{ devices: PushDevice[] }>>(
      '/push/subscribe',
      {
        data: { endpoint },
      },
    );
    return response.data;
  },

  listDevices: async (): Promise<ApiResponse<{ has_vapid: boolean; devices: PushDevice[] }>> => {
    const response =
      await apiClient.get<ApiResponse<{ has_vapid: boolean; devices: PushDevice[] }>>(
        '/push/subscriptions',
      );
    return response.data;
  },

  getPreferences: async (): Promise<ApiResponse<NotificationPreferencesView>> => {
    const response = await apiClient.get<ApiResponse<NotificationPreferencesView>>(
      '/notification-preferences',
    );
    return response.data;
  },

  savePreferences: async (prefs: {
    push_enabled: boolean;
    sms_enabled: boolean;
  }): Promise<ApiResponse<{ push_enabled: boolean; sms_enabled: boolean }>> => {
    const response = await apiClient.put<
      ApiResponse<{ push_enabled: boolean; sms_enabled: boolean }>
    >('/notification-preferences', prefs);
    return response.data;
  },

  /**
   * A page of the signed-in user's inbox.
   *
   * `filter` and `category` are optional; omitting them returns everything,
   * newest first. The server caps per_page at 50.
   */
  list: async (params?: {
    page?: number;
    per_page?: number;
    filter?: InboxFilter;
    category?: string | null;
  }): Promise<ApiResponse<InboxPage>> => {
    const response = await apiClient.get<ApiResponse<InboxPage>>('/notifications', {
      params: {
        page: params?.page ?? 1,
        per_page: params?.per_page ?? 15,
        // null would serialise as the string "null", so only send real values.
        ...(params?.filter ? { filter: params.filter } : {}),
        ...(params?.category ? { category: params.category } : {}),
      },
    });
    return response.data;
  },

  /**
   * The bell dropdown payload: a small, unread-only slice.
   *
   * Separate from list() so polling the badge never pulls the whole inbox.
   */
  listUnread: async (
    limit = 5,
  ): Promise<ApiResponse<{ notifications: InboxNotification[]; unread_count: number }>> => {
    const response = await apiClient.get<
      ApiResponse<{ notifications: InboxNotification[]; unread_count: number }>
    >('/notifications/unread', { params: { limit } });
    return response.data;
  },

  markAsRead: async (id: number): Promise<ApiResponse<{ id: number; unread_count: number }>> => {
    const response = await apiClient.post<ApiResponse<{ id: number; unread_count: number }>>(
      `/notifications/${id}/read`,
    );
    return response.data;
  },

  markAllAsRead: async (): Promise<ApiResponse<{ marked_read: number; unread_count: number }>> => {
    const response =
      await apiClient.post<ApiResponse<{ marked_read: number; unread_count: number }>>(
        '/notifications/read-all',
      );
    return response.data;
  },
};
