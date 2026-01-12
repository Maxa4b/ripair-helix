import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

type SettingsRecord = Record<string, unknown>;

export function useSettings() {
  return useQuery<SettingsRecord>({
    queryKey: ['settings'],
    queryFn: async (): Promise<SettingsRecord> => {
      const response = await apiClient.get('/settings');
      return response.data || {};
    },
  });
}

type UpdateSettingInput = {
  key: string;
  value: unknown;
};

export function useUpdateSetting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ key, value }: UpdateSettingInput): Promise<{ key: string; value: unknown }> => {
      const response = await apiClient.put(`/settings/${encodeURIComponent(key)}`, { value });
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
  });
}
