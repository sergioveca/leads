<?php /** @var bool $configured @var array $apps @var ?string $error */ ?>
<h1>Podio connection</h1>
<?php if (!$configured): ?>
<div class="flash err">Not connected: add PODIO_CLIENT_ID, PODIO_CLIENT_SECRET, PODIO_USERNAME and PODIO_PASSWORD to the server .env.</div>
<?php elseif ($error): ?>
<div class="flash err"><?= e($error) ?></div>
<?php else: ?>
<div class="flash">Signed in to Podio. Field lists are cached for 6 hours (<a href="/podio/check?refresh=1">reload now</a>).</div>
<?php foreach ($apps as $a): $app = $a['app']; ?>
<div class="card">
  <h2 style="margin-top:0"><?= e($app['name']) ?> <span class="mute small">app <?= (int)$app['app_id'] ?> · <?= count($app['fields']) ?> fields</span></h2>
  <table>
    <tr><th>Leads fills</th><th>Podio field</th><th>Type</th><th>Options</th></tr>
    <?php foreach ($a['want'] as $label): $f = \Leads\Podio::field($app, $label); ?>
    <tr>
      <td><?= e($label) ?></td>
      <td><?= $f ? e($f['label']) . ($f['required'] ? ' <b>(required)</b>' : '') : '<span class="chip st-dead">not found</span>' ?></td>
      <td><?= e($f['type'] ?? '') ?></td>
      <td class="small mute"><?= e(implode(' · ', array_slice(array_column($f['options'] ?? [], 'text'), 0, 25))) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php $req = array_filter($app['fields'], static fn($f) => $f['required'] && !in_array(\Leads\Podio::norm($f['label']), array_map([\Leads\Podio::class, 'norm'], $a['want']), true)); ?>
  <?php if ($req): ?><p class="flash err">Required in Podio but not filled by Leads: <?= e(implode(', ', array_column($req, 'label'))) ?></p><?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
