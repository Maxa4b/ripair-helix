import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export type AudioControllerState = {
  volume: number;
  muted: boolean;
  canAutoplay: boolean;
  activating: boolean;
  activationError: string | null;
};

export type AudioController = AudioControllerState & {
  setVolume: (value: number) => void;
  toggleMute: () => void;
  setMuted: (muted: boolean) => void;
  activate: () => Promise<boolean>;
};

const clampVolume = (value: number) => Math.min(100, Math.max(0, Math.round(value)));
const AUDIO_SRC = '/audio/marseillaise.mp3';
const DEFAULT_TRANSITION = 0.08;

type AudioRefs = {
  element: HTMLAudioElement | null;
  context: AudioContext | null;
  sourceNode: MediaElementAudioSourceNode | null;
  gainNode: GainNode | null;
};

const getAudioContextClass = (): typeof AudioContext | null => {
  if (typeof window === 'undefined') {
    return null;
  }
  return (
    window.AudioContext ||
    (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext ||
    null
  );
};

export function useAudioController(initialVolume = 80, autoplayEnabled = false): AudioController {
  const initialVolumeClamped = clampVolume(initialVolume);
  const initialMuted = autoplayEnabled ? initialVolumeClamped === 0 : true;
  const [state, setState] = useState<AudioControllerState>(() => ({
    volume: initialVolumeClamped,
    muted: initialMuted,
    canAutoplay: autoplayEnabled,
    activating: false,
    activationError: null,
  }));
  const refs = useRef<AudioRefs>({
    element: null,
    context: null,
    sourceNode: null,
    gainNode: null,
  });
  const volumeRef = useRef(state.volume);
  const mutedRef = useRef(state.muted);

  const applyGain = useCallback((volume: number, muted: boolean) => {
    const { element, gainNode, context } = refs.current;
    const value = muted ? 0 : volume / 100;
    if (gainNode && context) {
      gainNode.gain.setTargetAtTime(value, context.currentTime, DEFAULT_TRANSITION);
    } else if (element) {
      element.volume = value;
      element.muted = muted;
    }
  }, []);

  const ensureSetup = useCallback(() => {
    if (typeof window === 'undefined') {
      return null;
    }
    const { element, context, sourceNode } = refs.current;
    if (!element) {
      const audio = new Audio(AUDIO_SRC);
      audio.loop = true;
      audio.preload = 'auto';
      audio.crossOrigin = 'anonymous';
      audio.muted = true;
      refs.current.element = audio;
    }

    if (!context) {
      const AudioContextClass = getAudioContextClass();
      if (AudioContextClass) {
        refs.current.context = new AudioContextClass();
      }
    }

    if (!sourceNode && refs.current.element && refs.current.context) {
      try {
        const createdSource = refs.current.context.createMediaElementSource(refs.current.element);
        const gain = refs.current.context.createGain();
        createdSource.connect(gain);
        gain.connect(refs.current.context.destination);
        refs.current.sourceNode = createdSource;
        refs.current.gainNode = gain;
        applyGain(volumeRef.current, mutedRef.current);
      } catch (error) {
        console.warn('Impossible de relier l’audio à AudioContext', error);
        refs.current.sourceNode = null;
        refs.current.gainNode = null;
      }
    }

    return refs.current.element;
  }, [applyGain]);

  const setVolume = useCallback((next: number) => {
    ensureSetup();
    setState((prev) => {
      const volume = clampVolume(next);
      const nextMuted = volume === 0 ? true : prev.muted && prev.volume === volume;
      volumeRef.current = volume;
      mutedRef.current = nextMuted;
      applyGain(volume, nextMuted);
      return {
        ...prev,
        volume,
        muted: nextMuted,
      };
    });
  }, [applyGain, ensureSetup]);

  const setMuted = useCallback((muted: boolean) => {
    ensureSetup();
    setState((prev) => {
      mutedRef.current = muted;
      applyGain(prev.volume, muted);
      if (refs.current.element) {
        refs.current.element.muted = muted;
      }
      return {
        ...prev,
        muted,
      };
    });
  }, [applyGain, ensureSetup]);

  const toggleMute = useCallback(() => {
    setMuted(!mutedRef.current);
  }, [setMuted]);

  const activate = useCallback(async () => {
    const element = ensureSetup();
    if (!element) {
      return false;
    }

    setState((prev) => ({
      ...prev,
      activating: true,
      activationError: null,
    }));

    try {
      if (refs.current.context && refs.current.context.state === 'suspended') {
        await refs.current.context.resume();
      }
      element.muted = false;

      const playResult = element.play();
      if (playResult instanceof Promise) {
        await playResult;
      }

      const shouldMute = volumeRef.current === 0;
      mutedRef.current = shouldMute;
      applyGain(volumeRef.current, shouldMute);

      setState((prev) => ({
        ...prev,
        activating: false,
        activationError: null,
        canAutoplay: true,
        muted: shouldMute,
      }));

      return true;
    } catch (error) {
      console.warn('Activation audio impossible', error);
      const message =
        error instanceof Error ? error.message : 'Activation audio impossible pour Gaulois.';
      setState((prev) => ({
        ...prev,
        activating: false,
        activationError: message,
        canAutoplay: false,
      }));
      return false;
    }
  }, [applyGain, ensureSetup]);

  useEffect(() => {
    volumeRef.current = state.volume;
  }, [state.volume]);

  useEffect(() => {
    mutedRef.current = state.muted;
  }, [state.muted]);

  useEffect(() => {
    ensureSetup();
    return () => {
      const { sourceNode, gainNode, context, element } = refs.current;
      try {
        sourceNode?.disconnect();
      } catch (error) {
        console.warn('Erreur lors de la déconnection du MediaElementSource', error);
      }
      try {
        gainNode?.disconnect();
      } catch (error) {
        console.warn('Erreur lors de la déconnection du GainNode', error);
      }
      if (element) {
        element.pause();
        element.src = '';
      }
      if (context) {
        context.close().catch((error) => console.warn('Fermeture AudioContext échouée', error));
      }
      refs.current = {
        element: null,
        context: null,
        sourceNode: null,
        gainNode: null,
      };
    };
  }, [ensureSetup]);

  return useMemo(
    () => ({
      volume: state.volume,
      muted: state.muted,
      canAutoplay: state.canAutoplay,
      activating: state.activating,
      activationError: state.activationError,
      setVolume,
      toggleMute,
      setMuted,
      activate,
    }),
    [
      activate,
      setMuted,
      setVolume,
      state.activationError,
      state.activating,
      state.canAutoplay,
      state.muted,
      state.volume,
      toggleMute,
    ],
  );
}
