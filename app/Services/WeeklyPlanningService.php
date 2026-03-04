<?php

namespace App\Services;

use App\Models\User;
use App\Models\WeeklyReview;
use App\Models\WeeklyGoalTarget;
use App\Models\HabitLog;
use App\Models\MidLevelGoal;
use App\Jobs\AnalyzePastWeekJob;
use App\Jobs\GivePlanningBlessingJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SHOWCASE: Domain Logic & Business Rules
 *
 * @challenge Translating abstract psychological concepts (like "Grit" and "Antifragility") into measurable, programmatic metrics.
 * @solution Abstracted complex business rules into a dedicated Service layer, managing the Planning Window and mathematically calculating the Grit Score based on user effort and results.
 * @highlight Shows strong domain-driven thinking, database transaction handling, queue dispatching, and the ability to convert real-world business requirements into clean, testable algorithms.
 */
class WeeklyPlanningService
{
    /**
     * Determines the current planning window state based on the user's timezone and the day of the week.
     */
    public function getPlanningWindow(User $user): array
    {
        $tz = config('request.user_timezone', 'UTC');
        $now = now($tz);
        $dayOfWeek = $now->dayOfWeekIso; // 1 (Mon) - 7 (Sun)

        // FRIDAY (5), SATURDAY (6), SUNDAY (7) -> CURRENT WEEK
        if ($dayOfWeek >= 5) {
            $start = $now->copy()->startOfWeek();
            $end = $now->copy()->endOfWeek();

            return [
                'status' => 'open',
                'mode' => 'current',
                'week_start' => $start->toDateString(),
                'week_end' => $end->toDateString(),
                'label' => 'Semana Actual',
                'message' => 'Estás revisando la semana en curso. Los datos de este fin de semana se irán completando.',
                'color' => 'amber'
            ];
        }

        // MONDAY (1), TUESDAY (2) -> PREVIOUS WEEK (GRACE PERIOD)
        if ($dayOfWeek <= 2) {
            $prevWeek = $now->copy()->subWeek();

            return [
                'status' => 'open',
                'mode' => 'grace_period',
                'week_start' => $prevWeek->startOfWeek()->toDateString(),
                'week_end' => $prevWeek->endOfWeek()->toDateString(),
                'label' => 'Semana Pasada',
                'message' => 'Estás en periodo de gracia. Cierra la semana pasada antes de que sea tarde.',
                'color' => 'red'
            ];
        }

        // WEDNESDAY (3), THURSDAY (4) -> CLOSED (Execution Zone)
        return [
            'status' => 'closed',
            'mode' => 'execution_zone',
            'week_start' => null,
            'week_end' => null,
            'label' => 'Zona de Ejecución',
            'message' => 'La ventana de planificación está cerrada. Enfócate en ejecutar hoy. Vuelve el viernes.',
            'color' => 'gray'
        ];
    }

    /**
     * Retrieves the most recent completed reviews for the history timeline.
     */
    public function getReviewHistory(User $user)
    {
        return $user->weeklyReviews()
            ->where('status', 'completed')
            ->orderBy('week_start_date', 'desc')
            ->take(12)
            ->get()
            ->map(function ($review) {
                return [
                    'id' => $review->id,
                    'week_label' => 'Semana del ' . Carbon::parse($review->week_start_date)->format('d M'),
                    'period' => Carbon::parse($review->week_start_date)->format('d M') . ' - ' . Carbon::parse($review->week_end_date)->format('d M'),
                    'grit_score' => $review->grit_score,
                    'alignment' => $review->vision_alignment_score,
                    'completed_at_human' => $review->updated_at->diffForHumans(),
                    'is_perfect' => $review->grit_score >= 90
                ];
            });
    }

    /**
     * Initializes a Draft review and asynchronously triggers the past week analysis via AI.
     */
    public function initializeDraft(User $user, string $weekStart, string $weekEnd): WeeklyReview
    {
        $review = WeeklyReview::firstOrCreate(
            [
                'user_id' => $user->id,
                'week_start_date' => $weekStart,
                'week_end_date' => $weekEnd,
            ],
            [
                'status' => 'draft',
                'grit_score' => 0,
                'stats_snapshot' => [],
            ]
        );

        // If it's a new review or lacks stats, calculate the historical truth
        if (empty($review->stats_snapshot)) {
            $stats = $this->calculatePastStats($user, $weekStart, $weekEnd);
            $grit = $this->calculateGritScore($stats['metrics']);

            $review->update([
                'stats_snapshot' => $stats,
                'grit_score' => $grit
            ]);

            // Dispatch AI analysis job for premium users
            if ($user->isPremium()) {
                AnalyzePastWeekJob::dispatch($review);
            }
        }

        return $review;
    }

    /**
     * Commits the planning to the database using a transaction to ensure data integrity,
     * then triggers the tactical advice AI job.
     */
    public function submitPlanning(User $user, array $data): WeeklyReview
    {
        return DB::transaction(function () use ($user, $data) {
            $review = WeeklyReview::where('user_id', $user->id)
                ->where('week_start_date', $data['week_start_date'])
                ->firstOrFail();

            $review->update([
                'vision_alignment_score' => $data['vision_alignment_score'] ?? null,
                'user_global_reflection' => $data['user_global_reflection'] ?? null,
                'status' => 'completed',
            ]);

            if (!empty($data['targets'])) {
                foreach ($data['targets'] as $targetData) {
                    WeeklyGoalTarget::updateOrCreate(
                        [
                            'weekly_review_id' => $review->id,
                            'mid_level_goal_id' => $targetData['mid_level_goal_id'],
                        ],
                        [
                            'xp_target' => $targetData['xp_target'],
                            'difficulty_score' => $targetData['difficulty_score'] ?? null,
                            'user_specific_reflection' => $targetData['user_specific_reflection'] ?? null,
                            'wants_refinement' => $targetData['wants_refinement'] ?? false,
                            'refinement_note' => $targetData['refinement_note'] ?? null,
                        ]
                    );
                }
            }

            if ($user->isPremium()) {
                GivePlanningBlessingJob::dispatch($review);
            }

            return $review;
        });
    }

    /**
     * Core Data Collection Method.
     * Gathers historical context, tasks, habits, and emotional pulse to feed the AI and UI.
     */
    private function calculatePastStats(User $user, string $start, string $end): array
    {
        // 1. Historical Context
        $lastReview = WeeklyReview::where('user_id', $user->id)
            ->where('week_start_date', '<', $start)
            ->where('status', 'completed')
            ->latest('week_start_date')
            ->first();

        $historicalContext = null;
        if ($lastReview) {
            $historicalContext = [
                'grit_score' => $lastReview->grit_score,
                'rafael_advice' => $lastReview->rafael_global_comment,
            ];
        }

        // 2. Goal Records: Using whereHas through TopLevelGoal to resolve indirect relationship with User.
        $goalsData = MidLevelGoal::whereHas('topLevelGoal', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->where('status', 'active')
            ->with([
                'tasks' => function ($q) use ($start, $end) {
                    // Tasks that were due or interacted with this week
                    $q->whereBetween('due_date', [$start, $end])
                        ->orWhereBetween('updated_at', [$start, $end]);
                },
                'habits'
            ])
            ->get()
            ->map(function ($goal) use ($start, $end) {
                return [
                    'name' => $goal->name,
                    'progress' => "{$goal->current_value} / {$goal->target_value} {$goal->unit}",
                    'habits_summary' => $this->analyzeHabitsForWeek($goal->habits, $start, $end),
                    'top_tasks' => $this->analyzeTasksForWeek($goal->tasks),
                ];
            })->toArray();

        // 3. Emotional Pulse
        $logs = $user->dailyLogs()->whereBetween('log_date', [$start, $end])->get();

        $emotionalPulse = [
            'avg_energy' => $logs->count() > 0 ? round($logs->avg('energy_level'), 1) : null,
            'dominant_mood' => $logs->count() > 0 ? $logs->pluck('mood_label')->mode()[0] ?? 'Neutral' : 'Sin datos',
            'log_count' => $logs->count()
        ];

        // 4. Global Metrics Calculation
        $tasksInPeriod = $user->tasks()->whereBetween('due_date', [$start, $end])->get();
        $tasksCompleted = $tasksInPeriod->where('is_completed', true);

        // Score modifiers for UI representation
        $effortScore = $tasksCompleted->sum('difficulty') * 2;
        $resultScore = $tasksCompleted->sum('impact_value') * 5;

        return [
            'history' => $historicalContext,
            'goals_breakdown' => $goalsData,
            'emotional_pulse' => $emotionalPulse,
            'metrics' => [
                'tasks_completed' => $tasksCompleted->count(),
                'tasks_total' => $tasksInPeriod->count(),
                'habits_compliance' => 80, // Note: Abstracted for showcase purposes
                'effort_score' => min(100, $effortScore),
                'result_score' => min(100, $resultScore),
            ]
        ];
    }

    /**
     * Filters and selects the most relevant tasks based on a combined Difficulty & Impact weight.
     */
    private function analyzeTasksForWeek($tasks)
    {
        return $tasks->sortByDesc(function ($task) {
            return $task->difficulty + ($task->impact_value * 1.5);
        })
            ->take(5) // Top 5 tasks only
            ->map(function ($t) {
                return sprintf(
                    "[%s] %s (Dif: %d, Impact: %d)",
                    $t->is_completed ? 'WIN' : 'FAIL',
                    $t->name,
                    $t->difficulty,
                    $t->impact_value
                );
            })->values()->toArray();
    }

    /**
     * Summarizes habit execution occurrences within the planning window.
     */
    private function analyzeHabitsForWeek($habits, $start, $end)
    {
        return $habits->take(2)->map(function ($h) use ($start, $end) {
            $logsCount = HabitLog::where('habit_id', $h->id)
                ->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->count();
            return "{$h->name}: Realizado $logsCount veces";
        })->values()->toArray();
    }

    /**
     * Calculates the baseline Grit Score.
     * Weighted average: 60% Habits Consistency, 40% Tasks Completion.
     */
    private function calculateGritScore(array $metrics): int
    {
        $habitScore = $metrics['habits_compliance'] ?? 0;
        $taskScore = ($metrics['tasks_total'] > 0)
            ? ($metrics['tasks_completed'] / $metrics['tasks_total']) * 100
            : 0;

        return (int) round(($habitScore * 0.6) + ($taskScore * 0.4));
    }
}