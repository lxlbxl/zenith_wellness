
import React, { useState } from 'react';
import Card from './Card';
import Button from './Button';

interface MultiStepFormProps {
    steps: {
        title: string;
        description?: React.ReactNode;
        component: React.ReactNode;
        isValid?: boolean;
    }[];
    onComplete: () => void;
    onCancel?: () => void;
    headerTitle?: string;
    submitLabel?: string;
}

const MultiStepForm: React.FC<MultiStepFormProps> = ({
    steps,
    onComplete,
    onCancel,
    headerTitle = "Form",
    submitLabel = "Complete"
}) => {
    const [currentStep, setCurrentStep] = useState(0);

    const handleNext = () => {
        if (currentStep < steps.length - 1) {
            setCurrentStep(prev => prev + 1);
        } else {
            onComplete();
        }
    };

    const handleBack = () => {
        if (currentStep > 0) {
            setCurrentStep(prev => prev - 1);
        } else if (onCancel) {
            onCancel();
        }
    };

    const currentStepData = steps[currentStep];
    const progress = ((currentStep + 1) / steps.length) * 100;

    return (
        <div className="w-full max-w-2xl mx-auto">
            {/* Progress Bar */}
            <div className="mb-8">
                <div className="flex justify-between items-end mb-2">
                    <span className="text-[10px] font-bold uppercase text-stone-400 tracking-widest">
                        Step {currentStep + 1} of {steps.length}
                    </span>
                    <span className="text-[10px] font-bold uppercase text-brand-600 tracking-widest">
                        {Math.round(progress)}%
                    </span>
                </div>
                <div className="w-full h-1 bg-stone-100 rounded-full overflow-hidden">
                    <div
                        className="h-full bg-brand-500 transition-all duration-500 ease-out"
                        style={{ width: `${progress}%` }}
                    ></div>
                </div>
            </div>

            <Card className="min-h-[400px] flex flex-col relative !p-0 overflow-hidden shadow-2xl shadow-stone-200/50">
                {/* Step Content */}
                <div className="p-8 md:p-10 flex-1 flex flex-col">
                    <header className="mb-6 animate-in fade-in slide-in-from-left-4 duration-500" key={`header-${currentStep}`}>
                        <h2 className="text-3xl font-serif text-stone-800 mb-2">{currentStepData.title}</h2>
                        {currentStepData.description && (
                            <p className="text-stone-500">{currentStepData.description}</p>
                        )}
                    </header>

                    <div className="flex-1 animate-in fade-in zoom-in-95 duration-500 delay-100" key={`content-${currentStep}`}>
                        {currentStepData.component}
                    </div>
                </div>

                {/* Navigation Footer */}
                <div className="p-6 bg-stone-50 border-t border-stone-100 flex justify-between items-center">
                    <Button variant="ghost" onClick={handleBack} disabled={currentStep === 0 && !onCancel}>
                        {currentStep === 0 ? (onCancel ? 'Cancel' : '') : 'Back'}
                    </Button>

                    <Button
                        variant="primary"
                        onClick={handleNext}
                        disabled={currentStepData.isValid === false}
                        className="min-w-[120px]"
                    >
                        {currentStep === steps.length - 1 ? submitLabel : 'Next'} <i className="fa-solid fa-arrow-right ml-2 text-xs"></i>
                    </Button>
                </div>
            </Card>
        </div>
    );
};

export default MultiStepForm;
