<?php
// Пульс новичка — общая логика: БД, демо-данные, расчёт риска, Битрикс24.
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Minsk');

$CONFIG = file_exists(__DIR__ . '/config.php')
    ? require __DIR__ . '/config.php'
    : require __DIR__ . '/config.sample.php';

const CHECKPOINTS = [3, 14, 30, 60];
const DEPTS = ['sklad' => 'Склад', 'shop' => 'Магазины', 'sales' => 'Отдел продаж', 'office' => 'Офис'];

function cfg(string $k, $default = null) { global $CONFIG; return $CONFIG[$k] ?? $default; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function today(): DateTimeImmutable { return new DateTimeImmutable('today'); }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $file = __DIR__ . '/data/pulse.sqlite';
    $fresh = !file_exists($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($fresh) { create_schema($pdo); seed($pdo); }
    return $pdo;
}

function create_schema(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE newcomers (
        id INTEGER PRIMARY KEY, name TEXT, position TEXT, dept TEXT, unit TEXT,
        manager TEXT, mentor TEXT, phone TEXT, hire_date TEXT, token TEXT UNIQUE,
        status TEXT DEFAULT 'active', left_date TEXT, left_reason TEXT, b24_source TEXT
    );
    CREATE TABLE checkins (
        id INTEGER PRIMARY KEY, newcomer_id INTEGER, day INTEGER, created_at TEXT,
        q_clear INTEGER, q_contact INTEGER, q_schedule INTEGER, q_stay TEXT, comment TEXT
    );
    CREATE TABLE actions (
        id INTEGER PRIMARY KEY, newcomer_id INTEGER, type TEXT, text TEXT,
        b24_task_id TEXT, created_at TEXT
    );");
}

// ---------- демо-данные (выдуманные люди) ----------
function seed(PDO $pdo): void {
    mt_srand(20261111);
    $first_m = ['Алексей','Дмитрий','Иван','Максим','Артём','Никита','Павел','Сергей','Андрей','Егор','Кирилл','Виталий','Роман','Денис','Олег'];
    $first_f = ['Анна','Мария','Екатерина','Ольга','Юлия','Алина','Дарина','Ксения','Виктория','Полина','Наталья','Елена','Вероника','Татьяна','Яна'];
    $last = ['Ковальчук','Савицк','Новик','Жук','Лукашевич','Мельник','Гринкевич','Карпович','Шевчик','Бондар','Сидоренк','Левчук','Микулич','Пашкевич','Романович','Ткачук','Захарчук','Кравец','Тарасевич','Якубович'];
    $roles = [
        'sklad'  => [['Кладовщик',3],['Комплектовщик',4],['Водитель-экспедитор',2],['Грузчик',3]],
        'shop'   => [['Продавец-консультант',5],['Кассир',2],['Администратор магазина',1]],
        'sales'  => [['Менеджер по оптовым продажам',3],['Менеджер маркетплейсов',2]],
        'office' => [['Бухгалтер',1],['Специалист по закупкам',1],['Контент-менеджер',1]],
    ];
    $units = [
        'sklad' => ['Склад, Гродно'], 'shop' => ['Магазин «Центр»','Магазин «Триумф»','Магазин «Восток»'],
        'sales' => ['Опт и B2B','Маркетплейсы'], 'office' => ['Офис'],
    ];
    $managers = ['sklad' => 'Руслан Гайдук', 'shop' => 'Ирина Остапчук', 'sales' => 'Вадим Лешкевич', 'office' => 'Светлана Рудь'];
    $mentors = ['sklad' => ['Николай Бут','Геннадий Шпак'], 'shop' => ['Оксана Вашкевич','Людмила Кот'], 'sales' => ['Глеб Станкевич'], 'office' => ['Марина Дубок']];

    // Грузчики и водители в демо — мужчины, остальные должности — любые
    $person = function (string $role = '') use ($first_m, $first_f, $last) {
        $f = in_array($role, ['Грузчик', 'Водитель-экспедитор'], true) ? 0 : mt_rand(0, 1);
        $ln = $last[array_rand($last)];
        if ($ln === 'Савицк') $ln .= $f ? 'ая' : 'ий';
        if ($ln === 'Сидоренк') $ln .= 'о';
        return ($f ? $first_f[array_rand($first_f)] : $first_m[array_rand($first_m)]) . ' ' . $ln;
    };
    $pick_role = function () use ($roles) {
        $pool = [];
        foreach ($roles as $d => $list) foreach ($list as [$r, $w]) for ($i = 0; $i < $w; $i++) $pool[] = [$d, $r];
        return $pool[array_rand($pool)];
    };

    $ins = $pdo->prepare('INSERT INTO newcomers (name,position,dept,unit,manager,mentor,phone,hire_date,token,status,left_date,left_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $ci  = $pdo->prepare('INSERT INTO checkins (newcomer_id,day,created_at,q_clear,q_contact,q_schedule,q_stay,comment) VALUES (?,?,?,?,?,?,?,?)');
    $t = today();

    // Сценарии ответов: хорошие, сомневающиеся, тревожные
    $profiles = ['ok','ok','ok','ok','ok','doubt','doubt','alarm','silent'];
    $comments = [
        'alarm' => ['Никто толком не объяснил, как здесь всё устроено','Ставят на смены в выходные, хотя договаривались иначе','Не знаю, к кому идти с вопросами, все заняты','Обещали другую зарплату на первом месяце','Тяжело, непонятно, какой результат от меня ждут'],
        'doubt' => ['Пока непривычно, но разбираюсь','Много информации сразу','Хотелось бы больше обучения в первые дни','Не всегда понятно, кто ставит задачи'],
        'ok'    => ['', '', 'Всё нравится, коллектив хороший', 'Спасибо наставнику!', ''],
    ];

    // 40 текущих новичков (0–90 дней)
    for ($i = 0; $i < 40; $i++) {
        [$dept, $role] = $pick_role();
        $day = mt_rand(1, 90);
        $hire = $t->modify('-' . ($day - 1) . ' days')->format('Y-m-d');
        $hasMentor = mt_rand(1, 100) <= ($dept === 'sklad' ? 55 : 75);
        $mentor = $hasMentor ? $mentors[$dept][array_rand($mentors[$dept])] : null;
        $profile = $profiles[array_rand($profiles)];
        if (!$hasMentor && $profile === 'ok' && mt_rand(0, 1)) $profile = 'doubt';
        $ins->execute([$person($role), $role, $dept, $units[$dept][array_rand($units[$dept])], $managers[$dept], $mentor,
            '+375 29 ' . mt_rand(100, 999) . '-' . mt_rand(10, 99) . '-' . mt_rand(10, 99),
            $hire, bin2hex(random_bytes(8)), 'active', null, null]);
        $id = (int)$pdo->lastInsertId();
        foreach (CHECKPOINTS as $k => $cp) {
            if ($day < $cp) break;
            $isLast = !isset(CHECKPOINTS[$k + 1]) || $day < CHECKPOINTS[$k + 1];
            if ($profile === 'silent' && $isLast) break;          // не ответил на последний опрос
            if (mt_rand(1, 100) <= 8 && !$isLast) continue;        // иногда пропускает
            $p = $isLast ? $profile : (in_array($profile, ['alarm']) && mt_rand(0, 1) ? 'doubt' : 'ok');
            $answerDay = min($day, $cp + mt_rand(0, 2));
            $created = $t->modify('-' . ($day - $answerDay) . ' days')->format('Y-m-d') . ' ' . sprintf('%02d:%02d', mt_rand(8, 20), mt_rand(0, 59));
            [$cl, $co, $sc, $st] = match ($p) {
                'ok'    => [mt_rand(2, 3) === 2 && mt_rand(0, 3) === 0 ? 2 : 3, 1, mt_rand(0, 4) ? 3 : 2, 'yes'],
                'doubt' => [mt_rand(1, 2), $hasMentor ? 1 : 0, mt_rand(1, 3), 'unsure'],
                'alarm' => [1, $hasMentor ? mt_rand(0, 1) : 0, mt_rand(1, 2), mt_rand(0, 2) ? 'no' : 'unsure'],
            };
            $cm = $isLast ? $comments[$p][array_rand($comments[$p])] : '';
            $ci->execute([$id, $answerDay, $created, $cl, $co, $sc, $st, $cm]);
        }
    }

    // История за год: прошли испытательный срок и ушедшие (для статистики)
    $reasons = ['Не устроил график','Не сошлись по зарплате','Не справился с нормой','Ушёл без объяснения','Тяжёлая физическая работа','Нашёл работу ближе к дому','Конфликт в коллективе','Переезд'];
    for ($i = 0; $i < 70; $i++) {
        [$dept, $role] = $pick_role();
        $hire = $t->modify('-' . mt_rand(95, 400) . ' days');
        $left = mt_rand(1, 100) <= 55;
        if ($left) {
            // Ранние уходы чаще на складе и в магазинах
            $early = mt_rand(1, 100) <= (in_array($dept, ['sklad', 'shop']) ? 42 : 18);
            $tenure = $early ? (mt_rand(1, 100) <= 55 ? mt_rand(3, 21) : mt_rand(22, 89)) : mt_rand(91, 330);
            $ld = $hire->modify("+$tenure days");
            if ($ld > $t) $ld = $t->modify('-' . mt_rand(1, 30) . ' days');
            $ins->execute([$person($role), $role, $dept, $units[$dept][array_rand($units[$dept])], $managers[$dept], null, null,
                $hire->format('Y-m-d'), bin2hex(random_bytes(8)), 'left', $ld->format('Y-m-d'), $reasons[array_rand($reasons)]]);
        } else {
            $ins->execute([$person($role), $role, $dept, $units[$dept][array_rand($units[$dept])], $managers[$dept], null, null,
                $hire->format('Y-m-d'), bin2hex(random_bytes(8)), 'passed', null, null]);
        }
    }
}

function reset_demo(): void {
    $file = __DIR__ . '/data/pulse.sqlite';
    if (file_exists($file)) unlink($file);
}

// ---------- расчёт ----------
function day_of(array $n): int {
    $d = (new DateTimeImmutable($n['hire_date']))->diff(today())->days + 1;
    return max(1, (int)$d);
}

function current_checkpoint(int $day): int {
    $c = CHECKPOINTS[0];
    foreach (CHECKPOINTS as $cp) if ($day >= $cp) $c = $cp;
    return $c;
}

/** Возвращает ['score'=>int,'level'=>'green|yellow|red','reasons'=>[...],'last'=>checkin|null] */
function risk(array $n, array $checkins, array $actions): array {
    $day = day_of($n);
    $reasons = [];
    $score = 0;
    usort($checkins, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    $last = $checkins ? end($checkins) : null;

    // Пропущенный опрос: прошло ≥3 дня от контрольной точки, а ответа на неё нет
    foreach (CHECKPOINTS as $k => $cp) {
        $next = CHECKPOINTS[$k + 1] ?? 999;
        if ($day >= $cp + 3 && $day < $next + 3) {
            $answered = false;
            foreach ($checkins as $c) if ($c['day'] >= $cp && $c['day'] < $next) $answered = true;
            if (!$answered) { $score += 15; $reasons[] = "Не ответил на опрос {$cp}-го дня"; }
        }
    }

    if ($last) {
        if ($last['q_stay'] === 'no')     { $score += 40; $reasons[] = 'Не видит себя в компании через месяц'; }
        if ($last['q_stay'] === 'unsure') { $score += 15; $reasons[] = 'Не уверен, что останется'; }
        if ((int)$last['q_clear'] === 1)  { $score += 25; $reasons[] = 'Не понимает, что от него ждут'; }
        if ((int)$last['q_clear'] === 2)  { $score += 8;  $reasons[] = 'Задачи понятны не до конца'; }
        if ((int)$last['q_contact'] === 0){ $score += 20; $reasons[] = 'Не к кому обратиться с вопросом'; }
        if ((int)$last['q_schedule'] === 1){ $score += 20; $reasons[] = 'Не подходит график или нагрузка'; }
        if ((int)$last['q_schedule'] === 2){ $score += 6;  $reasons[] = 'График подходит частично'; }
        // Ухудшение по сравнению с прошлым опросом
        if (count($checkins) >= 2) {
            $prev = $checkins[count($checkins) - 2];
            $sum = fn($c) => (int)$c['q_clear'] + (int)$c['q_contact'] * 2 + (int)$c['q_schedule'] + ['yes' => 2, 'unsure' => 1, 'no' => 0][$c['q_stay']];
            if ($sum($last) <= $sum($prev) - 2) { $score += 10; $reasons[] = 'Ответы хуже, чем в прошлый раз'; }
        }
    }
    if (empty($n['mentor']) && $day <= 45) { $score += 10; $reasons[] = 'Не назначен наставник'; }

    if ($day <= 30 && $score > 0) $score = (int)round($score * 1.2); // самые опасные первые 30 дней

    // Разговор после последнего тревожного сигнала снижает риск
    $since = $last['created_at'] ?? $n['hire_date'];
    $talked = false;
    foreach ($actions as $a) {
        if ($a['type'] === 'talk' && $a['created_at'] >= $since) { $score = max(0, $score - 20); $reasons[] = 'С новичком поговорили после опроса (−20)'; $talked = true; break; }
    }

    $level = $score >= 60 ? 'red' : ($score >= 30 ? 'yellow' : 'green');
    return ['score' => $score, 'level' => $level, 'reasons' => $reasons, 'last' => $last, 'day' => $day, 'talked' => $talked];
}

function load_active(): array {
    $pdo = db();
    $rows = $pdo->query("SELECT * FROM newcomers WHERE status='active' ORDER BY hire_date DESC")->fetchAll();
    $ci = $pdo->query('SELECT * FROM checkins')->fetchAll();
    $ac = $pdo->query('SELECT * FROM actions ORDER BY created_at')->fetchAll();
    $byC = $byA = [];
    foreach ($ci as $c) $byC[$c['newcomer_id']][] = $c;
    foreach ($ac as $a) $byA[$a['newcomer_id']][] = $a;
    foreach ($rows as &$r) {
        $r['checkins'] = $byC[$r['id']] ?? [];
        $r['actions'] = $byA[$r['id']] ?? [];
        $r['risk'] = risk($r, $r['checkins'], $r['actions']);
        $r['open_task'] = null;
        foreach ($r['actions'] as $a) {
            if ($a['type'] === 'task') $r['open_task'] = $a;
            if ($a['type'] === 'talk') $r['open_task'] = null;
        }
    }
    return $rows;
}

function get_newcomer(int $id): ?array {
    foreach (load_active() as $r) if ((int)$r['id'] === $id) return $r;
    return null;
}

function add_action(int $id, string $type, string $text, ?string $b24 = null): void {
    db()->prepare('INSERT INTO actions (newcomer_id,type,text,b24_task_id,created_at) VALUES (?,?,?,?,?)')
        ->execute([$id, $type, $text, $b24, date('Y-m-d H:i')]);
}

function stats(): array {
    $pdo = db();
    $since = today()->modify('-365 days')->format('Y-m-d');
    $left = $pdo->query("SELECT * FROM newcomers WHERE status='left' AND left_date >= '$since'")->fetchAll();
    $buckets = ['до 2 недель' => [0, 14], '15–30 дней' => [15, 30], '31–60 дней' => [31, 60], '61–90 дней' => [61, 90], 'больше 90 дней' => [91, 9999]];
    $chart = [];
    foreach (DEPTS as $k => $_) foreach ($buckets as $b => $_r) $chart[$k][$b] = 0;
    $early = 0;
    foreach ($left as $l) {
        $t = (new DateTimeImmutable($l['hire_date']))->diff(new DateTimeImmutable($l['left_date']))->days;
        if ($t < 90) $early++;
        foreach ($buckets as $b => [$a, $z]) if ($t >= $a && $t <= $z) $chart[$l['dept']][$b]++;
    }
    $hired = (int)$pdo->query("SELECT COUNT(*) FROM newcomers WHERE hire_date >= '$since'")->fetchColumn();
    return ['left' => count($left), 'early' => $early, 'early_pct' => $left ? round($early / count($left) * 100) : 0,
        'hired' => $hired, 'chart' => $chart, 'buckets' => array_keys($buckets)];
}

// ---------- Битрикс24 ----------
function b24_call(string $method, array $params): array {
    $hook = rtrim((string)cfg('b24_webhook', ''), '/');
    if ($hook === '') return ['ok' => false, 'error' => 'Вебхук Битрикс24 не настроен (config.php)'];
    $ch = curl_init("$hook/$method.json");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => "Сеть: $err"];
    $j = json_decode($raw, true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'Неожиданный ответ Битрикс24'];
    if (isset($j['error'])) return ['ok' => false, 'error' => $j['error'] . ': ' . ($j['error_description'] ?? '')];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

function b24_task_url(string $taskId): string {
    $hook = (string)cfg('b24_webhook', '');
    if (!preg_match('~^(https://[^/]+)/rest/(\d+)/~', $hook, $m)) return '#';
    $uid = cfg('b24_responsible', [])[0] ?? $m[2];
    return "{$m[1]}/company/personal/user/{$m[2]}/tasks/task/view/$taskId/";
}

function task_text(array $n): array {
    $r = $n['risk'];
    $dept = DEPTS[$n['dept']];
    $title = "Поговорить с новичком: {$n['name']} — {$n['position']}, день {$r['day']}";
    $lines = [
        "[B]Почему:[/B] риск ухода {$r['score']} баллов.",
    ];
    foreach ($r['reasons'] as $why) $lines[] = "— $why";
    if (!empty($r['last']['comment'])) $lines[] = "\n[B]Комментарий новичка:[/B] «{$r['last']['comment']}»";
    $lines[] = "\n[B]Кто:[/B] {$n['name']}, {$n['position']}, {$n['unit']}, на работе с " . date('d.m.Y', strtotime($n['hire_date'])) . ". Наставник: " . ($n['mentor'] ?: 'не назначен') . '.';
    $lines[] = "\n[B]Что сделать (15 минут):[/B]\n1. Поговорить лично, не в чате.\n2. Спросить: что сейчас самое сложное? что мы обещали и не сделали?\n3. Если нет наставника — назначить.\n4. Отметить итог разговора в «Пульсе новичка».";
    $lines[] = "\nКарточка: " . rtrim((string)cfg('base_url', ''), '/') . '/person.php?id=' . $n['id'];
    return [$title, implode("\n", $lines)];
}

function layout_head(string $title, string $active = ''): void { ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> · Пульс новичка</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>
<header class="top">
  <a class="brand" href="index.php">Пульс новичка <small>адаптация · 90 дней</small></a>
  <nav>
    <a href="index.php" class="<?= $active === 'dash' ? 'on' : '' ?>">Пульт</a>
    <a href="links.php" class="<?= $active === 'links' ? 'on' : '' ?>">Ссылки на опрос</a>
    <a href="about.php" class="<?= $active === 'about' ? 'on' : '' ?>">Как считается риск</a>
  </nav>
</header>
<main class="wrap">
<?php }

function layout_foot(): void { ?>
</main>
<footer class="foot">Демо-версия · данные выдуманные · <a href="about.php">как это работает</a></footer>
</body>
</html>
<?php }

function badge(array $r): string {
    $label = ['red' => 'Высокий', 'yellow' => 'Средний', 'green' => 'Низкий'][$r['level']];
    return '<span class="badge ' . $r['level'] . '">' . $label . ' · ' . $r['score'] . '</span>';
}

function ans3(int $v, array $labels): string {
    return '<span class="ans a' . $v . '">' . h($labels[$v - 1]) . '</span>';
}

/** Общая оценка одного опроса: 1 — плохо, 2 — средне, 3 — хорошо */
function pulse_level(array $c): int {
    if ($c['q_stay'] === 'no' || (int)$c['q_clear'] === 1 || (int)$c['q_schedule'] === 1) return 1;
    if ($c['q_stay'] === 'unsure' || !(int)$c['q_contact'] || (int)$c['q_clear'] === 2) return 2;
    return 3;
}

function plural(int $n, string $one, string $few, string $many): string {
    $m10 = $n % 10; $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) return $one;
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) return $few;
    return $many;
}
