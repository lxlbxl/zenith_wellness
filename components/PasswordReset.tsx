import React, { useState } from 'react';
import { api } from '../services/api';

type ResetStep = 'request' | 'verify' | 'reset' | 'success';

interface PasswordResetProps {
    onBack: () => void;
}

const PasswordReset: React.FC<PasswordResetProps> = ({ onBack }) => {
    const [step, setStep] = useState<ResetStep>('request');
    const [email, setEmail] = useState('');
    const [token, setToken] = useState('');
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [maskedEmail, setMaskedEmail] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    const handleRequestReset = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const response = await api.post<{ message: string; dev_token?: string }>('/password/request', { email });
            setMessage(response.message);

            // In dev mode, auto-fill token for testing
            if (response.dev_token) {
                setToken(response.dev_token);
                setStep('verify');
            } else {
                setStep('verify');
            }
        } catch (err: any) {
            setError(err.message || 'Failed to send reset request');
        } finally {
            setLoading(false);
        }
    };

    const handleVerifyToken = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const response = await api.post<{ valid: boolean; email?: string; message?: string }>('/password/verify', { token });

            if (response.valid) {
                setMaskedEmail(response.email || '');
                setStep('reset');
            } else {
                setError(response.message || 'Invalid or expired token');
            }
        } catch (err: any) {
            setError(err.message || 'Token verification failed');
        } finally {
            setLoading(false);
        }
    };

    const handleResetPassword = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');

        if (password !== confirmPassword) {
            setError('Passwords do not match');
            return;
        }

        if (password.length < 6) {
            setError('Password must be at least 6 characters');
            return;
        }

        setLoading(true);

        try {
            await api.post('/password/reset', {
                token,
                password,
                password_confirm: confirmPassword
            });
            setStep('success');
        } catch (err: any) {
            setError(err.message || 'Failed to reset password');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-50 via-indigo-50 to-emerald-50 flex items-center justify-center p-4">
            <div className="w-full max-w-md">
                {/* Header */}
                <div className="text-center mb-8">
                    <div className="w-16 h-16 bg-gradient-to-br from-indigo-600 to-purple-600 rounded-3xl mx-auto flex items-center justify-center mb-4 shadow-xl shadow-indigo-200">
                        <i className="fa-solid fa-key text-white text-2xl"></i>
                    </div>
                    <h1 className="text-2xl font-black text-slate-900 tracking-tight">
                        {step === 'success' ? 'Password Reset!' : 'Reset Password'}
                    </h1>
                    <p className="text-slate-500 mt-2 text-sm">
                        {step === 'request' && "Enter your email to receive a reset link"}
                        {step === 'verify' && "Enter the code from your email"}
                        {step === 'reset' && `Create a new password for ${maskedEmail}`}
                        {step === 'success' && "Your password has been updated successfully"}
                    </p>
                </div>

                {/* Error/Message Display */}
                {error && (
                    <div className="mb-6 p-4 bg-rose-50 border border-rose-200 rounded-2xl text-rose-700 text-sm font-medium flex items-center gap-3">
                        <i className="fa-solid fa-circle-exclamation"></i>
                        {error}
                    </div>
                )}
                {message && step === 'verify' && (
                    <div className="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-emerald-700 text-sm font-medium flex items-center gap-3">
                        <i className="fa-solid fa-circle-check"></i>
                        {message}
                    </div>
                )}

                {/* Step: Request */}
                {step === 'request' && (
                    <form onSubmit={handleRequestReset} className="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-xl space-y-6">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                                Email Address
                            </label>
                            <input
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="you@example.com"
                                required
                                className="w-full px-5 py-4 bg-slate-50 border border-slate-100 rounded-2xl text-slate-800 font-medium focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-300 transition-all"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full py-4 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-200 disabled:opacity-50 flex items-center justify-center gap-2"
                        >
                            {loading ? (
                                <i className="fa-solid fa-circle-notch fa-spin"></i>
                            ) : (
                                <>
                                    <i className="fa-solid fa-paper-plane"></i>
                                    Send Reset Link
                                </>
                            )}
                        </button>

                        <button
                            type="button"
                            onClick={onBack}
                            className="w-full py-3 text-slate-500 font-medium hover:text-slate-700 transition-colors"
                        >
                            <i className="fa-solid fa-arrow-left mr-2"></i>
                            Back to Login
                        </button>
                    </form>
                )}

                {/* Step: Verify Token */}
                {step === 'verify' && (
                    <form onSubmit={handleVerifyToken} className="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-xl space-y-6">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                                Reset Code
                            </label>
                            <input
                                type="text"
                                value={token}
                                onChange={(e) => setToken(e.target.value)}
                                placeholder="Enter the code from your email"
                                required
                                className="w-full px-5 py-4 bg-slate-50 border border-slate-100 rounded-2xl text-slate-800 font-medium focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-300 transition-all font-mono"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full py-4 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-200 disabled:opacity-50 flex items-center justify-center gap-2"
                        >
                            {loading ? (
                                <i className="fa-solid fa-circle-notch fa-spin"></i>
                            ) : (
                                <>
                                    <i className="fa-solid fa-check"></i>
                                    Verify Code
                                </>
                            )}
                        </button>

                        <button
                            type="button"
                            onClick={() => setStep('request')}
                            className="w-full py-3 text-slate-500 font-medium hover:text-slate-700 transition-colors"
                        >
                            <i className="fa-solid fa-arrow-left mr-2"></i>
                            Try Different Email
                        </button>
                    </form>
                )}

                {/* Step: Reset Password */}
                {step === 'reset' && (
                    <form onSubmit={handleResetPassword} className="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-xl space-y-6">
                        <div>
                            <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                                New Password
                            </label>
                            <input
                                type="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="••••••••"
                                required
                                minLength={6}
                                className="w-full px-5 py-4 bg-slate-50 border border-slate-100 rounded-2xl text-slate-800 font-medium focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-300 transition-all"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                                Confirm Password
                            </label>
                            <input
                                type="password"
                                value={confirmPassword}
                                onChange={(e) => setConfirmPassword(e.target.value)}
                                placeholder="••••••••"
                                required
                                className="w-full px-5 py-4 bg-slate-50 border border-slate-100 rounded-2xl text-slate-800 font-medium focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-300 transition-all"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full py-4 bg-emerald-600 text-white font-bold rounded-2xl hover:bg-emerald-700 transition-all shadow-xl shadow-emerald-200 disabled:opacity-50 flex items-center justify-center gap-2"
                        >
                            {loading ? (
                                <i className="fa-solid fa-circle-notch fa-spin"></i>
                            ) : (
                                <>
                                    <i className="fa-solid fa-lock"></i>
                                    Set New Password
                                </>
                            )}
                        </button>
                    </form>
                )}

                {/* Step: Success */}
                {step === 'success' && (
                    <div className="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-xl text-center space-y-6">
                        <div className="w-20 h-20 bg-emerald-100 rounded-full mx-auto flex items-center justify-center">
                            <i className="fa-solid fa-check text-emerald-600 text-3xl"></i>
                        </div>

                        <p className="text-slate-600">
                            Your password has been reset. You can now log in with your new password.
                        </p>

                        <button
                            onClick={onBack}
                            className="w-full py-4 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 transition-all shadow-xl shadow-indigo-200 flex items-center justify-center gap-2"
                        >
                            <i className="fa-solid fa-arrow-right-to-bracket"></i>
                            Go to Login
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
};

export default PasswordReset;
