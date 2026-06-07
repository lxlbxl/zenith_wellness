<?php

class NotificationService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function create($userId, $type, $title, $message, $link = null)
    {
        try {
            $id = $this->generateUuid();
            $stmt = $this->db->prepare("
                INSERT INTO notifications (id, user_id, type, title, message, link)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$id, $userId, $type, $title, $message, $link]);
            return $id;
        } catch (PDOException $e) {
            error_log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }

    public function sendMilestoneNotification($userId, $dayCount)
    {
        $milestones = [
            7 => [
                'title' => 'Mastery Milestone: Day 7',
                'message' => 'You\'ve completed your first week of the Metabolic Reset! Your data shows a 15% improvement in focus stability. Keep going! 🚀',
                'type' => 'milestone'
            ],
            14 => [
                'title' => 'Halfway Point Achieved!',
                'message' => '14 days of consistency. You are now in the top 5% of your cohort for protocol adherence. Check your updated Pulse Index! 📈',
                'type' => 'milestone'
            ],
            21 => [
                'title' => 'Protocol Finalized: Elite Status',
                'message' => 'Congratulations! You\'ve finished the 21-day reset. Your metabolic baseline is recalibrated. View your Final Mastery Report. 🏆',
                'type' => 'milestone'
            ]
        ];

        if (isset($milestones[$dayCount])) {
            $m = $milestones[$dayCount];
            return $this->create($userId, $m['type'], $m['title'], $m['message'], '/dashboard');
        }
        return false;
    }

    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
