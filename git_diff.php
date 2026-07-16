<?php
header('Content-Type: text/plain');
echo "Running git diff...\n";

$outputs = [];
$cmds = [
    'git status',
    'git diff includes/functions.php',
    '"C:\Program Files\Git\bin\git.exe" status',
    '"C:\Program Files\Git\bin\git.exe" diff includes/functions.php'
];

foreach ($cmds as $cmd) {
    echo "========================================\n";
    echo "COMMAND: $cmd\n";
    $output = shell_exec($cmd . ' 2>&1');
    echo $output . "\n";
}
