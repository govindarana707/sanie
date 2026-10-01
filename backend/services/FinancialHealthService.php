<?php

/**
 * Deterministic, presentation-independent financial health scoring.
 *
 * All amounts and transaction counts are supplied by the existing dashboard
 * services; this class deliberately performs no database queries.
 */
class FinancialHealthService {
    public function calculate(array $metrics): array {
        $income = $this->number($metrics['income'] ?? 0);
        $expense = $this->number($metrics['expense'] ?? 0);
        $savings = $this->number($metrics['savings'] ?? 0);
        $netBalance = $this->number($metrics['net_balance'] ?? 0);
        $payable = max(0, $this->number($metrics['payable'] ?? 0));
        $receivable = max(0, $this->number($metrics['receivable'] ?? 0));
        $incomeCount = max(0, (int)($metrics['income_count'] ?? 0));
        $expenseCount = max(0, (int)($metrics['expense_count'] ?? 0));
        $activityCount = $incomeCount + $expenseCount;

        // A score needs at least one transaction or an actual financial position.
        // A brand-new, all-zero profile is therefore neutral rather than Critical.
        $hasMeaningfulData = $activityCount > 0
            || abs($netBalance) > 0.00001
            || abs($savings) > 0.00001
            || $payable > 0
            || $receivable > 0;
        if (!$hasMeaningfulData) {
            return [
                'score' => null,
                'status' => 'Not enough data',
                'state' => 'neutral',
                'max_score' => 100,
                'has_sufficient_data' => false,
                'breakdown' => []
            ];
        }

        $averageExpense = $expenseCount > 0 ? $expense / $expenseCount : 0.0;
        $breakdown = [
            'savings_rate' => $this->savingsRatePoints($income, $savings),
            'income_vs_expense' => $this->incomeExpensePoints($income, $expense),
            'net_balance' => $this->netBalancePoints($netBalance, $averageExpense),
            'debt_payable' => $this->debtPoints($payable, $income, $netBalance, $receivable, $averageExpense),
            'stability_activity' => $this->stabilityPoints($income, $expense, $netBalance, $activityCount),
        ];
        $score = max(0, min(100, array_sum($breakdown)));

        return [
            'score' => $score,
            'status' => self::statusForScore($score),
            'state' => self::stateForScore($score),
            'max_score' => 100,
            'has_sufficient_data' => true,
            'breakdown' => $breakdown
        ];
    }

    public static function statusForScore(int $score): string {
        if ($score >= 90) return 'Excellent';
        if ($score >= 80) return 'Very Good';
        if ($score >= 65) return 'Good';
        if ($score >= 45) return 'Fair';
        if ($score >= 25) return 'Needs Attention';
        return 'Critical';
    }

    /** A stable presentation key so every score element uses the same state. */
    public static function stateForScore(int $score): string {
        if ($score >= 90) return 'excellent';
        if ($score >= 80) return 'very-good';
        if ($score >= 65) return 'good';
        if ($score >= 45) return 'fair';
        if ($score >= 25) return 'needs-attention';
        return 'critical';
    }

    private function savingsRatePoints(float $income, float $savings): int {
        if ($income <= 0) return 0;
        $rate = ($savings / $income) * 100;
        if ($rate >= 20) return 30;
        if ($rate >= 15) return 24;
        if ($rate >= 10) return 18;
        if ($rate >= 5) return 10;
        if ($rate > 0) return 5;
        return 0;
    }

    private function incomeExpensePoints(float $income, float $expense): int {
        if ($income <= 0) return 0;
        $ratio = $expense / $income;
        if ($ratio <= .70) return 25;
        if ($ratio <= .80) return 20;
        if ($ratio <= .90) return 15;
        if ($ratio <= 1) return 8;
        return 0;
    }

    private function netBalancePoints(float $netBalance, float $averageExpense): int {
        if ($netBalance < 0) return 0;
        if ($netBalance == 0.0) return 5;
        if ($averageExpense <= 0) return 15;
        $buffer = $netBalance / $averageExpense;
        if ($buffer >= 1) return 20;
        if ($buffer >= .5) return 15;
        return 10;
    }

    private function debtPoints(float $payable, float $income, float $netBalance, float $receivable, float $averageExpense): int {
        if ($payable <= 0) return 15;
        $capacity = max($income, max(0, $netBalance + $receivable), $averageExpense);
        if ($capacity <= 0) return 0;
        $burden = $payable / $capacity;
        if ($burden <= .10) return 12;
        if ($burden <= .25) return 9;
        if ($burden <= .50) return 5;
        return 0;
    }

    private function stabilityPoints(float $income, float $expense, float $netBalance, int $activityCount): int {
        $points = 0;
        if ($income > 0) $points += 3;
        if ($netBalance > 0) $points += 2;
        elseif ($netBalance == 0.0) $points += 1;
        if ($income > 0 && $expense <= $income) $points += 3;
        if ($activityCount >= 2) $points += 2;
        elseif ($activityCount === 1) $points += 1;
        return $points;
    }

    private function number($value): float {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
