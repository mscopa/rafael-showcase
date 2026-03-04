<?php

namespace App\Services;

use App\Models\User;
use App\Models\DailyLog;
use App\Models\Habit;
use App\Models\Task;
use App\Http\Resources\TaskResource;
use App\Http\Resources\HabitResource;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * SHOWCASE: Separation of Concerns & Collection Mastery
 *
 * @challenge Preventing "Fat Controllers" when handling complex data aggregation for the user's daily agenda (mixing Tasks, Habits, and Backlogs).
 * @solution Moved schedule building to a dedicated service, heavily utilizing Laravel Collections (filter, sortBy, merge) to process data cleanly in memory while avoiding N+1 query problems via Eager Loading.
 * @highlight Demonstrates mastery of the framework's native tools, timezone handling, and keeping controllers strictly focused on HTTP responses.
 */
class AgendaService
{
    /**
     * Generates columns for the multi-day Agenda view.
     * Maps tasks and habits to their respective days within a given date period.
     */
    public function getCalendarColumns(User $user, Carbon $start, Carbon $end): array
    {
        $userTz = config('request.user_timezone', 'UTC');
        $period = CarbonPeriod::create($start, $end);

        // Optimization: Eager load all tasks and habits for the range to prevent N+1 issues
        $weeklyTasks = Task::where('user_id', $user->id)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->with(['midLevelGoal.area'])
            ->get();

        $activeHabits = Habit::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['midLevelGoal.area'])
            ->get();

        $columns = [];
        foreach ($period as $date) {
            /** @var Carbon $date */
            $dateString = $date->toDateString();
            $dayName = $date->englishDayOfWeek;

            // Determine if the iterated date is "today" relative to the user's local timezone
            $isToday = $date->copy()->setTimezone($userTz)->isToday();

            // Filter tasks specific to this date in memory
            $dayTasks = $weeklyTasks->filter(function (Task $task) use ($dateString) {
                /** @var Carbon|null $dueDate */
                $dueDate = $task->due_date;
                return $dueDate && $dueDate->toDateString() === $dateString;
            });

            // Filter habits scheduled for this day of the week
            $dayHabits = $activeHabits->filter(function (Habit $habit) use ($dayName) {
                $days = $habit->scheduled_days ?? [];
                return in_array(substr($dayName, 0, 3), $days);
            });

            // Merge Tasks and Habits into a unified stream, sorting by completion and time
            $items = collect()
                ->merge(TaskResource::collection($dayTasks)->resolve())
                ->merge(HabitResource::collection($dayHabits)->resolve())
                ->sortBy([
                    fn($item) => $item['is_completed'] ? 1 : 0,
                    fn($item) => empty($item['time']) ? 1 : 0,
                    fn($item) => $item['time'] ?? '23:59'
                ])
                ->values();

            $columns[] = [
                'date' => $dateString,
                'label' => $isToday ? __('agenda.column.today_label') : $date->translatedFormat('l d'),
                'is_today' => $isToday,
                'items' => $items,
            ];
        }

        return $columns;
    }

    /**
     * Retrieves tasks and habits without specific scheduled dates (The Backlog).
     */
    public function getBacklogItems(User $user): Collection
    {
        $backlogTasks = Task::where('user_id', $user->id)
            ->where('is_completed', false)
            ->whereNull('due_date')
            ->with('midLevelGoal.area')
            ->get();

        $backlogHabits = Habit::where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('scheduled_days')
                    ->orWhereJsonLength('scheduled_days', 0);
            })
            ->with('midLevelGoal.area')
            ->get();

        return collect()
            ->merge(TaskResource::collection($backlogTasks)->resolve())
            ->merge(HabitResource::collection($backlogHabits)->resolve())
            ->values();
    }

    /**
     * Aggregates the raw agenda for a single day, prioritizing overdue tasks and daily priorities.
     */
    public function getRawAgenda(User $user): array
    {
        // 1. Time Context: Resolving the user's local date dynamically
        $userTz = config('request.user_timezone', 'UTC');
        $localDate = now($userTz)->toDateString();
        $dayName = now($userTz)->format('l');

        // 2. Overdue Tasks: Prioritized items from past days
        $overdueTasks = $user->tasks()
            ->where('is_completed', false)
            ->where('due_date', '<', $localDate)
            ->with(['midLevelGoal.area'])
            ->get()
            ->map(function ($task) {
                $task->is_overdue = true;
                return $task;
            });

        // 3. Today's Pending Tasks (Utilizing custom macro 'whereLocalDate')
        $todayTasks = $user->tasks()
            ->whereLocalDate('due_date', $localDate)
            ->where('is_completed', false)
            ->with(['midLevelGoal.area'])
            ->get();

        // 4. Today's Completed Tasks
        $completedToday = $user->tasks()
            ->whereLocalDate('due_date', $localDate)
            ->where('is_completed', true)
            ->with(['midLevelGoal.area'])
            ->get();

        // Unified Task Collection: Overdue -> Today Pending -> Today Completed
        $allTasks = $overdueTasks->merge($todayTasks)->merge($completedToday);

        // 5. Habits corresponding to the current day
        $habits = Habit::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['midLevelGoal.area'])
            ->withCount([
                'logs as completed_today' => function ($query) use ($localDate) {
                    $query->whereLocalDate('completed_at', $localDate);
                }
            ])
            ->get()
            ->filter(function ($habit) use ($dayName) {
                if ($habit->frequency_type === 'days') {
                    $days = $habit->scheduled_days ?? [];
                    if (empty($days))
                        return true;
                    return in_array($dayName, $days);
                }
                return true;
            });

        // 6. "Absolute Priority" Item (Extracted from the previous day's Daily Log)
        $yesterdayDate = Carbon::parse($localDate)->subDay()->toDateString();
        $yesterdayLog = DailyLog::where('user_id', $user->id)
            ->whereLocalDate('log_date', $yesterdayDate)
            ->first();

        $priorityItem = null;
        if ($yesterdayLog && $yesterdayLog->top_priority_tomorrow) {
            $priorityItem = [
                'id' => 'priority_' . $yesterdayLog->id,
                'type' => 'priority',
                'name' => $yesterdayLog->top_priority_tomorrow,
                'description' => 'Tu prioridad #1 definida ayer.', // Kept Spanish for frontend consistency
                'time' => '00:00',
                'is_completed' => false,
                'area_name' => 'Enfoque',
                'area_color' => '#F59E0B'
            ];
        }

        return [
            'tasks' => $allTasks,
            'habits' => $habits,
            'priority' => $priorityItem
        ];
    }
}