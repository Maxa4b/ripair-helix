import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type EnrichmentRemoteEntry = {
  type: 'directory' | 'file';
  name: string;
  path: string;
  size: number | null;
  modified_at: string;
  extension: string | null;
};

export type EnrichmentRemoteListing = {
  root: {
    label: string;
    path: string;
  };
  current_path: string;
  parent_path: string | null;
  entries: EnrichmentRemoteEntry[];
};

export type EnrichmentJobPhase = {
  key: string;
  status: 'pending' | 'running' | 'completed' | 'cancelled' | 'error';
  startedAt: number | null;
  completedAt: number | null;
};

export type EnrichmentJobArtifact = {
  key: string;
  name: string;
  relative_path: string;
  size: number;
  modified_at: string;
};

export type EnrichmentJobSnapshot = {
  status: 'idle' | 'queued' | 'running' | 'completed' | 'cancelled' | 'error';
  mode: string;
  currentPhase: string | null;
  progress: number;
  phases: EnrichmentJobPhase[];
  artifacts: EnrichmentJobArtifact[];
  logTail: string[];
  error: string | null;
  warning: string | null;
  startedAt: number | null;
  completedAt: number | null;
  inputFile?: {
    name: string;
    size: number;
    path: string;
    modifiedAt: string;
  };
  actor?: {
    id: number;
    name: string;
  };
};

export type EnrichmentJob = {
  job_id: string;
  status: 'queued' | 'running' | 'completed' | 'cancelled' | 'error';
  mode: string;
  input_path: string;
  cancel_requested: boolean;
  created_at: number;
  updated_at: number;
  snapshot: EnrichmentJobSnapshot;
};

function isLiveJob(job: EnrichmentJob) {
  return job.status === 'queued' || job.status === 'running';
}

export function useProspectingEnrichmentFiles(path: string, enabled: boolean) {
  return useQuery<EnrichmentRemoteListing>({
    queryKey: ['prospecting', 'enrichment', 'files', path],
    enabled,
    queryFn: async () => {
      const response = await apiClient.get('/prospecting/enrichment/files', {
        params: path ? { path } : undefined,
      });

      return response.data.data as EnrichmentRemoteListing;
    },
    staleTime: 20_000,
    refetchOnWindowFocus: false,
  });
}

export function useProspectingEnrichmentJobs() {
  return useQuery<EnrichmentJob[]>({
    queryKey: ['prospecting', 'enrichment', 'jobs'],
    queryFn: async () => {
      const response = await apiClient.get('/prospecting/enrichment/jobs');
      return (response.data.data ?? response.data) as EnrichmentJob[];
    },
    refetchInterval: (query) => {
      const jobs = (query.state.data ?? []) as EnrichmentJob[];
      return jobs.some(isLiveJob) ? 4_000 : 20_000;
    },
    refetchOnWindowFocus: false,
  });
}

export function useStartProspectingEnrichmentJob() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: { input_path: string; mode?: 'run-all' }) => {
      const response = await apiClient.post('/prospecting/enrichment/jobs', payload);
      return (response.data.data ?? response.data) as EnrichmentJob;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'enrichment', 'jobs'] });
    },
  });
}

export function useCancelProspectingEnrichmentJob() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (jobId: string) => {
      const response = await apiClient.post(`/prospecting/enrichment/jobs/${jobId}/cancel`);
      return (response.data.data ?? response.data) as EnrichmentJob;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'enrichment', 'jobs'] });
    },
  });
}

export async function downloadProspectingEnrichmentArtifact(jobId: string, artifact: EnrichmentJobArtifact) {
  const response = await apiClient.get(`/prospecting/enrichment/jobs/${jobId}/artifacts/${artifact.key}`, {
    responseType: 'blob',
  });

  const blobUrl = window.URL.createObjectURL(response.data);
  const anchor = document.createElement('a');
  anchor.href = blobUrl;
  anchor.download = artifact.name;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  window.URL.revokeObjectURL(blobUrl);
}
