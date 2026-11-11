<?php
// Действия: задача в Б24, отметка разговора, наставник, сброс демо.
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $a, int $code = 200): never {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'reset') {
    if (($_POST['key'] ?? '') !== cfg('demo_reset_key')) out(['ok' => false, 'error' => 'Неверный ключ'], 403);
    reset_demo();
    db();
    out(['ok' => true]);
}

$n = $id ? get_newcomer($id) : null;
if (!$n) out(['ok' => false, 'error' => 'Новичок не найден'], 404);

switch ($action) {
    case 'task':
        if ($n['open_task']) out(['ok' => false, 'error' => 'Задача уже создана и ждёт разговора']);
        [$title, $desc] = task_text($n);
        $resp = cfg('b24_responsible', [])[$n['dept']] ?? null;
        $fields = [
            'TITLE' => $title,
            'DESCRIPTION' => $desc,
            'DEADLINE' => today()->modify('+1 day')->format('Y-m-d') . 'T18:00:00+03:00',
            'PRIORITY' => $n['risk']['score'] >= 80 ? 2 : 1,
            'TAGS' => ['новичок', 'адаптация'],
        ];
        if ($resp) $fields['RESPONSIBLE_ID'] = $resp;
        if (!cfg('b24_webhook')) {
            // Битрикс24 не подключён: фиксируем задачу только в пульте и честно говорим об этом
            add_action($id, 'task', "Задача руководителю ({$n['manager']}): $title (Битрикс24 не подключён — сохранено только в пульте)");
            out(['ok' => true, 'task_id' => '—', 'url' => '#', 'local' => true]);
        }
        $res = b24_call('tasks.task.add', ['fields' => $fields]);
        if (!$res['ok']) out(['ok' => false, 'error' => $res['error']]);
        $taskId = (string)($res['result']['task']['id'] ?? '');
        add_action($id, 'task', "Задача руководителю ({$n['manager']}): $title", $taskId);
        out(['ok' => true, 'task_id' => $taskId, 'url' => b24_task_url($taskId)]);

    case 'talk':
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') out(['ok' => false, 'error' => 'Напишите коротко, чем закончился разговор']);
        add_action($id, 'talk', mb_substr($text, 0, 1000));
        // Закрываем задачу в Б24, если она есть
        if ($n['open_task'] && $n['open_task']['b24_task_id']) {
            b24_call('task.commentitem.add', ['TASKID' => $n['open_task']['b24_task_id'], 'FIELDS' => ['POST_MESSAGE' => "Итог разговора: $text"]]);
            b24_call('tasks.task.complete', ['taskId' => $n['open_task']['b24_task_id']]);
        }
        out(['ok' => true]);

    case 'mentor':
        $m = trim((string)($_POST['mentor'] ?? ''));
        if ($m === '') out(['ok' => false, 'error' => 'Укажите наставника']);
        db()->prepare('UPDATE newcomers SET mentor=? WHERE id=?')->execute([mb_substr($m, 0, 80), $id]);
        add_action($id, 'mentor', "Назначен наставник: $m");
        out(['ok' => true]);
}
out(['ok' => false, 'error' => 'Неизвестное действие'], 400);
