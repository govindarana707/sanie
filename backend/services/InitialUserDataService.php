<?php

class InitialUserDataService
{
    public function __construct(private PDO $conn) {}

    public function createDefaults(int $userId): void
    {
        $account = $this->conn->prepare(
            'INSERT INTO accounts
             (user_id,name,type,account_number,balance,opening_balance,currency,color,icon,is_active,is_default,include_in_savings,include_in_net_balance)
             VALUES (:user_id,:name,:type,NULL,0.00,0.00,\'NPR\',:color,:icon,1,:is_default,0,1)'
        );
        foreach ([
            ['Cash', 'cash', '#10B981', 'cash', 1],
            ['Bank Account', 'bank', '#6366f1', 'bank', 0],
            ['eSewa', 'esewa', '#F59E0B', 'wallet', 0],
        ] as [$name, $type, $color, $icon, $isDefault]) {
            $account->execute([
                ':user_id' => $userId, ':name' => $name, ':type' => $type,
                ':color' => $color, ':icon' => $icon, ':is_default' => $isDefault,
            ]);
        }

        $category = $this->conn->prepare(
            'INSERT INTO categories
             (user_id,name,type,icon,color,description,is_default,status,is_pinned,sort_order)
             VALUES (:user_id,:name,:type,:icon,:color,:description,1,\'active\',0,999)'
        );
        foreach (self::defaultCategories() as $row) {
            $category->execute([':user_id' => $userId] + $row);
        }
    }

    public static function defaultCategories(): array
    {
        return [
            [':name'=>'Salary',':type'=>'income',':icon'=>'briefcase',':color'=>'#10B981',':description'=>'Monthly salary and wages'],
            [':name'=>'Freelance',':type'=>'income',':icon'=>'laptop',':color'=>'#6366f1',':description'=>'Freelance work income'],
            [':name'=>'Investments',':type'=>'income',':icon'=>'chart-line',':color'=>'#8B5CF6',':description'=>'Investment returns'],
            [':name'=>'Gifts',':type'=>'income',':icon'=>'gift',':color'=>'#EC4899',':description'=>'Gifts and bonuses'],
            [':name'=>'Food & Dining',':type'=>'expense',':icon'=>'utensils',':color'=>'#EF4444',':description'=>'Food and dining expenses'],
            [':name'=>'Transportation',':type'=>'expense',':icon'=>'car',':color'=>'#F59E0B',':description'=>'Transportation costs'],
            [':name'=>'Shopping',':type'=>'expense',':icon'=>'shopping-bag',':color'=>'#8B5CF6',':description'=>'Shopping expenses'],
            [':name'=>'Bills & Utilities',':type'=>'expense',':icon'=>'file-invoice',':color'=>'#6366f1',':description'=>'Bills and utilities'],
            [':name'=>'Entertainment',':type'=>'expense',':icon'=>'film',':color'=>'#EC4899',':description'=>'Entertainment expenses'],
            [':name'=>'Healthcare',':type'=>'expense',':icon'=>'heart',':color'=>'#10B981',':description'=>'Healthcare expenses'],
            [':name'=>'Education',':type'=>'expense',':icon'=>'book',':color'=>'#3B82F6',':description'=>'Education expenses'],
            [':name'=>'Others',':type'=>'expense',':icon'=>'ellipsis-h',':color'=>'#6B7280',':description'=>'Other expenses'],
        ];
    }
}
