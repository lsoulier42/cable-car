import { createContext } from 'react';

export interface AuthContextValue {
  token: string | null;
  email: string | null;
  roles: string[];
  isAuthenticated: boolean;
  login: (token: string) => void;
  logout: () => void;
}

export const AuthContext = createContext<AuthContextValue | null>(null);