import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type CustomerReview = {
  id: number;
  rating: number;
  comment: string;
  first_name: string | null;
  last_name: string | null;
  show_name: boolean;
  status: 'pending' | 'approved' | 'rejected';
  moderated_at: string | null;
  moderated_by: number | null;
  admin_note: string | null;
  image_path: string | null;
  image_url: string | null;
  source_page: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type ReviewFilters = {
  status?: 'pending' | 'approved' | 'rejected' | 'all';
  search?: string;
  limit?: number;
};

export function useReviews(filters: ReviewFilters = {}) {
  return useQuery<CustomerReview[]>({
    queryKey: ['reviews', filters],
    queryFn: async (): Promise<CustomerReview[]> => {
      const params: Record<string, unknown> = {};
      if (filters.status) params.status = filters.status;
      if (filters.search) params.search = filters.search;
      if (typeof filters.limit === 'number') params.limit = filters.limit;
      const response = await apiClient.get('/reviews', { params });
      return response.data.data ?? response.data ?? [];
    },
  });
}

type ModerateReviewInput = {
  id: number;
  status: 'approved' | 'rejected';
  admin_note?: string | null;
};

export function useModerateReview() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, status, admin_note }: ModerateReviewInput): Promise<CustomerReview> => {
      const response = await apiClient.patch(`/reviews/${id}`, { status, admin_note: admin_note ?? null });
      return response.data.data ?? response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
    },
  });
}
