<?php
require __DIR__ . '/lib.php';
$all = load_active();
$st = stats();

$red = array_filter($all, fn($n) => $n['risk']['level'] === 'red');
$silent = array_filter($all, fn($n) => (bool)array_filter($n['risk']['reasons'], fn($r) => str_starts_with($r, 'Не ответил')));
// «Поговорить сегодня»: высокий риск, без открытой задачи и без разговора после сигнала
$today = array_values(array_filter($all, fn($n) => $n['risk']['level'] === 'red' && !$n['open_task']));
usort($today, fn($a, $b) => $b['risk']['score'] <=> $a['risk']['score']);
$inWork = array_values(array_filter($all, fn($n) => (bool)$n['open_task']));
usort($all, fn($a, $b) => $b['risk']['score'] <=> $a['risk']['score']);

layout_head('Пульт', 'dash');
?>
<section class="hero">
  <div>
    <h1>Новички первых 90 дней</h1>
    <p class="muted">Каждый третий из ушедших за год не доработал до трёх месяцев. Здесь видно, кто может уйти, и с кем поговорить уже сегодня.</p>
  </div>
  <div class="today-date"><?= today()->format('d.m.Y') ?></div>
</section>

<section class="kpis">
  <div class="kpi"><div class="kpi-n"><?= count($all) ?></div><div class="kpi-l">новичков сейчас</div></div>
  <div class="kpi red"><div class="kpi-n"><?= count($red) ?></div><div class="kpi-l">в зоне высокого риска</div></div>
  <div class="kpi amber"><div class="kpi-n"><?= count($silent) ?></div><div class="kpi-l">не ответили на опрос</div></div>
  <div class="kpi"><div class="kpi-n"><?= $st['early_pct'] ?>%</div><div class="kpi-l">ушедших за год — до 90 дней<br><span class="muted"><?= $st['early'] ?> из <?= $st['left'] ?></span></div></div>
</section>

<section class="block">
  <div class="block-h">
    <h2>Поговорить сегодня <span class="count"><?= count($today) ?></span></h2>
    <p class="muted">Высокий риск, а руководитель ещё не в курсе. Одна кнопка — и у него задача в Битрикс24.</p>
  </div>
  <?php if (!$today): ?>
    <div class="empty">Все тревожные случаи уже в работе 👍</div>
  <?php endif; ?>
  <div class="cards">
  <?php foreach (array_slice($today, 0, 6) as $n): $r = $n['risk']; ?>
    <article class="card">
      <div class="card-top">
        <div>
          <a class="name" href="person.php?id=<?= $n['id'] ?>"><?= h($n['name']) ?></a>
          <div class="muted small"><?= h($n['position']) ?> · <?= h($n['unit']) ?></div>
        </div>
        <?= badge($r) ?>
      </div>
      <div class="dayline"><span>День <?= $r['day'] ?> из 90</span><div class="bar"><i style="width:<?= min(100, $r['day'] / 90 * 100) ?>%"></i></div></div>
      <ul class="reasons">
        <?php foreach (array_slice($r['reasons'], 0, 3) as $why): ?><li><?= h($why) ?></li><?php endforeach; ?>
      </ul>
      <?php if (!empty($r['last']['comment'])): ?><blockquote>«<?= h($r['last']['comment']) ?>»</blockquote><?php endif; ?>
      <div class="card-foot">
        <span class="muted small">Руководитель: <?= h($n['manager']) ?></span>
        <button class="btn primary js-task" data-id="<?= $n['id'] ?>">Задача в Битрикс24</button>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (count($today) > 6): ?><p class="muted small">И ещё <?= count($today) - 6 ?> — в таблице ниже.</p><?php endif; ?>
</section>

<?php if ($inWork): ?>
<section class="block">
  <div class="block-h"><h2>Задачи у руководителей <span class="count"><?= count($inWork) ?></span></h2>
  <p class="muted">Ждём, когда руководитель поговорит и отметит итог.</p></div>
  <div class="inwork">
  <?php foreach ($inWork as $n): $t = $n['open_task']; ?>
    <div class="inwork-row">
      <a href="person.php?id=<?= $n['id'] ?>"><?= h($n['name']) ?></a>
      <span class="muted small"><?= h($n['manager']) ?> · задача от <?= date('d.m H:i', strtotime($t['created_at'])) ?></span>
      <?php if ($t['b24_task_id']): ?><a class="small" target="_blank" href="<?= h(b24_task_url($t['b24_task_id'])) ?>">Задача №<?= h($t['b24_task_id']) ?> в Б24 ↗</a><?php endif; ?>
      <a class="btn ghost small" href="person.php?id=<?= $n['id'] ?>#talk">Отметить разговор</a>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="block">
  <div class="block-h">
    <h2>Все новички</h2>
    <div class="filters">
      <select id="f-dept"><option value="">Все отделы</option><?php foreach (DEPTS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
      <select id="f-risk"><option value="">Любой риск</option><option value="red">Высокий</option><option value="yellow">Средний</option><option value="green">Низкий</option></select>
      <input id="f-q" type="search" placeholder="Поиск по имени">
    </div>
  </div>
  <div class="table-wrap">
  <table class="tbl" id="tbl">
    <thead><tr><th>Новичок</th><th>Отдел</th><th>День</th><th>Наставник</th><th>Последний пульс</th><th>Риск</th></tr></thead>
    <tbody>
    <?php foreach ($all as $n): $r = $n['risk']; $l = $r['last']; ?>
      <tr data-dept="<?= $n['dept'] ?>" data-risk="<?= $r['level'] ?>" data-name="<?= h(mb_strtolower($n['name'])) ?>" onclick="location='person.php?id=<?= $n['id'] ?>'">
        <td><a href="person.php?id=<?= $n['id'] ?>"><?= h($n['name']) ?></a><div class="muted small"><?= h($n['position']) ?></div></td>
        <td><?= DEPTS[$n['dept']] ?><div class="muted small"><?= h($n['unit']) ?></div></td>
        <td><?= $r['day'] ?></td>
        <td><?= $n['mentor'] ? h($n['mentor']) : '<span class="warn">не назначен</span>' ?></td>
        <td><?php if ($l): ?><?= ans3(pulse_level($l), ['тревожный', 'есть сомнения', 'всё хорошо']) ?><div class="muted small"><?= date('d.m', strtotime($l['created_at'])) ?>, день <?= $l['day'] ?></div><?php else: ?><span class="muted">нет ответов</span><?php endif; ?></td>
        <td><?= badge($r) ?><?= $n['open_task'] ? '<div class="small muted">задача у рук.</div>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="block">
  <div class="block-h"><h2>Когда уходят люди</h2>
  <p class="muted">Ушедшие за последний год по стажу на момент ухода. Видно, где теряем людей быстрее всего.</p></div>
  <div class="chart-box"><canvas id="leftChart" height="110"></canvas></div>
</section>

<div class="toast" id="toast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
window.PULSE_CHART = <?= json_encode(['buckets' => $st['buckets'], 'depts' => DEPTS, 'data' => $st['chart']], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/app.js?v=3"></script>
<?php layout_foot();
