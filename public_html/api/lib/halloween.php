<?php
// Хэллоуин: сайт сам надевает тему с 1 октября по 2 ноября включительно по
// Москве и сам же её снимает, править код к началу и концу не нужно.
//
// Что меняется. Макет — страница «хелоуин» в Figma-файле MAKNEMY: синий
// акцент становится фиолетовым, фон — замок с луной, сакура — голые ветки с
// фиолетовыми листьями, по страницам летают летучие мыши и висит паутина.
//
// Как устроено. Синий цвет разбросан по десятку css-файлов, поэтому руками он
// не перекрашивается: tools/halloween_css.php собирает из каждого такого файла
// полную копию в css/hw/ с теми же правилами, где синие цвета и картинки
// заменены. Страница в эти даты подключает копию ВМЕСТО оригинала (каскад тот
// же, меняются только значения), а сверху css/halloween.css с декором. Копии
// обязаны совпадать со своими оригиналами — это проверяет
// tests/halloween_test.php, так что после правки css/*.css нужно перегенерировать:
//   php tools/halloween_css.php
//
// Кеш. Страницы лежат в LiteSpeed минуту (page_lscache()), поэтому тема
// включается и выключается не позже чем через минуту после полуночи по Москве.
// css и js из css/hw/ кешируются на год, как и всё в css/: номер ?v= у копии
// тот же, что у оригинала, и растёт вместе с ним.
//
// PHP 7.4: без match и без str_contains.

const HALLOWEEN_FROM = '10-01';
const HALLOWEEN_TO   = '11-02';

function halloween_on(?DateTimeInterface $now = null): bool {
    $now = $now ?? new DateTimeImmutable('now');
    $msk = (new DateTimeImmutable('@' . $now->getTimestamp()))
        ->setTimezone(new DateTimeZone('Europe/Moscow'))
        ->format('m-d');
    return $msk >= HALLOWEEN_FROM && $msk <= HALLOWEEN_TO;
}

// Тесты видят сайт без темы, в какой бы месяц их ни запускали; тест, которому
// нужна тема, объявляет HALLOWEEN сам до подключения _bootstrap.php.
if (!defined('HALLOWEEN')) {
    define('HALLOWEEN', defined('TESTING') ? false : halloween_on());
}

// Картинки, которые в тему подменяются целиком (<img src> в разметке страниц).
const HALLOWEEN_IMAGES = [
    'assets/design/home/card-fruits.webp'    => 'assets/halloween/card-fruits.webp',
    'assets/design/home/card-tier.webp'      => 'assets/halloween/card-tier.webp',
    'assets/design/home/card-prices.webp'    => 'assets/halloween/card-prices.webp',
    'assets/design/home/card-giveaways.webp' => 'assets/halloween/card-giveaways.webp',
    'assets/design/home/card-news.webp'      => 'assets/halloween/card-news.webp',
];

function hw_img(string $src): string {
    return HALLOWEEN && isset(HALLOWEEN_IMAGES[$src]) ? HALLOWEEN_IMAGES[$src] : $src;
}

// Декор поверх фона страницы: летучие мыши и паутина из макета. Координаты —
// макетные пиксели кадра шириной 1443 (x, y — угол картинки до поворота,
// w — ширина, r — поворот в градусах), как их отдаёт Figma, только y отсчитан
// от низа шапки (в макете она 195, на сайте ниже), а у главной — от верха
// своей секции. Декор лежит под содержимым страницы, поэтому там, где на сайте
// рекламный борт длиннее макетного (калькулятор, трейдинг, создание
// объявления), мыши и паутина опущены под борт — иначе их не видно.
// css пересчитывает координаты в единицы страницы. Ключ — страница.
const HALLOWEEN_DECOR = [
    'news' => [
        ['bat', 1182.9, 824.1, 149, -27.2],
        ['bat', 81.8, 757.1, 197.3, 7.4],
        ['bat', 1309.8, 702.8, 101.6, -2.9],
    ],
    'calculator' => [
        ['web', 1190, 925, 236, 0],
    ],
    'trade-new' => [
        ['bat', 225.8, 1056, 149, 22.2],
        ['bat', 1163, 1054.2, 149, -27.2],
    ],
    'trading' => [
        ['bat', 1182.5, 1018.9, 174.3, 22.2],
        ['bat', 1187.6, 759.9, 83.2, -53.5],
        ['bat', 1222.7, 778.4, 117, -26.4],
    ],
    'chat' => [
        ['bat', 287.8, 389, 87.6, 22.2],
        ['bat', 290.4, 258.8, 41.8, -53.5],
        ['bat', 308, 268.1, 58.8, -26.4],
    ],
    'profile' => [
        ['web', -11, 251, 146, 0],
        ['bat', 433, 115.6, 174.3, -13.8],
        ['bat', 1125.7, 814, 174.3, 23.4],
    ],
    'home-lead' => [
        ['bat', 1089, 642, 325, 0],
        ['bat', 29, 13, 174, 0],
        ['bat', 710, 223.2, 98, -3.6],
        ['web', 1151, 6, 236, 0],
    ],
    'home-faq' => [
        ['bat', 1090, 440, 149, 0],
        ['bat', 1181.1, 513.3, 77, 10.3],
        ['bat', 220.5, 183.7, 77, -15.9],
    ],
];

function halloween_decor(string $page, string $class = 'hw-decor'): string {
    if (!HALLOWEEN || !isset(HALLOWEEN_DECOR[$page])) {
        return '';
    }
    $out = '<div class="' . $class . '" aria-hidden="true">';
    foreach (HALLOWEEN_DECOR[$page] as $d) {
        $out .= sprintf(
            '<img class="hw-%s" src="assets/halloween/%s.webp" alt="" style="--x:%s;--y:%s;--w:%s;--r:%sdeg" />',
            $d[0], $d[0], $d[1], $d[2], $d[3], $d[4]
        );
    }
    return $out . '</div>';
}
