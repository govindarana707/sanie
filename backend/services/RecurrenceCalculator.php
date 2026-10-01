<?php

final class RecurrenceCalculator {
    public const TIMEZONE = 'Asia/Kathmandu';
    public const FREQUENCIES = ['daily','weekly','bi_weekly','monthly','quarterly','yearly'];

    private DateTimeZone $timezone;

    public function __construct() {
        $this->timezone = new DateTimeZone(self::TIMEZONE);
    }

    public function today(): string {
        return (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');
    }

    public function initialOccurrence(array $definition, ?string $notBefore = null): ?string {
        $this->validateSchedule($definition);
        $start = $this->date((string)$definition['start_date']);
        $floor = $notBefore ? max($start->format('Y-m-d'), $this->date($notBefore)->format('Y-m-d')) : $start->format('Y-m-d');
        $minimum = $this->date($floor);
        $frequency = (string)$definition['frequency'];

        if ($frequency === 'daily') {
            $candidate = $minimum;
        } elseif ($frequency === 'weekly' || $frequency === 'bi_weekly') {
            $first = $this->firstWeekdayOnOrAfter($start, (int)$definition['day_of_week']);
            $interval = $frequency === 'weekly' ? 7 : 14;
            $candidate = $this->advanceByDaysUntil($first, $minimum, $interval);
        } elseif ($frequency === 'monthly' || $frequency === 'quarterly') {
            $interval = $frequency === 'monthly' ? 1 : 3;
            $candidate = $this->firstAnchoredMonthOnOrAfter($start, (int)$definition['day_of_month']);
            while ($candidate < $minimum) {
                $candidate = $this->anchoredMonth($candidate, $interval, (int)$definition['day_of_month']);
            }
        } else {
            $month = (int)$start->format('n');
            $day = (int)$start->format('j');
            $candidate = $this->anchoredYear((int)$minimum->format('Y'), $month, $day);
            if ($candidate < $minimum || $candidate < $start) {
                $candidate = $this->anchoredYear((int)$candidate->format('Y') + 1, $month, $day);
            }
        }

        return $this->withinEnd($candidate, $definition) ? $candidate->format('Y-m-d') : null;
    }

    public function nextOccurrence(array $definition, string $currentOccurrence): ?string {
        $this->validateSchedule($definition);
        $current = $this->date($currentOccurrence);
        $frequency = (string)$definition['frequency'];

        if ($frequency === 'daily') {
            $candidate = $current->modify('+1 day');
        } elseif ($frequency === 'weekly') {
            $candidate = $current->modify('+7 days');
        } elseif ($frequency === 'bi_weekly') {
            $candidate = $current->modify('+14 days');
        } elseif ($frequency === 'monthly') {
            $candidate = $this->anchoredMonth($current, 1, (int)$definition['day_of_month']);
        } elseif ($frequency === 'quarterly') {
            $candidate = $this->anchoredMonth($current, 3, (int)$definition['day_of_month']);
        } else {
            $start = $this->date((string)$definition['start_date']);
            $candidate = $this->anchoredYear(
                (int)$current->format('Y') + 1,
                (int)$start->format('n'),
                (int)$start->format('j')
            );
        }

        return $this->withinEnd($candidate, $definition) ? $candidate->format('Y-m-d') : null;
    }

    public function dueOccurrences(array $definition, ?string $throughDate = null, int $limit = 366): array {
        if ($limit < 1) throw new InvalidArgumentException('Due occurrence limit must be positive.');
        $through = $this->date($throughDate ?: $this->today());
        $cursor = !empty($definition['next_occurrence'])
            ? $this->date((string)$definition['next_occurrence'])->format('Y-m-d')
            : $this->initialOccurrence($definition);
        $due = [];
        while ($cursor !== null && $this->date($cursor) <= $through && count($due) < $limit) {
            if (!$this->withinEnd($this->date($cursor), $definition)) break;
            $due[] = $cursor;
            $cursor = $this->nextOccurrence($definition, $cursor);
        }
        return $due;
    }

    public function validateSchedule(array $definition): void {
        $frequency = (string)($definition['frequency'] ?? '');
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Invalid recurrence frequency.');
        }
        $start = $this->date((string)($definition['start_date'] ?? ''));
        if (!empty($definition['end_date']) && $this->date((string)$definition['end_date']) < $start) {
            throw new InvalidArgumentException('End date cannot be before start date.');
        }
        if (in_array($frequency, ['weekly','bi_weekly'], true)) {
            $weekday = filter_var($definition['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            if ($weekday === false || $weekday < 1 || $weekday > 7) {
                throw new InvalidArgumentException('Weekly recurrence requires an ISO weekday from 1 to 7.');
            }
        }
        if (in_array($frequency, ['monthly','quarterly'], true)) {
            $monthDay = filter_var($definition['day_of_month'] ?? null, FILTER_VALIDATE_INT);
            if ($monthDay === false || $monthDay < 1 || $monthDay > 31) {
                throw new InvalidArgumentException('Monthly recurrence requires a day from 1 to 31.');
            }
        }
    }

    public function isOccurrenceInSequence(array $definition, string $candidate): bool {
        $target = $this->date($candidate);
        $cursor = $this->initialOccurrence($definition);
        $guard = 0;
        while ($cursor !== null && $this->date($cursor) <= $target && $guard++ < 20000) {
            if ($cursor === $target->format('Y-m-d')) return true;
            $cursor = $this->nextOccurrence($definition, $cursor);
        }
        return false;
    }

    private function date(string $value): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('A valid date in YYYY-MM-DD format is required.');
        }
        return $date;
    }

    private function withinEnd(DateTimeImmutable $candidate, array $definition): bool {
        return empty($definition['end_date']) || $candidate <= $this->date((string)$definition['end_date']);
    }

    private function firstWeekdayOnOrAfter(DateTimeImmutable $start, int $isoWeekday): DateTimeImmutable {
        $delta = ($isoWeekday - (int)$start->format('N') + 7) % 7;
        return $delta ? $start->modify("+{$delta} days") : $start;
    }

    private function advanceByDaysUntil(DateTimeImmutable $candidate, DateTimeImmutable $minimum, int $interval): DateTimeImmutable {
        if ($candidate >= $minimum) return $candidate;
        $days = (int)$candidate->diff($minimum)->format('%a');
        $steps = (int)ceil($days / $interval);
        $candidate = $candidate->modify('+' . ($steps * $interval) . ' days');
        return $candidate < $minimum ? $candidate->modify("+{$interval} days") : $candidate;
    }

    private function firstAnchoredMonthOnOrAfter(DateTimeImmutable $start, int $anchorDay): DateTimeImmutable {
        $monthStart = $start->modify('first day of this month');
        $candidate = $this->clampedDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $anchorDay);
        return $candidate < $start ? $this->anchoredMonth($candidate, 1, $anchorDay) : $candidate;
    }

    private function anchoredMonth(DateTimeImmutable $current, int $months, int $anchorDay): DateTimeImmutable {
        $targetMonth = $current->modify('first day of this month')->modify("+{$months} months");
        return $this->clampedDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('n'), $anchorDay);
    }

    private function anchoredYear(int $year, int $month, int $anchorDay): DateTimeImmutable {
        return $this->clampedDate($year, $month, $anchorDay);
    }

    private function clampedDate(int $year, int $month, int $anchorDay): DateTimeImmutable {
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $this->timezone);
        $lastDay = (int)$monthStart->format('t');
        return $monthStart->setDate($year, $month, min($anchorDay, $lastDay));
    }
}
