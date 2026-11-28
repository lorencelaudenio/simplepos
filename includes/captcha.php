<?php
declare(strict_types=1);

/** Simple session-backed math challenge for public forms. */

function captcha_question(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $valid = !empty($_SESSION['captcha_answer'])
        && (int)($_SESSION['captcha_expires'] ?? 0) >= time()
        && !empty($_SESSION['captcha_text']);
    if ($valid) {
        return (string)$_SESSION['captcha_text'];
    }

    $a = random_int(2, 20);
    $b = random_int(2, 20);
    $operation = random_int(0, 2);

    if ($operation === 0) {
        $answer = $a + $b;
        $text = $a . ' + ' . $b;
    } elseif ($operation === 1) {
        $high = max($a, $b);
        $low = min($a, $b);
        $answer = $high - $low;
        $text = $high . ' - ' . $low;
    } else {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $answer = $a * $b;
        $text = $a . ' × ' . $b;
    }

    $_SESSION['captcha_answer'] = (string)$answer;
    $_SESSION['captcha_expires'] = time() + 600;
    $_SESSION['captcha_text'] = $text;
    return $text;
}

function captcha_input(): string
{
    $question = e(captcha_question());
    return '<label class="field" style="margin-top:14px">Security check: ' . $question . ' = ?'
        . '<input type="text" name="captcha" inputmode="numeric" autocomplete="off" maxlength="4" required></label>';
}

function verify_captcha(?string $value = null): bool
{
    $expected = (string)($_SESSION['captcha_answer'] ?? '');
    $expires = (int)($_SESSION['captcha_expires'] ?? 0);
    unset($_SESSION['captcha_answer'], $_SESSION['captcha_expires'], $_SESSION['captcha_text']);

    $value = trim((string)($value ?? $_POST['captcha'] ?? ''));
    return $expected !== ''
        && $expires >= time()
        && $value !== ''
        && hash_equals($expected, $value);
}
