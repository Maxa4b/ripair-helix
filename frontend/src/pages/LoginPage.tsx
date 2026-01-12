import { isAxiosError } from 'axios';
import type { CSSProperties, FormEvent } from 'react';
import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login } = useAuth();
  const [email, setEmail] = useState('admin@ripair.shop');
  const [password, setPassword] = useState('changeme123!');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const state = location.state as { from?: { pathname?: string } } | undefined;
  const from = state?.from?.pathname ?? '/';

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await login({ email, password });
      navigate(from, { replace: true });
    } catch (error) {
      if (isAxiosError(error)) {
        const message = error.response?.data?.message ?? error.response?.data?.error ?? 'Connexion impossible.';
        setError(message);
      } else {
        setError('Connexion impossible.');
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'grid',
        placeItems: 'center',
        background: '#f1f5f9',
      }}
    >
      <form
        onSubmit={handleSubmit}
        style={{
          width: 360,
          background: '#fff',
          borderRadius: 12,
          padding: '32px 28px',
          boxShadow: '0 18px 40px rgba(15, 23, 42, 0.1)',
          display: 'grid',
          gap: 16,
        }}
      >
        <div>
          <h1 style={{ margin: 0, fontSize: 24 }}>Helix</h1>
          <p style={{ margin: '6px 0 0', color: '#64748b' }}>
            Connecte-toi pour accéder au back-office.
          </p>
        </div>

        {error && (
          <p style={{ color: '#e53e3e', background: '#fff5f5', padding: '10px 12px', borderRadius: 8 }}>
            {error}
          </p>
        )}

        <label style={{ display: 'grid', gap: 6, fontSize: 14, color: '#475569' }}>
          Adresse e-mail
          <input
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            style={inputStyle}
            placeholder="admin@ripair.shop"
          />
        </label>

        <label style={{ display: 'grid', gap: 6, fontSize: 14, color: '#475569' }}>
          Mot de passe
          <input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
            style={inputStyle}
          />
        </label>

        <button
          type="submit"
          disabled={loading}
          style={{
            border: 'none',
            borderRadius: 8,
            padding: '12px 16px',
            background: '#2563eb',
            color: '#fff',
            fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          {loading ? 'Connexion…' : 'Se connecter'}
        </button>
      </form>
    </div>
  );
}

const inputStyle: CSSProperties = {
  border: '1px solid #cbd5f5',
  borderRadius: 8,
  padding: '10px 12px',
  fontSize: 15,
  outline: 'none',
  background: '#f8fafc',
};
