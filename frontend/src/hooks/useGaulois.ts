import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type GauloisSeedSummary = {
  filename: string;
  brands: string[];
  models: string[];
  targets: string[];
  suppliers: Array<{
    key: string;
    urls: string[];
  }>;
  error?: string;
};

export type GauloisMeta = {
  config: {
    python: string;
    script_path: string;
    working_directory: string | null;
    timeout: number;
  };
  seeds: GauloisSeedSummary[];
};

export type GauloisRunPayload = {
  // on déclenche un run sur un seed précis
  seed: string;
  // options (laisse true par défaut côté backend)
  dry_run?: boolean;
  log?: boolean;
};

export type CreateGauloisSeedPayload = {
  filename?: string;
  brand: string;
  model: string;
  targets?: string[];
  suppliers: Array<{
    key: string;
    urls: string[];
  }>;
};

export type CreateGauloisSeedResponse = {
  status: 'created';
  filename: string;
  path: string;
  seed: {
    brand: string;
    model: string;
    suppliers: Record<string, string[]>;
    targets?: string[];
  };
};

export type GauloisRunResponse = {
  // aligné avec GauloisController::run()
  status: 'done' | 'failed';
  job_id: string;
  seed?: string;
  exit_code: number | null;
  duration_seconds: number;
  command: string;
  output: string[];
  error_output: string[];
  log_hint: string;
};

type GauloisLogList = {
  logs: Array<{
    filename: string;
    size: number;
    modified_at: string;
    completed: boolean;
  }>;
};

export function useGauloisMeta() {
  return useQuery<GauloisMeta>({
    queryKey: ['gaulois', 'meta'],
    queryFn: async () => {
      const response = await apiClient.get('/gaulois/meta');
      return response.data as GauloisMeta;
    },
  });
}

export function useGauloisLogs() {
  return useQuery<GauloisLogList>({
    queryKey: ['gaulois', 'logs'],
    queryFn: async () => {
      const response = await apiClient.get('/gaulois/logs');
      return response.data as GauloisLogList;
    },
    refetchInterval: 15000,
  });
}

export function useGauloisLogContent(filename?: string | null) {
  return useQuery({
    queryKey: ['gaulois', 'log', filename],
    queryFn: async () => {
      const response = await apiClient.get(
        `/gaulois/logs/${encodeURIComponent(filename ?? '')}`,
      );
      return response.data as { filename: string; content: string };
    },
    enabled: Boolean(filename),
  });
}

export function useCreateGauloisSeed() {
  const queryClient = useQueryClient();
  return useMutation<CreateGauloisSeedResponse, unknown, CreateGauloisSeedPayload>({
    mutationFn: async (payload: CreateGauloisSeedPayload) => {
      const response = await apiClient.post('/gaulois/seeds', payload);
      return response.data as CreateGauloisSeedResponse;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['gaulois', 'meta'] });
    },
  });
}

export function useGauloisRun() {
  const RUN_TIMEOUT_MS = 0; // 0 = illimité côté axios
  return useMutation<GauloisRunResponse, unknown, GauloisRunPayload>({
    mutationFn: async ({ seed, dry_run = true, log = true }) => {
      const response = await apiClient.post('/gaulois/run', {
        seed,
        dry_run,
        log,
      }, { timeout: RUN_TIMEOUT_MS });
      return response.data as GauloisRunResponse;
    },
  });
}
