import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type AvailabilityBlock = {
  id: number;
  type: 'open' | 'closed' | 'maintenance' | 'offsite';
  title: string | null;
  start_datetime: string;
  end_datetime: string;
  recurrence_rule: string | null;
  recurrence_until: string | null;
  color: string | null;
  notes: string | null;
  created_by: {
    id: number;
    full_name: string;
  };
};

export type AvailabilityRange = {
  start: string;
  end: string;
};

export function useAvailabilityBlocks(range: AvailabilityRange) {
  return useQuery({
    queryKey: ['availability-blocks', range],
    queryFn: async (): Promise<AvailabilityBlock[]> => {
      const response = await apiClient.get('/availability-blocks', { params: range });
      return response.data.data ?? response.data;
    },
  });
}

type CreateAvailabilityInput = {
  type: 'open' | 'closed' | 'maintenance' | 'offsite';
  title?: string;
  start_datetime: string;
  end_datetime: string;
  recurrence_rule?: string | null;
  recurrence_until?: string | null;
  color?: string | null;
  notes?: string | null;
};

export function useCreateAvailabilityBlock(range: AvailabilityRange) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: CreateAvailabilityInput): Promise<AvailabilityBlock> => {
      const response = await apiClient.post('/availability-blocks', payload);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['availability-blocks', range] });
    },
  });
}

export function useDeleteAvailabilityBlock(range: AvailabilityRange) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/availability-blocks/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['availability-blocks', range] });
    },
  });
}
