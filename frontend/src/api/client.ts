import axios from 'axios';

export const TOKEN_KEY = 'cable_car_token';

/** Axios instance pointed at the Symfony API (proxied by Vite in dev, same origin in prod). */
export const apiClient = axios.create({
  baseURL: '/api',
});

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    // A 401 on any protected call means an expired/invalid token: drop it and go back to login.
    if (error.response?.status === 401 && window.location.pathname !== '/login') {
      localStorage.removeItem(TOKEN_KEY);
      window.location.assign('/login');
    }
    return Promise.reject(error);
  },
);

/** Returns the server error payload as { message?, violations? }, or a generic message. */
export function extractApiError(error: unknown): { message: string; violations?: Array<{ propertyPath: string; message: string }> } {
  if (axios.isAxiosError(error) && error.response?.data) {
    const data = error.response.data as { message?: string; violations?: Array<{ propertyPath: string; message: string }>; code?: number };
    return {
      message: data.message ?? (data.code === 401 ? 'Identifiants invalides ou compte désactivé.' : 'Une erreur est survenue.'),
      violations: data.violations,
    };
  }
  return { message: 'Une erreur est survenue.' };
}