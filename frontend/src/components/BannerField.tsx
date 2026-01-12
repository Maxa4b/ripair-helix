import { useCallback, useEffect, useRef } from 'react';

export type Banner = {
  id: string;
  color: string;
  width: number;
  amplitude: number;
  length: number;
  speed: number;
};

export type BannerFieldProps = {
  density?: number;
  palette?: string[];
  reducedMotion?: boolean;
  className?: string;
};

type BannerState = Banner & {
  progress: number;
  offset: number;
};

const DEFAULT_PALETTE = ['#0B4DAA', '#ffffff', '#C7212F'];

const rand = (min: number, max: number) => Math.random() * (max - min) + min;

function createBanner(palette: string[]): BannerState {
  const color = palette[Math.floor(Math.random() * palette.length)];
  const id =
    typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : Math.random().toString(36).slice(2);
  return {
    id,
    color,
    width: rand(60, 140),
    amplitude: rand(14, 36),
    length: rand(180, 260),
    speed: rand(0.4, 1.1),
    progress: rand(0, 1),
    offset: rand(-Math.PI, Math.PI),
  };
}

export default function BannerField({
  density = 6,
  palette = DEFAULT_PALETTE,
  reducedMotion = false,
  className,
}: BannerFieldProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const bannersRef = useRef<BannerState[]>([]);
  const frameRef = useRef<number | null>(null);
  const lastTimeRef = useRef<number | null>(null);

  const resetBanners = useCallback(() => {
    bannersRef.current = Array.from({ length: density }, () => createBanner(palette));
  }, [density, palette]);

  useEffect(() => {
    if (reducedMotion) {
      cancelAnimationFrame(frameRef.current ?? 0);
      frameRef.current = null;
      return;
    }
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const resize = () => {
      canvas.width = canvas.offsetWidth * window.devicePixelRatio;
      canvas.height = canvas.offsetHeight * window.devicePixelRatio;
    };

    resize();
    resetBanners();

    const render = (time: number) => {
      if (!ctx || !canvas) return;
      if (lastTimeRef.current === null) {
        lastTimeRef.current = time;
      }
      const delta = Math.min(32, time - lastTimeRef.current);
      lastTimeRef.current = time;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.save();
      ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
      ctx.globalAlpha = 0.5;

      bannersRef.current.forEach((banner) => {
        const width = canvas.offsetWidth;
        const height = canvas.offsetHeight;
        const progress = (banner.progress + (banner.speed * delta) / 18000) % 1;
        banner.progress = progress;

        const startX = progress * (width + banner.length) - banner.length;
        const centerY = height * 0.5;

        ctx.beginPath();
        ctx.moveTo(startX, centerY);
        const segments = 6;
        for (let i = 0; i <= segments; i++) {
          const t = i / segments;
          const x = startX + t * banner.length;
          const wave = Math.sin(t * Math.PI * 2 + banner.offset + time * 0.0015);
          const y = centerY + wave * banner.amplitude;
          const offset = (wave * banner.width) / 2;
          if (i === 0) {
            ctx.moveTo(x, y - offset);
          } else {
            ctx.lineTo(x, y - offset);
          }
        }
        for (let i = segments; i >= 0; i--) {
          const t = i / segments;
          const x = startX + t * banner.length;
          const wave = Math.sin(t * Math.PI * 2 + banner.offset + time * 0.0015);
          const y = centerY + wave * banner.amplitude;
          const offset = (wave * banner.width) / 2;
          ctx.lineTo(x, y + offset);
        }
        ctx.closePath();
        ctx.fillStyle = banner.color;
        ctx.fill();
      });

      ctx.restore();
      frameRef.current = requestAnimationFrame(render);
    };

    frameRef.current = requestAnimationFrame(render);

    const observer = new ResizeObserver(() => {
      resize();
      resetBanners();
    });
    observer.observe(canvas);

    return () => {
      observer.disconnect();
      if (frameRef.current) {
        cancelAnimationFrame(frameRef.current);
      }
      frameRef.current = null;
      lastTimeRef.current = null;
    };
  }, [density, palette, reducedMotion, resetBanners]);

  useEffect(() => {
    if (reducedMotion) {
      bannersRef.current = [];
    }
  }, [reducedMotion]);

  return (
    <canvas
      ref={canvasRef}
      className={className}
      role="img"
      aria-label="Banderoles flottantes Gaulois"
      data-reduced-motion={reducedMotion}
    />
  );
}
