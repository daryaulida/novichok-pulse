<?php
require __DIR__ . '/lib.php';
$all = load_active();
$base = rtrim((string)cfg('base_url', ''), '/');
// Кому пора отправить опрос: наступила контрольная точка, а ответа на неё ещё нет
$due = [];
foreach ($all as $n) {
    $day = $n['risk']['day'];
    $cp = current_checkpoint($day);
    if ($day < $cp) continue;
    $has = false;
    foreach ($n['checkins'] as $c) if ($c['day'] >= $cp) $has = true;
    if (!$has) $due[] = $n + ['cp' => $cp];
}
layout_head('Ссылки на опрос', 'links');
?>
<section class="hero"><div>
  <h1>Ссылки на опрос</h1>
  <p class="muted">Опрос приходит на 3-й, 14-й, 30-й и 60-й день. Ниже — кому пора отправить сегодня. Ссылка личная, её можно переслать в Viber или распечатать QR-код и отдать на смене.</p>
</div></section>

<section class="block">
  <div class="block-h"><h2>Пора отправить <span class="count"><?= count($due) ?></span></h2></div>
  <div class="links">
  <?php foreach ($due as $n): $url = $base . '/q.php?t=' . $n['token']; ?>
    <div class="link-card">
      <div class="qr sm" data-qr="<?= h($url) ?>"></div>
      <div class="link-body">
        <a class="name" href="person.php?id=<?= $n['id'] ?>"><?= h($n['name']) ?></a>
        <div class="muted small"><?= h($n['position']) ?> · опрос <?= $n['cp'] ?>-го дня · <?= h($n['phone']) ?></div>
        <div class="link-actions">
          <button class="btn small js-copy" data-text="<?= h("Здравствуйте, {$n['name']}! Это HR. Как вам первые дни у нас? Ответьте, пожалуйста, на 4 коротких вопроса: $url") ?>">Скопировать сообщение</button>
          <a class="btn ghost small" target="_blank" href="q.php?t=<?= h($n['token']) ?>">Открыть</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<div class="toast" id="toast"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="assets/app.js?v=4"></script>
<?php layout_foot();
