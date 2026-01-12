import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import {
  useCreateGauloisSeed,
  useGauloisLogContent,
  useGauloisLogs,
  useGauloisMeta,
  useGauloisRun,
} from '../../hooks/useGaulois';

type RunFormState = {
  seed: string;
  dryRun: boolean;
  log: boolean;
};

type SeedFormState = {
  filename: string;
  brand: string;
  model: string;
  targets: string;
  suppliersText: string;
};

const INITIAL_RUN_STATE: RunFormState = {
  seed: '',
  dryRun: true,
  log: true,
};

const INITIAL_SEED_STATE: SeedFormState = {
  filename: '',
  brand: '',
  model: '',
  targets: '',
  suppliersText: '',
};

type ConsoleEntry = {
  id: string;
  label: string;
  lines: string[];
  when: string;
  kind: 'info' | 'error';
};

const TARGET_TEMPLATES = [
  'screen',
  'display',
  'lcd',
  'oled',
  'amoled',
  'touchscreen',
  'touch screen',
  'digitizer',
  'display assembly',
  'screen assembly',
  'super amoled',
  'hard oled',
  'incell',
  'in-cell',
  'tft',
  'ips',
  'back glass',
  'rear glass',
  'back cover glass',
  'back panel glass',
  'back cover',
  'battery cover',
  'rear housing cover',
  'back shell',
  'frame',
  'midframe',
  'housing',
  'middle frame',
  'mid frame',
  'metal frame',
  'chassis',
  'rear camera',
  'back camera',
  'main camera',
  'primary camera',
  'front camera',
  'selfie camera',
  'front facing camera',
  'ultra wide camera',
  'ultrawide camera',
  'telephoto camera',
  'zoom camera',
  'macro camera',
  'camera glass',
  'camera lens glass',
  'camera lens cover',
  'camera cover glass',
  'rear camera glass',
  'camera ring',
  'face id',
  'truedepth',
  'true depth camera',
  'touch id',
  'fingerprint sensor',
  'fingerprint reader',
  'home button with touch id',
  'earpiece',
  'ear speaker',
  'top speaker',
  'receiver speaker',
  'earpiece speaker',
  'loudspeaker',
  'bottom speaker',
  'main speaker',
  'speaker module',
  'ringer buzzer',
  'microphone',
  'mic',
  'main mic',
  'bottom mic',
  'top mic',
  'secondary mic',
  'noise cancelling mic',
  'ambient mic',
  'vibration motor',
  'vibrator',
  'taptic engine',
  'sim tray',
  'sim card tray',
  'sim holder',
  'sim slot',
  'sim reader',
  'sim socket',
  'sim connector',
  'sd slot',
  'sd card slot',
  'micro sd slot',
  'card reader',
  'battery',
  'replacement battery',
  'internal battery',
  'battery pack',
  'battery flex',
  'battery connector',
  'battery cable',
  'battery adhesive',
  'battery sticker',
  'charging port',
  'charge port',
  'usb charging port',
  'usb c port',
  'usb-c port',
  'lightning port',
  'micro usb port',
  'dock connector',
  'sub board',
  'charge board',
  'charging board',
  'usb board',
  'dock flex',
  'wireless charging',
  'wireless charging coil',
  'qi coil',
  'wireless coil',
  'magsafe coil',
  'wireless charging module',
  'nfc antenna',
  'nfc coil',
  'nfc module',
  'cellular antenna',
  'main antenna',
  'network antenna',
  'wifi antenna',
  'wi-fi antenna',
  'wlan antenna',
  'bluetooth antenna',
  'bt antenna',
  'gps antenna',
  'proximity sensor',
  'prox sensor',
  'ambient light sensor',
  'als sensor',
  'hall sensor',
  'gyro',
  'accelerometer',
  'magnetometer',
  'power button',
  'power key',
  'side button',
  'lock button',
  'volume button',
  'volume up button',
  'volume down button',
  'volume key',
  'mute switch',
  'silent switch',
  'ringer switch',
  'home button',
  'home key',
  'audio jack',
  'headphone jack',
  '3.5mm jack',
  'motherboard',
  'logic board',
  'mainboard',
  'power ic',
  'pmic',
  'power management ic',
  'audio ic',
  'codec ic',
  'charge ic',
  'charging ic',
  'flex cable',
  'flex',
  'ribbon cable',
  'screw set',
  'screws kit',
  'adhesive',
  'tape',
  'glue',
  'precut adhesive',
  'frame adhesive',
  'gasket',
  'seal',
  'waterproof seal',
  'dust seal',
];

const SEED_FORM_STORAGE_KEY = 'gaulois-seed-form';
const CONSOLE_STORAGE_KEY = 'gaulois-console';

const parseTargetsValue = (value: string) =>
  value
    .split(',')
    .map((target) => target.trim())
    .filter(Boolean);

const isUrl = (value: string) => /^https?:\/\//i.test(value);

const getSupplierKeyFromUrl = (value: string) => {
  try {
    const hostname = new URL(value).hostname.replace(/^www\./i, '');
    const parts = hostname.split('.').filter(Boolean);
    if (parts.length >= 2) {
      return parts[parts.length - 2];
    }
    return hostname || 'supplier';
  } catch {
    return 'supplier';
  }
};

const buildSuppliersFromText = (text: string) => {
  const grouped = new Map<string, string[]>();

  const addUrl = (key: string, url: string) => {
    if (!key || !url) return;
    const current = grouped.get(key) ?? [];
    if (!current.includes(url)) current.push(url);
    grouped.set(key, current);
  };

  text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .forEach((line) => {
      const parts = line.split(/\s+/).filter(Boolean);
      if (!parts.length) return;

      const firstIsUrl = isUrl(parts[0]);
      if (firstIsUrl) {
        parts.filter(isUrl).forEach((url) => addUrl(getSupplierKeyFromUrl(url), url));
        return;
      }

      const [key, ...urls] = parts;
      urls.filter(isUrl).forEach((url) => addUrl(key, url));
    });

  return Array.from(grouped, ([key, urls]) => ({
    key,
    urls,
  }));
};

const sanitizeSeedState = (value: Partial<SeedFormState>): SeedFormState => ({
  filename: typeof value.filename === 'string' ? value.filename : '',
  brand: typeof value.brand === 'string' ? value.brand : '',
  model: typeof value.model === 'string' ? value.model : '',
  targets: typeof value.targets === 'string' ? value.targets : '',
  suppliersText: typeof value.suppliersText === 'string' ? value.suppliersText : '',
});

export default function GauloisControlPanel() {
  const { data: meta } = useGauloisMeta();
  const { data: logs } = useGauloisLogs();
  const [selectedLog, setSelectedLog] = useState<string | null>(null);
  const { data: logContent } = useGauloisLogContent(selectedLog);
  const runMutation = useGauloisRun();
  const seedMutation = useCreateGauloisSeed();

  const [runState, setRunState] = useState<RunFormState>(INITIAL_RUN_STATE);
  const [seedState, setSeedState] = useState<SeedFormState>(INITIAL_SEED_STATE);
  const [targetTemplate, setTargetTemplate] = useState('');
  const [consoleEntries, setConsoleEntries] = useState<ConsoleEntry[]>([]);

  const runErrorMessage = useMemo(
    () => (runMutation.error instanceof Error ? runMutation.error.message : undefined),
    [runMutation.error],
  );
  const seedErrorMessage = useMemo(
    () => (seedMutation.error instanceof Error ? seedMutation.error.message : undefined),
    [seedMutation.error],
  );

  const availableSeeds = useMemo(
    () => meta?.seeds?.map((seed) => seed.filename) ?? [],
    [meta?.seeds],
  );

  useEffect(() => {
    try {
      const stored = localStorage.getItem(SEED_FORM_STORAGE_KEY);
      if (!stored) return;
      const parsed = JSON.parse(stored) as Partial<SeedFormState>;
      setSeedState(sanitizeSeedState(parsed ?? {}));
    } catch {
      // ignore storage issues
    }
  }, []);

  useEffect(() => {
    try {
      const stored = localStorage.getItem(CONSOLE_STORAGE_KEY);
      if (!stored) return;
      const parsed = JSON.parse(stored) as ConsoleEntry[];
      if (Array.isArray(parsed)) {
        setConsoleEntries(parsed.slice(0, 30));
      }
    } catch {
      // ignore storage issues
    }
  }, []);

  useEffect(() => {
    try {
      localStorage.setItem(SEED_FORM_STORAGE_KEY, JSON.stringify(seedState));
    } catch {
      // ignore storage issues
    }
  }, [seedState]);

  useEffect(() => {
    try {
      localStorage.setItem(CONSOLE_STORAGE_KEY, JSON.stringify(consoleEntries.slice(0, 30)));
    } catch {
      // ignore storage issues
    }
  }, [consoleEntries]);

  const pushConsoleEntry = useCallback((entry: ConsoleEntry) => {
    setConsoleEntries((prev) => [entry, ...prev].slice(0, 30));
  }, []);

  const handleRunSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!runState.seed) return;

    const payload = {
      seed: runState.seed,
      dry_run: runState.dryRun,
      log: runState.log,
    };

    const startedAt = new Date();

    runMutation.mutate(payload, {
      onSuccess: (data) => {
        const merged = [...(data.error_output || []), ...(data.output || [])].filter(Boolean);
        pushConsoleEntry({
          id: `run-${data.job_id}-${Date.now()}`,
          label: `Run ${data.job_id} (code ${data.exit_code ?? 'n/a'})`,
          lines: merged.length ? merged : ['(aucun message)'],
          when: startedAt.toLocaleString('fr-FR'),
          kind: data.exit_code === 0 ? 'info' : 'error',
        });
      },
      onError: (error: unknown) => {
        const message =
          (error as any)?.response?.data?.message ||
          (error as any)?.message ||
          'Erreur inconnue lors du lancement';
        pushConsoleEntry({
          id: `run-error-${Date.now()}`,
          label: 'Run interrompue',
          lines: [String(message)],
          when: startedAt.toLocaleString('fr-FR'),
          kind: 'error',
        });
      },
    });
  };

  const handleSeedSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const suppliersEntries = buildSuppliersFromText(seedState.suppliersText);
    const targets = parseTargetsValue(seedState.targets);

    seedMutation.mutate({
      filename: seedState.filename || undefined,
      brand: seedState.brand,
      model: seedState.model,
      targets: targets.length > 0 ? targets : undefined,
      suppliers: suppliersEntries.map((entry) => ({
        key: entry.key,
        urls: entry.urls,
      })),
    });
  };

  const handleTargetTemplateChange = (event: ChangeEvent<HTMLSelectElement>) => {
    const value = event.target.value;
    if (!value) return;

    setSeedState((current) => {
      const existing = parseTargetsValue(current.targets);
      const exists = existing.some((item) => item.toLowerCase() === value.toLowerCase());
      if (exists) return current;
      return {
        ...current,
        targets: [...existing, value].join(', '),
      };
    });

    setTargetTemplate('');
  };

  return (
    <div className="gaulois-ops">
      <div className="gaulois-ops__grid">
        <section className="gaulois-ops__tile gaulois-ops__tile--wide gaulois-surface">
          <header className="gaulois-ops__header">
            <div>
              <h3>Configuration serveur</h3>
              <p>Chemins et limites côté Python</p>
            </div>
          </header>
          {meta ? (
            <dl className="gaulois-ops__meta">
              <div>
                <dt>Python</dt>
                <dd>{meta.config.python}</dd>
              </div>
              <div>
                <dt>Script</dt>
                <dd>{meta.config.script_path}</dd>
              </div>
              <div>
                <dt>Repertoire</dt>
                <dd>{meta.config.working_directory ?? '-'}</dd>
              </div>
              <div>
                <dt>Timeout</dt>
                <dd>{meta.config.timeout}s</dd>
              </div>
            </dl>
          ) : (
            <p className="gaulois-message">Lecture configuration…</p>
          )}
        </section>

        <section className="gaulois-ops__tile gaulois-surface">
          <header className="gaulois-ops__header">
            <div>
              <h3>Lancer une run</h3>
              <p>Choisir un seed et lancer immédiatement</p>
            </div>
            {runMutation.isPending && <span className="gaulois-badge gaulois-badge--soft">Exécution…</span>}
          </header>
          <form className="gaulois-form gaulois-form--grid" onSubmit={handleRunSubmit}>
            <label className="gaulois-field">
              <span>Seed</span>
              <select
                value={runState.seed}
                onChange={(event) =>
                  setRunState((current) => ({
                    ...current,
                    seed: event.target.value,
                  }))
                }
                required
              >
                <option value="">Choisir un seed</option>
                {availableSeeds.map((seed) => (
                  <option key={seed} value={seed}>
                    {seed}
                  </option>
                ))}
              </select>
            </label>
            <div className="gaulois-form__cluster">
              <label className="gaulois-switch">
                <input
                  type="checkbox"
                  className="gaulois-switch__input"
                  checked={runState.dryRun}
                  onChange={(e) => setRunState((s) => ({ ...s, dryRun: e.target.checked }))}
                />
                <span className="gaulois-switch__visual" aria-hidden="true" />
                <span className="gaulois-switch__label">Dry-run</span>
              </label>

              <label className="gaulois-switch">
                <input
                  type="checkbox"
                  className="gaulois-switch__input"
                  checked={runState.log}
                  onChange={(e) => setRunState((s) => ({ ...s, log: e.target.checked }))}
                />
                <span className="gaulois-switch__visual" aria-hidden="true" />
                <span className="gaulois-switch__label">Générer un log</span>
              </label>
            </div>
            <div className="gaulois-form__actions">
              <button type="submit" className="gaulois-cta" disabled={runMutation.isPending || !runState.seed}>
                {runMutation.isPending ? 'Exécution…' : 'Lancer'}
              </button>
            </div>
          </form>
          {runMutation.data && (
            <div className="gaulois-terminal" aria-live="polite">
              <header>Retour exécution</header>
              <pre>{JSON.stringify(runMutation.data, null, 2)}</pre>
            </div>
          )}
          {runErrorMessage && (
            <p className="gaulois-message gaulois-message--error">
              {runErrorMessage ?? "Erreur lors de l'exécution."}
            </p>
          )}
        </section>

        <section className="gaulois-ops__tile gaulois-ops__tile--wide gaulois-surface gaulois-console">
          <header className="gaulois-ops__header">
            <div>
              <h3>Console Python</h3>
              <p>Affiche la sortie/erreurs des dernières exécutions</p>
            </div>
          </header>
          <div className="gaulois-console__list">
            {consoleEntries.length === 0 && <p className="gaulois-message">Aucune sortie pour le moment.</p>}
            {consoleEntries.map((entry) => (
              <article
                key={entry.id}
                className={
                  entry.kind === 'error'
                    ? 'gaulois-console__entry gaulois-console__entry--error'
                    : 'gaulois-console__entry'
                }
              >
                <div className="gaulois-console__meta">
                  <span>{entry.label}</span>
                  <span>{entry.when}</span>
                </div>
                <pre className="gaulois-console__body">
                  {entry.lines.join('\n')}
                </pre>
              </article>
            ))}
          </div>
        </section>

        <section className="gaulois-ops__tile gaulois-surface">
          <header className="gaulois-ops__header">
            <div>
              <h3>Créer un seed</h3>
              <p>Renseigner marque, modèle, cibles et fournisseurs</p>
            </div>
            {seedMutation.isPending && <span className="gaulois-badge gaulois-badge--soft">Création…</span>}
          </header>
          <form className="gaulois-form gaulois-form--grid" onSubmit={handleSeedSubmit}>
            <label className="gaulois-field">
              <span>Nom du fichier</span>
              <input
                type="text"
                value={seedState.filename}
                onChange={(event) =>
                  setSeedState((current) => ({
                    ...current,
                    filename: event.target.value,
                  }))
                }
                placeholder="optionnel (ex: iphone13_seed.json)"
              />
            </label>
            <label className="gaulois-field">
              <span>Marque</span>
              <input
                type="text"
                value={seedState.brand}
                onChange={(event) =>
                  setSeedState((current) => ({
                    ...current,
                    brand: event.target.value,
                  }))
                }
                required
              />
            </label>
            <label className="gaulois-field">
              <span>Modèle</span>
              <input
                type="text"
                value={seedState.model}
                onChange={(event) =>
                  setSeedState((current) => ({
                    ...current,
                    model: event.target.value,
                  }))
                }
                required
              />
            </label>
            <label className="gaulois-field gaulois-field--wide">
              <span>Cibles (séparées par des virgules)</span>
              <input
                type="text"
                value={seedState.targets}
                onChange={(event) =>
                  setSeedState((current) => ({
                    ...current,
                    targets: event.target.value,
                  }))
                }
                placeholder="écran, batterie…"
              />
              <select value={targetTemplate} onChange={handleTargetTemplateChange}>
                <option value="">Ajouter une cible…</option>
                {TARGET_TEMPLATES.map((target) => (
                  <option key={target} value={target}>
                    {target}
                  </option>
                ))}
              </select>
            </label>
            <label className="gaulois-field gaulois-field--wide">
              <span>Fournisseurs (URLs, 1 par ligne)</span>
              <textarea
                value={seedState.suppliersText}
                onChange={(event) =>
                  setSeedState((current) => ({
                    ...current,
                    suppliersText: event.target.value,
                  }))
                }
                placeholder={`https://mobilesentrix.com/...\nhttps://bricophone.fr/...`}
                rows={5}
              />
            </label>
            <div className="gaulois-form__actions">
              <button type="submit" className="gaulois-cta" disabled={seedMutation.isPending}>
                {seedMutation.isPending ? 'Création…' : 'Créer le seed'}
              </button>
            </div>
          </form>
          {seedMutation.data && (
            <div className="gaulois-terminal" aria-live="polite">
              <header>Seed enregistré</header>
              <pre>{JSON.stringify(seedMutation.data, null, 2)}</pre>
            </div>
          )}
          {seedErrorMessage && (
            <p className="gaulois-message gaulois-message--error">
              {seedErrorMessage ?? 'Erreur lors de la création.'}
            </p>
          )}
        </section>

        <section className="gaulois-ops__tile gaulois-ops__tile--wide gaulois-surface">
          <header className="gaulois-ops__header">
            <div>
              <h3>Seeds existants</h3>
              <p>Référentiel complet</p>
            </div>
          </header>
          <div className="gaulois-ops__seeds">
            {meta?.seeds?.length ? (
              meta.seeds.map((seed) => (
                <details key={seed.filename} className="gaulois-accordion">
                  <summary>
                    <span>{seed.filename}</span>
                    <span className="gaulois-accordion__meta">{seed.targets.length} cibles</span>
                  </summary>
                  <div className="gaulois-accordion__content">
                    <div className="gaulois-accordion__scroll">
                      <dl>
                        <div>
                          <dt>Marques</dt>
                          <dd>{seed.brands.join(', ') || '—'}</dd>
                        </div>
                        <div>
                          <dt>Modèles</dt>
                          <dd>{seed.models.join(', ') || '—'}</dd>
                        </div>
                        <div>
                          <dt>Cibles</dt>
                          <dd>{seed.targets.join(', ') || '—'}</dd>
                        </div>
                        <div>
                          <dt>Fournisseurs</dt>
                          <dd>
                            {seed.suppliers.map((supplier) => (
                              <div key={supplier.key} className="gaulois-accordion__supplier">
                                <strong>{supplier.key}</strong>
                                <ul>
                                  {supplier.urls.map((url) => (
                                    <li key={url}>
                                      <a href={url} target="_blank" rel="noreferrer">
                                        {url}
                                      </a>
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            ))}
                          </dd>
                        </div>
                      </dl>
                    </div>
                  </div>
                </details>
              ))
            ) : (
              <p className="gaulois-message">Aucun seed disponible.</p>
            )}
          </div>
        </section>

        <section className="gaulois-ops__tile gaulois-ops__tile--wide gaulois-surface">
          <header className="gaulois-ops__header">
            <div>
              <h3>Journal de marche</h3>
              <p>Logs horodatés</p>
            </div>
          </header>
          <div className="gaulois-logs">
            <aside className="gaulois-logs__list">
              <ul>
                {(logs?.logs ?? []).map((log) => (
                  <li key={log.filename}>
                    <button
                      type="button"
                      onClick={() => setSelectedLog(log.filename)}
                      className={
                        selectedLog === log.filename
                          ? 'gaulois-logs__item gaulois-logs__item--active'
                          : 'gaulois-logs__item'
                      }
                    >
                      <span className="gaulois-logs__name">{log.filename}</span>
                      <div className="gaulois-logs__meta">
                        <span className="gaulois-logs__size">{Math.round(log.size / 1024)} Ko</span>
                        <span className="gaulois-logs__time">
                          {new Date(log.modified_at).toLocaleTimeString('fr-FR')}
                        </span>
                      </div>
                      <span className="gaulois-logs__status" aria-hidden="true">
                        {log.completed ? '✅' : ''}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </aside>

            <div className="gaulois-logs__viewer" role="region" aria-live="polite">
              {selectedLog ? (
                logContent ? (
                  <pre className="gaulois-logs__viewer-content">{logContent.content}</pre>
                ) : (
                  <p className="gaulois-message">Chargement du log {selectedLog}…</p>
                )
              ) : (
                <p className="gaulois-message">Choisissez un log pour afficher son contenu.</p>
              )}
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
