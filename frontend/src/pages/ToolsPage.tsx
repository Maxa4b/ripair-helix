import type { CSSProperties } from 'react';

const tools = [
  {
    id: 'drive',
    label: 'Google Drive',
    description: 'Dossiers Helix, devis et reporting partagés',
    href: 'https://drive.google.com/drive/u/0/folders/1DtwfyzN6i2q-chxdso-3AfGZ8rr9iL37',
    accent: 'linear-gradient(135deg, #1a73e8, #4285f4)',
    icon: '📂',
  },
  {
    id: 'phpmyadmin',
    label: 'phpMyAdmin',
    description: 'Accès direct à la base ripair_shop pour les diagnostics',
    href: 'http://51.91.10.180/phpmyadmin/index.php?route=/database/structure&db=ripair/',
    accent: 'linear-gradient(135deg, #f97316, #fb923c)',
    icon: '🛠️',
  },
];

const pageStyle: CSSProperties = {
  display: 'grid',
  gap: 24,
};

const gridStyle: CSSProperties = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
  gap: 24,
};

const cardBaseStyle: CSSProperties = {
  borderRadius: 24,
  padding: '28px 24px',
  color: '#f8fafc',
  boxShadow: '0 20px 40px rgba(15,23,42,0.16)',
  border: '1px solid rgba(255,255,255,0.08)',
  textDecoration: 'none',
  display: 'flex',
  flexDirection: 'column',
  gap: 12,
  transition: 'transform 200ms ease, box-shadow 200ms ease',
};

export default function ToolsPage() {
  return (
    <div style={pageStyle}>
      <header style={{ display: 'grid', gap: 8 }}>
        <p
          style={{
            margin: 0,
            textTransform: 'uppercase',
            letterSpacing: '0.08em',
            fontSize: 12,
            color: '#64748b',
          }}
        >
          Centre d’outils
        </p>
        <h2 style={{ margin: 0 }}>Accès rapide</h2>
        <p style={{ margin: 0, color: '#475569', maxWidth: 520 }}>
          Retrouve les applications internes utilisées au quotidien. Chaque tuile s’ouvre dans un nouvel onglet,
          façon écran d’accueil tablette.
        </p>
      </header>

      <div style={gridStyle}>
        {tools.map((tool) => (
          <a
            key={tool.id}
            href={tool.href}
            target="_blank"
            rel="noreferrer"
            style={{
              ...cardBaseStyle,
              background: tool.accent,
            }}
            onMouseEnter={(event) => {
              event.currentTarget.style.transform = 'translateY(-6px) scale(1.02)';
              event.currentTarget.style.boxShadow = '0 30px 60px rgba(15,23,42,0.22)';
            }}
            onMouseLeave={(event) => {
              event.currentTarget.style.transform = 'none';
              event.currentTarget.style.boxShadow = '0 20px 40px rgba(15,23,42,0.16)';
            }}
          >
            <span
              aria-hidden
              style={{
                fontSize: 36,
                lineHeight: 1,
                filter: 'drop-shadow(0 6px 10px rgba(15,23,42,0.2))',
              }}
            >
              {tool.icon}
            </span>
            <div>
              <strong style={{ fontSize: 20 }}>{tool.label}</strong>
              <p style={{ margin: '6px 0 0', color: 'rgba(248,250,252,0.85)', fontSize: 15 }}>{tool.description}</p>
            </div>
          </a>
        ))}
      </div>
    </div>
  );
}

