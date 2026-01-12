import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type HelixUser = {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  role: 'owner' | 'manager' | 'technician' | 'frontdesk';
  phone: string | null;
  color: string | null;
  is_active: boolean;
  last_login_at: string | null;
};

type UserFilters = {
  role?: string;
  isActive?: boolean;
  search?: string;
};

export function useHelixUsers(filters: UserFilters = {}) {
  return useQuery<HelixUser[]>({
    queryKey: ['users', filters],
    queryFn: async (): Promise<HelixUser[]> => {
      const params: Record<string, unknown> = {};
      if (filters.role) params.role = filters.role;
      if (typeof filters.isActive === 'boolean') params.is_active = filters.isActive ? 'true' : 'false';
      if (filters.search) params.search = filters.search;

      const response = await apiClient.get('/users', { params });
      return response.data.data ?? response.data ?? [];
    },
  });
}

type CreateUserInput = {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  role: 'owner' | 'manager' | 'technician' | 'frontdesk';
  phone?: string | null;
  color?: string | null;
  is_active?: boolean;
};

type UpdateUserInput = {
  id: number;
  payload: Partial<Omit<CreateUserInput, 'password'>> & {
    password?: string | null;
  };
};

export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: CreateUserInput): Promise<HelixUser> => {
      const response = await apiClient.post('/users', payload);
      return response.data.data ?? response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

export function useUpdateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, payload }: UpdateUserInput): Promise<HelixUser> => {
      const response = await apiClient.patch(`/users/${id}`, payload);
      return response.data.data ?? response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

export function useDeleteUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/users/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}
