import { useMemo } from 'react';
import GauloisControlPanel from './GauloisControlPanel';
import { useGauloisLogs, useGauloisMeta } from '../../hooks/useGaulois';
import '../../styles/gaulois.css';

export default function GauloisPage() {
  const { data: meta } = useGauloisMeta();
  const { data: logs } = useGauloisLogs();

  const seedsCount = meta?.seeds?.length ?? 0;

  const supplierCount = useMemo(() => {
    if (!meta?.seeds) return 0;
    const keys = new Set<string>();
    meta.seeds.forEach((seed) => seed.suppliers.forEach((supplier) => keys.add(supplier.key)));
    return keys.size;
  }, [meta?.seeds]);

  const targetCount = useMemo(() => {
    if (!meta?.seeds) return 0;
    const targets = new Set<string>();
    meta.seeds.forEach((seed) => seed.targets.forEach((target) => targets.add(target)));
    return targets.size;
  }, [meta?.seeds]);

  const lastLog = logs?.logs?.[0];
  const lastLogLabel = lastLog
    ? new Date(lastLog.modified_at).toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
      })
    : 'Aucun';

  return (
    <main className="gaulois-app">
      <header className="gaulois-header">
        <div>
          <p className="gaulois-eyebrow">Automation catalogue</p>
          <h1>Poste Gaulois</h1>
          <p className="gaulois-lead">
            Crée, lance et surveille les jobs en une seule page. Pas d'effets, juste l'essentiel.
          </p>
        </div>
        <div className="gaulois-actions">
          <a className="gaulois-button gaulois-button--ghost" href="#gaulois-control">
            Aller au poste
          </a>
        </div>
      </header>

      <section className="gaulois-summary">
        <div className="gaulois-summary__card">
          <span className="gaulois-summary__label">Seeds</span>
          <span className="gaulois-summary__value">{seedsCount}</span>
          <span className="gaulois-summary__meta">actives</span>
        </div>
        <div className="gaulois-summary__card">
          <span className="gaulois-summary__label">Fournisseurs</span>
          <span className="gaulois-summary__value">{supplierCount}</span>
          <span className="gaulois-summary__meta">uniques</span>
        </div>
        <div className="gaulois-summary__card">
          <span className="gaulois-summary__label">Cibles</span>
          <span className="gaulois-summary__value">{targetCount}</span>
          <span className="gaulois-summary__meta">mots-clés</span>
        </div>
        <div className="gaulois-summary__card">
          <span className="gaulois-summary__label">Dernier log</span>
          <span className="gaulois-summary__value">{lastLogLabel}</span>
          <span className="gaulois-summary__meta">{lastLog ? lastLog.filename : '—'}</span>
        </div>
      </section>

      <section id="gaulois-control" className="gaulois-section">
        <div className="gaulois-section__header">
          <div>
            <p className="gaulois-eyebrow">Poste de commande</p>
            <h2>Créer, lancer, suivre</h2>
            <p className="gaulois-lead">
              Configurer un run, ajouter un seed, consulter les logs sans bouger de cette page.
            </p>
          </div>
        </div>
        <GauloisControlPanel />
      </section>
    </main>
  );
}
