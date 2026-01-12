export type VolumeTheme = {
  accent: string;
  background: string;
  foreground: string;
};

export type VolumeBand = 'low' | 'medium' | 'high';

export type VolumeThemeMap = Record<VolumeBand, VolumeTheme>;

export const DEFAULT_VOLUME_THEME: VolumeThemeMap = {
  low: {
    accent: '#0B4DAA',
    background: '#041E42',
    foreground: '#FFFFFF',
  },
  medium: {
    accent: '#F4F4F4',
    background: '#FFFFFF',
    foreground: '#041E42',
  },
  high: {
    accent: '#C7212F',
    background: '#A50F1B',
    foreground: '#FFFFFF',
  },
};

export const resolveVolumeBand = (volume: number): VolumeBand => {
  if (volume >= 66) {
    return 'high';
  }
  if (volume >= 33) {
    return 'medium';
  }
  return 'low';
};

