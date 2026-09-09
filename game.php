<?php
/**
 * Minesweeper — single-file PHP implementation
 * Game state is kept in the PHP session; every click reloads the page.
 */

session_start();

// ---------------------------------------------------------------------
// Config / defaults
// ---------------------------------------------------------------------
$DIFFICULTIES = [
    'easy'   => ['rows' => 9,  'cols' => 9,  'mines' => 10],
    'medium' => ['rows' => 16, 'cols' => 16, 'mines' => 40],
    'hard'   => ['rows' => 16, 'cols' => 30, 'mines' => 99],
];

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function newGame(string $difficulty): void
{
    global $DIFFICULTIES;
    if (!isset($DIFFICULTIES[$difficulty])) {
        $difficulty = 'easy';
    }
    $cfg = $DIFFICULTIES[$difficulty];

    $_SESSION['difficulty'] = $difficulty;
    $_SESSION['rows']       = $cfg['rows'];
    $_SESSION['cols']       = $cfg['cols'];
    $_SESSION['mines']      = $cfg['mines'];
    $_SESSION['board']      = null;   // mine layout, generated lazily on first click
    $_SESSION['revealed']   = array_fill(0, $cfg['rows'], array_fill(0, $cfg['cols'], false));
    $_SESSION['flagged']    = array_fill(0, $cfg['rows'], array_fill(0, $cfg['cols'], false));
    $_SESSION['status']     = 'playing'; // playing | won | lost
    $_SESSION['first_click']= true;
    $_SESSION['start_time'] = time();
    $_SESSION['end_time']   = null;
}

function placeMines(int $rows, int $cols, int $mineCount, int $safeR, int $safeC): array
{
    $board = array_fill(0, $rows, array_fill(0, $cols, 0));
    $placed = 0;

    while ($placed < $mineCount) {
        $r = random_int(0, $rows - 1);
        $c = random_int(0, $cols - 1);

        // Never place a mine on the first-clicked cell or its neighbours
        if (abs($r - $safeR) <= 1 && abs($c - $safeC) <= 1) {
            continue;
        }
        if ($board[$r][$c] === 'M') {
            continue;
        }
        $board[$r][$c] = 'M';
        $placed++;
    }

    // Compute adjacency numbers
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if ($board[$r][$c] === 'M') {
                continue;
            }
            $count = 0;
            foreach (neighbours($r, $c, $rows, $cols) as [$nr, $nc]) {
                if ($board[$nr][$nc] === 'M') {
                    $count++;
                }
            }
            $board[$r][$c] = $count;
        }
    }

    return $board;
}

function neighbours(int $r, int $c, int $rows, int $cols): array
{
    $out = [];
    for ($dr = -1; $dr <= 1; $dr++) {
        for ($dc = -1; $dc <= 1; $dc++) {
            if ($dr === 0 && $dc === 0) {
                continue;
            }
            $nr = $r + $dr;
            $nc = $c + $dc;
            if ($nr >= 0 && $nr < $rows && $nc >= 0 && $nc < $cols) {
                $out[] = [$nr, $nc];
            }
        }
    }
    return $out;
}

function floodReveal(int $r, int $c): void
{
    $rows = $_SESSION['rows'];
    $cols = $_SESSION['cols'];
    $board = $_SESSION['board'];
    $revealed = $_SESSION['revealed'];

    $stack = [[$r, $c]];
    while ($stack) {
        [$cr, $cc] = array_pop($stack);
        if ($revealed[$cr][$cc] || $_SESSION['flagged'][$cr][$cc]) {
            continue;
        }
        $revealed[$cr][$cc] = true;
        if ($board[$cr][$cc] === 0) {
            foreach (neighbours($cr, $cc, $rows, $cols) as [$nr, $nc]) {
                if (!$revealed[$nr][$nc] && !$_SESSION['flagged'][$nr][$nc]) {
                    $stack[] = [$nr, $nc];
                }
            }
        }
    }
    $_SESSION['revealed'] = $revealed;
}

function checkWin(): bool
{
    $rows = $_SESSION['rows'];
    $cols = $_SESSION['cols'];
    $board = $_SESSION['board'];
    $revealed = $_SESSION['revealed'];

    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if ($board[$r][$c] !== 'M' && !$revealed[$r][$c]) {
                return false;
            }
        }
    }
    return true;
}

function revealAllMines(): void
{
    $rows = $_SESSION['rows'];
    $cols = $_SESSION['cols'];
    $board = $_SESSION['board'];
    $revealed = $_SESSION['revealed'];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            if ($board[$r][$c] === 'M') {
                $revealed[$r][$c] = true;
            }
        }
    }
    $_SESSION['revealed'] = $revealed;
}

// ---------------------------------------------------------------------
// Handle input
// ---------------------------------------------------------------------
if (!isset($_SESSION['rows']) || isset($_GET['new'])) {
    newGame($_GET['difficulty'] ?? ($_SESSION['difficulty'] ?? 'easy'));
}

if (isset($_GET['action'], $_GET['r'], $_GET['c']) && $_SESSION['status'] === 'playing') {
    $r = (int) $_GET['r'];
    $c = (int) $_GET['c'];
    $rows = $_SESSION['rows'];
    $cols = $_SESSION['cols'];

    if ($r >= 0 && $r < $rows && $c >= 0 && $c < $cols) {
        if ($_GET['action'] === 'flag') {
            if (!$_SESSION['revealed'][$r][$c]) {
                $flagged = $_SESSION['flagged'];
                $flagged[$r][$c] = !$flagged[$r][$c];
                $_SESSION['flagged'] = $flagged;
            }
        } elseif ($_GET['action'] === 'reveal') {
            if (!$_SESSION['flagged'][$r][$c]) {
                if ($_SESSION['first_click']) {
                    $_SESSION['board'] = placeMines($rows, $cols, $_SESSION['mines'], $r, $c);
                    $_SESSION['first_click'] = false;
                }
                if ($_SESSION['board'][$r][$c] === 'M') {
                    $revealed = $_SESSION['revealed'];
                    $revealed[$r][$c] = true;
                    $_SESSION['revealed'] = $revealed;
                    $_SESSION['status'] = 'lost';
                    $_SESSION['end_time'] = time();
                    revealAllMines();
                } else {
                    floodReveal($r, $c);
                    if (checkWin()) {
                        $_SESSION['status'] = 'won';
                        $_SESSION['end_time'] = time();
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------
// Prepare data for rendering
// ---------------------------------------------------------------------
$rows      = $_SESSION['rows'];
$cols      = $_SESSION['cols'];
$mines     = $_SESSION['mines'];
$revealed  = $_SESSION['revealed'];
$flagged   = $_SESSION['flagged'];
$board     = $_SESSION['board'];
$status    = $_SESSION['status'];
$difficulty= $_SESSION['difficulty'];

$flagsUsed = 0;
foreach ($flagged as $row) {
    $flagsUsed += count(array_filter($row));
}

$elapsed = ($_SESSION['end_time'] ?? time()) - $_SESSION['start_time'];

$numberColors = [
    1 => '#1976d2', 2 => '#388e3c', 3 => '#d32f2f', 4 => '#7b1fa2',
    5 => '#ff8f00', 6 => '#0097a7', 7 => '#424242', 8 => '#000000',
];

function url(array $params): string
{
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Minesweeper</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Arial, sans-serif;
        background: #c0c0c0;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 20px;
        margin: 0;
        min-height: 100vh;
    }
    h1 { margin: 0 0 10px; color: #333; }

    .panel {
        background: #bdbdbd;
        border: 4px outset #eee;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 12px;
        border-radius: 4px;
    }
    .counter {
        background: #000;
        color: #ff3b30;
        font-family: "Courier New", monospace;
        font-weight: bold;
        font-size: 22px;
        padding: 4px 10px;
        border-radius: 3px;
        min-width: 56px;
        text-align: center;
    }
    .face {
        font-size: 26px;
        background: #d9d9d9;
        border: 3px outset #fff;
        border-radius: 4px;
        width: 42px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        cursor: pointer;
    }
    .face:active { border-style: inset; }

    .difficulties { display: flex; gap: 6px; margin-bottom: 14px; }
    .difficulties a {
        text-decoration: none;
        padding: 6px 12px;
        background: #ddd;
        border: 2px outset #fff;
        border-radius: 4px;
        color: #333;
        font-size: 14px;
    }
    .difficulties a.active { background: #9ccc65; border-style: inset; }

    table.board {
        border-collapse: collapse;
        border: 4px solid;
        border-color: #7b7b7b #eee #eee #7b7b7b;
        background: #bdbdbd;
    }
    table.board td {
        width: 26px;
        height: 26px;
        text-align: center;
        vertical-align: middle;
        padding: 0;
        font-weight: bold;
        font-size: 14px;
        font-family: "Courier New", monospace;
    }
    .cell-hidden {
        background: #bdbdbd;
        border: 3px outset #eee;
    }
    .cell-hidden a {
        display: block;
        width: 100%;
        height: 100%;
        text-decoration: none;
    }
    .cell-revealed {
        background: #d7d7d7;
        border: 1px solid #999;
    }
    .cell-mine { background: #ff5252; }
    .flag { color: #d32f2f; }

    .status-msg {
        margin-top: 14px;
        font-size: 18px;
        font-weight: bold;
        padding: 8px 16px;
        border-radius: 4px;
    }
    .status-won  { background: #c8e6c9; color: #2e7d32; }
    .status-lost { background: #ffcdd2; color: #c62828; }

    .hint {
        margin-top: 10px;
        font-size: 13px;
        color: #444;
        max-width: 420px;
        text-align: center;
    }
</style>
</head>
<body>

<h1>💣 Minesweeper</h1>

<div class="difficulties">
    <?php foreach (['easy' => 'Easy 9×9', 'medium' => 'Medium 16×16', 'hard' => 'Hard 16×30'] as $key => $label): ?>
        <a class="<?= $difficulty === $key ? 'active' : '' ?>"
           href="<?= url(['new' => 1, 'difficulty' => $key]) ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="counter">💣 <?= str_pad((string)max(0, $mines - $flagsUsed), 3, '0', STR_PAD_LEFT) ?></div>

    <a class="face" href="<?= url(['new' => 1, 'difficulty' => $difficulty]) ?>" title="New game">
        <?php if ($status === 'won'): ?>😎
        <?php elseif ($status === 'lost'): ?>😵
        <?php else: ?>🙂
        <?php endif; ?>
    </a>

    <div class="counter">⏱ <?= str_pad((string)min(999, $elapsed), 3, '0', STR_PAD_LEFT) ?></div>
</div>

<table class="board">
<?php for ($r = 0; $r < $rows; $r++): ?>
    <tr>
    <?php for ($c = 0; $c < $cols; $c++): ?>
        <?php
        $isRevealed = $revealed[$r][$c];
        $isFlagged  = $flagged[$r][$c];
        $cellValue  = $board[$r][$c] ?? null;
        $frozen     = $status !== 'playing';
        ?>
        <?php if ($isRevealed): ?>
            <td class="cell-revealed<?= $cellValue === 'M' ? ' cell-mine' : '' ?>">
                <?php if ($cellValue === 'M'): ?>
                    💣
                <?php elseif ($cellValue > 0): ?>
                    <span style="color: <?= $numberColors[$cellValue] ?>"><?= $cellValue ?></span>
                <?php endif; ?>
            </td>
        <?php else: ?>
            <td class="cell-hidden">
                <?php if ($frozen): ?>
                    <?= $isFlagged ? '<span class="flag">🚩</span>' : '' ?>
                <?php else: ?>
                    <a href="<?= url(['action' => 'reveal', 'r' => $r, 'c' => $c]) ?>"
                       oncontextmenu="event.preventDefault(); window.location.href='<?= url(['action' => 'flag', 'r' => $r, 'c' => $c]) ?>';">
                        <?= $isFlagged ? '<span class="flag">🚩</span>' : '' ?>
                    </a>
                <?php endif; ?>
            </td>
        <?php endif; ?>
    <?php endfor; ?>
    </tr>
<?php endfor; ?>
</table>

<?php if ($status === 'won'): ?>
    <div class="status-msg status-won">🎉 You win! Cleared the board in <?= $elapsed ?>s.</div>
<?php elseif ($status === 'lost'): ?>
    <div class="status-msg status-lost">💥 Boom! You hit a mine. Try again.</div>
<?php endif; ?>

<p class="hint">
    <strong>Left-click</strong> a tile to reveal it, <strong>right-click</strong> to plant or remove a flag.
    Click the face to start a new game.
</p>

</body>
</html>
