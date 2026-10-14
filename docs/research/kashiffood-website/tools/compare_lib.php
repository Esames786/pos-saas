<?php
// Name matching shared by the website ↔ POS comparison.
function norm(string $s): string
{
    $s = mb_strtolower($s);
    $map = [
        '&' => ' and ', 'bar-b-que' => 'bbq', 'bar b que' => 'bbq', 'bar b q' => 'bbq', 'barbecue' => 'bbq', 'b.b.q' => 'bbq',
        'khass' => 'khaas', 'chcicken' => 'chicken', 'al-fahom' => 'alfaham', 'al-faham' => 'alfaham', 'al faham' => 'alfaham',
        'kabab' => 'kebab', 'pcs' => 'pc', 'pieces' => 'pc', 'piece' => 'pc', 'persons' => 'person', 'people' => 'person',
        'chullu' => 'chollu', 'baluchi' => 'balochi', 'moroccan' => 'morocon', 'morrocan' => 'morocon', 'w/' => 'with ',
        'drinks' => 'drink', 'fries' => 'fry', 'rolls' => 'roll', 'burgers' => 'burger', 'wings' => 'wing',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

    return trim(preg_replace('/\s+/', ' ', $s));
}

function tokens(string $s): array
{
    $out = [];
    foreach (explode(' ', norm($s)) as $t) {
        if ($t === '' || in_array($t, ['the', 'a', 'of', 'with', 'and'], true)) {
            continue;
        }
        if (ctype_digit($t)) {
            $t = ltrim($t, '0') ?: '0';          // "01" = "1"
        } elseif (strlen($t) > 4 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss')) {
            $t = substr($t, 0, -1);              // shots = shot, nuggets = nugget
        }
        $out[] = $t;
    }

    return array_values(array_unique($out));
}

/** @return array{0: ?array, 1: float} best POS entry and its score */
function bestMatch(array $want, float $price, array $posById, ?string $onlyCategory = null): array
{
    $best = null;
    $bestScore = 0.0;
    foreach ($posById as $p) {
        if ($onlyCategory !== null && $p['category'] !== $onlyCategory) {
            continue;
        }
        $a = $want;
        $b = $p['tok'];
        $s = ($a && $b) ? count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b))) : 0.0;
        if ((float) $p['price'] === $price) {
            $s += 0.15;   // same price is evidence, not proof
        }
        if ($p['status'] !== 'active') {
            $s -= 0.05;
        }
        if ($s > $bestScore) {
            $bestScore = $s;
            $best = $p;
        }
    }

    return [$best, round($bestScore, 2)];
}
