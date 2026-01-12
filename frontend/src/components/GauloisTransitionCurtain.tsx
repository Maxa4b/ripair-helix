import { useEffect, useRef, useState } from 'react';

type GauloisTransitionCurtainProps = {
  active: boolean;
  reducedMotion?: boolean;
  durationMs?: number;
  onComplete?: () => void;
};

export default function GauloisTransitionCurtain({
  active,
  reducedMotion = false,
  durationMs = 700,
  onComplete,
}: GauloisTransitionCurtainProps) {
  const [armed, setArmed] = useState(false);
  const armFrameRef = useRef<number | null>(null);

  useEffect(() => {
    if (!active || reducedMotion) {
      if (armFrameRef.current !== null) {
        cancelAnimationFrame(armFrameRef.current);
        armFrameRef.current = null;
      }
      setArmed(false);
      return;
    }
    setArmed(false);
    armFrameRef.current = requestAnimationFrame(() => {
      armFrameRef.current = requestAnimationFrame(() => {
        setArmed(true);
        armFrameRef.current = null;
      });
    });
    return () => {
      if (armFrameRef.current !== null) {
        cancelAnimationFrame(armFrameRef.current);
        armFrameRef.current = null;
      }
    };
  }, [active, reducedMotion]);

  useEffect(() => {
    if (!active) return;
    if (reducedMotion) {
      onComplete?.();
      return;
    }
    const timeout = window.setTimeout(() => {
      onComplete?.();
    }, durationMs);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [active, durationMs, onComplete, reducedMotion]);

  if (!active) {
    return null;
  }

  return (
    <div className="gaulois-transition" data-armed={armed} aria-hidden="true">
      <div className="gaulois-transition__band gaulois-transition__band--blue" />
      <div className="gaulois-transition__band gaulois-transition__band--white" />
      <div className="gaulois-transition__band gaulois-transition__band--red" />
    </div>
  );
}
