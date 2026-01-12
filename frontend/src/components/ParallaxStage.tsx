import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode, CSSProperties, MouseEvent } from 'react';

export type ParallaxLayer = {
  id: string;
  label: string;
  depth: number;
  content?: ReactNode;
};

export type ParallaxStageEvent =
  | { type: 'ready' }
  | { type: 'pointer-move'; x: number; y: number }
  | { type: 'scroll'; progress: number };

export type ParallaxStageProps = {
  layers: ParallaxLayer[];
  onEvent?: (event: ParallaxStageEvent) => void;
  reducedMotion?: boolean;
  className?: string;
  children?: ReactNode;
  height?: number;
  banners?: boolean;
};

export default function ParallaxStage({
  layers,
  onEvent,
  reducedMotion = false,
  className,
  children,
  height = 420,
  banners = true,
}: ParallaxStageProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [scrollProgress, setScrollProgress] = useState(0);
  const [pointer, setPointer] = useState({ x: 0.5, y: 0.5 });
  const pendingPointerRef = useRef(pointer);
  const pointerFrameRef = useRef<number | null>(null);
  const scrollFrameRef = useRef<number | null>(null);

  useEffect(() => {
    onEvent?.({ type: 'ready' });
  }, [onEvent]);

  useEffect(() => {
    if (reducedMotion) {
      return () => undefined;
    }
    const node = containerRef.current;
    if (!node) {
      return () => undefined;
    }
    const compute = () => {
      scrollFrameRef.current = null;
      const rect = node.getBoundingClientRect();
      const viewportHeight = window.innerHeight || 1;
      const top = rect.top;
      const bottom = rect.bottom;
      if (bottom <= 0 || top >= viewportHeight) {
        return;
      }
      const visibility = Math.min(1, Math.max(0, 1 - top / viewportHeight));
      setScrollProgress((previous) => {
        if (Math.abs(previous - visibility) < 0.01) {
          return previous;
        }
        return visibility;
      });
      onEvent?.({ type: 'scroll', progress: visibility });
    };

    const schedule = () => {
      if (scrollFrameRef.current !== null) return;
      scrollFrameRef.current = requestAnimationFrame(compute);
    };

    schedule();
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    return () => {
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
      if (scrollFrameRef.current !== null) {
        cancelAnimationFrame(scrollFrameRef.current);
        scrollFrameRef.current = null;
      }
    };
  }, [onEvent, reducedMotion]);

  const handlePointerMove = useCallback(
    (event: MouseEvent<HTMLDivElement>) => {
      if (reducedMotion) {
        return;
      }
      const rect = event.currentTarget.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      const clampedX = Math.min(1, Math.max(0, x));
      const clampedY = Math.min(1, Math.max(0, y));
      pendingPointerRef.current = { x: clampedX, y: clampedY };
      if (pointerFrameRef.current === null) {
        pointerFrameRef.current = requestAnimationFrame(() => {
          pointerFrameRef.current = null;
          setPointer(pendingPointerRef.current);
        });
      }
      onEvent?.({ type: 'pointer-move', x: clampedX, y: clampedY });
    },
    [onEvent, reducedMotion],
  );

  const handlePointerLeave = useCallback(() => {
    pendingPointerRef.current = { x: 0.5, y: 0.5 };
    if (pointerFrameRef.current === null) {
      pointerFrameRef.current = requestAnimationFrame(() => {
        pointerFrameRef.current = null;
        setPointer(pendingPointerRef.current);
      });
    }
  }, []);

  const sortedLayers = useMemo(() => {
    return [...layers].sort((a, b) => a.depth - b.depth);
  }, [layers]);

  const dynamicLayers = useMemo(() => {
    return sortedLayers.map((layer) => {
      const depthFactor = reducedMotion ? 0 : layer.depth;
      const translateX = (pointer.x - 0.5) * depthFactor * 26; // plus de parallaxe
      const translateY = (pointer.y - 0.5) * depthFactor * 18;
      const scrollOffset = scrollProgress * depthFactor * -28;
      const style: CSSProperties = {
        transform: `translate3d(${translateX.toFixed(2)}px, ${(translateY + scrollOffset).toFixed(
          2,
        )}px, 0)`,
        transition: reducedMotion ? 'none' : 'transform 0.2s ease-out',
      };
      return {
        ...layer,
        style,
      };
    });
  }, [pointer, reducedMotion, scrollProgress, sortedLayers]);

  useEffect(
    () => () => {
      if (pointerFrameRef.current !== null) {
        cancelAnimationFrame(pointerFrameRef.current);
        pointerFrameRef.current = null;
      }
    },
    [],
  );

  useEffect(() => {
    if (reducedMotion) {
      setPointer({ x: 0.5, y: 0.5 });
    }
  }, [reducedMotion]);

  const stageStyle = useMemo(
    () =>
      ({
        minHeight: height,
        '--pointer-offset-x': `${(pointer.x - 0.5) * 60}px`,
        '--pointer-offset-y': `${(pointer.y - 0.5) * 50}px`,
        '--scroll-offset': `${scrollProgress * -80}px`,
      }) as CSSProperties,
    [height, pointer.x, pointer.y, scrollProgress],
  );

  return (
    <div
      ref={containerRef}
      className={className}
      data-reduced-motion={reducedMotion}
      aria-live="polite"
      style={stageStyle}
      onMouseMove={handlePointerMove}
      onMouseLeave={handlePointerLeave}
      onFocus={() => onEvent?.({ type: 'ready' })}
    >
      {banners && !reducedMotion && (
        <svg className="gaulois-parallax__banners" aria-hidden="true" role="presentation">
          <defs>
            <linearGradient id="gaulois-ribbon-blue" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="rgba(11,77,170,0)" />
              <stop offset="35%" stopColor="rgba(11,77,170,0.35)" />
              <stop offset="70%" stopColor="rgba(11,77,170,0.15)" />
              <stop offset="100%" stopColor="rgba(11,77,170,0.05)" />
            </linearGradient>
            <linearGradient id="gaulois-ribbon-red" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="rgba(199,33,47,0)" />
              <stop offset="35%" stopColor="rgba(199,33,47,0.32)" />
              <stop offset="70%" stopColor="rgba(199,33,47,0.14)" />
              <stop offset="100%" stopColor="rgba(199,33,47,0.05)" />
            </linearGradient>
            <linearGradient id="gaulois-ribbon-white" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="rgba(255,255,255,0)" />
              <stop offset="35%" stopColor="rgba(255,255,255,0.45)" />
              <stop offset="70%" stopColor="rgba(255,255,255,0.18)" />
              <stop offset="100%" stopColor="rgba(255,255,255,0.08)" />
            </linearGradient>
          </defs>
          <g className="gaulois-parallax__banner gaulois-parallax__banner--blue">
            <path
              d="M -10 200 C 160 160, 240 260, 420 180 C 580 120, 740 220, 900 180 C 1040 144, 1180 224, 1240 200 L 1240 320 L -10 320 Z"
              fill="url(#gaulois-ribbon-blue)"
            />
          </g>
          <g className="gaulois-parallax__banner gaulois-parallax__banner--white">
            <path
              d="M -40 260 C 120 220, 280 300, 420 240 C 580 180, 720 280, 880 240 C 1040 200, 1180 280, 1260 260 L 1260 360 L -40 360 Z"
              fill="url(#gaulois-ribbon-white)"
            />
          </g>
          <g className="gaulois-parallax__banner gaulois-parallax__banner--red">
            <path
              d="M -20 320 C 160 280, 240 360, 420 300 C 560 252, 720 340, 880 300 C 1020 264, 1160 344, 1240 320 L 1240 420 L -20 420 Z"
              fill="url(#gaulois-ribbon-red)"
            />
          </g>
        </svg>
      )}
      <div className="gaulois-parallax__layers" aria-hidden="true">
        {dynamicLayers.map((layer) => (
          <div
            key={layer.id}
            className="gaulois-parallax__layer"
            data-depth={layer.depth}
            style={layer.style}
          >
            <div className="gaulois-parallax__layer-content">
              <span className="gaulois-parallax__layer-label">{layer.label}</span>
              {layer.content}
            </div>
          </div>
        ))}
      </div>
      <div className="gaulois-parallax__overlay">{children}</div>
    </div>
  );
}
