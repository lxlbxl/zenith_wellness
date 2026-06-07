
import React, { useState } from 'react';
import { api } from '../../services/api';
import { User } from '../../types';
import { db } from '../../services/db';

interface SalesRegistrationFormProps {
    onSuccess: (user: User) => void;
    defaultPersona?: string;
    btnText?: string;
    className?: string;
}

const SalesRegistrationForm: React.FC<SalesRegistrationFormProps> = ({
    onSuccess,
    defaultPersona = 'newbie',
    btnText = "Start My Transformation",
    className = ""
}) => {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleRegister = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError('');

        try {
            // 1. Register
            await api.post('/auth/register', {
                name,
                email,
                password,
                persona: defaultPersona
            });

            // 2. Auto-Login
            const loginRes = await api.post<any>('/auth/login', {
                email,
                password
            });

            if (loginRes.token && loginRes.user) {
                // 3. Save Session - use api.setToken to ensure correct key is used
                api.setToken(loginRes.token);
                db.setSession(loginRes.user);

                onSuccess(loginRes.user);
            }
        } catch (err: any) {
            console.error(err);
            setError(err.message || 'Registration failed. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleRegister} className={`space-y-4 ${className}`}>
            {error && (
                <div className="p-3 bg-red-50 text-red-600 text-sm rounded-xl border border-red-100 flex items-center gap-2">
                    <i className="fa-solid fa-circle-exclamation"></i>
                    {error}
                </div>
            )}

            <div>
                <label className="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Full Name</label>
                <input
                    type="text"
                    required
                    value={name}
                    onChange={e => setName(e.target.value)}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-medium focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all"
                    placeholder="e.g. Alex Chen"
                />
            </div>

            <div>
                <label className="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Email Address</label>
                <input
                    type="email"
                    required
                    value={email}
                    onChange={e => setEmail(e.target.value)}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-medium focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all"
                    placeholder="alex@example.com"
                />
            </div>

            <div>
                <label className="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Password</label>
                <input
                    type="password"
                    required
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 font-medium focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all"
                    placeholder="Min. 8 characters"
                />
            </div>

            <button
                type="submit"
                disabled={loading}
                className="w-full bg-indigo-600 text-white font-black uppercase tracking-widest py-4 rounded-xl shadow-xl shadow-indigo-200 hover:bg-indigo-700 hover:scale-[1.02] active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
                {loading ? (
                    <><i className="fa-solid fa-circle-notch fa-spin"></i> Processing...</>
                ) : (
                    <>{btnText} <i className="fa-solid fa-arrow-right"></i></>
                )}
            </button>

            <p className="text-center text-xs text-slate-400 mt-4">
                <i className="fa-solid fa-lock mr-1"></i>
                Encrypted & Secure. No credit card required.
            </p>
        </form>
    );
};

export default SalesRegistrationForm;
