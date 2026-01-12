import { useMutation } from '@tanstack/react-query';
import apiClient from '../api/client';

type RefreshResponse = {
  message?: string;
  version?: number;
  updated_at?: string;
};

export function useRefreshOptionsCache() {
  return useMutation({
    mutationFn: async (): Promise<RefreshResponse> => {
      const response = await apiClient.post('/options-cache/refresh');
      return response.data ?? {};
    },
  });
}
