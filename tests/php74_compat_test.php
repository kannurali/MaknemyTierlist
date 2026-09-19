<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Совместимость с PHP 7.4 — версией, на которой работает maknemy.com.
//
// Зачем отдельный тест. Локально стоит PHP 8.x, и он проглатывает всё, что
// написано под восьмёрку. `php -l` тем более: он проверяет СИНТАКСИС, а
// функции 8.0+ синтаксически безупречны на любой версии. То есть
// str_contains() спокойно линтуется, проходит весь прогон тестов на машине
// разработчика — и падает `Call to undefined function` уже на бою, у
// посетителя. Поймать это можно только так: перечислить, чего на 7.4 нет, и
// проверить текстом.
//
// Деплоится (см. .cpanel.yml) только public_html/, поэтому фатальным для
// сайта является именно он. tests/ проверяем заодно: прогон могут запустить
// и на 7.4.
//
// Списки ниже — практические, а не исчерпывающие: сюда внесено то, за чем
// реально тянется рука. Встретишь на бою что-то мимо списка — допиши сюда
// вместе с починкой.

$ROOT = dirname(__DIR__);

// --------------------------------------------------------------------------
//  Что на 7.4 отсутствует
// --------------------------------------------------------------------------

// Функции. Опаснее всего: не ловятся линтером, падают в рантайме.
const PHP74_MISSING_FUNCTIONS = [
    // 8.0
    'str_contains'          => '8.0',
    'str_starts_with'       => '8.0',
    'str_ends_with'         => '8.0',
    'fdiv'                  => '8.0',
    'get_debug_type'        => '8.0',
    'preg_last_error_msg'   => '8.0',
    'get_resource_id'       => '8.0',
    // 8.1
    'array_is_list'         => '8.1',
    'enum_exists'           => '8.1',
    'fsync'                 => '8.1',
    'fdatasync'             => '8.1',
    // 8.2
    'mysqli_execute_query'  => '8.2',
    'ini_parse_quantity'    => '8.2',
    'memory_reset_peak_usage' => '8.2',
    // 8.3
    'json_validate'         => '8.3',
    'mb_str_pad'            => '8.3',
    'str_increment'         => '8.3',
    'str_decrement'         => '8.3',
    // 8.4
    'array_find'            => '8.4',
    'array_find_key'        => '8.4',
    'array_any'             => '8.4',
    'array_all'             => '8.4',
    'mb_trim'               => '8.4',
    'mb_ucfirst'            => '8.4',
    'request_parse_body'    => '8.4',
];

// Синтаксис. Этот как раз упадёт при php -l на 7.4 — но линтера под 7.4 у
// нас нет, поэтому проверяем здесь же, заодно.
const PHP74_MISSING_SYNTAX = [
    ['~\?->~',                                   'nullsafe-оператор ?->',        '8.0'],
    ['~(?<![\w$>])match\s*\(~',                  'выражение match()',            '8.0'],
    ['~^\s*\#\[~m',                              'атрибуты #[...]',              '8.0'],
    ['~\bcatch\s*\(\s*[\w\\\\]+\s*\)~',          'catch без переменной',         '8.0'],
    ['~\bfunction\s+\w+\s*\([^)]*\)\s*:\s*static\b~', 'возвращаемый тип static', '8.0'],
    ['~\b(?:public|private|protected)\s+(?:readonly\s+)?[\w\|\?\\\\]+\s+\$\w+\s*[,)]~',
                                                 'промоушен конструктора',       '8.0'],
    ['~\benum\s+\w+~',                           'enum',                         '8.1'],
    ['~\breadonly\s+~',                          'readonly',                     '8.1'],
    ['~\)\s*:\s*never\b~',                       'возвращаемый тип never',       '8.1'],
    ['~\w\s*\(\s*\.\.\.\s*\)~',                  'первоклассный вызов f(...)',   '8.1'],
];

// --------------------------------------------------------------------------
//  Разбор файла
// --------------------------------------------------------------------------

// Комментарии, строки и HTML выкидываем ЧЕРЕЗ ТОКЕНАЙЗЕР, а не регуляркой.
// Иначе этот самый файл первым и упал бы: имена запрещённых функций лежат в
// нём строками. Тот же случай, что со словом <textarea> в
// profile_page_test.php — только там хватило вырезания комментариев.
function php74_code_only(string $src): string {
    $skip = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML];
    if (defined('T_ENCAPSED_AND_WHITESPACE')) { $skip[] = T_ENCAPSED_AND_WHITESPACE; }
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], $skip, true)) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

// Вызовы функций берём тоже из токенов: T_STRING, за которым идёт "(", и
// перед которым НЕ стоит "->", "::" или "function". Так метод $x->fdiv() и
// объявление собственной функции с таким именем не считаются вызовом
// встроенной.
function php74_called_functions(string $src): array {
    $tokens = token_get_all($src);
    $names  = [];
    $n      = count($tokens);
    // Имя функции может приехать тремя токенами. Обычный вызов — T_STRING.
    // Вызов с ведущим слэшем (\str_contains) PHP 8 отдаёт целиком как
    // T_NAME_FULLY_QUALIFIED, и проверка только по T_STRING его пропускала —
    // дыра в страже, ровно та, ради закрытия которой он написан. На 7.4
    // такого токена нет вовсе, поэтому список собирается через defined().
    $nameTokens = [T_STRING];
    foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED'] as $extra) {
        if (defined($extra)) { $nameTokens[] = constant($extra); }
    }

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || !in_array($t[0], $nameTokens, true)) { continue; }

        // Следующий значимый токен обязан быть "(".
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if ($j >= $n || $tokens[$j] !== '(') { continue; }

        // Предыдущий значимый — не стрелка, не ::, не function.
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k--; }
        if ($k >= 0 && is_array($tokens[$k])
            && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }
        // Ведущий слэш и пространство имён отбрасываем: \str_contains и
        // str_contains — одна и та же встроенная функция.
        $bare = $t[1];
        $cut  = strrpos($bare, '\\');
        if ($cut !== false) { $bare = substr($bare, $cut + 1); }
        $names[strtolower($bare)] = true;
    }
    return array_keys($names);
}

function php74_php_files(string $dir): array {
    $out = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $out[] = $f->getPathname(); }
    }
    sort($out);
    return $out;
}

// --------------------------------------------------------------------------
//  Проверки
// --------------------------------------------------------------------------

test('в public_html нет функций новее PHP 7.4', function () use ($ROOT) {
    $files = php74_php_files($ROOT . '/public_html');
    assert_true(count($files) > 0, 'файлы для проверки нашлись');
    foreach ($files as $f) {
        $short = substr($f, strlen($ROOT) + 1);
        foreach (php74_called_functions(file_get_contents($f)) as $fn) {
            if (isset(PHP74_MISSING_FUNCTIONS[$fn])) {
                assert_true(false, "$short: {$fn}() появилась в PHP "
                    . PHP74_MISSING_FUNCTIONS[$fn] . ', на бою это Call to undefined function');
            }
        }
    }
    assert_true(true, count($files) . ' файлов проверено');
});

test('в public_html нет синтаксиса новее PHP 7.4', function () use ($ROOT) {
    foreach (php74_php_files($ROOT . '/public_html') as $f) {
        $short = substr($f, strlen($ROOT) + 1);
        $code  = php74_code_only(file_get_contents($f));
        foreach (PHP74_MISSING_SYNTAX as [$re, $what, $since]) {
            assert_eq(0, preg_match($re, $code), "$short: $what — из PHP $since");
        }
    }
    assert_true(true, 'синтаксис в пределах 7.4');
});

// Тесты на сервер не уезжают (.cpanel.yml публикует только public_html), но
// прогон могут запустить и на 7.4 — тогда несовместимый тест упадёт раньше,
// чем проверит хоть что-то. Себя из проверки исключаем: списки запрещённых
// имён лежат в этом файле ключами массивов, а они для токенайзера
// T_CONSTANT_ENCAPSED_STRING только внутри строк — но имена функций в
// PHP74_MISSING_FUNCTIONS именно строки, так что ложного срабатывания не
// будет. Исключение оставлено на случай, если список однажды перепишут в
// виде вызовов.
test('сами тесты тоже остаются в пределах 7.4', function () use ($ROOT) {
    $self = realpath(__FILE__);
    foreach (php74_php_files($ROOT . '/tests') as $f) {
        if (realpath($f) === $self) { continue; }
        $short = substr($f, strlen($ROOT) + 1);
        $src   = file_get_contents($f);
        foreach (php74_called_functions($src) as $fn) {
            if (isset(PHP74_MISSING_FUNCTIONS[$fn])) {
                assert_true(false, "$short: {$fn}() появилась в PHP " . PHP74_MISSING_FUNCTIONS[$fn]);
            }
        }
        $code = php74_code_only($src);
        foreach (PHP74_MISSING_SYNTAX as [$re, $what, $since]) {
            assert_eq(0, preg_match($re, $code), "$short: $what — из PHP $since");
        }
    }
    assert_true(true, 'тесты в пределах 7.4');
});

// Проверка на самого себя: если разбор сломается (например, кто-то заменит
// токенайзер регуляркой), тесты выше начнут молча проходить на любом коде.
// Поэтому скармливаем разборщику заведомо плохой образец и требуем, чтобы он
// его увидел, — и заведомо хороший, чтобы не ловил лишнего.
test('разборщик действительно ловит нарушения', function () {
    $bad = "<?php\nif (str_contains(\$a, 'x')) { echo \$o?->p; }\n";
    assert_true(in_array('str_contains', php74_called_functions($bad), true),
        'вызов запрещённой функции найден');
    assert_eq(1, preg_match(PHP74_MISSING_SYNTAX[0][0], php74_code_only($bad)),
        'nullsafe найден');

    // Те же имена, но внутри строки и комментария — это НЕ вызовы.
    $good = "<?php\n// str_contains(\$a, 'x')\n\$s = 'str_contains(';\n\$o = \$a->str_contains(\$b);\n";
    assert_true(!in_array('str_contains', php74_called_functions($good), true),
        'строка, комментарий и вызов метода не считаются вызовом функции');
    assert_eq(0, preg_match(PHP74_MISSING_SYNTAX[0][0], php74_code_only($good)),
        'в чистом коде nullsafe не мерещится');
});

run_tests();
