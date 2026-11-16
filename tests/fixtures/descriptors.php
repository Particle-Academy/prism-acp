<?php

// Linux only: report every descriptor this process holds as {fd: what it points at}. Metadata only -- readlink names
// the object, nothing is opened or read through it.

declare(strict_types=1);

$out = [];
foreach (scandir('/proc/self/fd') ?: [] as $name) {
    if (ctype_digit($name)) {
        $out[$name] = (string) @readlink('/proc/self/fd/'.$name);
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
