<?php

// Holds an exclusive flock on the given file for the given seconds, as another refresh would.
// Prints "locked" once it holds it.
[$_, $path, $seconds] = $argv;
$handle = fopen($path, 'c');
if ($handle === false || !flock($handle, LOCK_EX)) {
    exit(1);
}
echo "locked\n";
fflush(STDOUT);
usleep((int) ((float) $seconds * 1000000));
flock($handle, LOCK_UN);
