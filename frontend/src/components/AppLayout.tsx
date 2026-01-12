import type { CSSProperties } from 'react';
import { useMemo } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

export default function AppLayout() {
  const { user, logout } = useAuth();
  const location = useLocation();
  const isGaulois = location.pathname.startsWith('/gaulois');

  const armGauloisTransition = () => {
    if (typeof window !== 'undefined' && window.sessionStorage) {
      try {
        window.sessionStorage.setItem('helix.gauloisTransition', String(Date.now()));
        window.sessionStorage.setItem('helix.gauloisAudio', '1');
      } catch (error) {
        console.warn('Impossible de préparer la transition Gaulois.', error);
      }
    }
  };

  const palette = useMemo(
    () =>
      isGaulois
        ? {
            asideBg: '#050816',
            asideBorder: 'transparent',
            textPrimary: '#e2e8f0',
            textSecondary: '#94a3b8',
            roleBg: 'rgba(14, 116, 144, 0.25)',
            roleText: '#38bdf8',
            linkActiveBg: 'rgba(14, 116, 144, 0.35)',
            linkActiveText: '#f8fafc',
            linkInactive: '#94a3b8',
            buttonBg: '#d7263d',
            mainBg: '#050816',
            hoverBg: 'rgba(14, 116, 144, 0.18)',
          }
        : {
            asideBg: '#f8fafc',
            asideBorder: '#edf2f7',
            textPrimary: '#111827',
            textSecondary: '#64748b',
            roleBg: '#e0ecff',
            roleText: '#1d4ed8',
            linkActiveBg: '#e0ecff',
            linkActiveText: '#1d4ed8',
            linkInactive: '#2d3748',
            buttonBg: '#e53e3e',
            mainBg: '#f9fafb',
            hoverBg: 'rgba(226, 232, 240, 0.6)',
          },
    [isGaulois],
  );

  const linkStyle = ({ isActive }: { isActive: boolean }): CSSProperties => {
    if (isGaulois) {
      return {
        padding: '10px 14px',
        borderRadius: 8,
        textDecoration: 'none',
        fontWeight: isActive ? 600 : 500,
        display: 'block',
        transition: 'all 0.15s ease',
        transform: 'translateZ(0)',
      };
    }
    return {
        padding: '10px 14px',
        borderRadius: 8,
        textDecoration: 'none',
        color: isActive ? palette.linkActiveText : palette.linkInactive,
        background: isActive ? palette.linkActiveBg : 'transparent',
        fontWeight: isActive ? 600 : 500,
        display: 'block',
        transition: 'all 0.15s ease',
        transform: 'translateZ(0)',
    };
  };

  const handleLogout = async () => {
    await logout();
  };

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: '220px 1fr',
        minHeight: '100vh',
        position: 'relative',
      }}
    >
      <aside
        /* Sticky sidebar that follows scroll */
        style={{
          position: 'sticky',
          top: 0,
          alignSelf: 'start',
          height: '100vh',
          overflow: 'auto',

          padding: 24,
          borderRight: `1px solid ${palette.asideBorder}`,
          background: palette.asideBg,
          display: 'flex',
          flexDirection: 'column',
          gap: 16,
        }}
      >
        <div>
          <h1 style={{ margin: 0, fontSize: 22, color: palette.textPrimary }}>Helix</h1>
          <p style={{ color: palette.textSecondary, marginTop: 4 }}>{user?.full_name}</p>
          <span
            style={{
              display: 'inline-block',
              marginTop: 4,
              padding: '2px 8px',
              borderRadius: 999,
              background: palette.roleBg,
              color: palette.roleText,
              fontSize: 12,
            }}
          >
            {user?.role}
          </span>
        </div>

        <nav style={{ display: 'grid', gap: 6 }}>
          <NavLink
            to="/"
            end
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Tableau de bord
          </NavLink>
          <NavLink
            to="/calendar"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Agenda
          </NavLink>
          <NavLink
            to="/appointments"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Rendez-vous
          </NavLink>
          <NavLink
            to="/technicians"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Techniciens
          </NavLink>
          <NavLink
            to="/reviews"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Avis
          </NavLink>
          <NavLink
            to="/availability"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Disponibilités
          </NavLink>
          <NavLink
            to="/settings"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Paramètres
          </NavLink>
          <NavLink
            to="/tools"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Outils
          </NavLink>
          <NavLink
            to="/livreo"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Livreo
          </NavLink>
          <NavLink
            to="/rhcatalogo"
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            RHCatalogo
          </NavLink>
          <NavLink
            to="/gaulois"
            onClick={armGauloisTransition}
            style={linkStyle}
            className={({ isActive }) =>
              isGaulois
                ? `gaulois-nav-link${isActive ? ' gaulois-nav-link--active' : ''}`
                : undefined
            }
          >
            Gaulois
          </NavLink>
        </nav>

        <button
          type="button"
          onClick={handleLogout}
          style={{
            marginTop: 16,
            padding: '10px 14px',
            borderRadius: 8,
            border: 'none',
            cursor: 'pointer',
            background: palette.buttonBg,
            color: '#fff',
          }}
        >
          Se déconnecter
        </button>
      </aside>

      <main
        style={{
          padding: isGaulois ? '32px 32px 56px 24px' : '32px 40px',
          background: palette.mainBg,
          position: 'relative',
        }}
      >
        <Outlet />
      </main>
    </div>
  );
}
