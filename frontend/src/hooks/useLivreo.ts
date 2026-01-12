import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiClient from '../api/client';

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type LivreoOrderListItem = {
  id: number;
  number: string;
  status: string;
  payment_status: string;
  workflow_status?: string;
  delivery_type: string;
  placed_at: string;
  total_ttc: number;
  shipping_total: number;
  customer_email: string;
  customer_name: string;
  shipping_city: string;
  tracking_number: string | null;
  carrier_name: string | null;
  mobilesentrix_unassigned?: boolean;
  has_shipping_label?: boolean;
  boxtal_buy_in_progress?: boolean;
  boxtal_last_event?: Record<string, unknown> | null;
  shipping_choice?: {
    name?: string | null;
    operator?: string | null;
    service?: string | null;
    type?: string | null;
    provider?: string | null;
    method_id?: string | null;
    delay?: unknown;
    price?: number | null;
    price_original?: number | null;
    is_free?: boolean;
  };
};

export type LivreoOrderListResponse = {
  data: LivreoOrderListItem[];
  meta: PaginationMeta;
};

export type LivreoOrderItem = {
  id: number;
  name: string;
  reference: string | null;
  quantity: number;
  total_ttc: number;
};

export type LivreoPayment = {
  provider: string;
  method: string;
  status: string;
  transaction_reference: string | null;
  amount: number;
  currency: string;
  created_at: string;
};

export type LivreoSupplierOrder = {
  id: number;
  order_id: number;
  supplier: string;
  supplier_order_number: string | null;
  supplier_carrier?: string | null;
  supplier_tracking_number?: string | null;
  supplier_tracking_url?: string | null;
  supplier_shipped_at?: string | null;
  supplier_email_message_id?: string | null;
  supplier_email_subject?: string | null;
  supplier_email_from?: string | null;
  supplier_email_received_at?: string | null;
  status: 'to_order' | 'ordered' | 'received' | 'problem';
  notes: string | null;
  ordered_at: string | null;
  received_at: string | null;
  items: Array<{
    id: number;
    order_item_id: number | null;
    product_variant_id: number | null;
    supplier_sku: string | null;
    quantity: number;
    unit_cost: number | null;
  }>;
};

export type LivreoOrderResponse = {
  order: {
    id: number;
    number: string;
    status: string;
    payment_status: string;
    delivery_type: string;
    placed_at: string;
    total_ttc: number;
    shipping_total: number;
    tracking_number: string | null;
    carrier_name: string | null;
    customer_email: string;
    billing_address: Record<string, unknown>;
    shipping_address: Record<string, unknown>;
    metadata: Record<string, unknown>;
    shipping_choice?: Record<string, unknown>;
    shop_links: {
      admin: string;
      customer: string;
    };
  };
  items: LivreoOrderItem[];
  payments: LivreoPayment[];
  supplier_order: LivreoSupplierOrder | null;
};

export type LivreoOrdersQuery = {
  q?: string;
  status?: string;
  payment_status?: string;
  delivery_type?: string;
  needs_label?: boolean;
  per_page?: number;
  page?: number;
};

export function useLivreoOrders(params: LivreoOrdersQuery) {
  return useQuery<LivreoOrderListResponse>({
    queryKey: ['livreo', 'orders', params],
    queryFn: async () => {
      const response = await apiClient.get('/livreo/orders', { params });
      return response.data as LivreoOrderListResponse;
    },
  });
}

export function useLivreoOrder(id?: number | null) {
  return useQuery<LivreoOrderResponse>({
    queryKey: ['livreo', 'order', id],
    queryFn: async () => {
      const response = await apiClient.get(`/livreo/orders/${id}`);
      return response.data as LivreoOrderResponse;
    },
    enabled: Boolean(id),
  });
}

export function useUpdateLivreoOrder() {
  const queryClient = useQueryClient();
  return useMutation<
    { status: 'ok' },
    unknown,
    { id: number; status?: string | null; internal_note?: string | null }
  >({
    mutationFn: async ({ id, ...payload }) => {
      const response = await apiClient.patch(`/livreo/orders/${id}`, payload);
      return response.data as { status: 'ok' };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'orders'] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.id] });
    },
  });
}

export function useAddLivreoShipment() {
  const queryClient = useQueryClient();
  return useMutation<
    { status: 'ok'; shipments: unknown[] },
    unknown,
    { orderId: number; carrier_name: string; tracking_number: string; set_status?: string | null }
  >({
    mutationFn: async ({ orderId, ...payload }) => {
      const response = await apiClient.post(`/livreo/orders/${orderId}/shipments`, payload);
      return response.data as { status: 'ok'; shipments: unknown[] };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'orders'] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
    },
  });
}

export function useCreateLivreoSupplierOrder() {
  const queryClient = useQueryClient();
  return useMutation<
    { supplier_order: LivreoSupplierOrder },
    unknown,
    { orderId: number; supplier?: string; supplier_order_number?: string | null; status?: string; notes?: string | null }
  >({
    mutationFn: async ({ orderId, ...payload }) => {
      const response = await apiClient.post(`/livreo/orders/${orderId}/supplier-orders`, payload);
      return response.data as { supplier_order: LivreoSupplierOrder };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
    },
  });
}

export function useUpdateLivreoSupplierOrder() {
  const queryClient = useQueryClient();
  return useMutation<
    { supplier_order: LivreoSupplierOrder },
    unknown,
    { id: number; supplier_order_number?: string | null; status?: string; notes?: string | null; orderId?: number }
  >({
    mutationFn: async ({ id, ...payload }) => {
      const response = await apiClient.patch(`/livreo/supplier-orders/${id}`, payload);
      return response.data as { supplier_order: LivreoSupplierOrder };
    },
    onSuccess: async (_data, variables) => {
      if (variables.orderId) {
        await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
      }
    },
  });
}

export type MobileSentrixMailSyncResponse = {
  ok: boolean;
  status?: number;
  error?: string;
  scanned?: number;
  created?: number;
  matched?: number;
  matched_existing?: number;
  matched_auto?: number;
  unmatched?: number;
  ambiguous?: number;
  skipped_existing?: number;
  ignored?: number;
  errors?: string[];
};

export function useSyncMobileSentrixMail() {
  const queryClient = useQueryClient();
  return useMutation<
    MobileSentrixMailSyncResponse,
    unknown,
    { orderId?: number; since_days?: number; limit?: number }
  >({
    mutationFn: async ({ orderId: _orderId, ...payload }) => {
      const response = await apiClient.post(`/livreo/suppliers/mobilesentrix/sync-mail`, payload);
      return response.data as MobileSentrixMailSyncResponse;
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'orders'] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'mobilesentrix', 'shipments'] });
      if (variables.orderId) {
        await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
      }
    },
  });
}

export type MobileSentrixShipmentOption = {
  supplier_order_number: string;
  carrier: string | null;
  tracking_number: string | null;
  received_at: string | null;
  subject: string | null;
  from_email: string | null;
};

export function useMobileSentrixShipments(params: { only_unassigned?: boolean; limit?: number }) {
  return useQuery<{ ok: boolean; only_unassigned: boolean; count: number; items: MobileSentrixShipmentOption[] }>({
    queryKey: ['livreo', 'mobilesentrix', 'shipments', params],
    queryFn: async () => {
      const response = await apiClient.get('/livreo/suppliers/mobilesentrix/shipments', { params });
      return response.data as { ok: boolean; only_unassigned: boolean; count: number; items: MobileSentrixShipmentOption[] };
    },
  });
}

export function useAssignMobileSentrixShipment() {
  const queryClient = useQueryClient();
  return useMutation<
    { ok: boolean; supplier_order: LivreoSupplierOrder },
    unknown,
    { supplierOrderId: number; supplier_order_number: string; orderId?: number }
  >({
    mutationFn: async ({ supplierOrderId, ...payload }) => {
      const response = await apiClient.post(`/livreo/supplier-orders/${supplierOrderId}/assign-mobilesentrix`, payload);
      return response.data as { ok: boolean; supplier_order: LivreoSupplierOrder };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'mobilesentrix', 'shipments'] });
      if (variables.orderId) {
        await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
      }
    },
  });
}

export type LivreoRmaListItem = {
  id: number;
  rma_number: string;
  status: string;
  reason: string;
  created_at: string;
  order: { id: number; number: string };
  customer_email: string | null;
  comments_count: number;
  attachments_count: number;
};

export type LivreoRmaListResponse = { data: LivreoRmaListItem[]; meta: PaginationMeta };

export type LivreoRmaResponse = {
  ticket: {
    id: number;
    rma_number: string;
    status: string;
    reason: string;
    description: string | null;
    created_at: string;
    metadata: Record<string, unknown>;
    order: {
      id: number;
      number: string;
      status: string;
      payment_status: string;
      placed_at: string;
      shipping_choice?: Record<string, unknown>;
    } | null;
    shop_links: Record<string, string | null>;
  };
  items: Array<{
    id: number;
    quantity: number;
    evaluation_status: string;
    notes: string | null;
    order_item: { id: number | null; name: string | null; reference: string | null };
  }>;
  comments: Array<{
    id: number;
    comment: string;
    is_internal: boolean;
    created_at: string;
    author_email: string | null;
  }>;
  attachments: Array<{ id: number; path: string; type: string; created_at: string }>;
};

export type LivreoRmaQuery = { q?: string; status?: string; per_page?: number; page?: number };

export function useLivreoRmas(params: LivreoRmaQuery) {
  return useQuery<LivreoRmaListResponse>({
    queryKey: ['livreo', 'sav', params],
    queryFn: async () => {
      const response = await apiClient.get('/livreo/sav', { params });
      return response.data as LivreoRmaListResponse;
    },
  });
}

export function useLivreoRma(id?: number | null) {
  return useQuery<LivreoRmaResponse>({
    queryKey: ['livreo', 'sav', 'ticket', id],
    queryFn: async () => {
      const response = await apiClient.get(`/livreo/sav/${id}`);
      return response.data as LivreoRmaResponse;
    },
    enabled: Boolean(id),
  });
}

export function useUpdateLivreoRma() {
  const queryClient = useQueryClient();
  return useMutation<
    { status: 'ok' },
    unknown,
    { id: number; status: string; comment?: string | null; comment_visibility?: 'internal' | 'customer' }
  >({
    mutationFn: async ({ id, ...payload }) => {
      const response = await apiClient.patch(`/livreo/sav/${id}`, payload);
      return response.data as { status: 'ok' };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'sav'] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'sav', 'ticket', variables.id] });
    },
  });
}

export function useAddLivreoRmaComment() {
  const queryClient = useQueryClient();
  return useMutation<
    { status: 'ok'; comment_id: number },
    unknown,
    { id: number; comment: string; visibility?: 'internal' | 'customer' }
  >({
    mutationFn: async ({ id, ...payload }) => {
      const response = await apiClient.post(`/livreo/sav/${id}/comments`, payload);
      return response.data as { status: 'ok'; comment_id: number };
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'sav', 'ticket', variables.id] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'sav'] });
    },
  });
}

export type LivreoBoxtalOffer = {
  method_id: string;
  name: string;
  price: number;
  delay: unknown;
  type: string;
  origin: string;
  operator: string;
  service: string;
};

export type LivreoBoxtalRefreshResponse = {
  requested: {
    from: { country: string; zip: string; city: string; type: string };
    to: { country: string; zip: string; city: string; type: string };
    packages_count: number;
    content_code: string;
  };
  stored_choice: Record<string, unknown>;
  preference: Record<string, unknown>;
  selected: LivreoBoxtalOffer | null;
  offers: LivreoBoxtalOffer[];
};

export function useLivreoBoxtalRefresh() {
  return useMutation<LivreoBoxtalRefreshResponse, unknown, { orderId: number }>({
    mutationFn: async ({ orderId }) => {
      const response = await apiClient.post(`/livreo/orders/${orderId}/boxtal/refresh`);
      return response.data as LivreoBoxtalRefreshResponse;
    },
  });
}

export type LivreoBoxtalBuyLabelResponse = {
  status: 'ok';
  selected: LivreoBoxtalOffer | null;
  shipping_label: Record<string, unknown>;
  carrier_name: string;
  tracking_number: string | null;
};

export function useLivreoBoxtalBuyLabel() {
  const queryClient = useQueryClient();
  return useMutation<
    LivreoBoxtalBuyLabelResponse,
    unknown,
    { orderId: number; method_id?: string | null; set_status?: string | null; force?: boolean; dev?: boolean }
  >({
    mutationFn: async ({ orderId, ...payload }) => {
      const response = await apiClient.post(`/livreo/orders/${orderId}/boxtal/buy-label`, payload);
      return response.data as LivreoBoxtalBuyLabelResponse;
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'orders'] });
      await queryClient.invalidateQueries({ queryKey: ['livreo', 'order', variables.orderId] });
    },
  });
}
