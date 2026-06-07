
import { User, UserPersona, UserStats, ChatMessage, CohortProgress, CycleLog } from '../types';
import { api } from './api';

export const db = {
  // Session - Sync read, Async write verification
  getSession: (): User | null => {
    return api.getSession();
  },

  setSession: (user: User) => {
    // We don't save token here, assume api.setSession was called on login
    // This might be redundant if api handles it, but keeping for compatibility
    const token = localStorage.getItem('auth_token') || '';
    api.setSession(token, user);
  },

  clearSession: () => {
    api.clearSession();
  },

  updateUserPersona: async (userId: string, persona: UserPersona) => {
    // Optimistic update
    const session = api.getSession();
    if (session && session.id === userId) {
      session.persona = persona;
      api.setSession(localStorage.getItem('auth_token') || '', session);
    }
    await api.post('/auth/persona', { persona });
  },

  // Async Data Fetching
  getStats: async (userId: string): Promise<UserStats | null> => {
    try {
      return await api.get<UserStats>(`/users/${userId}/stats`);
    } catch (e) {
      console.error("Failed to load stats", e);
      return null; // Fallback or throw?
    }
  },

  saveStats: async (userId: string, stats: UserStats) => {
    await api.post(`/users/${userId}/stats`, stats);
  },

  // Meals
  saveMealLog: async (userId: string, meal: any) => {
    await api.post(`/users/${userId}/meals`, meal);
  },

  getMealHistory: async (userId: string): Promise<any[]> => {
    try {
      return await api.get<any[]>(`/users/${userId}/meals`);
    } catch (e) { return []; }
  },

  // Chat - Now using API instead of localStorage
  saveChatHistory: async (userId: string, messages: ChatMessage[]) => {
    try {
      await api.post(`/users/${userId}/chat`, { messages });
    } catch (e) {
      console.error("Failed to save chat history", e);
      // Fallback to localStorage if API fails
      localStorage.setItem(`zenith_chat_${userId}`, JSON.stringify(messages));
    }
  },

  getChatHistory: async (userId: string): Promise<ChatMessage[]> => {
    try {
      const data = await api.get<ChatMessage[]>(`/users/${userId}/chat`);
      return data.map((m: any) => ({ ...m, timestamp: new Date(m.timestamp) }));
    } catch (e) {
      // Fallback to localStorage
      const data = localStorage.getItem(`zenith_chat_${userId}`);
      if (!data) return [];
      return JSON.parse(data).map((m: any) => ({ ...m, timestamp: new Date(m.timestamp) }));
    }
  },

  // Recommendations
  saveRecommendations: (userId: string, recs: any[]) => {
    localStorage.setItem(`zenith_recs_${userId}`, JSON.stringify(recs));
  },

  getRecommendations: (userId: string): any[] => {
    const data = localStorage.getItem(`zenith_recs_${userId}`);
    return data ? JSON.parse(data) : [];
  },

  // Cohort Progress
  saveCohortProgress: async (userId: string, progress: CohortProgress) => {
    await api.post(`/cohort/${progress.programId}/progress`, progress);
  },

  getCohortProgress: async (userId: string, programId: string): Promise<CohortProgress | null> => {
    try {
      return await api.get<CohortProgress>(`/cohort/${programId}/progress`);
    } catch (e) { return null; }
  },

  // Cycle Tracking
  saveCycleLog: async (userId: string, log: CycleLog) => {
    await api.post(`/users/${userId}/cycles`, log);
  },

  getCycleLogs: async (userId: string): Promise<CycleLog[]> => {
    try {
      return await api.get<CycleLog[]>(`/users/${userId}/cycles`);
    } catch (e) { return []; }
  },

  updateCycleLogEndDate: async (userId: string, logId: string, endDate: string) => {
    await api.put(`/users/${userId}/cycles/${logId}`, { endDate });
  },

  deleteCycleLog: async (userId: string, logId: string) => {
    await api.delete(`/users/${userId}/cycles/${logId}`);
  }
};
