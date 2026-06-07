import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
    children: ReactNode;
    fallback?: ReactNode;
    onError?: (error: Error, errorInfo: ErrorInfo) => void;
}

interface State {
    hasError: boolean;
    error: Error | null;
    retryCount: number;
}

class ErrorBoundary extends React.Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null,
        retryCount: 0
    };

    public static getDerivedStateFromError(error: Error): Partial<State> {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error("Uncaught error:", error, errorInfo);
        
        // Send to backend logging
        this.reportError(error, errorInfo);
        
        // Custom error handler
        if (this.props.onError) {
            this.props.onError(error, errorInfo);
        }
    }

    private async reportError(error: Error, errorInfo: ErrorInfo) {
        try {
            await fetch('/api/errors/report', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: error.message,
                    stack: error.stack,
                    componentStack: errorInfo.componentStack,
                    url: window.location.href,
                    timestamp: new Date().toISOString()
                })
            });
        } catch {
            // Silently fail if error reporting fails
        }
    }

    private handleRetry = () => {
        this.setState(prev => ({
            hasError: false,
            error: null,
            retryCount: prev.retryCount + 1
        }));
    };

    public render() {
        if (this.state.hasError) {
            // Custom fallback
            if (this.props.fallback) {
                return this.props.fallback;
            }

            // Default fallback with retry
            return (
                <div className="flex h-screen w-full items-center justify-center bg-stone-50">
                    <div className="text-center max-w-md p-6">
                        <div className="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
                            <svg className="w-8 h-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-2xl font-bold text-stone-800 mb-2">Something went wrong</h2>
                        <p className="text-stone-500 mb-6">
                            We've been notified and are looking into it. 
                            {this.state.retryCount > 0 && ` (Retry attempt ${this.state.retryCount})`}
                        </p>
                        <div className="flex gap-3 justify-center">
                            <button
                                onClick={this.handleRetry}
                                className="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-medium hover:shadow-lg transition-all duration-200"
                            >
                                Try Again
                            </button>
                            <button
                                onClick={() => window.location.reload()}
                                className="px-5 py-2.5 bg-stone-200 text-stone-700 rounded-xl font-medium hover:bg-stone-300 transition-all duration-200"
                            >
                                Reload Page
                            </button>
                        </div>
                        {this.state.error && process.env.NODE_ENV === 'development' && (
                            <details className="mt-6 text-left">
                                <summary className="text-sm text-stone-400 cursor-pointer">Error Details</summary>
                                <pre className="mt-2 p-3 bg-stone-100 rounded-lg text-xs overflow-auto max-h-40">
                                    {this.state.error.message}
                                    {'\n\n'}
                                    {this.state.error.stack}
                                </pre>
                            </details>
                        )}
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
