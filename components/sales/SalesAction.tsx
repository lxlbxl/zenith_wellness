import React from 'react';
import { User } from '../../types';
import SalesRegistrationForm from './SalesRegistrationForm';
import Button from '../ui/Button';

interface SalesActionProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    onSwitchToLogin?: () => void;
    price?: number;
    btnText?: string;
    defaultPersona?: string;
    className?: string;
    isDark?: boolean;
}

const SalesAction: React.FC<SalesActionProps> = ({
    user,
    onLogin,
    onPurchase,
    onSwitchToLogin,
    price,
    btnText = "Unlock Protocol",
    defaultPersona = 'newbie',
    className = "",
    isDark = false
}) => {
    // Authenticated View: Purchase/Unlock
    if (user) {
        return (
            <div className={`p-8 rounded-[2rem] border shadow-xl flex flex-col items-center text-center ${isDark ? 'bg-zinc-900 border-zinc-800' : 'bg-white border-slate-100'}`}>
                <div className="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl mb-4 animate-pulse">
                    <i className="fa-solid fa-unlock"></i>
                </div>
                <h3 className={`text-2xl font-bold mb-2 ${isDark ? 'text-white' : 'text-slate-900'}`}>Welcome back, {user.name}</h3>
                <p className={`mb-6 ${isDark ? 'text-zinc-400' : 'text-slate-500'}`}>
                    You are logged in and eligible for this protocol.
                </p>

                <Button
                    variant={isDark ? 'secondary' : 'primary'}
                    size="lg"
                    className="w-full shadow-2xl"
                    onClick={onPurchase}
                >
                    {btnText} {price ? `• $${(price / 100).toFixed(0)}` : ''}
                </Button>

                <p className="mt-4 text-xs text-slate-400">
                    One-click enrollment added to your account.
                </p>
            </div>
        );
    }

    // Unauthenticated View: Registration
    return (
        <div className={className}>
            <SalesRegistrationForm
                onSuccess={onLogin!}
                defaultPersona={defaultPersona}
                btnText={btnText}
            />
            {onSwitchToLogin && (
                <div className="mt-4 pt-4 border-t border-slate-100 text-center">
                    <p className="text-xs text-slate-400 font-medium">
                        Already have an account?{' '}
                        <button
                            onClick={onSwitchToLogin}
                            className="text-indigo-600 font-bold hover:underline"
                        >
                            Sign in
                        </button>
                    </p>
                </div>
            )}
        </div>
    );
};

export default SalesAction;
