import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ProspectingCompany } from '../../hooks/useProspecting';
import { useGoogleMapsApi } from '../../hooks/useGoogleMapsApi';
import type { GoogleMapsApi, MapHandle, MarkerHandle, MapsEventListener } from './googleMapsTypes';

type Props = {
  companies: ProspectingCompany[];
  selectedCompanyId: number | null;
  onSelectCompany: (company: ProspectingCompany | null) => void;
  onBoundsChange: (bounds: string) => void;
  children?: ReactNode;
};

type MarkerEntry = {
  marker: MarkerHandle;
  element: HTMLDivElement;
};

type ClusterItem =
  | {
      kind: 'company';
      key: string;
      company: ProspectingCompany;
    }
  | {
      kind: 'cluster';
      key: string;
      count: number;
      lat: number;
      lng: number;
    };

const DEFAULT_CENTER = { lat: 46.603354, lng: 1.888334 };

export default function ProspectingMap({ companies, selectedCompanyId, onSelectCompany, onBoundsChange, children }: Props) {
  const { google, isReady, error } = useGoogleMapsApi();
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<MapHandle | null>(null);
  const markersRef = useRef<Map<string, MarkerEntry>>(new Map());
  const idleListenerRef = useRef<MapsEventListener | null>(null);
  const clickListenerRef = useRef<MapsEventListener | null>(null);
  const [zoom, setZoom] = useState(6);

  useEffect(() => {
    if (!isReady || !google || mapRef.current || !containerRef.current) {
      return;
    }

    mapRef.current = new google.maps.Map(containerRef.current, {
      center: DEFAULT_CENTER,
      zoom: 6,
      gestureHandling: 'greedy',
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      mapId: import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || undefined,
    });

    clickListenerRef.current = mapRef.current.addListener('click', () => {
      onSelectCompany(null);
    });
  }, [google, isReady, onSelectCompany]);

  useEffect(() => {
    if (!mapRef.current || !google) {
      return;
    }

    idleListenerRef.current?.remove?.();

    idleListenerRef.current = mapRef.current.addListener('idle', () => {
      const bounds = mapRef.current?.getBounds?.() ?? null;
      const currentZoom = mapRef.current?.getZoom?.();

      if (typeof currentZoom === 'number') {
        setZoom(currentZoom);
      }

      if (!bounds) {
        return;
      }

      const northEast = bounds.getNorthEast();
      const southWest = bounds.getSouthWest();

      onBoundsChange(
        [
          southWest.lat(),
          southWest.lng(),
          northEast.lat(),
          northEast.lng(),
        ].join(','),
      );
    });

    return () => {
      idleListenerRef.current?.remove?.();
    };
  }, [google, onBoundsChange]);

  const items = useMemo(() => buildDisplayItems(companies, zoom), [companies, zoom]);

  useEffect(() => {
    if (!google || !mapRef.current) {
      return;
    }

    const map = mapRef.current;
    const nextKeys = new Set(items.map((item) => item.key));

    for (const [key, entry] of markersRef.current.entries()) {
      if (nextKeys.has(key)) {
        continue;
      }

        entry.marker.map = null;
        entry.marker.setMap?.(null);
        markersRef.current.delete(key);
      }

    items.forEach((item) => {
      const existing = markersRef.current.get(item.key);
      if (item.kind === 'company') {
        const isSelected = item.company.id === selectedCompanyId;

        if (existing) {
          existing.element.className = companyMarkerClassName(item.company.contact_status, isSelected);
          existing.marker.position = {
            lat: item.company.lat ?? DEFAULT_CENTER.lat,
            lng: item.company.lng ?? DEFAULT_CENTER.lng,
          };
          return;
        }

        const element = document.createElement('div');
        element.className = companyMarkerClassName(item.company.contact_status, isSelected);

        const marker = createMarker(google, map, element, {
          lat: item.company.lat ?? DEFAULT_CENTER.lat,
          lng: item.company.lng ?? DEFAULT_CENTER.lng,
        });

        marker.addListener?.('click', () => onSelectCompany(item.company));
        markersRef.current.set(item.key, { marker, element });
        return;
      }

      if (existing) {
        existing.element.textContent = String(item.count);
        existing.marker.position = { lat: item.lat, lng: item.lng };
        return;
      }

      const element = document.createElement('div');
      element.className = 'prospecting-cluster';
      element.textContent = String(item.count);

      const marker = createMarker(google, map, element, {
        lat: item.lat,
        lng: item.lng,
      });

      marker.addListener?.('click', () => {
        const map = mapRef.current;
        if (!map) {
          return;
        }

        map.panTo({ lat: item.lat, lng: item.lng });
        map.setZoom(Math.min((map.getZoom?.() ?? 6) + 2, 16));
      });

      markersRef.current.set(item.key, { marker, element });
    });
  }, [google, items, onSelectCompany, selectedCompanyId]);

  useEffect(() => {
    const markers = markersRef.current;

    return () => {
      idleListenerRef.current?.remove?.();
      clickListenerRef.current?.remove?.();

      markers.forEach((entry) => {
        entry.marker.map = null;
        entry.marker.setMap?.(null);
      });
      markers.clear();
    };
  }, []);

  return (
    <div className="prospecting-map-shell">
      <div ref={containerRef} className="prospecting-map-shell__map" />
      <div className="prospecting-map-shell__overlay">
        <div className="prospecting-banner">
          <strong>Carte Google Maps</strong>
          <div style={{ marginTop: 6, color: 'rgba(255,255,255,0.78)' }}>
            {error
              ? error
              : isReady
                ? `${companies.length} entreprises chargees dans le viewport`
                : 'Chargement de la carte...'}
          </div>
        </div>
      </div>
      {children}
    </div>
  );
}

function createMarker(google: GoogleMapsApi, map: MapHandle, element: HTMLDivElement, position: { lat: number | null; lng: number | null }): MarkerHandle {
  const lat = position.lat ?? DEFAULT_CENTER.lat;
  const lng = position.lng ?? DEFAULT_CENTER.lng;

  if (google.maps.marker?.AdvancedMarkerElement) {
    return new google.maps.marker.AdvancedMarkerElement({
      map,
      position: { lat, lng },
      content: element,
    });
  }

  return new google.maps.Marker({
    map,
    position: { lat, lng },
    icon: {
      url:
        'data:image/svg+xml;utf8,' +
        encodeURIComponent(
          `<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 26 26"><circle cx="13" cy="13" r="10" fill="#0f172a" stroke="#fff" stroke-width="4"/></svg>`,
        ),
      scaledSize: new google.maps.Size(26, 26),
    },
  });
}

function companyMarkerClassName(status: ProspectingCompany['contact_status'], isSelected: boolean) {
  const colorClass =
    status === 'contacte'
      ? 'prospecting-chip__dot--green'
      : status === 'en_cours_de_contact'
        ? 'prospecting-chip__dot--blue'
        : 'prospecting-chip__dot--red';

  return `prospecting-marker ${colorClass.replace('prospecting-chip__dot', 'prospecting-marker')}${isSelected ? ' prospecting-marker--selected' : ''}`;
}

function buildDisplayItems(companies: ProspectingCompany[], zoom: number): ClusterItem[] {
  const geocodedCompanies = companies.filter((company) => typeof company.lat === 'number' && typeof company.lng === 'number');

  if (zoom >= 13 || geocodedCompanies.length <= 60) {
    return geocodedCompanies.map((company) => ({
      kind: 'company',
      key: `company-${company.id}`,
      company,
    }));
  }

  const cellSize = zoom <= 6 ? 92 : zoom <= 8 ? 78 : zoom <= 10 ? 62 : 48;
  const buckets = new Map<string, ProspectingCompany[]>();

  geocodedCompanies.forEach((company) => {
    const world = project(company.lat ?? 0, company.lng ?? 0, zoom);
    const bucketKey = `${Math.floor(world.x / cellSize)}:${Math.floor(world.y / cellSize)}`;
    const bucket = buckets.get(bucketKey);

    if (bucket) {
      bucket.push(company);
    } else {
      buckets.set(bucketKey, [company]);
    }
  });

  const items: ClusterItem[] = [];
  buckets.forEach((bucket, bucketKey) => {
    if (bucket.length === 1) {
      items.push({
        kind: 'company',
        key: `company-${bucket[0].id}`,
        company: bucket[0],
      });
      return;
    }

    const lat = bucket.reduce((sum, company) => sum + (company.lat ?? 0), 0) / bucket.length;
    const lng = bucket.reduce((sum, company) => sum + (company.lng ?? 0), 0) / bucket.length;

    items.push({
      kind: 'cluster',
      key: `cluster-${bucketKey}`,
      count: bucket.length,
      lat,
      lng,
    });
  });

  return items;
}

function project(lat: number, lng: number, zoom: number): { x: number; y: number } {
  const siny = Math.sin((lat * Math.PI) / 180);
  const clamped = Math.min(Math.max(siny, -0.9999), 0.9999);
  const scale = 256 * Math.pow(2, zoom);

  return {
    x: ((lng + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + clamped) / (1 - clamped)) / (4 * Math.PI)) * scale,
  };
}
