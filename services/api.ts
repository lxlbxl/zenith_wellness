export const API_BASE_URL = '/api';

interface FetchOptions extends RequestInit {
    token?: string;
}

// Mock Data Store Helpers
const getStorage = (key: string) => {
    try {
        return JSON.parse(localStorage.getItem(key) || '[]');
    } catch { return []; }
};

const setStorage = (key: string, data: any) => {
    localStorage.setItem(key, JSON.stringify(data));
};

class ApiService {
    private token: string | null = null;
    private useMock: boolean = false; // Set to false to use real PHP backend

    constructor() {
        this.token = localStorage.getItem('auth_token');
    }

    setToken(token: string) {
        this.token = token;
        localStorage.setItem('auth_token', token);
    }

    clearToken() {
        this.token = null;
        localStorage.removeItem('auth_token');
    }

    private async mockDelay(ms: number = 500) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    private async mockRouter(endpoint: string, options: FetchOptions): Promise<any> {
        await this.mockDelay();
        const method = options.method || 'GET';
        const body = options.body ? JSON.parse(options.body as string) : {};

        console.log(`[Mock API] ${method} ${endpoint}`, body);

        // --- AUTH ---
        if (endpoint === '/auth/login') {
            const { email } = body;
            const users = getStorage('mock_users');
            const user = users.find((u: any) => u.email === email) || {
                id: '1', email, name: 'Test User', persona: 'active', role: 'user'
            };
            return { token: 'mock_jwt_token', user };
        }
        if (endpoint === '/auth/register') {
            const users = getStorage('mock_users');
            if (users.find((u: any) => u.email === body.email)) throw new Error('User already exists');
            const newUser = { id: Date.now().toString(), ...body, role: 'user' };
            users.push(newUser);
            setStorage('mock_users', users);
            return { message: 'Registered successfully' };
        }

        // --- GOALS ---
        if (endpoint.startsWith('/goals/user/')) {
            const userId = endpoint.split('/')[3].split('?')[0]; // simple parsing
            const goals = getStorage(`mock_goals_${userId}`);

            if (method === 'GET') {
                const statusParam = endpoint.includes('?status=') ? endpoint.split('status=')[1] : null;
                if (statusParam) return goals.filter((g: any) => g.status === statusParam);
                return goals;
            }
            if (method === 'POST') {
                const newGoal = {
                    id: Date.now().toString(),
                    user_id: userId,
                    ...body,
                    current_value: 0,
                    progress_percent: 0,
                    status: 'active',
                    created_at: new Date().toISOString()
                };
                goals.push(newGoal);
                setStorage(`mock_goals_${userId}`, goals);
                return newGoal;
            }
        }

        if (endpoint.startsWith('/goals/goal/')) {
            const goalId = endpoint.split('/')[3];
            // Find user who has this goal (inefficient but works for mock)
            // Actually, we need userId to find the key. 
            // Simplified: Iterate all local storage mock_goals keys? 
            // Hack: Assume current user from session or just search known keys
            const session = this.getSession();
            if (!session) throw new Error('Unauthorized');
            const userId = session.id;
            const goals = getStorage(`mock_goals_${userId}`);

            if (method === 'DELETE') {
                const newGoals = goals.filter((g: any) => g.id !== goalId);
                setStorage(`mock_goals_${userId}`, newGoals);
                return { success: true };
            }
            if (method === 'PUT') {
                const idx = goals.findIndex((g: any) => g.id === goalId);
                if (idx !== -1) {
                    goals[idx] = { ...goals[idx], ...body };
                    setStorage(`mock_goals_${userId}`, goals);
                    return goals[idx];
                }
            }
        }

        if (endpoint.startsWith('/goals/stats/')) {
            const userId = endpoint.split('/')[3];
            const goals = getStorage(`mock_goals_${userId}`);
            return {
                total_goals: goals.length,
                active_goals: goals.filter((g: any) => g.status === 'active').length,
                completed_goals: goals.filter((g: any) => g.status === 'completed').length,
                abandoned_goals: 0,
                completion_rate: goals.length ? Math.round((goals.filter((g: any) => g.status === 'completed').length / goals.length) * 100) : 0,
                by_category: []
            };
        }

        if (endpoint.startsWith('/goals/progress/')) {
            const goalId = endpoint.split('/')[3];
            const session = this.getSession();
            const userId = session?.id || '1'; // fallback
            const goals = getStorage(`mock_goals_${userId}`);
            const idx = goals.findIndex((g: any) => g.id === goalId);

            if (idx !== -1) {
                const goal = goals[idx];
                // Update goal
                if (body.value !== undefined) goal.current_value = body.value;

                // Recalculate progress
                if (goal.target_value > 0) {
                    goal.progress_percent = Math.min(100, Math.round((goal.current_value / goal.target_value) * 100));
                }

                // Save log (optional for mock)
                // const logs = getStorage(`mock_goal_logs_${goalId}`);
                // logs.push({ ...body, date: new Date().toISOString() });
                // setStorage(`mock_goal_logs_${goalId}`, logs);

                goals[idx] = goal;
                setStorage(`mock_goals_${userId}`, goals);
                return goal;
            }
        }

        // --- HABITS ---
        if (endpoint === '/habits/templates') {
            return [
                { id: 'h1', title: 'Morning Water', description: 'Drink 500ml water', category: 'hydration', icon: 'fa-glass-water', recommended_frequency: 'daily' },
                { id: 'h2', title: 'Daily Walk', description: '30 min brisk walk', category: 'movement', icon: 'fa-person-walking', recommended_frequency: 'daily' },
                { id: 'h3', title: 'Meditation', description: '10 min mindfulness', category: 'mindfulness', icon: 'fa-om', recommended_frequency: 'daily' },
                { id: 'h4', title: 'No Sugar', description: 'Avoid added sugar', category: 'nutrition', icon: 'fa-ban', recommended_frequency: 'daily' },
                { id: 'h5', title: 'Read 10 Pages', description: 'Read a book', category: 'self_care', icon: 'fa-book', recommended_frequency: 'daily' }
            ];
        }

        // --- WELLNESS LOGS ---
        if (endpoint === '/wellness/log') {
            const session = this.getSession();
            const userId = session?.id || '1';
            let logs = getStorage(`mock_wellness_logs_${userId}`);
            const { log_date, water_glasses, sleep_hours, sleep_quality } = body;

            if (method === 'POST') {
                if (!logs.find((l: any) => l.log_date === log_date)) {
                    logs.push({ log_date, water_glasses: water_glasses || 0, sleep_hours: sleep_hours || 0, sleep_quality: sleep_quality || 0 });
                    setStorage(`mock_wellness_logs_${userId}`, logs);
                }
                return { success: true };
            }
            if (method === 'PUT') {
                const logIdx = logs.findIndex((l: any) => l.log_date === log_date);
                if (logIdx !== -1) {
                    if (water_glasses !== undefined) logs[logIdx].water_glasses = water_glasses;
                    if (sleep_hours !== undefined) logs[logIdx].sleep_hours = sleep_hours;
                    if (sleep_quality !== undefined) logs[logIdx].sleep_quality = sleep_quality;
                }
                setStorage(`mock_wellness_logs_${userId}`, logs);
                return { success: true };
            }
        }

        // --- CYCLES ---
        if (endpoint.match(/\/users\/[^/]+\/cycles/)) {
            const userId = endpoint.split('/')[2];
            let logs = getStorage(`mock_cycle_logs_${userId}`);

            if (method === 'GET') {
                return logs;
            }

            if (method === 'POST') {
                const newLog = body;
                logs.push(newLog);
                setStorage(`mock_cycle_logs_${userId}`, logs);
                return newLog;
            }

            if (method === 'PUT') {
                // Format: /users/:userId/cycles/:logId
                const parts = endpoint.split('/');
                const logId = parts[4];
                const idx = logs.findIndex((l: any) => l.id === logId);
                if (idx !== -1) {
                    logs[idx] = { ...logs[idx], ...body };
                    setStorage(`mock_cycle_logs_${userId}`, logs);
                    return logs[idx];
                }
            }

            if (method === 'DELETE') {
                const parts = endpoint.split('/');
                const logId = parts[4];
                logs = logs.filter((l: any) => l.id !== logId);
                setStorage(`mock_cycle_logs_${userId}`, logs);
                return { success: true };
            }
        }

        if (endpoint.startsWith('/habits/user/')) {
            const userId = endpoint.split('/')[3];
            const habits = getStorage(`mock_habits_${userId}`);

            if (method === 'GET') {
                return habits;
            }
            if (method === 'POST') {
                const newHabit = {
                    id: Date.now().toString(),
                    user_id: userId,
                    ...body,
                    current_streak: 0,
                    longest_streak: 0,
                    total_completions: 0,
                    is_active: 1,
                    created_at: new Date().toISOString()
                };
                habits.push(newHabit);
                setStorage(`mock_habits_${userId}`, habits);
                return newHabit;
            }
        }

        if (endpoint.startsWith('/habits/today/')) {
            const userId = endpoint.split('/')[3];
            const habits = getStorage(`mock_habits_${userId}`);
            const logs = getStorage(`mock_habit_logs_${userId}`);
            const today = new Date().toISOString().split('T')[0];

            return habits.filter((h: any) => h.is_active).map((h: any) => ({
                ...h,
                completed_today: logs.some((l: any) => l.habit_id === h.id && l.log_date === today)
            }));
        }

        if (endpoint.startsWith('/habits/stats/')) {
            const userId = endpoint.split('/')[3];
            const habits = getStorage(`mock_habits_${userId}`);
            const logs = getStorage(`mock_habit_logs_${userId}`);
            const today = new Date().toISOString().split('T')[0];
            const todayLogs = logs.filter((l: any) => l.log_date === today);

            return {
                active_habits: habits.length,
                total_completions: logs.length,
                best_streak: Math.max(0, ...habits.map((h: any) => h.longest_streak || 0)),
                today_completion_rate: habits.length ? Math.round((todayLogs.length / habits.length) * 100) : 0,
                today_completed: todayLogs.length,
                today_total: habits.length
            };
        }

        if (endpoint === '/habits/log') {
            const session = this.getSession();
            const userId = session?.id || '1';
            const logs = getStorage(`mock_habit_logs_${userId}`);
            const habits = getStorage(`mock_habits_${userId}`);
            const { habit_id, log_date } = method === 'DELETE' ? (options.body ? JSON.parse(options.body as string) : {}) : body;

            if (method === 'POST') {
                if (!logs.find((l: any) => l.habit_id === habit_id && l.log_date === log_date)) {
                    logs.push({ habit_id, log_date });
                    setStorage(`mock_habit_logs_${userId}`, logs);

                    // Update streak (simplified)
                    const habitIdx = habits.findIndex((h: any) => h.id === habit_id);
                    if (habitIdx !== -1) {
                        habits[habitIdx].current_streak += 1;
                        habits[habitIdx].total_completions += 1;
                        habits[habitIdx].longest_streak = Math.max(habits[habitIdx].longest_streak, habits[habitIdx].current_streak);
                        setStorage(`mock_habits_${userId}`, habits);
                    }
                }
                return { success: true };
            }
            if (method === 'DELETE') {
                const newLogs = logs.filter((l: any) => !(l.habit_id === habit_id && l.log_date === log_date));
                setStorage(`mock_habit_logs_${userId}`, newLogs);

                // Update streak (simplified - decrement)
                const habitIdx = habits.findIndex((h: any) => h.id === habit_id);
                if (habitIdx !== -1 && habits[habitIdx].current_streak > 0) {
                    habits[habitIdx].current_streak -= 1;
                    habits[habitIdx].total_completions -= 1;
                    setStorage(`mock_habits_${userId}`, habits);
                }
                return { success: true };
            }
        }

        if (endpoint.startsWith('/habits/')) {
            // Likely delete /habits/:id
            const habitId = endpoint.split('/')[2];
            if (method === 'DELETE') {
                const session = this.getSession();
                const userId = session?.id || '1';
                const habits = getStorage(`mock_habits_${userId}`);
                const newHabits = habits.filter((h: any) => h.id !== habitId);
                setStorage(`mock_habits_${userId}`, newHabits);
                return { success: true };
            }
        }

        // --- USERS / STATS ---
        if (endpoint.includes('/stats')) {
            return null; // Return null so app uses defaults, or implement mock stats
        }

        // Default: 404
        console.warn(`[Mock API] Endpoint not mocked: ${endpoint}`);
        throw new Error('Not Found (Mock)');
    }

    async request<T>(endpoint: string, options: FetchOptions = {}): Promise<T> {
        if (this.useMock) {
            return this.mockRouter(endpoint, options);
        }

        const headers = new Headers(options.headers);
        headers.set('Content-Type', 'application/json');

        if (this.token && !endpoint.includes('/auth/')) {
            if (this.isTokenExpired(this.token)) {
                console.warn("Token expired - session will be refreshed on next login");
                // Don't clear session here - let the 401 handler or user logout handle it
                // This prevents aggressive logout on page refresh
            }
            headers.set('Authorization', `Bearer ${this.token}`);
        }

        let response: Response;
        try {
            response = await fetch(`${API_BASE_URL}${endpoint}`, {
                ...options,
                headers,
            });
        } catch (networkError) {
            // Network error (backend unavailable, CORS, etc.)
            console.warn("Network error - backend may be unavailable:", networkError);
            throw new Error('Network error - please check your connection');
        }

        let data: any;
        try {
            data = await response.json();
        } catch (parseError) {
            // Response is not JSON
            console.warn("Failed to parse response as JSON");
            throw new Error('Invalid server response');
        }

        if (!response.ok) {
            if (response.status === 401 && !endpoint.includes('/auth/')) {
                console.warn("Unauthorized access - token may be expired");
                // Don't auto-clear session or redirect - let the component handle the error
                // This prevents logout on page refresh when backend is unavailable
            }
            throw new Error(data.message || 'API Request Failed');
        }

        return data as T;
    }

    get<T>(endpoint: string) {
        return this.request<T>(endpoint, { method: 'GET' });
    }

    post<T>(endpoint: string, body: any) {
        return this.request<T>(endpoint, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    }

    put<T>(endpoint: string, body: any) {
        return this.request<T>(endpoint, {
            method: 'PUT',
            body: JSON.stringify(body),
        });
    }

    delete<T>(endpoint: string, body?: any) {
        return this.request<T>(endpoint, {
            method: 'DELETE',
            body: body ? JSON.stringify(body) : undefined
        });
    }

    // Auth Helpers
    setSession(token: string, user: any) {
        this.setToken(token);
        localStorage.setItem('zenith_session', JSON.stringify(user));
    }

    getSession() {
        const session = localStorage.getItem('zenith_session');
        return session ? JSON.parse(session) : null;
    }

    clearSession() {
        this.clearToken();
        localStorage.removeItem('zenith_session');
    }

    private isTokenExpired(token: string): boolean {
        try {
            if (!token || !token.includes('.')) {
                return false; // Can't determine expiration, assume valid
            }
            const payload = JSON.parse(atob(token.split('.')[1]));
            if (!payload.exp) {
                return false; // No expiration claim, assume valid
            }
            return payload.exp * 1000 < Date.now();
        } catch (e) {
            // If we can't parse the token, don't assume it's expired
            // Let the backend validate it instead
            return false;
        }
    }
}

export const api = new ApiService();
