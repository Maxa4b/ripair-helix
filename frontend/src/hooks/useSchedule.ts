
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type ScheduleSlotStatus = 'available' | 'closed' | 'booked' | 'past';

export type ScheduleSlot = {
  time: string;
  start: string;
  end: string;
  toggle_end?: string;
  status: ScheduleSlotStatus;
  toggleable: boolean;
  block_id: number | null;
  discount: number;
  step_minutes?: number;
};

export type ScheduleDay = {
  date: string;
  slots: ScheduleSlot[];
};

export type ScheduleQueryParams = {
  start: string;
  days?: number;
  duration_min?: number;
  lead_min?: number;
};

export function useScheduleSlots(params: ScheduleQueryParams) {
  return useQuery({
    queryKey: ['schedule', 'slots', params],
    queryFn: async (): Promise<ScheduleDay[]> => {
      const response = await apiClient.get('/schedule/slots', { params });
      if (Array.isArray(response.data)) {
        return response.data;
      }
      if (Array.isArray(response.data?.data)) {
        return response.data.data;
      }
      return [];
    },
    enabled: Boolean(params.start),
  });
}

type ToggleVariables = {
  start: string;
  end: string;
  makeAvailable: boolean;
  blockId?: number | null;
  stepMinutes?: number | null;
  durationMinutes?: number | null;
};

export function useToggleSlot(params: ScheduleQueryParams) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ start, end, makeAvailable, blockId, stepMinutes, durationMinutes }: ToggleVariables) => {
      await apiClient.post('/schedule/slots/toggle', {
        start,
        end,
        make_available: makeAvailable,
        block_id: blockId ?? undefined,
        step_minutes: stepMinutes ?? undefined,
        duration_min: durationMinutes ?? undefined,
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['schedule', 'slots', params] });
    },
  });
}
