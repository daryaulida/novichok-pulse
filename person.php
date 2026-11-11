<?php
require __DIR__ . '/lib.php';
$n = get_newcomer((int)($_GET['id'] ?? 0));
if (!$n) { http_response_code(404); layout_head('Не найдено'); echo '<p>Новичок не найден. <a href="index.php">На пульт</a></p>'; layout_foot(); exit; }
$r = $n['risk'];
$base = rtrim((string)cfg('base_url', ''), '/');
$qLink = "q.php?t={$n['token']}";

// Лента событий: опросы и действия
$feed = [];
foreach ($n['checkins'] as $c) $feed[] = ['at' => $c['created_at'], 'kind' => 'checkin', 'c' => $c];
foreach ($n['actions'] as $a) $feed[] = ['at' => $a['created_at'], 'kind' => $a['type'], 'a' => $a];
$feed[] = ['at' => $n['hire_date'] . ' 09:00', 'kind' => 'hire'];
usort($feed, fn($x, $y) => strcmp($y['at'], $x['at']));

$face = [1 => '😟', 2 => '😐', 3 => '🙂'];
layout_head($n['name']);
?>
<a class="back" href="index.php">← Все новички</a>
<section class="person">
  <div class="person-main">
    <div class="person-h">
      <div>
        <h1><?= h($n['name']) ?></h1>
        <p class="muted"><?= h($n['position']) ?> · <?= DEPTS[$n['dept']] ?>, <?= h($n['unit']) ?></p>
      </div>
      <?= badge($r) ?>
    </div>

    <div class="timeline">
      <div class="tl-bar"><i style="width:<?= min(100, $r['day'] / 90 * 100) ?>%"></i></div>
      <?php foreach (CHECKPOINTS as $cp):
        $ans = null; foreach ($n['checkins'] as $c) if ($c['day'] >= $cp && $c['day'] < $cp + 11) $ans = $c;
        $cls = $ans ? 'done' : ($r['day'] >= $cp ? 'miss' : 'future'); ?>
        <div class="tl-pt <?= $cls ?>" style="left:<?= $cp / 90 * 100 ?>%" title="Опрос <?= $cp ?>-го дня">
          <span class="<?= $ans ? 'lvl' . pulse_level($ans) : '' ?>"><?= $ans ? '✓' : ($cls === 'miss' ? '!' : '') ?></span><em><?= $cp ?> дн.</em>
        </div>
      <?php endforeach; ?>
      <div class="tl-now" style="left:<?= min(100, $r['day'] / 90 * 100) ?>%"><b>сегодня · день <?= $r['day'] ?></b></div>
    </div>

    <h2>Почему такой риск</h2>
    <?php if ($r['reasons']): ?>
      <ul class="reasons big"><?php foreach ($r['reasons'] as $why): ?><li><?= h($why) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">Тревожных сигналов нет.</p><?php endif; ?>
    <p class="small muted"><a href="about.php">Как считаются баллы</a></p>

    <h2>История</h2>
    <ol class="feed">
    <?php foreach ($feed as $f): ?>
      <li class="feed-<?= $f['kind'] ?>">
        <time><?= date('d.m.Y', strtotime($f['at'])) ?></time>
        <?php if ($f['kind'] === 'hire'): ?>
          <div>Вышел на работу. Руководитель — <?= h($n['manager']) ?>.</div>
        <?php elseif ($f['kind'] === 'checkin'): $c = $f['c']; ?>
          <div><b>Опрос, день <?= $c['day'] ?></b>
            <div class="answers">
              <span>Задачи понятны: <?= ans3((int)$c['q_clear'], ['нет', 'частично', 'да']) ?></span>
              <span>Есть к кому обратиться: <?= ans3($c['q_contact'] ? 3 : 1, ['нет', '', 'да']) ?></span>
              <span>График и нагрузка: <?= ans3((int)$c['q_schedule'], ['не подходят', 'терпимо', 'подходят']) ?></span>
              <span>Через месяц у нас: <?= ans3(['yes' => 3, 'unsure' => 2, 'no' => 1][$c['q_stay']], ['нет', 'не уверен', 'да']) ?></span>
            </div>
            <?php if ($c['comment']): ?><blockquote>«<?= h($c['comment']) ?>»</blockquote><?php endif; ?>
          </div>
        <?php else: $a = $f['a']; ?>
          <div><b><?= ['task' => 'Задача в Битрикс24', 'talk' => 'Разговор', 'mentor' => 'Наставник'][$a['type']] ?? $a['type'] ?></b>
            <div><?= h($a['text']) ?></div>
            <?php if ($a['b24_task_id']): ?><a class="small" target="_blank" href="<?= h(b24_task_url($a['b24_task_id'])) ?>">Открыть задачу №<?= h($a['b24_task_id']) ?> ↗</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ol>
  </div>

  <aside class="person-side">
    <div class="side-box">
      <h3>Данные</h3>
      <dl>
        <dt>На работе с</dt><dd><?= date('d.m.Y', strtotime($n['hire_date'])) ?> (день <?= $r['day'] ?>)</dd>
        <dt>Руководитель</dt><dd><?= h($n['manager']) ?></dd>
        <dt>Наставник</dt><dd><?= $n['mentor'] ? h($n['mentor']) : '<span class="warn">не назначен</span>' ?></dd>
        <dt>Телефон</dt><dd><?= h($n['phone']) ?></dd>
      </dl>
    </div>

    <div class="side-box">
      <h3>Действия</h3>
      <?php if ($n['open_task']): ?>
        <p class="small">Задача руководителю создана <?= date('d.m H:i', strtotime($n['open_task']['created_at'])) ?>.
        <?php if ($n['open_task']['b24_task_id']): ?><a target="_blank" href="<?= h(b24_task_url($n['open_task']['b24_task_id'])) ?>">№<?= h($n['open_task']['b24_task_id']) ?> ↗</a><?php endif; ?></p>
      <?php else: ?>
        <button class="btn primary full js-task" data-id="<?= $n['id'] ?>">Задача руководителю в Битрикс24</button>
      <?php endif; ?>

      <form class="js-form" id="talk" data-action="talk" data-id="<?= $n['id'] ?>">
        <label>Итог разговора</label>
        <textarea name="text" rows="3" placeholder="Например: перевели в дневную смену, назначили наставника"></textarea>
        <button class="btn full">Отметить разговор</button>
      </form>

      <?php if (!$n['mentor']): ?>
      <form class="js-form" data-action="mentor" data-id="<?= $n['id'] ?>">
        <label>Назначить наставника</label>
        <input name="mentor" placeholder="Имя опытного сотрудника">
        <button class="btn full">Назначить</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="side-box">
      <h3>Опрос новичка</h3>
      <p class="small muted">Личная ссылка. Её можно отправить в Viber или распечатать QR.</p>
      <a class="btn ghost full" target="_blank" href="<?= h($qLink) ?>">Открыть опрос как новичок ↗</a>
      <div class="qr" data-qr="<?= h($base . '/' . $qLink) ?>"></div>
    </div>
  </aside>
</section>
<div class="toast" id="toast"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="assets/app.js?v=3"></script>
<?php layout_foot();
