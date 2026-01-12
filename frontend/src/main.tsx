import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CacheProvider } from '@emotion/react';
import createCache from '@emotion/cache';
import './index.css';
import App from './App';
import { AuthProvider } from './providers/AuthProvider';

const queryClient = new QueryClient();
const emotionInsertionPoint = document.querySelector<HTMLMetaElement>(
  'meta[name="emotion-insertion-point"]',
);
const muiCache = createCache({
  key: 'mui',
  prepend: true,
  speedy: false,
  container: document.head ?? document.documentElement,
  insertionPoint: emotionInsertionPoint ?? undefined,
});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <CacheProvider value={muiCache}>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </QueryClientProvider>
    </CacheProvider>
  </StrictMode>,
);
