import { useQuery } from '@tanstack/react-query';
import apiClient from '../api/client';
import type { CsvRemoteListing } from '../features/csv-explorer/types';

export function useCsvExplorerRemoteFiles(path: string, enabled: boolean) {
  return useQuery<CsvRemoteListing>({
    queryKey: ['csv-explorer', 'files', path],
    enabled,
    queryFn: async () => {
      const response = await apiClient.get('/csv-explorer/files', {
        params: path ? { path } : undefined,
      });

      return response.data.data as CsvRemoteListing;
    },
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  });
}
