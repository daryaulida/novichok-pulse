<?php
// Опрос новичка: телефон, 1 минута.
require __DIR__ . '/lib.php';
$token = (string)($_GET['t'] ?? '');
$st = db()->prepare("SELECT * FROM newcomers WHERE token=? AND status='active'");
$st->execute([$token]);
$n = $st->fetch();
$done = false; $error = '';

if ($n && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clear = (int)($_POST['q_clear'] ?? 0);
    $contact = $_POST['q_contact'] ?? '';
    $sched = (int)($_POST['q_schedule'] ?? 0);
    $stay = $_POST['q_stay'] ?? '';
    if ($clear < 1 || $clear > 3 || !in_array($contact, ['0', '1'], true) || $sched < 1 || $sched > 3 || !in_array($stay, ['yes', 'unsure', 'no'], true)) {
        $error = 'Ответьте, пожалуйста, на все 4 вопроса';
    } else {
        db()->prepare('INSERT INTO checkins (newcomer_id,day,created_at,q_clear,q_contact,q_schedule,q_stay,comment) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$n['id'], day_of($n), date('Y-m-d H:i'), $clear, (int)$contact, $sched, $stay, mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 500)]);
        $done = true;
    }
}
$first = $n ? explode(' ', $n['name'])[0] : '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#16a34a">
<title>Как вам у нас?</title>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body class="q-body">
<div class="q">
<?php if (!$n): ?>
  <h1>Ссылка не работает</h1>
  <p class="muted">Возможно, она устарела. Спросите новую у HR.</p>
<?php elseif ($done): ?>
  <div class="q-done">
    <div class="q-emoji">🙌</div>
    <h1>Спасибо, <?= h($first) ?>!</h1>
    <p>Ответы получил HR. Если вы написали, что что-то не так, с вами свяжутся в ближайшие дни — не придётся ждать, пока станет совсем плохо.</p>
    <p class="muted small">Следующий короткий опрос придёт через пару недель.</p>
  </div>
<?php else: ?>
  <div class="q-head">
    <div class="q-logo">Пульс новичка · опрос</div>
    <h1><?= h($first) ?>, как вам у нас?</h1>
    <p class="muted">Вы с нами <?= day_of($n) ?>-й день. 4 вопроса, меньше минуты. Ответы видит только HR, чтобы вовремя помочь.</p>
  </div>
  <?php if ($error): ?><div class="q-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="q-form">
    <fieldset>
      <legend>1. Понятно, что от вас ждут на работе?</legend>
      <div class="opts three">
        <label><input type="radio" name="q_clear" value="1"><span>Нет</span></label>
        <label><input type="radio" name="q_clear" value="2"><span>Частично</span></label>
        <label><input type="radio" name="q_clear" value="3"><span>Да</span></label>
      </div>
    </fieldset>
    <fieldset>
      <legend>2. Есть человек, к которому можно подойти с вопросом?</legend>
      <div class="opts two">
        <label><input type="radio" name="q_contact" value="0"><span>Нет</span></label>
        <label><input type="radio" name="q_contact" value="1"><span>Да</span></label>
      </div>
    </fieldset>
    <fieldset>
      <legend>3. График и нагрузка подходят?</legend>
      <div class="opts three">
        <label><input type="radio" name="q_schedule" value="1"><span>Нет</span></label>
        <label><input type="radio" name="q_schedule" value="2"><span>Терпимо</span></label>
        <label><input type="radio" name="q_schedule" value="3"><span>Да</span></label>
      </div>
    </fieldset>
    <fieldset>
      <legend>4. Через месяц вы видите себя у нас?</legend>
      <div class="opts three">
        <label><input type="radio" name="q_stay" value="no"><span>Нет</span></label>
        <label><input type="radio" name="q_stay" value="unsure"><span>Не уверен</span></label>
        <label><input type="radio" name="q_stay" value="yes"><span>Да</span></label>
      </div>
    </fieldset>
    <fieldset>
      <legend>Что мешает или что улучшить? <span class="muted">(необязательно)</span></legend>
      <textarea name="comment" rows="3" maxlength="500" placeholder="Можно коротко"></textarea>
    </fieldset>
    <button class="btn primary full big">Отправить</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
