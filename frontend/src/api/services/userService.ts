import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, User } from '../../types';

export const userService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<User>> => {
    const response = await apiClient.get<PaginatedResponse<User>>('/users', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<User>> => {
    const response = await apiClient.get<ApiResponse<User>>(`/users/${id}`);
    return response.data;
  },

  create: async (data: Partial<User>): Promise<ApiResponse<User>> => {
    const response = await apiClient.post<ApiResponse<User>>('/users', data);
    return response.data;
  },

  update: async (id: number, data: Partial<User>): Promise<ApiResponse<User>> => {
    const response = await apiClient.put<ApiResponse<User>>(`/users/${id}`, data);
    return response.data;
  },

  delete: async (id: number): Promise<ApiResponse<null>> => {
    const response = await apiClient.delete<ApiResponse<null>>(`/users/${id}`);
    return response.data;
  },

  toggleStatus: async (id: number): Promise<ApiResponse<User>> => {
    const response = await apiClient.put<ApiResponse<User>>(`/users/${id}/toggle-status`);
    return response.data;
  },
};