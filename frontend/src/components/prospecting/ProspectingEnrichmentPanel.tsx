import { useEffect, useMemo, useState } from 'react';
import {
  downloadProspectingEnrichmentArtifact,
  useCancelProspectingEnrichmentJob,
  useGenerateProspectingEnrichmentSeed,
  useProspectingEnrichmentFiles,
  useProspectingEnrichmentJobs,
  useStartProspectingEnrichmentJob,
  type EnrichmentJob,
  type EnrichmentRemoteEntry,
} from '../../hooks/useProspectingEnrichment';
import { formatDateTime } from './prospectingUtils';
import ProspectingFileBrowserDialog from './ProspectingFileBrowserDialog';

function formatProgress(value: number) {
  return `${Math.round((value || 0) * 100)}%`;
}

function parentDirectory(path: string | null) {
  if (!path) return '_generated';
  const normalized = path.replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
  if (!normalized.includes('/')) return '';
  return normalized.slice(0, normalized.lastIndexOf('/'));
}

function formatBytes(value: number) {
  if (value < 1024) return `${value} o`;
  if (value < 1024 ** 2) return `${(value / 1024).toFixed(1)} Ko`;
  if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} Mo`;
  return `${(value / 1024 ** 3).toFixed(2)} Go`;
}

function translatePhase(value: string | null) {
  if (!value) return 'En attente';
  return (
    {
      ingest: 'Ingestion',
      resolve_domains: 'Résolution domaines',
      crawl: 'Crawl',
      score_emails: 'Scoring emails',
      export_final: 'Export final',
    }[value] ?? value
  );
}

function translateStatus(value: EnrichmentJob['status']) {
  return (
    {
      queued: 'En file',
      running: 'En cours',
      completed: 'Terminé',
      cancelled: 'Annulé',
      error: 'Erreur',
    }[value] ?? value
  );
}

export default function ProspectingEnrichmentPanel() {
  const jobsQuery = useProspectingEnrichmentJobs();
  const [browserOpen, setBrowserOpen] = useState(false);
  const [browserTarget, setBrowserTarget] = useState<'input' | 'seed'>('input');
  const [browserPath, setBrowserPath] = useState('');
  const [selectedInputPath, setSelectedInputPath] = useState<string | null>(null);
  const [selectedSeedPath, setSelectedSeedPath] = useState<string | null>(null);
  const [selectedJobId, setSelectedJobId] = useState<string | null>(null);

  const filesQuery = useProspectingEnrichmentFiles(browserPath, browserOpen);
  const startMutation = useStartProspectingEnrichmentJob();
  const cancelMutation = useCancelProspectingEnrichmentJob();
  const generateSeedMutation = useGenerateProspectingEnrichmentSeed();

  const jobs = jobsQuery.data ?? [];

  useEffect(() => {
    if (!selectedJobId && jobs.length > 0) {
      setSelectedJobId(jobs[0].job_id);
    }
  }, [jobs, selectedJobId]);

  const selectedJob = useMemo(
    () => jobs.find((job) => job.job_id === selectedJobId) ?? jobs[0] ?? null,
    [jobs, selectedJobId],
  );

  const runningJob = useMemo(() => jobs.find((job) => job.status === 'queued' || job.status === 'running') ?? null, [jobs]);

  const handleSelectFile = (entry: EnrichmentRemoteEntry) => {
    if (browserTarget === 'seed') {
      setSelectedSeedPath(entry.path);
    } else {
      setSelectedInputPath(entry.path);
    }
    setBrowserOpen(false);
  };

  const handleStart = async () => {
    if (!selectedInputPath) {
      window.alert('Selectionne d abord un fichier source sur le VPS.');
      return;
    }

    try {
      const job = await startMutation.mutateAsync({
        input_path: selectedInputPath,
        seed_input_path: selectedSeedPath,
        mode: 'run-all',
      });
      setSelectedJobId(job.job_id);
    } catch (error) {
      console.error(error);
      window.alert('Impossible de lancer le pipeline.');
    }
  };

  const handleGenerateSeed = async () => {
    try {
      const generated = await generateSeedMutation.mutateAsync();
      setSelectedSeedPath(generated.file.path);
      window.alert(
        `Seed genere: ${generated.file.path}\n${generated.rows_written} ligne(s) retenue(s) sur ${generated.companies_considered} entreprise(s) inspectee(s).`,
      );
    } catch (error) {
      console.error(error);
      window.alert('Generation du domain_seed.csv impossible.');
    }
  };

  const handleCancel = async () => {
    if (!runningJob) {
      return;
    }

    try {
      await cancelMutation.mutateAsync(runningJob.job_id);
    } catch (error) {
      console.error(error);
      window.alert('Annulation impossible.');
    }
  };

  const handleDownload = async (artifactKey: string) => {
    if (!selectedJob) {
      return;
    }

    const artifact = selectedJob.snapshot.artifacts.find((item) => item.key === artifactKey);
    if (!artifact) {
      return;
    }

    try {
      await downloadProspectingEnrichmentArtifact(selectedJob.job_id, artifact);
    } catch (error) {
      console.error(error);
      window.alert('Téléchargement impossible.');
    }
  };

  return (
    <>
      <section className="prospecting-enrichment">
        <div className="prospecting-enrichment__header">
          <div style={{ display: 'grid', gap: 6 }}>
            <p className="prospecting-enrichment__eyebrow">Pipeline industriel</p>
            <h2 className="prospecting-panel__title" style={{ fontSize: '1.35rem' }}>
              Enrichissement SIRENE vers domaine puis emails
            </h2>
            <p style={{ margin: 0, color: '#64748b', maxWidth: 900 }}>
              Lance le pipeline Python `company_enrichment` directement depuis Helix. Le job tourne côté backend, survit au refresh et expose ses artefacts finaux sans dépendre de ta session navigateur.
            </p>
          </div>
          <div className="prospecting-actions">
            <button
              type="button"
              className="prospecting-button prospecting-button--ghost"
              onClick={() => {
                setBrowserTarget('input');
                setBrowserPath(selectedInputPath ? parentDirectory(selectedInputPath) : '');
                setBrowserOpen(true);
              }}
            >
              Choisir la source VPS
            </button>
            <button
              type="button"
              className="prospecting-button prospecting-button--ghost"
              onClick={() => {
                setBrowserTarget('seed');
                setBrowserPath(parentDirectory(selectedSeedPath));
                setBrowserOpen(true);
              }}
            >
              Choisir un seed domaines
            </button>
            <button
              type="button"
              className="prospecting-button prospecting-button--ghost"
              onClick={() => void handleGenerateSeed()}
              disabled={generateSeedMutation.isPending}
            >
              {generateSeedMutation.isPending ? 'Generation...' : 'Generer le domain_seed.csv'}
            </button>
            <button
              type="button"
              className="prospecting-button prospecting-button--primary"
              onClick={() => void handleStart()}
              disabled={startMutation.isPending || !!runningJob}
            >
              {startMutation.isPending ? 'Lancement...' : runningJob ? 'Job déjà en cours' : 'Lancer run-all'}
            </button>
            <button
              type="button"
              className="prospecting-button prospecting-button--danger"
              onClick={() => void handleCancel()}
              disabled={!runningJob || cancelMutation.isPending}
            >
              {cancelMutation.isPending ? 'Annulation...' : 'Annuler le job'}
            </button>
          </div>
        </div>

        <div className="prospecting-enrichment__summary">
          <article className="prospecting-stat">
            <p className="prospecting-stat__label">Source sélectionnée</p>
            <p className="prospecting-enrichment__value">{selectedInputPath ?? 'Aucun fichier choisi'}</p>
          </article>
          <article className="prospecting-stat">
            <p className="prospecting-stat__label">Seed domaines</p>
            <p className="prospecting-enrichment__value">{selectedSeedPath ?? 'Aucun seed'}</p>
          </article>
          <article className="prospecting-stat">
            <p className="prospecting-stat__label">Dernier statut</p>
            <p className="prospecting-enrichment__value">{selectedJob ? translateStatus(selectedJob.status) : 'Aucun job'}</p>
          </article>
          <article className="prospecting-stat">
            <p className="prospecting-stat__label">Phase courante</p>
            <p className="prospecting-enrichment__value">{translatePhase(selectedJob?.snapshot.currentPhase ?? null)}</p>
          </article>
          <article className="prospecting-stat">
            <p className="prospecting-stat__label">Progression</p>
            <p className="prospecting-enrichment__value">{selectedJob ? formatProgress(selectedJob.snapshot.progress) : '0%'}</p>
          </article>
        </div>

        <div className="prospecting-enrichment__grid">
          <section className="prospecting-panel">
            <div style={{ display: 'grid', gap: 6 }}>
              <h3 className="prospecting-panel__title">Jobs récents</h3>
              <p style={{ margin: 0, color: '#64748b' }}>
                {jobsQuery.isLoading ? 'Chargement...' : `${jobs.length} job(s) visibles`}
              </p>
            </div>

            {jobsQuery.isError ? <p className="prospecting-enrichment__error">Impossible de charger l’historique des jobs.</p> : null}

            <div className="prospecting-list">
              {jobs.length === 0 ? (
                <div className="prospecting-file-browser__empty">Aucun lancement enregistré pour le moment.</div>
              ) : (
                jobs.map((job) => (
                  <button
                    key={job.job_id}
                    type="button"
                    className={`prospecting-list-item${selectedJob?.job_id === job.job_id ? ' prospecting-list-item--active' : ''}`}
                    onClick={() => setSelectedJobId(job.job_id)}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10 }}>
                      <strong>{job.snapshot.inputFile?.name ?? job.input_path}</strong>
                      <span style={{ color: '#64748b', fontSize: 12 }}>{translateStatus(job.status)}</span>
                    </div>
                    <div style={{ color: '#64748b', fontSize: 13, textAlign: 'left' }}>
                      {translatePhase(job.snapshot.currentPhase)} · {formatProgress(job.snapshot.progress)}
                    </div>
                    <div style={{ color: '#94a3b8', fontSize: 12, textAlign: 'left' }}>
                      Mis à jour: {formatDateTime(new Date(job.updated_at).toISOString())}
                    </div>
                  </button>
                ))
              )}
            </div>
          </section>

          <section className="prospecting-panel">
            <div style={{ display: 'grid', gap: 6 }}>
              <h3 className="prospecting-panel__title">Détail du job</h3>
              <p style={{ margin: 0, color: '#64748b' }}>
                {selectedJob ? `Job ${selectedJob.job_id}` : 'Sélectionne un job pour voir ses sorties'}
              </p>
              {selectedJob?.snapshot.seedFile ? (
                <p style={{ margin: 0, color: '#64748b' }}>Seed: {selectedJob.snapshot.seedFile.path}</p>
              ) : null}
            </div>

            {!selectedJob ? (
              <div className="prospecting-file-browser__empty">Aucun job sélectionné.</div>
            ) : (
              <>
                <div className="prospecting-enrichment__phases">
                  {selectedJob.snapshot.phases.map((phase) => (
                    <div key={phase.key} className="prospecting-enrichment__phase">
                      <strong>{translatePhase(phase.key)}</strong>
                      <span>{phase.status}</span>
                    </div>
                  ))}
                </div>

                {selectedJob.snapshot.error ? (
                  <p className="prospecting-enrichment__error">{selectedJob.snapshot.error}</p>
                ) : null}

                <div style={{ display: 'grid', gap: 8 }}>
                  <h4 style={{ margin: 0 }}>Artefacts</h4>
                  {selectedJob.snapshot.artifacts.length === 0 ? (
                    <div className="prospecting-file-browser__empty">Aucun artefact produit pour l’instant.</div>
                  ) : (
                    <div className="prospecting-enrichment__artifacts">
                      {selectedJob.snapshot.artifacts.map((artifact) => (
                        <button
                          key={artifact.key}
                          type="button"
                          className="prospecting-enrichment__artifact"
                          onClick={() => void handleDownload(artifact.key)}
                        >
                          <strong>{artifact.name}</strong>
                          <span>{formatBytes(artifact.size)}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                <div style={{ display: 'grid', gap: 8 }}>
                  <h4 style={{ margin: 0 }}>Logs</h4>
                  <pre className="prospecting-enrichment__logs">
                    {selectedJob.snapshot.logTail.length > 0 ? selectedJob.snapshot.logTail.join('\n') : 'Aucune sortie pour le moment.'}
                  </pre>
                </div>
              </>
            )}
          </section>
        </div>
      </section>

      <ProspectingFileBrowserDialog
        open={browserOpen}
        title={browserTarget === 'seed' ? 'Choisir le seed de domaines' : 'Choisir le fichier source SIRENE'}
        description={
          browserTarget === 'seed'
            ? 'Seed facultatif contenant au moins siren + source_url/domain. Utilisé par resolve_domains comme provider prioritaire.'
            : 'Le pipeline lira ce fichier directement sur le VPS. Extensions autorisées: CSV, TSV, TXT, Parquet.'
        }
        listing={filesQuery.data}
        isLoading={filesQuery.isLoading}
        errorMessage={filesQuery.isError ? 'Impossible de charger les fichiers du VPS.' : null}
        selectingPath={browserTarget === 'seed' ? selectedSeedPath : selectedInputPath}
        onClose={() => setBrowserOpen(false)}
        onNavigate={setBrowserPath}
        onSelectFile={handleSelectFile}
      />
    </>
  );
}
