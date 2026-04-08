export type LatLngLiteral = {
  lat: number;
  lng: number;
};

export type MapsEventListener = {
  remove: () => void;
};

export type LatLngAccessor = {
  lat: () => number;
  lng: () => number;
};

export type LatLngBoundsAccessor = {
  getNorthEast: () => LatLngAccessor;
  getSouthWest: () => LatLngAccessor;
};

export type MarkerHandle = {
  position?: LatLngLiteral;
  map?: MapHandle | null;
  setMap?: (map: MapHandle | null) => void;
  addListener?: (eventName: string, handler: () => void) => MapsEventListener;
};

export type MapHandle = {
  addListener: (eventName: string, handler: () => void) => MapsEventListener;
  getBounds?: () => LatLngBoundsAccessor | null;
  getZoom?: () => number | undefined;
  panTo: (position: LatLngLiteral) => void;
  setZoom: (zoom: number) => void;
};

export type GoogleMapsApi = {
  maps: {
    Map: new (
      element: HTMLElement,
      options: Record<string, unknown>,
    ) => MapHandle;
    Marker: new (options: {
      map: MapHandle;
      position: LatLngLiteral;
      icon?: {
        url: string;
        scaledSize?: unknown;
      };
    }) => MarkerHandle;
    Size: new (width: number, height: number) => unknown;
    marker?: {
      AdvancedMarkerElement: new (options: {
        map: MapHandle;
        position: LatLngLiteral;
        content: HTMLElement;
      }) => MarkerHandle;
    };
  };
};
