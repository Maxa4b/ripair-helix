import { useEffect, useState } from 'react';
import type { GoogleMapsApi } from '../components/prospecting/googleMapsTypes';

declare global {
  interface Window {
    google?: GoogleMapsApi;
    __helixGoogleMapsLoader?: Promise<GoogleMapsApi>;
  }
}

type GoogleLike = GoogleMapsApi;

function loadGoogleMapsApi(): Promise<GoogleLike> {
  const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;

  if (!apiKey) {
    return Promise.reject(new Error('VITE_GOOGLE_MAPS_API_KEY est manquante.'));
  }

  if (window.google?.maps) {
    return Promise.resolve(window.google);
  }

  if (window.__helixGoogleMapsLoader) {
    return window.__helixGoogleMapsLoader;
  }

  window.__helixGoogleMapsLoader = new Promise<GoogleLike>((resolve, reject) => {
    const script = document.createElement('script');
    const params = new URLSearchParams({
      key: apiKey,
      v: 'weekly',
      libraries: 'marker',
    });

    script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`;
    script.async = true;
    script.defer = true;

    script.onload = () => {
      if (window.google?.maps) {
        resolve(window.google);
        return;
      }

      reject(new Error('Google Maps API chargee sans objet global exploitable.'));
    };

    script.onerror = () => reject(new Error('Impossible de charger Google Maps.'));

    document.head.appendChild(script);
  });

  return window.__helixGoogleMapsLoader;
}

export function useGoogleMapsApi() {
  const [maps, setMaps] = useState<GoogleLike | null>(window.google?.maps ? window.google : null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    loadGoogleMapsApi()
      .then((googleInstance) => {
        if (!active) {
          return;
        }

        setMaps(googleInstance);
        setError(null);
      })
      .catch((reason) => {
        if (!active) {
          return;
        }

        setError(reason instanceof Error ? reason.message : 'Chargement Google Maps impossible.');
      });

    return () => {
      active = false;
    };
  }, []);

  return {
    google: maps,
    isReady: maps !== null,
    error,
  };
}
