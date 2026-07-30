import apiClient from '../client';
import type { ApiResponse, LoginResponse, User } from '../../types';

export const authService = {
  login: async (email: string, password: string): Promise<ApiResponse<LoginResponse>> => {
    const response = await apiClient.post<ApiResponse<LoginResponse>>('/auth/login', { email, password });
    return response.data;
  },

  logout: async (): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/logout');
    return response.data;
  },

  getProfile: async (): Promise<ApiResponse<User>> => {
    const response = await apiClient.get<ApiResponse<User>>('/auth/profile');
    return response.data;
  },

  refreshToken: async (): Promise<ApiResponse<{ token: string }>> => {
    const response = await apiClient.post<ApiResponse<{ token: string }>>('/auth/refresh');
    return response.data;
  },

  forgotPassword: async (email: string): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/forgot-password', { email });
    return response.data;
  },

  resetPassword: async (token: string, password: string): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/reset-password', { token, password });
    return response.data;
  },
};