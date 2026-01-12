import axios from 'axios';

// Client dédié RHCatalogo (cible l'API e-commerce)
const rhcClient = axios.create({
  baseURL:
    import.meta.env.VITE_RHC_API_URL ??
    import.meta.env.VITE_API_URL ??
    'http://localhost:8000/api',
  timeout: 20000,
  headers: {
    Accept: 'application/json',
  },
});

export default rhcClient;
