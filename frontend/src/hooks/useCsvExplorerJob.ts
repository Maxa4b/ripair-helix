import { useQuery } from '@tanstack/react-query';
import apiClient from '../api/client';
import type { CsvRemoteJob } from '../features/csv-explorer/types';

const ACTIVE_STATUSES = new Set(['queued', 'reading']);

export function useCsvExplorerJob(jobId: string | null) {
  return useQuery<CsvRemoteJob>({
    queryKey: ['csv-explorer', 'job', jobId],
    enabled: typeof jobId === 'string' && jobId.length > 0,
    queryFn: async () => {
      const response = await apiClient.get(`/csv-explorer/jobs/${jobId}`);
      return response.data.data as CsvRemoteJob;
    },
    refetchInterval: (query) => {
      const job = query.state.data;
      if (!job) {
        return 2000;
      }

      return ACTIVE_STATUSES.has(job.status) ? 2000 : false;
    },
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}
