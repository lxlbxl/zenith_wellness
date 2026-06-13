import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { User } from './types';
import { db } from './services/db';
import { api } from './services/api';

interface AuthContextValue {
  user: User | null;
  isLoading: boolean;
  login: (user: User) => void;
  logout: () => void;
  updateUser: (user: User) => void;
}

const AuthContext = createContext<AuthContextValue>({
  user: null,
  isLoading: true,
  login: () => {},
  logout: () => {},
  updateUser: () => {},
});

export const useAuth = () => useContext(AuthContext);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const session = db.getSession();
    if (session) {
      setUser(session);
    }
    setIsLoading(false);
  }, []);

  const login = useCallback((u: User) => {
    db.setSession(u);
    setUser(u);
  }, []);

  const logout = useCallback(() => {
    db.clearSession();
    api.setToken(null);
    setUser(null);
  }, []);

  const updateUser = useCallback((u: User) => {
    db.setSession(u);
    setUser(u);
  }, []);

  return (
    <AuthContext.Provider value={{ user, isLoading, login, logout, updateUser }}>
      {children}
    </AuthContext.Provider>
  );
};