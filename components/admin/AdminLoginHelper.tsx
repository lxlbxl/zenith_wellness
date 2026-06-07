
import React from 'react';
import { api } from '../../services/api';

const AdminLoginHelper: React.FC<{ onRetry: () => void; errorDetails?: string }> = ({ onRetry, errorDetails }) => {
    const handleReLogin = async () => {
        // Force logout and redirect or just clear token
        api.clearSession();
        window.location.reload();
    };

    return (
        <div className="p-8 text-center">
            <div className="mb-4 text-amber-500 text-4xl"><i className="fa-solid fa-lock"></i></div>
            <h3 className="text-xl font-bold text-slate-800">Authentication Required</h3>
            <p className="text-slate-500 my-4 max-w-md mx-auto">
                The Admin Console requires a fresh login to verify permissions with the new backend system.
            </p>
            {errorDetails && (
                <div className="bg-rose-50 text-rose-600 p-3 rounded-lg text-xs font-mono mb-4 border border-rose-100">
                    Error: {errorDetails}
                </div>
            )}
            <button
                onClick={handleReLogin}
                className="bg-slate-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-slate-800 transition-colors"
            >
                Log Out & Sign In Again
            </button>
            <p className="mt-4 text-xs text-slate-400">
                Use the seeded admin account: <strong>test@zenith.com</strong> / <strong>password</strong>
            </p>
        </div>
    );
};

export default AdminLoginHelper;
