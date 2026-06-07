
import React from 'react';

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: 'primary' | 'secondary' | 'ghost' | 'outline';
    size?: 'sm' | 'md' | 'lg';
    children: React.ReactNode;
    icon?: string;
}

const Button: React.FC<ButtonProps> = ({
    variant = 'primary',
    size = 'md',
    children,
    icon,
    className = '',
    ...props
}) => {
    const baseStyles = "inline-flex items-center justify-center rounded-full font-bold transition-all duration-300 active:scale-95 disabled:opacity-50 disabled:pointer-events-none";

    const variants = {
        primary: "bg-brand-600 hover:bg-brand-700 text-white shadow-lg shadow-brand-500/30 hover:shadow-brand-500/40",
        secondary: "bg-stone-100 hover:bg-stone-200 text-stone-700",
        ghost: "bg-transparent hover:bg-stone-100 text-stone-500 hover:text-stone-800",
        outline: "border-2 border-brand-500 text-brand-600 hover:bg-brand-50"
    };

    const sizes = {
        sm: "px-4 py-2 text-xs",
        md: "px-6 py-3 text-sm",
        lg: "px-8 py-4 text-base"
    };

    return (
        <button
            className={`${baseStyles} ${variants[variant]} ${sizes[size]} ${className}`}
            {...props}
        >
            {icon && <i className={`fa-solid ${icon} mr-2`}></i>}
            {children}
        </button>
    );
};

export default Button;
