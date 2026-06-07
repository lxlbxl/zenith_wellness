
import React from 'react';

interface CardProps {
    children: React.ReactNode;
    className?: string;
    onClick?: () => void;
    glass?: boolean;
}

const Card: React.FC<CardProps> = ({ children, className = '', onClick, glass = false }) => {
    const baseClasses = glass
        ? "glass border border-white/40"
        : "bg-white border border-stone-100 shadow-[0_8px_30px_rgb(0,0,0,0.04)]";

    return (
        <div
            onClick={onClick}
            className={`rounded-3xl p-6 transition-all duration-300 ${baseClasses} ${onClick ? 'cursor-pointer hover:-translate-y-1 hover:shadow-xl' : ''} ${className}`}
        >
            {children}
        </div>
    );
};

export default Card;
