import { useEffect, useMemo, useState, useRef, type ReactNode } from 'react';
import {
  useAddLivreoRmaComment,
  useLivreoBoxtalRefresh,
  useLivreoBoxtalBuyLabel,
  useCreateLivreoSupplierOrder,
  useLivreoOrder,
  useLivreoOrders,
  useLivreoRma,
  useLivreoRmas,
  useMobileSentrixShipments,
  useAssignMobileSentrixShipment,
  useUpdateLivreoOrder,
  useUpdateLivreoRma,
  useUpdateLivreoSupplierOrder,
  useSyncMobileSentrixMail,
  type LivreoBoxtalRefreshResponse,
} from '../hooks/useLivreo';
import apiClient from '../api/client';

const formatMoney = (value?: number | null) =>
  new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(value ?? 0);

const formatDateTime = (iso?: string | null) => {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const normalizeDelayLabel = (delay: unknown): string | null => {
  if (!delay) return null;
  if (typeof delay === 'string') return delay.trim() || null;
  if (typeof delay === 'number' && Number.isFinite(delay)) return `${delay} jour(s)`;
  if (typeof delay === 'object') {
    const minDays = (delay as any).min_days;
    const maxDays = (delay as any).max_days;
    if (typeof minDays === 'number' && typeof maxDays === 'number') return `${minDays} - ${maxDays} jours`;
    if (typeof minDays === 'number') return `${minDays} jours`;
    if (typeof maxDays === 'number') return `${maxDays} jours`;
  }
  return null;
};

const openPrintableBlob = (blob: Blob) => {
  const url = URL.createObjectURL(blob);
  const win = window.open(url, '_blank', 'noopener,noreferrer');
  if (!win) return;
  win.addEventListener('beforeunload', () => URL.revokeObjectURL(url));
};

const trackingUrlFor = (operator: unknown, tracking: unknown): string | null => {
  const code = String(operator ?? '').trim().toUpperCase();
  const tn = String(tracking ?? '').trim();
  if (!tn) return null;

  const q = encodeURIComponent(tn);
  if (code === 'POFR' || code === 'COLI') {
    return `https://www.laposte.fr/outils/suivre-vos-envois?code=${q}`;
  }
  if (code === 'CHRP') {
    return `https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=${q}`;
  }
  if (code === 'MONR') {
    return `https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition=${q}`;
  }
  return null;
};

type ShippingChoice = {
  provider?: string | null;
  type?: string | null;
  name?: string | null;
  operator?: string | null;
  service?: string | null;
  method_id?: string | null;
  delay?: unknown;
  price?: unknown;
};

const carrierLogoForShippingChoice = (choice?: ShippingChoice | null): { url: string; alt: string } | null => {
  const operator = String(choice?.operator ?? '').toUpperCase();
  const name = String(choice?.name ?? '').toLowerCase();

  if (operator === 'COLI' || name.includes('colissimo')) {
    return { url: 'https://dirigeants-entreprise.com/content/uploads/Apps-Colissimo.jpg', alt: 'Colissimo' };
  }
  if (operator === 'CHRP') {
    return { url: 'https://apps.oxatis.com/Files/112496/Img/11/Apps-Chronopost.jpg', alt: 'Chronopost' };
  }
  if (operator === 'MONR' || name.includes('mondial')) {
    return {
      url: 'https://cdn1.oxatis.com/Files/112496/dyn-images/19/Apps-Mondial-relay.png?w=1200&h=1200',
      alt: 'Mondial Relay',
    };
  }

  return null;
};

const orderStatusOptions = [
  { value: 'pending_payment', label: 'Attente paiement' },
  { value: 'paid', label: 'Payée' },
  { value: 'awaiting_assignment', label: 'À attribuer' },
  { value: 'ms_in_transit', label: 'MS en cours' },
  { value: 'awaiting_customer_shipment', label: 'À expédier' },
  { value: 'shipped', label: 'Expédiée' },
  { value: 'delivered', label: 'Livrée' },
  { value: 'cancelled', label: 'Annulée' },
];

const orderStatusUpdateOptions = [
  { value: 'pending_payment', label: 'Attente paiement' },
  { value: 'paid', label: 'Payée' },
  { value: 'awaiting_assignment', label: 'À attribuer' },
  { value: 'ms_in_transit', label: 'MS en cours' },
  { value: 'awaiting_customer_shipment', label: 'À expédier' },
  { value: 'shipped', label: 'Expédiée' },
  { value: 'delivered', label: 'Livrée' },
  { value: 'cancelled', label: 'Annulée' },
];

const orderStatusUpdateWritable = new Set(['shipped', 'delivered', 'cancelled']);

const orderStatusLabel = (status: string) => {
  const map: Record<string, string> = {
    pending_payment: 'Attente paiement',
    paid: 'Payée',
    awaiting_assignment: 'À attribuer',
    ms_in_transit: 'MS en cours',
    awaiting_customer_shipment: 'À expédier',
    shipped: 'Expédiée',
    delivered: 'Livrée',
    cancelled: 'Annulée',
  };
  return map[status] ?? status ?? '-';
};

const deliveryTypeOptions = [
  { value: '', label: 'Toutes livraisons' },
  { value: 'shipping', label: 'Domicile' },
  { value: 'relay', label: 'Point relais' },
  { value: 'workshop_pickup', label: 'Retrait atelier' },
];

const rmaStatusOptions = [
  { value: 'received', label: 'Ouvert' },
  { value: 'in_review', label: 'En cours' },
  { value: 'accepted', label: 'Accepté' },
  { value: 'refused', label: 'Refusé / clôturé' },
  { value: 'refunded', label: 'Remboursé' },
  { value: 'replaced', label: 'Remplacé' },
];

function pill(label: string, color: string) {
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 8,
        padding: '4px 10px',
        borderRadius: 999,
        background: `${color}20`,
        color,
        fontSize: 12,
        fontWeight: 800,
        textTransform: 'uppercase',
        letterSpacing: '0.02em',
      }}
    >
      {label}
    </span>
  );
}

function RmaStatusPill({ status }: { status: string }) {
  const map: Record<string, { label: string; color: string }> = {
    received: { label: 'Ouvert', color: '#1d4ed8' },
    in_review: { label: 'En cours', color: '#0f766e' },
    accepted: { label: 'Accepté', color: '#16a34a' },
    refused: { label: 'Clôturé', color: '#b91c1c' },
    refunded: { label: 'Remboursé', color: '#7c3aed' },
    replaced: { label: 'Remplacé', color: '#2563eb' },
  };
  const config = map[status] ?? { label: status, color: '#475569' };
  return pill(config.label, config.color);
}

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 18 }}>
      <h4 style={{ margin: 0 }}>{title}</h4>
      <div style={{ marginTop: 12 }}>{children}</div>
    </section>
  );
}

function ShippingChoiceCard({
  choice,
  fallbackType,
  fallbackPrice,
}: {
  choice: ShippingChoice | null;
  fallbackType: string;
  fallbackPrice: number;
}) {
  const name = String(choice?.name ?? choice?.service ?? '').trim() || '—';
  const delay = normalizeDelayLabel(choice?.delay);
  const priceRaw = choice?.price ?? fallbackPrice ?? 0;
  const price = Number.isFinite(Number(priceRaw)) ? Number(priceRaw) : 0;
  const priceOriginalRaw = (choice as any)?.price_original ?? null;
  const priceOriginal = Number.isFinite(Number(priceOriginalRaw)) ? Number(priceOriginalRaw) : null;
  const isFreeShipping = (choice as any)?.is_free === true || (price === 0 && (priceOriginal ?? 0) > 0);
  const displayPrice = isFreeShipping && (priceOriginal ?? 0) > 0 ? (priceOriginal as number) : price;
  const type = String(choice?.type ?? fallbackType ?? '').trim();
  const groupLabel = type === 'relay' ? 'Point relais' : type === 'workshop_pickup' ? 'Retrait atelier' : 'Livraison à domicile';
  const logo = carrierLogoForShippingChoice(choice);

  return (
    <div style={{ display: 'grid', gap: 10 }}>
      <div style={{ fontWeight: 900, color: '#0f172a' }}>{groupLabel}</div>
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 14,
          padding: 14,
          borderRadius: 18,
          background: '#fff',
          border: '1px solid #3abafc',
          boxShadow: '0 0 0 4px rgba(58, 186, 252, 0.16)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, minWidth: 0 }}>
          <div
            style={{
              width: 54,
              height: 54,
              borderRadius: 16,
              background: '#fff',
              border: '1px solid #e2e8f0',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              overflow: 'hidden',
              flex: '0 0 auto',
            }}
          >
            {logo ? (
              <img src={logo.url} alt={logo.alt} style={{ width: 26, height: 26, objectFit: 'contain' }} />
            ) : (
              <span style={{ fontSize: 18, color: '#0f172a' }}>🚚</span>
            )}
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontWeight: 900, fontSize: 16, color: '#0f172a', lineHeight: 1.1 }}>{name}</div>
            {delay && <div style={{ marginTop: 4, fontSize: 13, color: '#64748b' }}>Estimé : {delay}</div>}
          </div>
        </div>
        <div style={{ flex: '0 0 auto', textAlign: 'right' }}>
          <div style={{ fontWeight: 900, fontSize: 18, color: '#0b1f4f' }}>{formatMoney(displayPrice)}</div>
          {isFreeShipping && (
            <div style={{ marginTop: 2, fontSize: 12, fontWeight: 900, color: '#16a34a' }}>Livraison offerte</div>
          )}
        </div>
      </div>

      <div style={{ display: 'grid', gap: 4, color: '#475569', fontSize: 12 }}>
        <div>
          {choice?.operator ? `Opérateur : ${choice.operator}` : 'Opérateur : —'}
          {choice?.provider ? ` - Source : ${choice.provider}` : ''}
        </div>
        {choice?.method_id ? <div>Méthode : {choice.method_id}</div> : null}
      </div>
    </div>
  );
}

export default function LivreoPage() {
  const [tab, setTab] = useState<'orders' | 'sav'>('orders');
  const initialOrderListMode = (() => {
    if (typeof window === 'undefined') return 'to_ship' as const;
    const stored = window.localStorage.getItem('livreoOrderListMode');
    return stored === 'all' || stored === 'to_ship' ? (stored as 'all' | 'to_ship') : ('to_ship' as const);
  })();

  const [orderListMode, setOrderListMode] = useState<'all' | 'to_ship'>(initialOrderListMode);

  const [orderSearch, setOrderSearch] = useState('');
  const [orderDeliveryType, setOrderDeliveryType] = useState('');
  const [orderStatus, setOrderStatus] = useState('');
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);

  useEffect(() => {
    try {
      window.localStorage.setItem('livreoOrderListMode', orderListMode);
    } catch {
      // ignore
    }
  }, [orderListMode]);

  const ordersQuery = useLivreoOrders({
    q: orderSearch || undefined,
    status: orderStatus || undefined,
    payment_status: orderListMode === 'to_ship' ? 'paid' : undefined,
    delivery_type: (orderListMode === 'to_ship' ? '' : orderDeliveryType) || undefined,
    needs_label: orderListMode === 'to_ship' ? true : undefined,
    per_page: 20,
  });

  const orderDetailQuery = useLivreoOrder(selectedOrderId);
  const updateOrderMutation = useUpdateLivreoOrder();
  const createSupplierOrderMutation = useCreateLivreoSupplierOrder();
  const updateSupplierOrderMutation = useUpdateLivreoSupplierOrder();
  const syncMobileSentrixMailMutation = useSyncMobileSentrixMail();
  const assignMobileSentrixMutation = useAssignMobileSentrixShipment();
  const boxtalRefreshMutation = useLivreoBoxtalRefresh();
  const boxtalBuyLabelMutation = useLivreoBoxtalBuyLabel();
  const [boxtalRefreshData, setBoxtalRefreshData] = useState<LivreoBoxtalRefreshResponse | null>(null);
  const [boxtalBuySetShipped, setBoxtalBuySetShipped] = useState(true);
  const [boxtalBuyUseDev, setBoxtalBuyUseDev] = useState(false);

  const [supplierOrderNumber, setSupplierOrderNumber] = useState('');
  const [supplierOrderStatus, setSupplierOrderStatus] = useState<'to_order' | 'ordered' | 'received' | 'problem'>('to_order');
  const [supplierOrderNotes, setSupplierOrderNotes] = useState('');
  const autoSyncKeyRef = useRef<string>('');
  const [mobileSentrixSelected, setMobileSentrixSelected] = useState('');

  const mobileSentrixShipmentsQuery = useMobileSentrixShipments({
    only_unassigned: true,
    limit: 120,
  });

  const orderDetail = orderDetailQuery.data?.order;
  const supplierOrder = orderDetailQuery.data?.supplier_order ?? null;
  const orderDetailWorkflowStatus = useMemo(() => {
    if (!orderDetail) return 'paid';
    const status = String(orderDetail.status ?? '');
    if (status === 'cancelled') return 'cancelled';
    if (status === 'delivered') return 'delivered';
    if (status === 'shipped') return 'shipped';

    const payment = String(orderDetail.payment_status ?? '');
    if (payment !== 'paid') return 'pending_payment';

    const meta = (orderDetail.metadata ?? {}) as Record<string, any>;
    const hasLabel = String(meta?.shipping_label?.emc_ref ?? '').trim() !== '';

    const supplierNum = supplierOrder ? String(supplierOrder.supplier_order_number ?? '').trim() : '';
    const supplierStatus = supplierOrder ? String(supplierOrder.status ?? '') : '';
    if (supplierOrder && supplierNum === '') return 'awaiting_assignment';
    if (supplierOrder && supplierNum !== '') return supplierStatus === 'received' ? (hasLabel ? 'paid' : 'awaiting_customer_shipment') : 'ms_in_transit';
    return hasLabel ? 'paid' : 'awaiting_customer_shipment';
  }, [orderDetail, supplierOrder]);

  const shippingChoice = useMemo<ShippingChoice | null>(() => {
    const explicit = (orderDetail?.shipping_choice ?? null) as ShippingChoice | null;
    if (explicit && Object.keys(explicit).length > 0) return explicit;
    const metadata = (orderDetail?.metadata ?? {}) as Record<string, unknown>;
    const shipping = metadata.shipping;
    if (shipping && typeof shipping === 'object') return shipping as ShippingChoice;
    return null;
  }, [orderDetail?.metadata, orderDetail?.shipping_choice]);

  useEffect(() => {
    if (!orderDetail) return;
    setBoxtalRefreshData(null);
  }, [orderDetail?.id]);

  useEffect(() => {
    if (!supplierOrder) return;
    setSupplierOrderNumber(supplierOrder.supplier_order_number ?? '');
    setSupplierOrderStatus(supplierOrder.status);
    setSupplierOrderNotes(supplierOrder.notes ?? '');
    setMobileSentrixSelected(supplierOrder.supplier_order_number ?? '');
  }, [supplierOrder?.id]);

  useEffect(() => {
    if (!orderDetail || !supplierOrder) return;
    if (String(supplierOrder.supplier ?? '') !== 'mobile_sentrix' && String(supplierOrder.supplier ?? '') !== 'mobilesentrix') return;
    if (supplierOrder.supplier_tracking_number) return;

    const key = `${orderDetail.id}:${supplierOrder.id}`;
    if (autoSyncKeyRef.current === key) return;
    autoSyncKeyRef.current = key;

    // Auto: tente de récupérer le suivi depuis la boîte mail (sans action manuelle).
    syncMobileSentrixMailMutation.mutate({ orderId: orderDetail.id, since_days: 45, limit: 80 });
  }, [orderDetail?.id, supplierOrder?.id, supplierOrder?.supplier_tracking_number]);

  useEffect(() => {
    if (!supplierOrder || !orderDetail) return;
    if (updateSupplierOrderMutation.isPending) return;

    const serverKey = `${supplierOrder.supplier_order_number ?? ''}|${supplierOrder.status}|${supplierOrder.notes ?? ''}`;
    const draftKey = `${supplierOrderNumber}|${supplierOrderStatus}|${supplierOrderNotes}`;
    if (draftKey === serverKey) return;

    const handle = window.setTimeout(() => {
      updateSupplierOrderMutation.mutate({
        id: supplierOrder.id,
        supplier_order_number: supplierOrderNumber.trim() || null,
        status: supplierOrderStatus,
        notes: supplierOrderNotes.trim() || null,
        orderId: orderDetail.id,
      });
    }, 700);

    return () => window.clearTimeout(handle);
  }, [
    supplierOrder?.id,
    supplierOrder?.supplier_order_number,
    supplierOrder?.status,
    supplierOrder?.notes,
    supplierOrderNumber,
    supplierOrderStatus,
    supplierOrderNotes,
    orderDetail?.id,
    updateSupplierOrderMutation.isPending,
  ]);

  const [rmaSearch, setRmaSearch] = useState('');
  const [rmaStatus, setRmaStatus] = useState('');
  const [selectedRmaId, setSelectedRmaId] = useState<number | null>(null);

  const rmasQuery = useLivreoRmas({ q: rmaSearch || undefined, status: rmaStatus || undefined, per_page: 20 });
  const rmaDetailQuery = useLivreoRma(selectedRmaId);
  const updateRmaMutation = useUpdateLivreoRma();
  const addRmaCommentMutation = useAddLivreoRmaComment();

  const [rmaNewComment, setRmaNewComment] = useState('');
  const [rmaCommentVisibility, setRmaCommentVisibility] = useState<'customer' | 'internal'>('customer');
  const [rmaStatusDraft, setRmaStatusDraft] = useState<string>('');

  const rmaDetail = rmaDetailQuery.data?.ticket;

  useEffect(() => {
    if (!rmaDetail) return;
    setRmaStatusDraft(rmaDetail.status);
  }, [rmaDetail?.id]);

  return (
    <div style={{ padding: 22 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <h2 style={{ margin: 0 }}>Livreo</h2>
        <div style={{ display: 'flex', gap: 10 }}>
          <button
            type="button"
            onClick={() => setTab('orders')}
            style={{
              border: '1px solid #e2e8f0',
              background: tab === 'orders' ? '#0ea5e9' : '#fff',
              color: tab === 'orders' ? '#fff' : '#0f172a',
              padding: '10px 14px',
              borderRadius: 12,
              fontWeight: 800,
              cursor: 'pointer',
            }}
          >
            Commandes
          </button>
          <button
            type="button"
            onClick={() => setTab('sav')}
            style={{
              border: '1px solid #e2e8f0',
              background: tab === 'sav' ? '#0ea5e9' : '#fff',
              color: tab === 'sav' ? '#fff' : '#0f172a',
              padding: '10px 14px',
              borderRadius: 12,
              fontWeight: 800,
              cursor: 'pointer',
            }}
          >
            SAV
          </button>
        </div>
      </div>

      {tab === 'orders' && (
        <section style={{ marginTop: 18, display: 'grid', gridTemplateColumns: '420px 1fr', gap: 18, alignItems: 'start' }}>
          <div style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 16 }}>
            <div style={{ display: 'grid', gap: 10 }}>
              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  type="button"
                  onClick={() => {
                    setOrderListMode('all');
                  }}
                  style={{
                    border: '1px solid #e2e8f0',
                    background: orderListMode === 'all' ? '#0ea5e9' : '#fff',
                    color: orderListMode === 'all' ? '#fff' : '#0f172a',
                    padding: '10px 12px',
                    borderRadius: 12,
                    fontWeight: 900,
                    cursor: 'pointer',
                    flex: 1,
                  }}
                >
                  Toutes
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setOrderListMode('to_ship');
                    setOrderStatus('');
                    setOrderDeliveryType('');
                  }}
                  style={{
                    border: '1px solid #e2e8f0',
                    background: orderListMode === 'to_ship' ? '#0ea5e9' : '#fff',
                    color: orderListMode === 'to_ship' ? '#fff' : '#0f172a',
                    padding: '10px 12px',
                    borderRadius: 12,
                    fontWeight: 900,
                    cursor: 'pointer',
                    flex: 1,
                  }}
                >
                  À expédier
                </button>
              </div>
              <input
                value={orderSearch}
                onChange={(e) => setOrderSearch(e.target.value)}
                placeholder="Rechercher (numéro, email, suivi…)"
                style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
              />

              <div hidden={orderListMode === 'to_ship'} style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 10 }}>
                <select
                  value={orderStatus}
                  onChange={(e) => setOrderStatus(e.target.value)}
                  style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
                  disabled={orderListMode === 'to_ship'}
                >
                  <option value="">Tous statuts</option>
                  {orderStatusOptions.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </div>

              <select
                hidden={orderListMode === 'to_ship'}
                value={orderDeliveryType}
                onChange={(e) => setOrderDeliveryType(e.target.value)}
                style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
                disabled={orderListMode === 'to_ship'}
              >
                {deliveryTypeOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </div>

            <div style={{ marginTop: 14 }}>
              {(ordersQuery.data?.data ?? []).length === 0 && (
                <p style={{ margin: 0, color: '#64748b' }}>{ordersQuery.isLoading ? 'Chargement…' : 'Aucune commande.'}</p>
              )}

              <div style={{ display: 'grid', gap: 10 }}>
                {(ordersQuery.data?.data ?? []).map((order) => {
                  const isActive = selectedOrderId === order.id;
                  const shipName =
                    String((order as any)?.shipping_choice?.name ?? (order as any)?.shipping_choice?.service ?? '').trim() || '—';
                  const hasLabel = Boolean((order as any)?.has_shipping_label);
                  const buyInProgress = Boolean((order as any)?.boxtal_buy_in_progress);
                  const lastEvent = (order as any)?.boxtal_last_event as any;

                  return (
                    <button
                      key={order.id}
                      type="button"
                      onClick={() => setSelectedOrderId(order.id)}
                      style={{
                        textAlign: 'left',
                        borderRadius: 14,
                        border: `1px solid ${isActive ? '#0ea5e9' : '#e2e8f0'}`,
                        background: isActive ? 'rgba(14, 165, 233, 0.08)' : '#fff',
                        padding: 12,
                        cursor: 'pointer',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontWeight: 900, color: '#0f172a' }}>{order.number}</div>
                          <div style={{ color: '#475569', fontSize: 13, marginTop: 2, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {order.customer_email}
                          </div>
                          <div style={{ color: '#64748b', fontSize: 12, marginTop: 4 }}>
                            {shipName} - {formatMoney(order.total_ttc)}
                          </div>
                          {lastEvent?.event === 'buy_failed' ? (
                            <div style={{ color: '#b91c1c', fontSize: 12, marginTop: 6, fontWeight: 900 }}>
                              Boxtal: erreur (voir logs)
                            </div>
                          ) : null}
                        </div>
                        <div style={{ display: 'grid', justifyItems: 'end', gap: 6 }}>
                          <div style={{ fontSize: 12, fontWeight: 900, color: '#0f172a' }}>
                            {orderStatusLabel(String(order.workflow_status ?? order.status))}
                          </div>
                          {orderListMode === 'to_ship' ? (
                            <div
                              style={{
                                fontSize: 12,
                                fontWeight: 900,
                                color: buyInProgress ? '#0b1f4f' : hasLabel ? '#16a34a' : '#b45309',
                              }}
                            >
                              {buyInProgress ? 'Bordereau en cours' : hasLabel ? 'Bordereau OK' : 'À expédier'}
                            </div>
                          ) : null}
                          <div style={{ color: '#64748b', fontSize: 12 }}>{formatDateTime(order.placed_at)}</div>
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>

          <div style={{ display: 'grid', gap: 18 }}>
            {!orderDetail && (
              <div style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 18, color: '#64748b' }}>
                Sélectionnez une commande pour afficher les détails.
              </div>
            )}

            {orderDetail && (
              <>
                <section style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 18 }}>
                  <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                    <div style={{ display: 'grid', gap: 6 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                        <h3 style={{ margin: 0 }}>{orderDetail.number}</h3>
                      </div>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, color: '#475569', fontSize: 13 }}>
                        <span style={{ fontWeight: 800, color: '#0f172a' }}>{orderDetail.customer_email}</span>
                        <span style={{ opacity: 0.65 }}>-</span>
                        <span>{formatDateTime(orderDetail.placed_at)}</span>
                      </div>
                    </div>

                  </div>

                  <div style={{ marginTop: 12, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                    <strong>Statut</strong>
                    <select
                      value={orderDetailWorkflowStatus}
                      onChange={(e) => {
                        const next = e.target.value;
                        if (!orderStatusUpdateWritable.has(next)) return;
                        updateOrderMutation.mutate({ id: orderDetail.id, status: next });
                      }}
                      disabled={updateOrderMutation.isPending}
                      style={{
                        border: '1px solid #e2e8f0',
                        borderRadius: 12,
                        padding: '10px 12px',
                        minWidth: 220,
                      }}
                    >
                      {orderStatusUpdateOptions.map((opt) => (
                        <option key={opt.value} value={opt.value} disabled={!orderStatusUpdateWritable.has(opt.value)}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                    {updateOrderMutation.isPending ? (
                      <span style={{ color: '#64748b', fontSize: 12, fontWeight: 800 }}>Enregistrement…</span>
                    ) : null}
                  </div>
                </section>

                <Card title="Articles / Totaux">
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                    <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14 }}>
                      <strong>Articles</strong>
                      <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                        {(orderDetailQuery.data?.items ?? []).map((item) => (
                          <div
                            key={item.id}
                            style={{
                              display: 'flex',
                              justifyContent: 'space-between',
                              gap: 10,
                              border: '1px solid #e2e8f0',
                              borderRadius: 12,
                              padding: '10px 12px',
                            }}
                          >
                            <div style={{ minWidth: 0 }}>
                              <div style={{ fontWeight: 800, color: '#0f172a' }}>{item.name}</div>
                              <div style={{ color: '#64748b', fontSize: 12, marginTop: 2 }}>
                                {item.reference ?? '—'} - x{item.quantity}
                              </div>
                            </div>
                            <div style={{ fontWeight: 900 }}>{formatMoney(item.total_ttc)}</div>
                          </div>
                        ))}
                      </div>
                    </div>

                    <div style={{ display: 'grid', gap: 14 }}>
                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#f8fafc' }}>
                        <strong>Totaux</strong>
                        <div style={{ marginTop: 10, display: 'grid', gap: 6 }}>
                          <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                            <span>Commande</span>
                            <strong>{formatMoney(orderDetail.total_ttc)}</strong>
                          </div>
                          <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                            <span>Livraison</span>
                            <strong>{formatMoney(orderDetail.shipping_total)}</strong>
                          </div>
                        </div>
                      </div>

                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#f8fafc' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                          <strong>Livraison (choix client)</strong>
                          <button
                            type="button"
                            onClick={() =>
                              boxtalRefreshMutation.mutate(
                                { orderId: orderDetail.id },
                                { onSuccess: (data) => setBoxtalRefreshData(data) },
                              )
                            }
                            disabled={boxtalRefreshMutation.isPending}
                            style={{
                              border: '1px solid #bae6fd',
                              background: '#e0f2fe',
                              color: '#0b1f4f',
                              padding: '8px 10px',
                              borderRadius: 12,
                              fontWeight: 900,
                              cursor: 'pointer',
                              opacity: boxtalRefreshMutation.isPending ? 0.7 : 1,
                            }}
                          >
                            Rafraîchir Boxtal
                          </button>
                        </div>

                        <div style={{ marginTop: 10 }}>
                          <ShippingChoiceCard
                            choice={shippingChoice}
                            fallbackType={orderDetail.delivery_type}
                            fallbackPrice={orderDetail.shipping_total}
                          />
                        </div>

                        {(() => {
                          const meta = (orderDetail.metadata ?? {}) as Record<string, any>;
                          const shippingLabel = meta.shipping_label as Record<string, any> | undefined;
                          const boxtalLogs = Array.isArray((meta as any).boxtal_logs) ? ((meta as any).boxtal_logs as any[]) : [];
                          const hasLabel = Boolean(shippingLabel?.emc_ref);
                          const tracking = String(shippingLabel?.carrier_reference ?? orderDetail.tracking_number ?? '').trim();
                          const trackingUrl = trackingUrlFor(shippingLabel?.operator, tracking);
                          const labelPrice = typeof shippingLabel?.price === 'number' ? (shippingLabel.price as number) : null;

                          return (
                            <div style={{ marginTop: 12, borderTop: '1px solid #e2e8f0', paddingTop: 12, display: 'grid', gap: 10 }}>
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                                <div style={{ fontWeight: 900, color: '#0f172a' }}>Bordereau Boxtal</div>
                                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                                  <label style={{ display: 'flex', gap: 10, alignItems: 'center', color: '#475569', fontSize: 13 }}>
                                    <input
                                      type="checkbox"
                                      checked={boxtalBuyUseDev}
                                      onChange={(e) => setBoxtalBuyUseDev(e.target.checked)}
                                    />
                                    Mode test (sans paiement)
                                  </label>
                                  <label style={{ display: 'flex', gap: 10, alignItems: 'center', color: '#475569', fontSize: 13 }}>
                                  <input
                                    type="checkbox"
                                    checked={boxtalBuySetShipped}
                                    onChange={(e) => setBoxtalBuySetShipped(e.target.checked)}
                                  />
                                  Marquer expédiée
                                </label>
                                </div>
                              </div>

                              {hasLabel ? (
                                <div
                                  style={{
                                    border: '1px solid #e2e8f0',
                                    borderRadius: 14,
                                    padding: 12,
                                    background: '#fff',
                                    display: 'grid',
                                    gap: 8,
                                  }}
                                >
                                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                                    <div style={{ minWidth: 0 }}>
                                      <div style={{ fontWeight: 900, color: '#0f172a' }}>{String(shippingLabel?.name ?? 'Boxtal')}</div>
                                      <div style={{ marginTop: 2, color: '#64748b', fontSize: 12 }}>
                                        Réf. Boxtal : {String(shippingLabel?.emc_ref ?? '—')}
                                      </div>
                                      {tracking ? (
                                        <div style={{ marginTop: 2, color: '#64748b', fontSize: 12 }}>
                                          Suivi :{' '}
                                          {trackingUrl ? (
                                            <a
                                              href={trackingUrl}
                                              target="_blank"
                                              rel="noreferrer noopener"
                                              style={{ color: '#0ea5e9', fontWeight: 900, textDecoration: 'underline' }}
                                            >
                                              {tracking}
                                            </a>
                                          ) : (
                                            tracking
                                          )}
                                        </div>
                                      ) : null}
                                      {labelPrice !== null ? (
                                        <div style={{ marginTop: 2, color: '#64748b', fontSize: 12 }}>Prix : {formatMoney(labelPrice)}</div>
                                      ) : null}
                                    </div>
                                    <button
                                      type="button"
                                      onClick={async () => {
                                        try {
                                          const res = await apiClient.get(`/livreo/orders/${orderDetail.id}/boxtal/label`, {
                                            responseType: 'blob',
                                          });
                                          const contentType = String((res.headers as any)?.['content-type'] ?? 'application/pdf');
                                          openPrintableBlob(new Blob([res.data], { type: contentType }));
                                        } catch (e) {
                                          const data = (e as any)?.response?.data;
                                          const msg =
                                            data?.message ?? "Impossible d'ouvrir le bordereau. Réessaie dans quelques secondes.";
                                          window.alert(msg);
                                        }
                                      }}
                                      style={{
                                        border: 'none',
                                        background: '#0ea5e9',
                                        color: '#fff',
                                        padding: '10px 14px',
                                        borderRadius: 12,
                                        fontWeight: 900,
                                        cursor: 'pointer',
                                        whiteSpace: 'nowrap',
                                      }}
                                    >
                                      Imprimer
                                    </button>
                                  </div>

                                  {boxtalLogs.length ? (
                                    <details>
                                      <summary style={{ cursor: 'pointer', fontWeight: 900, color: '#0b1f4f' }}>Logs Boxtal</summary>
                                      <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                                        {[...boxtalLogs]
                                          .slice(-10)
                                          .reverse()
                                          .map((l, idx) => (
                                            <div
                                              key={idx}
                                              style={{
                                                border: '1px solid #e2e8f0',
                                                borderRadius: 12,
                                                padding: '10px 12px',
                                                background: '#f8fafc',
                                                color: '#0f172a',
                                                fontSize: 12,
                                              }}
                                            >
                                              <div style={{ fontWeight: 900 }}>
                                                {String(l?.event ?? 'event')} - {String(l?.at ?? '')}
                                              </div>
                                              <div style={{ marginTop: 4, color: '#475569' }}>
                                                {(l?.operator || l?.service) ? `${String(l?.operator ?? '')}:${String(l?.service ?? '')}` : ''}
                                                {l?.emc_ref ? ` - ${String(l.emc_ref)}` : ''}
                                                {typeof l?.price === 'number' ? ` - ${formatMoney(l.price)}` : ''}
                                              </div>
                                              {Array.isArray(l?.errors) && l.errors.length ? (
                                                <div style={{ marginTop: 6, color: '#b91c1c', fontWeight: 800 }}>
                                                  {String(l.errors.filter(Boolean).slice(0, 2).join(' - '))}
                                                </div>
                                              ) : null}
                                            </div>
                                          ))}
                                      </div>
                                    </details>
                                  ) : null}
                                </div>
                              ) : (
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                                  <div style={{ color: '#475569', fontSize: 13 }}>
                                    {orderDetail.payment_status === 'paid'
                                      ? 'Acheter le bordereau Boxtal pour cette commande.'
                                      : 'Commande non payée : bordereau indisponible.'}
                                  </div>
                                  <button
                                    type="button"
                                    disabled={boxtalBuyLabelMutation.isPending || (orderDetail.payment_status !== 'paid' && !boxtalBuyUseDev)}
                                    onClick={() => {
                                      if (orderDetail.payment_status !== 'paid' && !boxtalBuyUseDev) return;
                                      (async () => {
                                        try {
                                          const validate = await apiClient.get(`/livreo/orders/${orderDetail.id}/boxtal/validate`);
                                          if (validate?.data?.ok === false) {
                                            const missingRecipient = Array.isArray(validate.data?.missing_recipient_fields)
                                              ? validate.data.missing_recipient_fields
                                              : [];
                                            const missingShipper = Array.isArray(validate.data?.missing_shipper_env)
                                              ? validate.data.missing_shipper_env
                                              : [];
                                            window.alert(
                                              `Adresse incomplète.\n\nClient: ${missingRecipient.join(', ') || '—'}\nExpéditeur (.env): ${missingShipper.join(', ') || '—'}`,
                                            );
                                            return;
                                          }
                                        } catch {
                                          // ignore validate errors: we still try buy-label which will return clear 422s
                                        }

                                        const ok = window.confirm(
                                          'Acheter et ouvrir le bordereau Boxtal ? (Le PDF s’ouvre dans un nouvel onglet)',
                                        );
                                        if (!ok) return;

                                        try {
                                          await (boxtalBuyLabelMutation as any).mutateAsync({
                                            orderId: orderDetail.id,
                                            method_id: boxtalRefreshData?.selected?.method_id
                                              ? String(boxtalRefreshData.selected.method_id)
                                              : shippingChoice?.method_id
                                                ? String(shippingChoice.method_id)
                                                : null,
                                            dev: boxtalBuyUseDev,
                                            set_status: boxtalBuySetShipped ? 'shipped' : null,
                                          });

                                          const res = await apiClient.get(`/livreo/orders/${orderDetail.id}/boxtal/label`, {
                                            responseType: 'blob',
                                          });
                                          const contentType = String((res.headers as any)?.['content-type'] ?? 'application/pdf');
                                          openPrintableBlob(new Blob([res.data], { type: contentType }));
                                        } catch {
                                          // handled by mutation error UI
                                        }
                                      })();
                                    }}
                                    style={{
                                      border: 'none',
                                      background: '#0ea5e9',
                                      color: '#fff',
                                      padding: '10px 14px',
                                      borderRadius: 12,
                                      fontWeight: 900,
                                      cursor: 'pointer',
                                      opacity: boxtalBuyLabelMutation.isPending || orderDetail.payment_status !== 'paid' ? 0.7 : 1,
                                    }}
                                  >
                                    Acheter & imprimer
                                  </button>
                                </div>
                              )}

                              {boxtalBuyLabelMutation.isError ? (
                                <div style={{ color: '#b91c1c', fontWeight: 800, fontSize: 13 }}>
                                  {(() => {
                                    const data = (boxtalBuyLabelMutation.error as any)?.response?.data;
                                    const msg = data?.message ?? "Impossible d'acheter le bordereau Boxtal.";
                                    const errors = Array.isArray(data?.errors) ? data.errors.filter(Boolean) : [];
                                    const dbg = data?.debug;
                                    const dbgTxt =
                                      dbg && (dbg.operator || dbg.service || dbg.collecte)
                                        ? ` [${[dbg.operator, dbg.service].filter(Boolean).join(':')}${dbg.collecte ? ` - collecte ${dbg.collecte}` : ''}]`
                                        : '';
                                    return errors.length ? `${msg}${dbgTxt} (${errors.join(' - ')})` : `${msg}${dbgTxt}`;
                                  })()}
                                </div>
                              ) : null}
                            </div>
                          );
                        })()}

                        {boxtalRefreshMutation.isError && (
                          <div style={{ marginTop: 10, color: '#b91c1c', fontWeight: 800, fontSize: 13 }}>
                            Impossible de récupérer la cotation Boxtal.
                          </div>
                        )}

                        {boxtalRefreshData && (
                          <div style={{ marginTop: 12, borderTop: '1px solid #e2e8f0', paddingTop: 12, display: 'grid', gap: 10 }}>
                            <div style={{ fontWeight: 900, color: '#0f172a' }}>Cotation Boxtal (recalculée)</div>

                            {boxtalRefreshData.selected ? (
                              <div
                                style={{
                                  border: '1px solid #e2e8f0',
                                  borderRadius: 14,
                                  padding: 12,
                                  background: '#fff',
                                  display: 'flex',
                                  justifyContent: 'space-between',
                                  gap: 12,
                                  alignItems: 'flex-start',
                                }}
                              >
                                <div style={{ minWidth: 0 }}>
                                  <div style={{ fontWeight: 900, color: '#0f172a' }}>{boxtalRefreshData.selected.name}</div>
                                  <div style={{ marginTop: 4, color: '#64748b', fontSize: 13 }}>
                                    {boxtalRefreshData.selected.delay ? `Estimé : ${String(boxtalRefreshData.selected.delay)}` : 'Estimé : —'}
                                  </div>
                                  <div style={{ marginTop: 4, color: '#64748b', fontSize: 12 }}>
                                    Méthode : {boxtalRefreshData.selected.method_id}
                                  </div>
                                </div>
                                <div style={{ fontWeight: 900, color: '#0b1f4f' }}>{formatMoney(boxtalRefreshData.selected.price)}</div>
                              </div>
                            ) : (
                              <div style={{ color: '#b91c1c', fontWeight: 800, fontSize: 13 }}>
                                Aucune offre Boxtal correspondante trouvée.
                              </div>
                            )}

                            <details>
                              <summary style={{ cursor: 'pointer', fontWeight: 900, color: '#0b1f4f' }}>Voir toutes les offres</summary>
                              <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                                {(boxtalRefreshData.offers ?? []).map((offer) => (
                                  <div
                                    key={offer.method_id}
                                    style={{
                                      border: '1px solid #e2e8f0',
                                      borderRadius: 12,
                                      padding: '10px 12px',
                                      background:
                                        boxtalRefreshData.selected?.method_id === offer.method_id ? 'rgba(58,186,252,0.12)' : '#fff',
                                    }}
                                  >
                                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10 }}>
                                      <div style={{ minWidth: 0 }}>
                                        <div style={{ fontWeight: 900 }}>{offer.name}</div>
                                        <div style={{ color: '#64748b', fontSize: 12, marginTop: 2 }}>
                                          {offer.delay ? `Estimé : ${String(offer.delay)}` : 'Estimé : —'} - {offer.type}
                                        </div>
                                        <div style={{ color: '#64748b', fontSize: 11, marginTop: 2 }}>{offer.method_id}</div>
                                      </div>
                                      <div style={{ fontWeight: 900 }}>{formatMoney(offer.price)}</div>
                                    </div>
                                  </div>
                                ))}
                              </div>
                            </details>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </Card>

                <Card title="Commande fournisseur (Mobile Sentrix)">
                  {!supplierOrder && (
                    <button
                      type="button"
                      disabled={createSupplierOrderMutation.isPending}
                      onClick={() =>
                        createSupplierOrderMutation.mutate(
                          { orderId: orderDetail.id, supplier: 'mobile_sentrix', status: 'to_order' },
                          { onSuccess: () => void orderDetailQuery.refetch() },
                        )
                      }
                      style={{
                        border: 'none',
                        background: '#0f766e',
                        color: '#fff',
                        padding: '10px 14px',
                        borderRadius: 12,
                        fontWeight: 900,
                        cursor: 'pointer',
                        opacity: createSupplierOrderMutation.isPending ? 0.7 : 1,
                      }}
                    >
                      Créer un suivi fournisseur
                    </button>
                  )}

                  {supplierOrder && (
                    <div style={{ display: 'grid', gap: 12 }}>
                      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                        <input
                          placeholder="N° commande fournisseur"
                          value={supplierOrderNumber}
                          onChange={(e) => setSupplierOrderNumber(e.target.value)}
                          style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
                        />
                        <select
                          value={supplierOrderStatus}
                          onChange={(e) => setSupplierOrderStatus(e.target.value as any)}
                          style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
                        >
                          <option value="to_order">À commander</option>
                          <option value="ordered">Commandée</option>
                          <option value="received">Reçue</option>
                          <option value="problem">Problème</option>
                        </select>
                      </div>

                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#fff' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                          <strong>MobileSentrix (détecté par email)</strong>
                        </div>

                        <div style={{ marginTop: 10, display: 'grid', gridTemplateColumns: '1fr', gap: 10 }}>
                          <select
                            value={mobileSentrixSelected}
                            onChange={(e) => {
                              const value = e.target.value;
                              setMobileSentrixSelected(value);
                              if (!value) return;
                              if (!supplierOrder) return;
                              assignMobileSentrixMutation.mutate({
                                supplierOrderId: supplierOrder.id,
                                supplier_order_number: value,
                                orderId: orderDetail.id,
                              });
                            }}
                            style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
                          >
                            <option value="">{mobileSentrixShipmentsQuery.isLoading ? 'Chargement…' : 'À attribuer…'}</option>
                            {(mobileSentrixShipmentsQuery.data?.items ?? []).map((opt) => (
                              <option key={opt.supplier_order_number} value={opt.supplier_order_number}>
                                {opt.supplier_order_number}
                                {opt.received_at ? ` — ${formatDateTime(opt.received_at)}` : ''}
                                {opt.carrier ? ` — ${opt.carrier}` : ''}
                                {opt.tracking_number ? ` — ${opt.tracking_number}` : ''}
                              </option>
                            ))}
                          </select>
                        </div>

                        {assignMobileSentrixMutation.error ? (
                          <div style={{ marginTop: 8, color: '#b91c1c', fontSize: 12 }}>Impossible d'attribuer cette commande MobileSentrix.</div>
                        ) : null}
                      </div>
                      <textarea
                        value={supplierOrderNotes}
                        onChange={(e) => setSupplierOrderNotes(e.target.value)}
                        rows={3}
                        placeholder="Notes internes (ex : SKU, remark, incident...)"
                        style={{ width: '100%', border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px', resize: 'vertical' }}
                      />

                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#f8fafc' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                          <strong>Suivi MobileSentrix (email)</strong>
                          <div style={{ color: '#64748b', fontSize: 12, fontWeight: 800 }}>
                            {syncMobileSentrixMailMutation.isPending ? 'Recherche dans la boîte mail…' : 'Auto'}
                          </div>
                        </div>

                        <div style={{ marginTop: 10, display: 'grid', gap: 6 }}>
                          <div style={{ fontWeight: 900 }}>
                            {supplierOrder.supplier_tracking_number ? (
                              <a
                                href={
                                  supplierOrder.supplier_tracking_url
                                    ? String(supplierOrder.supplier_tracking_url)
                                    : `https://www.google.com/search?q=${encodeURIComponent(String(supplierOrder.supplier_tracking_number))}`
                                }
                                target="_blank"
                                rel="noreferrer"
                                style={{ color: '#0b1f4f', textDecoration: 'underline' }}
                              >
                                {supplierOrder.supplier_tracking_number}
                              </a>
                            ) : (
                              '—'
                            )}
                          </div>
                          <div style={{ color: '#475569', fontSize: 13 }}>
                            {supplierOrder.supplier_carrier ? `Transporteur : ${supplierOrder.supplier_carrier}` : 'Transporteur : —'}
                            {supplierOrder.supplier_shipped_at ? ` - Expédié : ${formatDateTime(supplierOrder.supplier_shipped_at)}` : ''}
                          </div>
                          <div style={{ color: '#64748b', fontSize: 12 }}>
                            {supplierOrder.supplier_email_subject
                              ? `Dernier email : ${formatDateTime(supplierOrder.supplier_email_received_at)} - ${supplierOrder.supplier_email_subject}`
                              : 'Dernier email : —'}
                          </div>
                          {syncMobileSentrixMailMutation.data?.ok ? (
                            <div style={{ color: '#0f172a', fontSize: 12 }}>
                              Import : {syncMobileSentrixMailMutation.data.created ?? 0} nouveau(x) -{' '}
                              {syncMobileSentrixMailMutation.data.matched ?? 0} associée(s) -{' '}
                              {syncMobileSentrixMailMutation.data.matched_auto ?? 0} auto - {syncMobileSentrixMailMutation.data.skipped_existing ?? 0} déjà vus
                            </div>
                          ) : null}
                          {syncMobileSentrixMailMutation.data?.ok === false && syncMobileSentrixMailMutation.data.error ? (
                            <div style={{ color: '#b91c1c', fontSize: 12 }}>{syncMobileSentrixMailMutation.data.error}</div>
                          ) : null}
                        </div>
                      </div>

                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
                        <div style={{ color: '#64748b', fontSize: 12, fontWeight: 800 }}>
                          {updateSupplierOrderMutation.isPending
                            ? 'Sauvegarde…'
                            : updateSupplierOrderMutation.isError
                              ? 'Erreur de sauvegarde (reessayez).'
                              : 'Sauvegarde auto.'}
                        </div>
                      </div>
                    </div>
                  )}
                </Card>
              </>
            )}
          </div>
        </section>
      )}

      {tab === 'sav' && (
        <section style={{ marginTop: 18, display: 'grid', gridTemplateColumns: '420px 1fr', gap: 18, alignItems: 'start' }}>
          <div style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 16 }}>
            <div style={{ display: 'grid', gap: 10 }}>
              <input
                value={rmaSearch}
                onChange={(e) => setRmaSearch(e.target.value)}
                placeholder="Rechercher (ticket, commande, email…)"
                style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
              />
              <select
                value={rmaStatus}
                onChange={(e) => setRmaStatus(e.target.value)}
                style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px' }}
              >
                <option value="">Tous statuts</option>
                {rmaStatusOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </div>

            <div style={{ marginTop: 14 }}>
              {(rmasQuery.data?.data ?? []).length === 0 && (
                <p style={{ margin: 0, color: '#64748b' }}>{rmasQuery.isLoading ? 'Chargement…' : 'Aucun ticket.'}</p>
              )}

              <div style={{ display: 'grid', gap: 10 }}>
                {(rmasQuery.data?.data ?? []).map((ticket) => {
                  const isActive = selectedRmaId === ticket.id;
                  return (
                    <button
                      key={ticket.id}
                      type="button"
                      onClick={() => setSelectedRmaId(ticket.id)}
                      style={{
                        textAlign: 'left',
                        borderRadius: 14,
                        border: `1px solid ${isActive ? '#0ea5e9' : '#e2e8f0'}`,
                        background: isActive ? 'rgba(14, 165, 233, 0.08)' : '#fff',
                        padding: 12,
                        cursor: 'pointer',
                      }}
                    >
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontWeight: 900, color: '#0f172a' }}>{ticket.rma_number}</div>
                          <div style={{ color: '#475569', fontSize: 13, marginTop: 2 }}>{ticket.customer_email ?? '—'}</div>
                          <div style={{ color: '#64748b', fontSize: 12, marginTop: 4 }}>
                            {ticket.order?.number ? `Commande ${ticket.order.number}` : '—'} - {ticket.reason}
                          </div>
                        </div>
                        <div style={{ display: 'grid', justifyItems: 'end', gap: 6 }}>
                          <RmaStatusPill status={ticket.status} />
                          <div style={{ color: '#64748b', fontSize: 12 }}>{formatDateTime(ticket.created_at)}</div>
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>

          <div style={{ display: 'grid', gap: 18 }}>
            {!rmaDetail && (
              <div style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', padding: 18, color: '#64748b' }}>
                Sélectionnez un ticket pour afficher les détails.
              </div>
            )}

            {rmaDetail && (
              <>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <h3 style={{ margin: 0 }}>{rmaDetail.rma_number}</h3>
                    <RmaStatusPill status={rmaDetail.status} />
                  </div>
                </div>

                <Card title="Détails">
                  <div style={{ display: 'grid', gap: 10 }}>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#f8fafc' }}>
                        <strong>Raison</strong>
                        <div style={{ marginTop: 8, fontWeight: 800 }}>{rmaDetail.reason}</div>
                      </div>
                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14, background: '#f8fafc' }}>
                        <strong>Commande</strong>
                        <div style={{ marginTop: 8, fontWeight: 800 }}>{rmaDetail.order?.number ?? '—'}</div>
                      </div>
                    </div>
                    {rmaDetail.description ? (
                      <div style={{ border: '1px solid #e2e8f0', borderRadius: 14, padding: 14 }}>
                        <strong>Description</strong>
                        <p style={{ margin: '10px 0 0 0', whiteSpace: 'pre-wrap', color: '#0f172a' }}>{rmaDetail.description}</p>
                      </div>
                    ) : null}

                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'center' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <strong>Statut</strong>
                        <select
                          value={rmaStatusDraft || rmaDetail.status}
                          onChange={(e) => {
                            const next = e.target.value;
                            setRmaStatusDraft(next);
                            updateRmaMutation.mutate({ id: rmaDetail.id, status: next });
                          }}
                          disabled={updateRmaMutation.isPending}
                          style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '8px 10px' }}
                        >
                          {rmaStatusOptions.map((opt) => (
                            <option key={opt.value} value={opt.value}>
                              {opt.label}
                            </option>
                          ))}
                        </select>
                      </div>
                      {updateRmaMutation.isPending ? (
                        <div style={{ color: '#64748b', fontSize: 12, fontWeight: 900 }}>Enregistrement…</div>
                      ) : null}
                    </div>
                  </div>
                </Card>

                <Card title="Discussion">
                  <div style={{ display: 'grid', gap: 12 }}>
                    {(rmaDetailQuery.data?.comments ?? []).length === 0 && <p style={{ margin: 0, color: '#64748b' }}>Aucun message.</p>}
                    {(rmaDetailQuery.data?.comments ?? []).map((comment) => (
                      <div
                        key={comment.id}
                        style={{
                          border: '1px solid #e2e8f0',
                          borderRadius: 14,
                          padding: 12,
                          background: comment.is_internal ? '#fff7ed' : '#f8fafc',
                        }}
                      >
                        <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{comment.comment}</p>
                        <div style={{ marginTop: 8, display: 'flex', justifyContent: 'space-between', color: '#64748b', fontSize: 12 }}>
                          <span>{comment.is_internal ? 'Interne' : 'Client'}</span>
                          <span>{formatDateTime(comment.created_at)}</span>
                        </div>
                      </div>
                    ))}

                    <div style={{ borderTop: '1px solid #e2e8f0', paddingTop: 12, display: 'grid', gap: 10 }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'center' }}>
                        <strong>Nouveau message</strong>
                        <select
                          value={rmaCommentVisibility}
                          onChange={(e) => setRmaCommentVisibility(e.target.value as any)}
                          style={{ border: '1px solid #e2e8f0', borderRadius: 12, padding: '8px 10px' }}
                        >
                          <option value="customer">Envoyer au client</option>
                          <option value="internal">Note interne</option>
                        </select>
                      </div>
                      <textarea
                        value={rmaNewComment}
                        onChange={(e) => setRmaNewComment(e.target.value)}
                        rows={3}
                        placeholder={rmaCommentVisibility === 'internal' ? 'Note interne (non visible client)' : 'Message client'}
                        style={{ width: '100%', border: '1px solid #e2e8f0', borderRadius: 12, padding: '10px 12px', resize: 'vertical' }}
                      />
                      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}>
                        <button
                          type="button"
                          onClick={() => setRmaNewComment('')}
                          style={{ border: '1px solid #e2e8f0', background: '#fff', padding: '10px 14px', borderRadius: 12, fontWeight: 900, cursor: 'pointer' }}
                        >
                          Effacer
                        </button>
                        <button
                          type="button"
                          disabled={addRmaCommentMutation.isPending || rmaNewComment.trim() === ''}
                          onClick={() => {
                            addRmaCommentMutation.mutate(
                              { id: rmaDetail.id, comment: rmaNewComment.trim(), visibility: rmaCommentVisibility },
                              { onSuccess: () => setRmaNewComment('') },
                            );
                          }}
                          style={{
                            border: 'none',
                            background: '#0f766e',
                            color: '#fff',
                            padding: '10px 14px',
                            borderRadius: 12,
                            fontWeight: 900,
                            cursor: 'pointer',
                            opacity: addRmaCommentMutation.isPending ? 0.7 : 1,
                          }}
                        >
                          Envoyer
                        </button>
                      </div>
                    </div>
                  </div>
                </Card>
              </>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
