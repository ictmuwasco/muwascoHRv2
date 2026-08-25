import apiClient from '../client';
import type { ApiResponse } from '../../types';

export interface PushDevice {
  id: number
  device_name: string
  platform: string | null
  last_used_at: string | null
  created_at: string | null
}

export interface NotificationPreferencesView {
  push_enabled: boolean
  sms_enabled: boolean
  effective_push_enabled: boolean
  effective_sms_enabled: boolean
  reminders_mandatory: boolean
  reminder_time: string
  sms_fallback_delay_minutes: number
  phone_masked: string | null
  has_active_push: boolean
}

export interface SubscribePayload {
  endpoint: string
  keys: { p256dh: string; auth: string }
  device_name?: string
  platform?: string
}

export const notificationService = {
  getVapidPublicKey: async (): Promise<ApiResponse<{ public_key: string }>> => {
    const response = await apiClient.get<ApiResponse<{ public_key: string }>>('/push/vapid-public-key');
    return response.data;
  },

  subscribe: async (payload: SubscribePayload): Promise<ApiResponse<{ subscription_id: number; devices: PushDevice[] }>> => {
    const response = await apiClient.post<
      ApiResponse<{ subscription_id: number; devices: PushDevice[] }>
    >('/push/subscribe', payload);
    return response.data;
  },

  unsubscribe: async (endpoint: string): Promise<ApiResponse<{ devices: PushDevice[] }>> => {
    const response = await apiClient.delete<ApiResponse<{ devices: PushDevice[] }>>('/push/subscribe', {
      data: { endpoint },
    });
    return response.data;
  },

  listDevices: async (): Promise<ApiResponse<{ has_vapid: boolean; devices: PushDevice[] }>> => {
    const response = await apiClient.get<
      ApiResponse<{ has_vapid: boolean; devices: PushDevice[] }>
    >('/push/subscriptions');
    return response.data;
  },

  getPreferences: async (): Promise<ApiResponse<NotificationPreferencesView>> => {
    const response = await apiClient.get<ApiResponse<NotificationPreferencesView>>(
      '/notification-preferences'
    );
    return response.data;
  },

  savePreferences: async (
    prefs: { push_enabled: boolean; sms_enabled: boolean }
  ): Promise<ApiResponse<{ push_enabled: boolean; sms_enabled: boolean }>> => {
    const response = await apiClient.put<
      ApiResponse<{ push_enabled: boolean; sms_enabled: boolean }>
    >('/notification-preferences', prefs);
    return response.data;
  },
};
