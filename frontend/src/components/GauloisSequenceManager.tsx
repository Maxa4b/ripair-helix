import type { ReactNode } from 'react';
import { useEffect } from 'react';

type Props = {
  children: ReactNode;
  autoStart?: boolean;
  reducedMotion?: boolean;
  onSequenceComplete?: () => void;
};

export default function GauloisSequenceManager({
  children,
  autoStart = true,
  reducedMotion = false,
  onSequenceComplete,
}: Props) {
  useEffect(() => {
    if (!autoStart) return;
    if (reducedMotion) {
      onSequenceComplete?.();
      return;
    }
    const handle = window.setTimeout(() => onSequenceComplete?.(), 650);
    return () => window.clearTimeout(handle);
  }, [autoStart, reducedMotion, onSequenceComplete]);

  return <>{children}</>;
}

