import { api } from "./api";
import { UserStats, ChatMessage, User, Program, CohortProgress } from "../types";

// --- Helpers ---

/** Extract text from multi-format AI API responses (Gemini / OpenAI / OpenRouter) */
const extractText = (response: any): string | null => {
  return response?.candidates?.[0]?.content?.parts?.[0]?.text
    || response?.choices?.[0]?.message?.content
    || null;
};

/** Safely parse JSON from AI text, stripping markdown fences */
const safeParseJSON = <T>(text: string | null, fallback: T): T => {
  if (!text) return fallback;
  try {
    const cleaned = text.replace(/```json\n?|\n?```/g, '').trim();
    const parsed = JSON.parse(cleaned);
    return parsed as T;
  } catch {
    console.warn("Failed to parse AI JSON response:", text?.substring(0, 200));
    return fallback;
  }
};

// --- Session-level caches ---

const sessionCache: Record<string, { data: any; ts: number }> = {};
const CACHE_TTL_MS = 10 * 60 * 1000; // 10 minutes

const getCached = <T>(key: string): T | null => {
  const entry = sessionCache[key];
  if (entry && Date.now() - entry.ts < CACHE_TTL_MS) {
    return entry.data as T;
  }
  if (entry) delete sessionCache[key];
  return null;
};

const setCache = (key: string, data: any) => {
  sessionCache[key] = { data, ts: Date.now() };
};

// --- AI Coaching Chat ---

export const getWellnessCoaching = async (
  user: User,
  stats: UserStats,
  history: ChatMessage[],
  currentMessage: string
) => {
  try {
    // Send recent history for context (last 10 messages to stay within token limits)
    const recentHistory = history.slice(-10).map(m => ({
      role: m.role,
      content: m.content
    }));

    const response = await api.post<any>('/ai/chat', {
      agent: 'coach_sara',
      userId: user.id,
      message: currentMessage,
      history: recentHistory
    });

    const text = extractText(response);
    return text || "I'm here for you. Let's refocus on your wellness journey.";
  } catch (error) {
    console.error("Coaching Error:", error);
    throw new Error("Connection interrupted. Take a deep breath, and let's try again.");
  }
};

// --- Meal Image Analysis ---

export const analyzeMealImage = async (base64Image: string) => {
  const fallback = {
    foodItems: ["Unknown meal"],
    macros: { protein: 0, carbs: 0, fats: 0, calories: 0 },
    wellnessScore: 0,
    summary: "Could not analyze this meal. Please try a clearer photo."
  };

  try {
    const response = await api.post<any>('/ai/analyze-meal', {
      image: base64Image
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, null);

    if (!parsed) return fallback;

    // Validate required fields exist with safe defaults
    return {
      foodItems: Array.isArray(parsed.foodItems) ? parsed.foodItems : ["Detected meal"],
      macros: {
        protein: Number(parsed.macros?.protein) || 0,
        carbs: Number(parsed.macros?.carbs) || 0,
        fats: Number(parsed.macros?.fats) || 0,
        calories: Number(parsed.macros?.calories) || 0,
      },
      wellnessScore: Number(parsed.wellnessScore ?? parsed.pcosScore ?? parsed.pcos_score) || 0,
      summary: parsed.summary || parsed.metabolicVerdict || parsed.verdict || "Meal analyzed successfully.",
    };
  } catch (error) {
    console.error("Meal Analysis Error:", error);
    throw error;
  }
};

// --- Smart Recommendations ---

export const getSmartRecommendations = async (userId: string, stats: UserStats) => {
  const prompt = `Recommend 3 specific wellness resources based on my current performance.
  Focus: ${stats.focusMinutes}m, Goal: ${stats.goals.focusMinutes}m.
  Calories: ${stats.macros.calories}kcal.
  Recent Mood: ${stats.moodHistory[stats.moodHistory.length - 1]?.mood || 'unknown'}.

  Return JSON array: [{title, type (video/article/exercise), description, duration}]`;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'content_studio',
      userId,
      message: prompt,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON<any[]>(text, []);

    // Validate each item
    return Array.isArray(parsed) ? parsed.map(item => ({
      title: item.title || "Wellness Resource",
      type: item.type || "article",
      description: item.description || "",
      duration: item.duration || "5 min",
      link: item.link || "#"
    })) : [];
  } catch (error) {
    console.error("Recommendations Error:", error);
    return [];
  }
};

// --- Cohort Morning Briefing ---

export const getCohortMorningBriefing = async (userId: string, program: Program, stats: UserStats) => {
  // Check cache first
  const cacheKey = `briefing_${userId}_${program.id}_${new Date().toDateString()}`;
  const cached = getCached<any>(cacheKey);
  if (cached) return cached;

  const fallback = {
    briefing: "Focus on your protocol objectives today. Small consistent actions lead to lasting results.",
    tasks: [] as { label: string; difficulty: string }[],
    performanceTip: "Stay hydrated and prioritize protein at your first meal."
  };

  const prompt = `Generate my morning briefing for today. I'm in the "${program.title}" program.

  Return JSON: { briefing: string, tasks: [{label, difficulty}], performanceTip: string }`;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'content_studio',
      userId,
      message: prompt,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, fallback);
    const result = {
      briefing: parsed.briefing || fallback.briefing,
      tasks: Array.isArray(parsed.tasks) ? parsed.tasks.map((t: any) => ({
        label: t.label || "Complete task",
        difficulty: t.difficulty || "med"
      })) : [],
      performanceTip: parsed.performanceTip || fallback.performanceTip
    };

    setCache(cacheKey, result);
    return result;
  } catch (error) {
    console.error("Morning Briefing Error:", error);
    return fallback;
  }
};

// --- Cohort Performance Analysis ---

export const getCohortPerformanceAnalysis = async (userId: string, program: Program, progress: CohortProgress) => {
  const fallback = {
    insight: "Continue working through your protocol tasks. Every completed objective strengthens your trajectory.",
    status: "In Progress",
    masteryScores: (program.objectives || []).map(o => ({ objective: o, score: Math.round(progress.performanceScore * 0.8) })),
    recommendation: "Maintain your current pace and focus on consistency."
  };

  const prompt = `Analyze my progress in the "${program.title}" cohort.
  Completed: ${progress.tasks.filter(t => t.completed).length}/${progress.tasks.length} tasks.
  Score: ${progress.performanceScore}%.

  Return JSON: { insight, status, masteryScores: [{objective, score}], recommendation }`;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'analyst',
      userId,
      message: prompt,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, fallback);

    return {
      insight: parsed.insight || fallback.insight,
      status: parsed.status || fallback.status,
      masteryScores: Array.isArray(parsed.masteryScores) ? parsed.masteryScores : fallback.masteryScores,
      recommendation: parsed.recommendation || fallback.recommendation
    };
  } catch (error) {
    console.error("Performance Analysis Error:", error);
    return fallback;
  }
};

// --- Prep Guide Generation ---

export const generatePrepGuide = async (user: User, stats: UserStats, programId: string) => {
  const fallback = {
    overview: "Your 7-day preparation begins now. Focus on cleaning out inflammatory foods.",
    dailyTasks: [
      { day: 1, title: "Kitchen Audit", tasks: ["Remove sugary drinks", "Check expiration dates", "Organize protein sources"] },
      { day: 2, title: "Meal Planning", tasks: ["Plan breakfast options", "Schedule grocery run", "Prep containers"] },
      { day: 3, title: "Sleep Optimization", tasks: ["Set consistent bedtime", "Remove screens from bedroom", "Get blackout curtains"] }
    ],
    shoppingList: ["Eggs", "Salmon", "Leafy greens", "Avocados", "Nuts", "Olive oil"],
    tips: ["Start hydrating with 8 glasses daily", "Begin reducing caffeine gradually", "Track your mood daily"],
    weeklyGoals: ["Complete pantry audit", "Log 3 meals", "Sleep 7+ hours nightly"]
  };

  const prompt = `Generate a personalized 7-day prep guide for "${user.name}" joining the metabolic reset program.
  User Persona: ${user.persona}
  Current focus: ${stats.focusMinutes}m, Macros: ${stats.macros.calories}kcal.
  Program ID: ${programId}

  Include: meal timing strategies, sleep optimization, pantry cleanup checklist, daily micro-tasks.
  Format as JSON with sections:
  {
    "overview": "string",
    "dailyTasks": [{"day": 1, "title": "string", "tasks": ["string"]}],
    "shoppingList": ["string"],
    "tips": ["string"],
    "weeklyGoals": ["string"]
  }`;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'content_studio',
      userId: user.id,
      message: prompt,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, fallback);
    return {
      overview: parsed.overview || fallback.overview,
      dailyTasks: Array.isArray(parsed.dailyTasks) ? parsed.dailyTasks : fallback.dailyTasks,
      shoppingList: Array.isArray(parsed.shoppingList) ? parsed.shoppingList : fallback.shoppingList,
      tips: Array.isArray(parsed.tips) ? parsed.tips : fallback.tips,
      weeklyGoals: Array.isArray(parsed.weeklyGoals) ? parsed.weeklyGoals : fallback.weeklyGoals
    };
  } catch (error) {
    console.error("Prep Guide Generation Error:", error);
    return fallback;
  }
};

// --- Workshop Curriculum ---

export const generateWorkshopCurriculum = async (userId: string, workshopTitle: string) => {
  const fallback = {
    title: workshopTitle,
    duration: "2 hours",
    objectives: ["Understand core metabolic principles", "Learn practical implementation strategies"],
    sessions: [
      { time: "0:00 - 0:30", topic: "Introduction", description: "Overview and goal setting" },
      { time: "0:30 - 1:00", topic: "Core Concepts", description: "Deep dive into metabolic health" },
      { time: "1:00 - 1:30", topic: "Practical Application", description: "Hands-on exercises" },
      { time: "1:30 - 2:00", topic: "Q&A", description: "Live questions and answers" }
    ],
    materials: ["Workbook PDF", "Meal planning template", "Progress tracker"],
    takeaways: ["Personalized action plan", "Community access", "Follow-up resources"]
  };

  const prompt = `Generate a detailed curriculum for the wellness workshop: "${workshopTitle}".
  Include: learning objectives, session breakdown with times, materials needed.
  Format as JSON: {
    "title": "string",
    "duration": "string",
    "objectives": ["string"],
    "sessions": [{"time": "string", "topic": "string", "description": "string"}],
    "materials": ["string"],
    "takeaways": ["string"]
  }`;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'content_studio',
      userId,
      message: prompt,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, fallback);
    return {
      title: parsed.title || fallback.title,
      duration: parsed.duration || fallback.duration,
      objectives: Array.isArray(parsed.objectives) ? parsed.objectives : fallback.objectives,
      sessions: Array.isArray(parsed.sessions) ? parsed.sessions : fallback.sessions,
      materials: Array.isArray(parsed.materials) ? parsed.materials : fallback.materials,
      takeaways: Array.isArray(parsed.takeaways) ? parsed.takeaways : fallback.takeaways
    };
  } catch (error) {
    console.error("Curriculum Generation Error:", error);
    return fallback;
  }
};

// --- Personalized Greeting (cached per session) ---

export const getPersonalizedGreeting = async (userId: string) => {
  const cacheKey = `greeting_${userId}`;
  const cached = getCached<string>(cacheKey);
  if (cached) return cached;

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'coach_sara',
      userId,
      message: 'Give me a brief, warm personalized greeting (1-2 sentences max). Reference my current state - streak, energy, or recent achievement. Include one micro-suggestion for right now.'
    });

    const text = extractText(response);
    const greeting = text || "Welcome back! You're doing great - keep up the momentum!";
    setCache(cacheKey, greeting);
    return greeting;
  } catch (error) {
    console.error("Personalized Greeting Error:", error);
    return "Welcome back! Ready to make today count?";
  }
};

// --- Sentiment Trend Analysis (used by Journal) ---

export const getSentimentTrend = async (userId: string) => {
  const fallback = {
    overallTrend: "stable" as const,
    dominantMood: "neutral",
    insight: "Keep journaling consistently to build a clearer picture of your emotional patterns.",
    affirmation: "Every word you write is an act of self-awareness.",
    suggestedFocus: "Continue your reflection practice."
  };

  try {
    const response = await api.post<any>('/ai/chat', {
      agent: 'companion',
      userId,
      message: `Analyze my emotional journey over the past week based on my journal entries.
      Return JSON: {
        overallTrend: "improving"|"stable"|"declining",
        dominantMood: string,
        insight: string,
        affirmation: string,
        suggestedFocus: string
      }`,
      config: { responseMimeType: "application/json" }
    });

    const text = extractText(response);
    const parsed = safeParseJSON(text, fallback);
    return {
      overallTrend: parsed.overallTrend || fallback.overallTrend,
      dominantMood: parsed.dominantMood || fallback.dominantMood,
      insight: parsed.insight || fallback.insight,
      affirmation: parsed.affirmation || fallback.affirmation,
      suggestedFocus: parsed.suggestedFocus || fallback.suggestedFocus
    };
  } catch (error) {
    console.error("Sentiment Trend Error:", error);
    return fallback;
  }
};
