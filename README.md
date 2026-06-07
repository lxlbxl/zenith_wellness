# Zenith Wellness

A comprehensive wellness platform built with React, TypeScript, and Vite.

## 🌟 Features

### Core Components
- **Dashboard** - Personal wellness overview and activity tracking
- **Habit Tracker** - Build and maintain healthy habits
- **Goal Manager** - Set and track wellness goals
- **Journal** - Daily reflection and mood tracking
- **Challenge Hub** - Participate in wellness challenges
- **Cohort Lounge** - Community features and group activities
- **Coach Panel** - Coaching and guidance interface
- **Admin Panel** - Administrative controls

### Health Tracking
- **Cycle Tracker** - Menstrual cycle tracking
- **Meal Tracker** - Nutrition and meal planning
- **Workout Logger** - Exercise tracking
- **Focus Timer** - Productivity and focus sessions
- **Activity Tracker** - Daily activity monitoring

### User Experience
- **Onboarding Quiz** - Personalized onboarding experience
- **Profile Settings** - User profile management
- **Privacy Center** - Privacy controls and settings
- **Notification Center** - Real-time notifications
- **Payment Modal** - Secure payment processing
- **Cookie Banner** - GDPR compliance

### Gamification
- **Trophy Cabinet** - Achievements and rewards
- **Gamification Hub** - Points, badges, and leaderboards

## 🏗️ Project Structure

```
zenith_wellness/
├── api/                    # PHP backend API
│   ├── auth/              # Authentication handlers
│   ├── controllers/       # API controllers
│   ├── database/          # Database configuration
│   ├── middleware/        # API middleware
│   ├── migrations/        # Database migrations
│   ├── services/          # Business logic services
│   └── webhooks/          # Webhook handlers
├── components/            # React components
│   ├── admin/            # Admin-specific components
│   ├── funnels/          # Sales funnel components
│   ├── payment/          # Payment components
│   ├── sales/            # Sales components
│   └── ui/               # Reusable UI components
├── hooks/                 # Custom React hooks
├── services/              # API and business logic services
├── tests/                 # Playwright end-to-end tests
├── utils/                 # Utility functions
├── public/                # Static assets
└── release/               # Release builds
```

## 🚀 Getting Started

### Prerequisites
- Node.js 18+ 
- PHP 8.0+
- MySQL 8.0+
- Composer (for PHP dependencies)

### Installation

1. Install frontend dependencies:
```bash
cd zenith_wellness
npm install
```

2. Install backend dependencies:
```bash
cd api
composer install
```

3. Configure database:
- Copy `api/config.php` and update database credentials
- Run database migrations via `api/migrations/`

### Development

Start the development server:
```bash
npm run dev
```

Run the backend:
```bash
# Windows PowerShell
.\tools\start_backend.ps1
```

### Building for Production

```bash
npm run build
```

## 🧪 Testing

Run end-to-end tests:
```bash
npm run test:e2e
```

## 📱 Responsive Design

The application is fully responsive and optimized for:
- Desktop (1920px+)
- Tablet (768px - 1024px)
- Mobile (320px - 767px)

## 🔐 Security Features

- JWT-based authentication
- Rate limiting on API endpoints
- Input validation and sanitization
- CORS protection
- SQL injection prevention
- XSS protection

## 🛠️ Tech Stack

### Frontend
- React 18
- TypeScript
- Vite
- Tailwind CSS

### Backend
- PHP 8.0+
- MySQL
- RESTful API architecture

### Testing
- Playwright

## 📄 License

Proprietary - Zenith Wellness

## 🤝 Contributing

This is a private project. Please contact the maintainers for contribution guidelines.

## 📞 Support

For support and questions, please refer to the internal documentation.