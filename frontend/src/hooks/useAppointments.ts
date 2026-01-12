import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type Appointment = {
  id: number;
  service_label: string;
  duration_min: number | null;
  start_datetime: string;
  end_datetime: string;
  status: string;
  status_updated_at: string | null;
  assigned_user?: {
    id: number;
    full_name: string;
  } | null;
  customer: {
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
  };
  store_code: string | null;
  price_estimate_cents: number | null;
  discount_pct: number | null;
  source: string | null;
  internal_notes: string | null;
};

type AppointmentListResponse = {
  data: Appointment[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type AppointmentFilters = {
  page?: number;
  perPage?: number;
  search?: string;
  status?: string;
  assignedUserId?: number;
};

export function useAppointments(filters: AppointmentFilters) {
  const { page = 1, perPage = 10, search, status, assignedUserId } = filters;

  return useQuery<AppointmentListResponse>({
    queryKey: ['appointments', { page, perPage, search, status, assignedUserId }],
    queryFn: async (): Promise<AppointmentListResponse> => {
      const params: {
        page: number;
        per_page: number;
        search?: string;
        status?: string;
        assigned_user_id?: number;
      } = {
        page,
        per_page: perPage,
      };
      if (search) params.search = search;
      if (status && status !== 'all') params.status = status;
      if (typeof assignedUserId === 'number') params.assigned_user_id = assignedUserId;
      const response = await apiClient.get('/appointments', { params });
      return response.data;
    },
    placeholderData: (previous) => previous,
  });
}

type UpdateAppointmentPayload = {
  id: number;
  payload: Partial<{
    status: string;
    assigned_user_id: number | null;
    internal_notes: string | null;
  }>;
};

export function useUpdateAppointment() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, payload }: UpdateAppointmentPayload): Promise<Appointment> => {
      const response = await apiClient.patch(`/appointments/${id}`, payload);
      return response.data.data ?? response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['appointments'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'summary'] });
    },
  });
}
