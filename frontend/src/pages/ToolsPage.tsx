import type { CSSProperties } from 'react';
import { Link } from 'react-router-dom';

type ToolCard = {
  id: string;
  label: string;
  description: string;
  href: string;
  accent: string;
  icon: string;
  internal: boolean;
};

const tools: ToolCard[] = [
  {
    id: 'csv-explorer',
    label: 'CSV Explorer',
    description: 'Exploration locale de CSV massifs en streaming avec worker et table virtualisee',
    href: '/csv-explorer',
    accent: 'linear-gradient(135deg, #0f172a, #2563eb)',
    icon: 'CSV',
    internal: true,
  },
  {
    id: 'drive',
    label: 'Google Drive',
    description: 'Dossiers Helix, devis et reporting partages',
    href: 'https://drive.google.com/drive/u/0/folders/1DtwfyzN6i2q-chxdso-3AfGZ8rr9iL37',
    accent: 'linear-gradient(135deg, #1a73e8, #4285f4)',
    icon: 'DRV',
    internal: false,
  },
  {
    id: 'phpmyadmin',
    label: 'phpMyAdmin',
    description: 'Acces direct a la base ripair_shop pour les diagnostics',
    href: 'http://51.91.10.180/phpmyadmin/index.php?route=/database/structure&db=ripair/',
    accent: 'linear-gradient(135deg, #f97316, #fb923c)',
    icon: 'SQL',
    internal: false,
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

const iconStyle: CSSProperties = {
  fontSize: 30,
  lineHeight: 1,
  fontWeight: 800,
  letterSpacing: '-0.05em',
  filter: 'drop-shadow(0 6px 10px rgba(15,23,42,0.2))',
};

function attachHoverMotion(element: HTMLElement, active: boolean) {
  element.style.transform = active ? 'translateY(-6px) scale(1.02)' : 'none';
  element.style.boxShadow = active
    ? '0 30px 60px rgba(15,23,42,0.22)'
    : '0 20px 40px rgba(15,23,42,0.16)';
}

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
          Centre d outils
        </p>
        <h2 style={{ margin: 0 }}>Acces rapide</h2>
        <p style={{ margin: 0, color: '#475569', maxWidth: 620 }}>
          Retrouve les applications internes utilisees au quotidien. Les outils Helix restent dans l application,
          les raccourcis externes s ouvrent dans un nouvel onglet.
        </p>
      </header>

      <div style={gridStyle}>
        {tools.map((tool) =>
          tool.internal ? (
            <Link
              key={tool.id}
              to={tool.href}
              style={{
                ...cardBaseStyle,
                background: tool.accent,
              }}
              onMouseEnter={(event) => attachHoverMotion(event.currentTarget, true)}
              onMouseLeave={(event) => attachHoverMotion(event.currentTarget, false)}
            >
              <span aria-hidden style={iconStyle}>
                {tool.icon}
              </span>
              <div>
                <strong style={{ fontSize: 20 }}>{tool.label}</strong>
                <p style={{ margin: '6px 0 0', color: 'rgba(248,250,252,0.85)', fontSize: 15 }}>{tool.description}</p>
              </div>
            </Link>
          ) : (
            <a
              key={tool.id}
              href={tool.href}
              target="_blank"
              rel="noreferrer"
              style={{
                ...cardBaseStyle,
                background: tool.accent,
              }}
              onMouseEnter={(event) => attachHoverMotion(event.currentTarget, true)}
              onMouseLeave={(event) => attachHoverMotion(event.currentTarget, false)}
            >
              <span aria-hidden style={iconStyle}>
                {tool.icon}
              </span>
              <div>
                <strong style={{ fontSize: 20 }}>{tool.label}</strong>
                <p style={{ margin: '6px 0 0', color: 'rgba(248,250,252,0.85)', fontSize: 15 }}>{tool.description}</p>
              </div>
            </a>
          ),
        )}
      </div>
    </div>
  );
}
