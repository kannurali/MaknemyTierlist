# Хэллоуинский ивент Spooky Tokens — дизайн

2026-10-30. Заменяет большую версию «Тыквенной охоты» (PR #187: задания,
вопросы, секретки, главы). Из #187 взяты кошелёк, журнал наград, билеты,
итоги и выдача. Модуль по-прежнему `hunt`, страница `/halloween`, админка
`/admin/hunt` — слова `event`/`promo` режут блокировщики.

## Решения владельца (30.10)

| Вопрос | Ответ |
| --- | --- |
| Старая версия #187 | Заменить простой: задания, секретки, главы убрать |
| Даты | Дату старта ставит владелец в админке, дальше 7 суток по Москве |
| Время на сайте | Считается только пока вкладка на экране, вкладки не складываются |
| Розыгрыш | Свой, на `/halloween` (как в #187), не через `/giveaways` |
| Числа | Вход 10·15·20·25·30·35·40, 1 ч — 15, 2 ч — 20; правятся в админке до старта |
| Титулы | В профиле, чате и объявлениях трейдинга рядом с ником, навсегда |
| Токен | Череп из сообщения владельца — `assets/hunt/token*.webp` |
| Визуал | Делаем сами: дизайн-агент в стиле Хэллоуин-темы |

## Механика

- **Сезон.** `starts_at` — полночь по Москве дня 1. Дни идут по 86400 с
  (в Москве нет перевода часов). День N = `intdiv(now − starts_at, 86400) + 1`,
  1…7. `ends_at = starts_at + 7·86400` — после него токены не копятся.
- **Фазы** (`hunt_phase`): `draft` — черновик; `soon` — опубликован, не начался;
  `open` — идут 7 дней, токены копятся, билеты продаются; `sales` — 7 дней
  прошли, билеты ещё продаются до `sales_end_at`; `closed` — ждём итогов;
  `drawn` — итоги есть.
- **Вход.** Первый запрос `api/session.php` вошедшего за сутки N начисляет
  `login[N]` (ref `login:N`). Пропущенный день не догоняется: ключ прошлого дня
  уже не выдаётся.
- **Время.** Вкладка на экране шлёт `beat` раз в минуту. Сервер засчитывает
  `min(now − beat_at, 65)` секунд (минута с запасом на задержку сети), если
  прошлый пульс был не раньше 120 с назад, иначе 0 (первый пульс после перерыва только ставит отметку). Пульсы
  всех вкладок пишут в одну строку `hunt_time`, поэтому две вкладки дают ту же
  минуту, а не две. Обновление условное (`WHERE beat_at = :old`): два
  одновременных пульса не засчитают время дважды. На 3600 с — `hours[0]`
  (ref `hour:N:1`), на 7200 с — `hours[1]` (`hour:N:2`). Больше 7200 за сутки
  не копится, ответ `full` — вкладка перестаёт слать пульсы до следующих суток.
- **Потолок** `cap` = сумма `login` + 7 × сумма `hours`. **Цена билета** =
  `floor(cap / 2)`, билетов не больше 2: два билета — это все токены ивента.
- **Счётчик** показывает `earned` («собрано»): траты на билеты его не уменьшают.
- **Титулы.** Все 7 `login:*` → титул `login`, все 14 `hour:*:*` → `hours`.
  Строка в `hunt_titles` появляется в той же транзакции, что последняя награда.
  Тексты (ru/en) — в `titles_json` сезона.
- **Итоги** — как в #187: кнопка «Разыграть» после `sales_end_at`, Фишер—Йетс
  по билетам, один человек — один приз, 2 билета — 2 шанса, просрочка
  подтверждения → «Перевыбрать».
- Гость ничего не копит: счётчик показывает потолок и зовёт войти через Roblox.
- Забаненный (`site_banned`) не копит и не покупает.

## Данные — `docs/migrations/2026-10-30-hunt.sql`

- `hunts`: `id, slug, status, title_ru/en, rules_ru/en, starts_at,
  sales_end_at, draw_at, login_json, hours_json, prizes_json, claim_days,
  titles_json, created_at, updated_at, drawn_at`.
- `hunt_wallets (hunt_id, user_id)`: `balance, earned, tickets, first_at, last_at`.
- `hunt_ledger`: `id, hunt_id, user_id, delta, kind (login|hour|ticket|admin),
  ref, created_at`, `UNIQUE (hunt_id, user_id, ref)`.
- `hunt_time (hunt_id, user_id, day)`: `secs, beat_at`.
- `hunt_tickets`, `hunt_wins` — как в #187.
- `hunt_titles (user_id, hunt_id, kind)`: `created_at`.

## API

- `GET api/hunt.php` — публичное, LSCache 30 с, тег `hunt`:
  `{hunt: {id, title:{ru,en}, rules:{ru,en}, phase, starts_at, ends_at,
  sales_end_at, draw_at, day, days:7, login:[7 чисел], hours:[2 числа], cap,
  price, limit:2, prizes:[{name,count,icon}], titles:{login:{ru,en},
  hours:{ru,en}}, hunters}, winners:[{prize, user:{id,username,display_name}}]}`
  или `{hunt:null}`.
- `GET api/hunt_me.php` — личное: `{ok, hunt_id, wallet:{balance, earned,
  tickets}, day, today:{secs, full}, days:[{n, login:{reward, state:
  got|missed|today|future}, hours:[{need, reward, got}, …], secs}],
  tickets:[{id,no,seq,created_at,void}], wins:[{id, prize, status, deadline,
  ticket_no}], titles:[{kind, title:{ru,en}}], tg, next_day_at}`.
- `POST api/hunt_act.php`:
  - `{act:"beat"}` → `{ok, status: done|full|closed, day, secs, gains, wallet,
    titles_new}`;
  - `{act:"ticket", seq}` → `{ok, status, ticket, tickets, wallet, price,
    limit, …}`; статусы `done|already|limit|stale|balance|closed|login|banned`;
  - `{act:"confirm_win", id}` → `{ok, status, win}`.
  - `gains` — `[{kind:"login"|"hour", n, k?, delta}]`, `titles_new` —
    `[{kind, title:{ru,en}}]`.
- `api/session.php`, поле `hunt` (вошедший, не забанен, сезон не черновик):
  `{phase, day, earned, balance, tickets, cap, price, limit, secs, full,
  gains, titles_new, next: won|ticket|null}`, иначе `null`.
- `api/hunt_admin.php` — только `admin_ids`: GET сезон, статистика,
  участники, билеты, выигрыши; POST `save | draw | win | redraw | void`.
  После старта `starts_at`, `login`, `hours` не меняются.
- Титулы для чужих людей — `hunt_titles_for($pdo, $uids)`; поле `titles`
  у автора в ответах профиля, чата и трейдинга.

## Страницы (дизайн-агент)

- `/halloween` (`halloween.php`): шапка ивента с токеном и формулой «Заходи
  каждый день и сиди на сайте → собирай токены → купи билет на Gas»;
  календарь 7 дней (получено, пропущено, сегодня, впереди); две шкалы
  «1 час» и «2 часа» за сегодня; баланс и кнопка билета (2 места); мои билеты
  и выигрыши; призы, даты, правила; оба титула с условиями.
- `js/hunt-bar.js` на каждой публичной странице: вертикальная шкала у правого
  края, токен, `earned / cap`, по нажатию `/halloween`; пульсы времени;
  всплывашка «+N» при `gains` и при новом титуле. Не перекрывает нижнюю
  рекламную полосу и её мини-строку, лист ☰, поле ввода чата, калькулятор.

## Проверка

`tests/hunt_test.php`: повтор награды, пропуск дня, пульсы и вкладки, полночь
по Москве, потолок и цена, лимит билетов, титулы, итоги. Скриншоты страницы и
счётчика при 390 и 1440 px.
