import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type ProspectingStatus = 'non_contacte' | 'en_cours_de_contact' | 'contacte';

export type ProspectingCompany = {
  id: number;
  company_id: string;
  name: string;
  siren: string | null;
  siret: string | null;
  segment: string | null;
  source: string | null;
  website: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  postal_code: string | null;
  city: string | null;
  country: string | null;
  lat: number | null;
  lng: number | null;
  google_place_id: string | null;
  relevance_score: number;
  contact_status: ProspectingStatus;
  contact_owner: string | null;
  first_contact_at: string | null;
  last_contact_at: string | null;
  notes: string | null;
  excel_row_id: string | null;
  version: number;
  created_at: string | null;
  updated_at: string | null;
  history?: ProspectingHistoryEntry[];
};

export type ProspectingHistoryEntry = {
  id: number;
  previous_status: ProspectingStatus | null;
  new_status: ProspectingStatus | null;
  previous_owner: string | null;
  new_owner: string | null;
  previous_notes: string | null;
  new_notes: string | null;
  source: string;
  change_note: string | null;
  changed_by: number | null;
  changed_by_name: string | null;
  changed_at: string | null;
  created_at: string | null;
};

export type ProspectingCompanyListResponse = {
  data: ProspectingCompany[];
  meta: {
    total: number;
    returned: number;
    limit: number;
    bounds: {
      south: number;
      west: number;
      north: number;
      east: number;
    } | null;
  };
};

export type ProspectingFilters = {
  bounds?: string | null;
  status?: string;
  segment?: string;
  q?: string;
  zone?: string;
  contact_owner?: string;
  missing_contact?: boolean;
  only_geocoded?: boolean;
  limit?: number;
};

export type ProspectingStats = {
  total: number;
  non_contacte: number;
  en_cours_de_contact: number;
  contacte: number;
  with_coordinates: number;
  missing_contacts: number;
  coverage_rate: number;
};

type UpdateCompanyStatusInput = {
  id: number;
  contact_status: ProspectingStatus;
  version: number;
  contact_owner?: string | null;
  notes?: string | null;
};

type UpdateCompanyInput = {
  id: number;
  payload: Partial<ProspectingCompany> & {
    version: number;
  };
};

function buildParams(filters: ProspectingFilters): Record<string, string> {
  const params: Record<string, string> = {};

  if (filters.bounds) params.bounds = filters.bounds;
  if (filters.status && filters.status !== 'all') params.status = filters.status;
  if (filters.segment && filters.segment !== 'all') params.segment = filters.segment;
  if (filters.q) params.q = filters.q;
  if (filters.zone) params.zone = filters.zone;
  if (filters.contact_owner) params.contact_owner = filters.contact_owner;
  if (filters.missing_contact) params.missing_contact = 'true';
  if (typeof filters.only_geocoded === 'boolean') params.only_geocoded = filters.only_geocoded ? 'true' : 'false';
  if (typeof filters.limit === 'number') params.limit = String(filters.limit);

  return params;
}

export function useProspectingCompanies(filters: ProspectingFilters) {
  return useQuery<ProspectingCompanyListResponse>({
    queryKey: ['prospecting', 'companies', filters],
    queryFn: async () => {
      const response = await apiClient.get('/prospecting/companies', {
        params: buildParams(filters),
      });

      return response.data as ProspectingCompanyListResponse;
    },
  });
}

export function useProspectingCompany(companyId: number | null) {
  return useQuery<ProspectingCompany>({
    queryKey: ['prospecting', 'company', companyId],
    enabled: typeof companyId === 'number',
    queryFn: async () => {
      const response = await apiClient.get(`/prospecting/companies/${companyId}`);
      return response.data.data ?? response.data;
    },
  });
}

export function useProspectingStats(filters: Omit<ProspectingFilters, 'bounds' | 'only_geocoded' | 'limit'>) {
  return useQuery<ProspectingStats>({
    queryKey: ['prospecting', 'stats', filters],
    queryFn: async () => {
      const response = await apiClient.get('/prospecting/stats', {
        params: buildParams(filters),
      });

      return response.data.data ?? response.data;
    },
  });
}

export function useUpdateProspectingCompanyStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...payload }: UpdateCompanyStatusInput): Promise<ProspectingCompany> => {
      const response = await apiClient.patch(`/prospecting/companies/${id}/status`, payload);
      return response.data.data ?? response.data;
    },
    onMutate: async (variables) => {
      await queryClient.cancelQueries({ queryKey: ['prospecting'] });

      const companiesSnapshots = queryClient.getQueriesData<ProspectingCompanyListResponse>({
        queryKey: ['prospecting', 'companies'],
      });
      const detailSnapshot = queryClient.getQueryData<ProspectingCompany>(['prospecting', 'company', variables.id]);

      for (const [key, value] of companiesSnapshots) {
        if (!value) {
          continue;
        }

        queryClient.setQueryData<ProspectingCompanyListResponse>(key, {
          ...value,
          data: value.data.map((company) =>
            company.id === variables.id
              ? {
                  ...company,
                  contact_status: variables.contact_status,
                  contact_owner: variables.contact_owner ?? company.contact_owner,
                  notes: variables.notes ?? company.notes,
                  version: company.version + 1,
                  last_contact_at: new Date().toISOString(),
                }
              : company,
          ),
        });
      }

      if (detailSnapshot) {
        queryClient.setQueryData<ProspectingCompany>(['prospecting', 'company', variables.id], {
          ...detailSnapshot,
          contact_status: variables.contact_status,
          contact_owner: variables.contact_owner ?? detailSnapshot.contact_owner,
          notes: variables.notes ?? detailSnapshot.notes,
          version: detailSnapshot.version + 1,
          last_contact_at: new Date().toISOString(),
        });
      }

      return {
        companiesSnapshots,
        detailSnapshot,
      };
    },
    onError: (_error, variables, context) => {
      context?.companiesSnapshots?.forEach(([key, snapshot]) => {
        queryClient.setQueryData(key, snapshot);
      });

      if (context?.detailSnapshot) {
        queryClient.setQueryData(['prospecting', 'company', variables.id], context.detailSnapshot);
      }
    },
    onSettled: (_data, _error, variables) => {
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'companies'] });
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'company', variables.id] });
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'stats'] });
    },
  });
}

export function useUpdateProspectingCompany() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, payload }: UpdateCompanyInput): Promise<ProspectingCompany> => {
      const response = await apiClient.patch(`/prospecting/companies/${id}`, payload);
      return response.data.data ?? response.data;
    },
    onSuccess: (company) => {
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'companies'] });
      queryClient.setQueryData(['prospecting', 'company', company.id], company);
      queryClient.invalidateQueries({ queryKey: ['prospecting', 'stats'] });
    },
  });
}

export function useRunProspectingExcelSync() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (mode: 'import' | 'export' | 'resync') => {
      const response = await apiClient.post('/prospecting/sync/excel', { mode });
      return response.data.data ?? response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prospecting'] });
    },
  });
}
