
export type Mood = 'ecstatic' | 'happy' | 'neutral' | 'tired' | 'stressed' | 'down';

export type UserPersona = 'lead' | 'newbie' | 'active' | 'veteran';

export type UserRole = 'user' | 'admin' | 'banned';

// Women's Health Types
export type Symptom = 'cramps' | 'headache' | 'fatigue' | 'mood_swings' | 'bloating' | 'acne' | 'cravings' | 'breast_tenderness' | 'back_pain' | 'nausea';

export type CyclePhase = 'menstrual' | 'follicular' | 'ovulation' | 'luteal';

export type FlowIntensity = 'light' | 'medium' | 'heavy' | 'spotting';

export interface CycleLog {
  id: string;
  startDate: string;
  endDate?: string;
  cycleLength?: number;
  periodLength?: number;
  symptoms: Symptom[];
  flowIntensity?: FlowIntensity;
  notes?: string;
}

export interface CycleStats {
  averageCycleLength: number;
  averagePeriodLength: number;
  currentPhase: CyclePhase;
  dayOfCycle: number;
  nextPeriodDate: string;
  fertileWindowStart?: string;
  fertileWindowEnd?: string;
  ovulationDate?: string;
}

export interface User {
  id: string;
  email: string;
  name: string;
  persona: UserPersona;
  role?: UserRole;
  created_at?: string;
}

export interface DailyGoals {
  focusMinutes: number;
  completedTasks: number;
  targetMood: Mood;
}

export interface CohortTask {
  id: string;
  label: string;
  completed: boolean;
  difficulty: 'low' | 'med' | 'high';
}

export interface Program {
  id: string;
  title: string;
  description: string;
  startDate: string; // ISO string for Cohort logic
  endDate?: string;
  isPurchased?: boolean;
  category: string;
  image: string;
  objectives?: string[];
  price?: number; // in cents
  duration?: number; // days
  maxParticipants?: number;
  participantCount?: number;
  isPublic?: boolean;
  tags?: string[];
}

export interface CohortProgress {
  programId: string;
  currentDay: number;
  tasks: CohortTask[];
  performanceScore: number;
  aiFeedback: string;
}

export interface UserStats {
  focusMinutes: number;
  completedTasks: number;
  moodHistory: { date: string; mood: Mood }[];
  dailyStreak: number;
  goals: DailyGoals;
  purchasedProgramIds: string[];
  macros: { protein: number; carbs: number; fats: number; calories: number };
  cohortProgress?: Record<string, CohortProgress>;
  cycleStats?: CycleStats;
  persona?: UserPersona;
}

export interface ChatMessage {
  role: 'user' | 'model';
  content: string;
  timestamp: Date;
}

export interface RecommendedResource {
  title: string;
  type: 'article' | 'video' | 'exercise';
  description: string;
  link: string;
  duration?: string;
}

export enum AppTab {
  DASHBOARD = 'dashboard',
  ROUTINES = 'routines',
  CHALLENGES = 'challenges',
  MEAL_TRACKER = 'meal_tracker',
  WELLNESS = 'wellness',
  HABITS = 'habits',
  GOALS = 'goals',
  JOURNAL = 'journal',
  COACH = 'coach',
  GAMIFICATION = 'gamification', // Hub for points/achievements
  COHORT_LOUNGE = 'cohort_lounge',
  CYCLE_TRACKER = 'cycle_tracker',
  RESOURCES = 'resources',
  ADMIN = 'admin',
  SETTINGS = 'settings',
  MENU = 'menu'
}

// Workout Types
export interface Exercise {
  id: string;
  name: string;
  category: string;
  description?: string;
  muscle_group?: string;
  icon?: string;
}

export interface WorkoutLog {
  id: string;
  user_id: string;
  exercise_id?: string;
  exercise_name: string;
  workout_type: string;
  duration_minutes: number;
  sets?: number;
  reps?: number;
  weight_kg?: number;
  distance_km?: number;
  calories_burned?: number;
  notes?: string;
  workout_date: string;
  created_at: string;
}

