import apiClient from '../client';
import type { ApiResponse, LoginResponse, User } from '../../types';

export const authService = {
  login: async (email: string, password: string): Promise<ApiResponse<LoginResponse>> => {
    const response = await apiClient.post<ApiResponse<LoginResponse>>('/auth/login', {
      email,
      password,
    });
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

  /**
   * Step 1: ask for a reset link + 6-digit code.
   *
   * The backend answers identically for a known and an unknown address, so the
   * UI must render `message` verbatim and must never try to infer success from
   * anything else in the response.
   */
  forgotPassword: async (email: string): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/forgot-password', { email });
    return response.data;
  },

  /** Is the link in the URL still live? Lets the page say "expired" up front. */
  validateResetToken: async (
    token: string,
  ): Promise<ApiResponse<{ valid: boolean; masked: string | null }>> => {
    const response = await apiClient.get<ApiResponse<{ valid: boolean; masked: string | null }>>(
      '/auth/reset-password/validate',
      { params: { token } },
    );
    return response.data;
  },

  /** Step 2: check the 6-digit code from the email. */
  verifyResetOtp: async (token: string, otp: string): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/reset-password/verify-otp', {
      token,
      otp,
    });
    return response.data;
  },

  /** Step 3: set the new password. Requires step 2 to have succeeded. */
  resetPassword: async (
    token: string,
    password: string,
    confirmPassword: string,
  ): Promise<ApiResponse<null>> => {
    const response = await apiClient.post<ApiResponse<null>>('/auth/reset-password/complete', {
      token,
      password,
      confirm_password: confirmPassword,
    });
    return response.data;
  },
};
