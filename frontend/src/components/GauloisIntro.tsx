import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FC, ReactNode } from 'react';

export type GauloisIntroEvent =
  | { type: 'ready' }
  | { type: 'skipped' }
  | { type: 'completed' };

export type GauloisIntroProps = {
  onEvent?: (event: GauloisIntroEvent) => void;
  reducedMotion?: boolean;
  className?: string;
  durationMs?: number;
};

const DEFAULT_INTRO_DURATION_MS = 1200;

type IntroStatus = 'idle' | 'running' | 'done';

const GauloisIntro: FC<GauloisIntroProps> = ({
  onEvent,
  reducedMotion = false,
  className,
  durationMs = DEFAULT_INTRO_DURATION_MS,
}) => {
  const [status, setStatus] = useState<IntroStatus>('idle');
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const completedRef = useRef(false);

  useEffect(() => {
    onEvent?.({ type: 'ready' });
  }, [onEvent]);

  const clearTimer = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const finish = useCallback(
    (origin: 'auto' | 'skip' | 'cta') => {
      clearTimer();
      setStatus('done');
      if (origin === 'skip') {
        onEvent?.({ type: 'skipped' });
      }
      if (!completedRef.current) {
        completedRef.current = true;
        onEvent?.({ type: 'completed' });
      }
    },
    [clearTimer, onEvent],
  );

  useEffect(() => {
    if (completedRef.current) {
      return () => clearTimer();
    }

    if (reducedMotion) {
      finish('auto');
      return () => clearTimer();
    }

    setStatus('running');
    timerRef.current = setTimeout(() => finish('auto'), durationMs);

    return () => clearTimer();
  }, [clearTimer, durationMs, finish, reducedMotion]);

  // Étape 1: squelette — pas d’interactions CTA/skip, l’intro se complète seule

  const rootClassName = useMemo(() => {
    return ['gaulois-intro', className].filter(Boolean).join(' ');
  }, [className]);

  const headline: ReactNode = useMemo(() => 'Immersion tricolore', []);

  return (
    <section
      className={rootClassName}
      aria-label="Séquence d’introduction Gaulois"
      data-status={status}
      data-reduced-motion={reducedMotion}
    >
      <div className="gaulois-intro__backdrop" aria-hidden="true" />
      <div className="gaulois-intro__content" aria-live="polite">
        <div className="gaulois-intro__logo-wrapper">
          <svg
            className="gaulois-intro__logo"
            viewBox="0 0 220 110"
            role="img"
            aria-labelledby="gaulois-intro-logo-title"
          >
            <title id="gaulois-intro-logo-title">Identité Gaulois</title>
            <g className="gaulois-intro__logo-strokes" aria-hidden="true">
              <path
                className="gaulois-intro__stroke gaulois-intro__stroke--blue"
                d="M24 18 L86 12 L78 96 L18 90 Z"
              />
              <path
                className="gaulois-intro__stroke gaulois-intro__stroke--white"
                d="M86 12 L132 18 L124 90 L78 96 Z"
              />
              <path
                className="gaulois-intro__stroke gaulois-intro__stroke--red"
                d="M132 18 L198 26 L174 94 L124 90 Z"
              />
            </g>
            <text className="gaulois-intro__logo-text" x="110" y="78" textAnchor="middle">
              Gaulois
            </text>
          </svg>
        </div>
        <p className="gaulois-intro__headline">{headline}</p>
        <p className="gaulois-intro__subline">Les systèmes se synchronisent à la Marseillaise…</p>
      </div>
      {/* Étape 1: pas d’actions utilisateurs pour l’intro */}
    </section>
  );
};

export default GauloisIntro;
