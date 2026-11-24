<?php $u = \Leads\Auth::user(); $flash = \Leads\View::flash(); $here = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($__title ? $__title . ' · ' : '') . 'Leads · 4Return') ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= e(LEADS_VERSION) ?>">
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<?php if ($u): ?>
<header class="top">
  <a class="brand" href="/"><span class="logo">4R</span> Leads</a>
  <nav>
    <a href="/" class="<?= $here === '/' ? 'on' : '' ?>">Dashboard</a>
    <a href="/leads" class="<?= str_starts_with((string)$here, '/lead') ? 'on' : '' ?>">Leads</a>
    <?php if ($u['role'] === 'admin'): ?><a href="/admin" class="<?= str_starts_with((string)$here, '/admin') ? 'on' : '' ?>">Admin</a><?php endif; ?>
  </nav>
  <form method="post" action="/logout" class="who"><?= csrf_field() ?><span><?= e($u['email']) ?><?= $u['client_name'] ? ' · ' . e($u['client_name']) : '' ?></span><button class="link">Sign out</button></form>
</header>
<?php endif; ?>
<main class="<?= $u ? '' : 'narrow' ?>">
<?php if ($flash): ?><div class="flash <?= e($flash['kind']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
<?= $__content ?>
</main>
<footer class="foot">Leads v<?= e(LEADS_VERSION) ?> · Public records: Wayne County Treasurer, City of Detroit · Contact data: DealMachine · Calls only 9 a.m.–9 p.m. ET, never to DNC or opted-out numbers.</footer>
</body>
</html>
