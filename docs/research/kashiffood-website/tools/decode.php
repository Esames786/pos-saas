<?php
// Decode a Next.js page's RSC payload (self.__next_f.push chunks) into one string.
function rsc(string $html): string
{
    preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html, $m);
    $out = '';
    foreach ($m[1] as $c) {
        $out .= json_decode('"' . $c . '"') ?? '';
    }

    return $out;
}

/** The balanced JSON value ({...} or [...]) that starts right after $key. */
function valueAfter(string $s, string $key, int $from = 0): mixed
{
    $i = strpos($s, $key, $from);
    if ($i === false) {
        return null;
    }
    $i += strlen($key);
    while ($i < strlen($s) && ctype_space($s[$i])) {
        $i++;
    }
    $open = $s[$i] ?? '';
    $close = $open === '{' ? '}' : ($open === '[' ? ']' : null);
    if (! $close) {
        return null;
    }
    $depth = 0;
    $inStr = false;
    $bs = chr(92);
    $dq = chr(34);
    for ($j = $i, $n = strlen($s); $j < $n; $j++) {
        $ch = $s[$j];
        if ($inStr) {
            if ($ch === $bs) {
                $j++;
                continue;
            }
            if ($ch === $dq) {
                $inStr = false;
            }
            continue;
        }
        if ($ch === $dq) {
            $inStr = true;
            continue;
        }
        if ($ch === $open) {
            $depth++;
        } elseif ($ch === $close && --$depth === 0) {
            return json_decode(substr($s, $i, $j - $i + 1), true);
        }
    }

    return null;
}
