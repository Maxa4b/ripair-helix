import { useEffect, useRef } from 'react';

type Props = {
  reducedMotion?: boolean;
};

export default function FrenchRibbonsCanvas({ reducedMotion = false }: Props) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    if (reducedMotion) return;
    const canvas = canvasRef.current;
    if (!canvas) return;

    const context = canvas.getContext('2d');
    if (!context) return;

    const resize = () => {
      const dpr = window.devicePixelRatio || 1;
      canvas.width = Math.floor(window.innerWidth * dpr);
      canvas.height = Math.floor(window.innerHeight * dpr);
      canvas.style.width = '100%';
      canvas.style.height = '100%';
      context.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    resize();
    window.addEventListener('resize', resize);

    let raf = 0;
    const start = performance.now();
    const draw = (now: number) => {
      const t = (now - start) / 1000;
      context.clearRect(0, 0, window.innerWidth, window.innerHeight);

      const colors = ['rgba(29,78,216,0.18)', 'rgba(255,255,255,0.12)', 'rgba(220,38,38,0.18)'];
      const stripeWidth = Math.max(280, Math.floor(window.innerWidth / 3));

      for (let i = 0; i < 3; i += 1) {
        context.save();
        context.fillStyle = colors[i];
        const offset = Math.sin(t * 0.6 + i) * 24;
        context.translate(i * stripeWidth + offset, 0);
        context.beginPath();
        context.moveTo(0, 0);
        context.lineTo(stripeWidth, 0);
        context.lineTo(stripeWidth - 120, window.innerHeight);
        context.lineTo(-120, window.innerHeight);
        context.closePath();
        context.fill();
        context.restore();
      }

      raf = window.requestAnimationFrame(draw);
    };

    raf = window.requestAnimationFrame(draw);
    return () => {
      window.cancelAnimationFrame(raf);
      window.removeEventListener('resize', resize);
    };
  }, [reducedMotion]);

  return (
    <canvas
      ref={canvasRef}
      aria-hidden="true"
      style={{
        position: 'fixed',
        inset: 0,
        width: '100%',
        height: '100%',
        pointerEvents: 'none',
        zIndex: 0,
        opacity: reducedMotion ? 0 : 1,
      }}
    />
  );
}

