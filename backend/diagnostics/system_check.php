<?php
require_once __DIR__ . '/../includes/diagnostic_guard.php';
error_reporting(E_ALL);
ini_set('display_errors',1);

require_once '../config/database.php';

$db=(new Database())->getConnection();

echo "<!doctype html>";
echo "<html>";
echo "<head>";

echo "<title>SanIE System Integrity Diagnostic</title>";

echo "<style>

body{
font-family:Arial;
background:#f5f5f5;
padding:25px;
}

table{
width:100%;
border-collapse:collapse;
background:white;
margin-bottom:30px;
}

th{
background:#2563eb;
color:white;
padding:10px;
}

td{
padding:8px;
border:1px solid #ddd;
}

.good{
color:green;
font-weight:bold;
}

.bad{
color:red;
font-weight:bold;
}

.warn{
color:#ff9800;
font-weight:bold;
}

pre{
background:#efefef;
padding:10px;
overflow:auto;
}

</style>";

echo "</head><body>";

echo "<h1>SanIE System Integrity Diagnostic</h1>";

function runCheck($db,$title,$sql){

    echo "<h2>$title</h2>";

    try{

        $stmt=$db->query($sql);

        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rows)==0){

            echo "<p class='good'>✔ PASS</p>";

            return;
        }

        echo "<p class='bad'>✖ ".count($rows)." issue(s)</p>";

        echo "<table>";

        echo "<tr>";

        foreach(array_keys($rows[0]) as $k){

            echo "<th>$k</th>";

        }

        echo "</tr>";

        foreach($rows as $r){

            echo "<tr>";

            foreach($r as $v){

                echo "<td>".htmlspecialchars($v)."</td>";

            }

            echo "</tr>";

        }

        echo "</table>";

    }catch(Exception $e){

        echo "<p class='bad'>".$e->getMessage()."</p>";

    }

}

runCheck($db,"Categories with NULL user_id",

"SELECT id,name,type,user_id
FROM categories
WHERE user_id IS NULL");

runCheck($db,"Accounts with NULL user_id",

"SELECT id,name,user_id
FROM accounts
WHERE user_id IS NULL");

runCheck($db,"Transactions with NULL user_id",

"SELECT id,amount,user_id
FROM transactions
WHERE user_id IS NULL");

runCheck($db,"Budgets with NULL user_id",

"SELECT id,name,user_id
FROM budgets
WHERE user_id IS NULL");

runCheck($db,"Goals with NULL user_id",

"SELECT id,title,user_id
FROM goals
WHERE user_id IS NULL");

runCheck($db,"Recurring Transactions NULL user",

"SELECT id,user_id
FROM recurring_transactions
WHERE user_id IS NULL");

runCheck($db,"Subcategories without Category",

"
SELECT s.id,s.name
FROM subcategories s
LEFT JOIN categories c
ON c.id=s.category_id
WHERE c.id IS NULL
");

runCheck($db,"Transactions with Missing Category",

"
SELECT t.id,t.category_id
FROM transactions t
LEFT JOIN categories c
ON c.id=t.category_id
WHERE t.category_id IS NOT NULL
AND c.id IS NULL
");

runCheck($db,"Budgets with Missing Category",

"
SELECT b.id,b.category_id
FROM budgets b
LEFT JOIN categories c
ON c.id=b.category_id
WHERE b.category_id IS NOT NULL
AND c.id IS NULL
");

runCheck($db,"Duplicate Categories",

"
SELECT
user_id,
name,
type,
COUNT(*) total

FROM categories

GROUP BY
user_id,
name,
type

HAVING total>1
");

runCheck($db,"Deleted Categories Still Active",

"
SELECT *
FROM categories
WHERE status='deleted'
AND deleted_at IS NULL
");

runCheck($db,"Active Categories With deleted_at",

"
SELECT *
FROM categories
WHERE status='active'
AND deleted_at IS NOT NULL
");

echo "<h2>Table Counts</h2>";

$tables=[
'users',
'accounts',
'transactions',
'categories',
'subcategories',
'budgets',
'goals',
'notifications',
'recurring_transactions'
];

echo "<table>";

echo "<tr><th>Table</th><th>Rows</th></tr>";

foreach($tables as $table){

    try{

        $count=$db->query("SELECT COUNT(*) c FROM $table")->fetch()['c'];

        echo "<tr>";

        echo "<td>$table</td>";

        echo "<td>$count</td>";

        echo "</tr>";

    }catch(Exception $e){

        echo "<tr>";

        echo "<td>$table</td>";

        echo "<td>Error</td>";

        echo "</tr>";

    }

}

echo "</table>";

echo "<h2>Database Summary</h2>";

echo "<table>";

echo "<tr><th>Item</th><th>Status</th></tr>";

$checks=[
"Database Connection"=>"OK",
"Foreign Keys"=>"Checked",
"NULL Ownership"=>"Checked",
"Duplicate Records"=>"Checked",
"Orphan Records"=>"Checked",
"Soft Delete"=>"Checked"
];

foreach($checks as $k=>$v){

    echo "<tr>";

    echo "<td>$k</td>";

    echo "<td class='good'>$v</td>";

    echo "</tr>";

}

echo "</table>";

echo "</body></html>";
