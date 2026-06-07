// src/utils/countdown.ts
export const calculateTimeRemaining = (targetDate: Date) => {
    const total = Date.parse(targetDate.toString()) - Date.parse(new Date().toString());
    const seconds = Math.floor((total / 1000) % 60);
    const minutes = Math.floor((total / 1000 / 60) % 60);
    const hours = Math.floor((total / (1000 * 60 * 60)) % 24);
    const days = Math.floor(total / (1000 * 60 * 60 * 24));

    return {
        total,
        days,
        hours,
        minutes,
        seconds
    };
};

export const getNextCohortDate = () => {
    // Logic to find the next Friday or specific date
    // For now, just return a date 3 days from now for demo purposes
    const date = new Date();
    date.setDate(date.getDate() + 3);
    date.setHours(23, 59, 59);
    return date;
};
