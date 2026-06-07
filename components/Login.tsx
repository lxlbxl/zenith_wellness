import React, { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { db } from '../services/db';
import { api } from '../services/api';
import { User, UserPersona } from '../types';
import PasswordReset from './PasswordReset';
import OnboardingQuiz from './OnboardingQuiz';

interface LoginProps {
  onLogin: (user: User) => void;
  onSwitchToSales?: () => void;
}

const loginSchema = z.object({
  email: z.string().email("Invalid metabolic ID format"),
  password: z.string().min(6, "Security key must be at least 6 characters"),
});

type LoginFormInputs = z.infer<typeof loginSchema>;

const Login: React.FC<LoginProps> = ({ onLogin, onSwitchToSales }) => {
  const [isRegistering, setIsRegistering] = useState(false);
  const [showPasswordReset, setShowPasswordReset] = useState(false);
  const [formError, setFormError] = useState('');

  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginFormInputs>({
    resolver: zodResolver(loginSchema)
  });

  const onLoginSubmit = async (data: LoginFormInputs) => {
    setFormError('');
    try {
      const res = await api.post<{ token: string; user: User }>('/auth/login', { email: data.email, password: data.password });
      if (res.token) {
        api.setToken(res.token);
        db.setSession(res.user);
        onLogin(res.user);
      }
    } catch (err: any) {
      console.error(err);
      setFormError(err.message || 'Authentication failed. Please check your credentials.');
    }
  };

  const handleOnboardingComplete = async (data: any) => {
    try {
      // 1. Register
      await api.post('/auth/register', { name: data.name, email: data.email, password: data.password });

      // 2. Auto-login
      const res = await api.post<{ token: string; user: User }>('/auth/login', { email: data.email, password: data.password });
      api.setToken(res.token);

      // 3. Set Persona
      if (data.persona) {
        db.updateUserPersona(data.email, data.persona as UserPersona);
      }

      // 4. Set Session (reload to get updated persona if needed, though db.updateUserPersona updates local storage)
      const finalUser = db.getSession() || res.user;
      db.setSession(finalUser);
      onLogin(finalUser);

    } catch (err: any) {
      console.error(err);
      alert(err.message || 'Registration failed.');
    }
  };

  // Show password reset flow
  if (showPasswordReset) {
    return <PasswordReset onBack={() => setShowPasswordReset(false)} />;
  }

  // Show Onboarding Quiz (Registration)
  if (isRegistering) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 p-4 font-sans">
        <div className="w-full max-w-4xl">
          <OnboardingQuiz
            onComplete={handleOnboardingComplete}
            onCancel={() => setIsRegistering(false)}
          />
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 p-6 font-sans">
      <div className="w-full max-w-md bg-white p-10 rounded-[3rem] border border-slate-100 shadow-xl shadow-indigo-100/20">
        <div className="flex flex-col items-center mb-10 text-center">
          <div className="w-16 h-16 bg-indigo-600 rounded-[1.5rem] flex items-center justify-center text-white text-3xl mb-6 shadow-lg shadow-indigo-200">
            <i className="fa-solid fa-bolt"></i>
          </div>
          <h1 className="text-3xl font-black text-slate-900 tracking-tight">Zenith Portal</h1>
          <p className="text-slate-500 mt-2 font-medium">
            Log in to your performance protocol
          </p>
        </div>

        <form onSubmit={handleSubmit(onLoginSubmit)} className="space-y-4">
          <div className="space-y-2">
            <label htmlFor="email" className="text-[10px] font-black uppercase text-slate-400 tracking-widest px-1">Email Protocol</label>
            <input
              id="email"
              {...register('email')}
              type="email"
              className="w-full bg-slate-50 border border-slate-100 p-4 rounded-2xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold"
            />
            {errors.email && <p className="text-rose-500 text-xs font-bold px-1">{errors.email.message}</p>}
          </div>
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <label htmlFor="password" className="text-[10px] font-black uppercase text-slate-400 tracking-widest px-1">Security Key</label>
              <button
                type="button"
                onClick={() => setShowPasswordReset(true)}
                className="text-[10px] font-bold text-indigo-600 hover:text-indigo-700 transition-colors"
              >
                Forgot Password?
              </button>
            </div>
            <input
              id="password"
              {...register('password')}
              type="password"
              className="w-full bg-slate-50 border border-slate-100 p-4 rounded-2xl focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold"
            />
            {errors.password && <p className="text-rose-500 text-xs font-bold px-1">{errors.password.message}</p>}
          </div>

          {formError && <p className="text-rose-500 text-xs font-bold text-center animate-in fade-in">{formError}</p>}

          <button
            type="submit"
            disabled={isSubmitting}
            className="w-full bg-indigo-600 text-white py-4 rounded-2xl font-black shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all mt-4 disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            {isSubmitting && <i className="fa-solid fa-circle-notch fa-spin"></i>}
            Initialize Session
          </button>
        </form>

        <div className="mt-8 pt-6 border-t border-slate-50 text-center space-y-3">
          <div>
            <p className="text-xs text-slate-400 font-bold">
              New to Zenith?
            </p>
            <button
              onClick={() => setIsRegistering(true)}
              className="text-indigo-600 font-black text-sm hover:underline mt-1"
            >
              Start Application
            </button>
          </div>
          {onSwitchToSales && (
            <div>
              <button
                onClick={onSwitchToSales}
                className="text-slate-400 font-medium text-xs hover:text-slate-600 hover:underline"
              >
                View membership options
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Login;

