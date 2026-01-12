import { useEffect, useRef } from 'react';

export type FireworksEvent =
  | { type: 'ready'; canvas: HTMLCanvasElement | null }
  | { type: 'burst'; origin: { x: number; y: number } };

export type FireworksCanvasProps = {
  active: boolean;
  quality?: 'low' | 'medium' | 'high';
  onEvent?: (event: FireworksEvent) => void;
  reducedMotion?: boolean;
  className?: string;
  burstSignal?: { id: number; x?: number; y?: number } | null;
  intensity?: number; // 0..1 — échelle d’énergie (liée au volume)
};

export default function FireworksCanvas({
  active,
  quality = 'medium',
  onEvent,
  reducedMotion = false,
  className,
  burstSignal,
  intensity = 0.7,
}: FireworksCanvasProps) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const particlesRef = useRef<Particle[]>([]);
  const frameRef = useRef<number | null>(null);
  const spawnRef = useRef<(x?: number, y?: number) => void>(() => undefined);
  const lastTimeRef = useRef<number>(0);
  const visibleRef = useRef<boolean>(true);

  useEffect(() => {
    if (!active || reducedMotion) {
      if (frameRef.current) {
        cancelAnimationFrame(frameRef.current);
      }
      return;
    }
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const clamp = (v: number, min: number, max: number) => Math.max(min, Math.min(max, v));
    const energy = clamp(intensity, 0.2, 1);
    const baseCountBase = quality === 'high' ? 180 : quality === 'medium' ? 120 : 70;
    const baseCount = Math.round(baseCountBase * energy);
    const maxParticles = (quality === 'high' ? 1200 : quality === 'medium' ? 800 : 500) * energy;
    const devicePixelRatio = window.devicePixelRatio || 1;

    const resize = () => {
      canvas.width = canvas.offsetWidth * devicePixelRatio;
      canvas.height = canvas.offsetHeight * devicePixelRatio;
    };

    resize();

    // Palette dynamique depuis CSS vars si disponibles
    const toRGB = (css: string | null | undefined): string | null => {
      if (!css) return null;
      const value = css.trim();
      const hex = value.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
      if (hex) {
        let h = hex[1];
        if (h.length === 3) h = h.split('').map((c) => c + c).join('');
        const r = parseInt(h.slice(0, 2), 16);
        const g = parseInt(h.slice(2, 4), 16);
        const b = parseInt(h.slice(4, 6), 16);
        return `${r},${g},${b}`;
      }
      const rgb = value.match(/^rgba?\(([^)]+)\)/i);
      if (rgb) {
        const parts = rgb[1].split(',').map((x) => x.trim());
        const r = parseFloat(parts[0]);
        const g = parseFloat(parts[1]);
        const b = parseFloat(parts[2]);
        if ([r, g, b].every((n) => Number.isFinite(n))) {
          return `${Math.round(r)},${Math.round(g)},${Math.round(b)}`;
        }
      }
      return null;
    };

    const computed = getComputedStyle(canvas);
    const accent = toRGB(computed.getPropertyValue('--gaulois-accent')) || '199,33,47';
    const fore = toRGB(computed.getPropertyValue('--gaulois-foreground')) || '255,255,255';
    const blue = '11,77,170';
    const palette = [accent, fore, blue];

    const spawnBurst = (x?: number, y?: number) => {
      const originX = typeof x === 'number' ? x : Math.random() * canvas.width;
      const originY =
        typeof y === 'number' ? y : canvas.height * (0.3 + Math.random() * 0.4);
      for (let i = 0; i < baseCount; i++) {
        if (particlesRef.current.length >= maxParticles) break;
        particlesRef.current.push(createParticle(originX, originY, devicePixelRatio, palette));
      }
    };

    spawnRef.current = spawnBurst;

    const render = () => {
      if (!ctx || !canvas) return;
      if (!visibleRef.current) {
        frameRef.current = requestAnimationFrame(render);
        return;
      }
      const now = performance.now();
      const last = lastTimeRef.current || now;
      lastTimeRef.current = now;
      const dt = Math.min(0.033, (now - last) / 1000);

      ctx.globalCompositeOperation = 'destination-out';
      ctx.fillStyle = 'rgba(0, 0, 0, 0.18)';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.globalCompositeOperation = 'lighter';

      particlesRef.current = particlesRef.current.filter((particle) => {
        particle.life -= dt * particle.decay;
        if (particle.life <= 0) {
          return false;
        }
        particle.vy += particle.gravity * dt * 60;
        particle.x += particle.vx * dt * 60;
        particle.y += particle.vy * dt * 60;

        ctx.beginPath();
        ctx.fillStyle = `rgba(${particle.color}, ${particle.life})`;
        ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
        ctx.fill();

        return true;
      });

      if (particlesRef.current.length < baseCount / 3) {
        spawnBurst();
      }

      frameRef.current = requestAnimationFrame(render);
    };

    frameRef.current = requestAnimationFrame(render);

    const observer = new ResizeObserver(() => {
      resize();
    });
    observer.observe(canvas);

    // Pause le rendu si le canvas n'est pas visible
    let intersection: IntersectionObserver | null = null;
    if ('IntersectionObserver' in window) {
      intersection = new IntersectionObserver(
        (entries) => {
          const entry = entries[0];
          const isVisible = !!entry?.isIntersecting && entry.intersectionRatio > 0.05;
          visibleRef.current = isVisible;
          if (!isVisible && frameRef.current) {
            cancelAnimationFrame(frameRef.current);
            frameRef.current = null;
          } else if (isVisible && frameRef.current === null) {
            frameRef.current = requestAnimationFrame(render);
          }
        },
        { threshold: [0, 0.05, 0.1, 0.25] },
      );
      intersection.observe(canvas);
    }

    const handleVis = () => {
      const hidden = document.visibilityState === 'hidden';
      visibleRef.current = !hidden;
      if (hidden && frameRef.current) {
        cancelAnimationFrame(frameRef.current);
        frameRef.current = null;
      } else if (!hidden && frameRef.current === null) {
        frameRef.current = requestAnimationFrame(render);
      }
    };
    document.addEventListener('visibilitychange', handleVis);

    const handleBurst = (event: MouseEvent) => {
      const rect = canvas.getBoundingClientRect();
      const x = (event.clientX - rect.left) * devicePixelRatio;
      const y = (event.clientY - rect.top) * devicePixelRatio;
      spawnBurst(x, y);
      onEvent?.({ type: 'burst', origin: { x, y } });
    };

    canvas.addEventListener('click', handleBurst);

    return () => {
      observer.disconnect();
      if (intersection) intersection.disconnect();
      document.removeEventListener('visibilitychange', handleVis);
      canvas.removeEventListener('click', handleBurst);
      if (frameRef.current) {
        cancelAnimationFrame(frameRef.current);
      }
      frameRef.current = null;
      particlesRef.current = [];
      spawnRef.current = () => undefined;
    };
  }, [active, quality, reducedMotion, onEvent]);

  useEffect(() => {
    if (!active || reducedMotion) {
      return;
    }
    if (burstSignal && spawnRef.current) {
      spawnRef.current(burstSignal.x, burstSignal.y);
    }
  }, [active, burstSignal, reducedMotion]);

  useEffect(() => {
    if (canvasRef.current) {
      onEvent?.({ type: 'ready', canvas: canvasRef.current });
    }
  }, [onEvent]);

  return (
    <canvas
      ref={canvasRef}
      className={className}
      role="img"
      aria-label="Animation feu d’artifice Gaulois"
      data-active={active}
      data-quality={quality}
      data-reduced-motion={reducedMotion}
      tabIndex={0}
      onKeyDown={(e) => {
        if (reducedMotion) return;
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          const el = canvasRef.current;
          if (!el) return;
          const x = (el.width || 0) / 2;
          const y = (el.height || 0) / 2;
          if (typeof spawnRef.current === 'function') {
            spawnRef.current(x, y);
            onEvent?.({ type: 'burst', origin: { x, y } });
          }
        }
      }}
    />
  );
}

type Particle = {
  x: number;
  y: number;
  vx: number;
  vy: number;
  gravity: number;
  life: number;
  size: number;
  color: string;
  decay: number;
};

function createParticle(x: number, y: number, devicePixelRatio: number, palette: string[]): Particle {
  const angle = Math.random() * Math.PI * 2;
  const speed = Math.random() * 4 + 1;
  const vx = Math.cos(angle) * speed * devicePixelRatio * 0.8;
  const vy = Math.sin(angle) * speed * devicePixelRatio * 0.8;
  const color = palette[Math.floor(Math.random() * palette.length)] || '255,255,255';
  const decay = 0.6 + Math.random() * 0.6; // 0.6..1.2 — durée de vie variable

  return {
    x,
    y,
    vx,
    vy,
    gravity: 0.05 * devicePixelRatio,
    life: 1,
    size: Math.random() * 2 * devicePixelRatio + 1,
    color,
    decay,
  };
}
