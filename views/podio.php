<?php
/** @var array $draft @var array $choices @var ?array $pushed @var array $messages @var ?string $error @var ?array $done @var bool $configured */
$long = ['motivation' => 4, 'notes' => 6, 'reply' => 4];
?>
<h1>New Podio Seller <span class="mute small">Home Detroit · Seller Leads</span></h1>

<?php if ($done): ?>
<div class="flash">
  Created in Podio: <a href="<?= e($done['url']) ?>" target="_blank" rel="noopener"><b>open the Seller Lead</b></a>
  <?= $done['reused_contact'] ? '· linked to the existing Contact (same phone)' : ($done['contact_url'] ? '· new <a href="' . e($done['contact_url']) . '" target="_blank" rel="noopener">Contact</a>' : '') ?>
  <?php if ($draft['parcel_id'] !== ''): ?>· lead set to Contacted in <a href="/lead?id=<?= e(urlencode($draft['parcel_id'])) ?>">Leads</a><?php endif; ?>.
</div>
<?php if ($done['warnings']): ?>
<div class="flash err"><b>Not filled in Podio</b> (fill these by hand if needed):<ul><?php foreach ($done['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<p><a href="/leads">← Leads</a></p>
<?php return; endif; ?>

<?php if (!$configured): ?>
<div class="flash err">Podio is not connected yet: add PODIO_CLIENT_ID, PODIO_CLIENT_SECRET, PODIO_USERNAME and PODIO_PASSWORD to the server .env. You can still review the draft.</div>
<?php endif; ?>
<?php if ($error): ?><div class="flash err">Podio said: <?= e($error) ?></div><?php endif; ?>
<?php if ($pushed): ?>
<div class="flash excl">Already sent to Podio on <?= e(date('M j, Y g:i a', (int)strtotime($pushed['created_at'] . ' UTC'))) ?>:
  <a href="<?= e($pushed['seller_url']) ?>" target="_blank" rel="noopener">open the Seller Lead</a>. Creating again adds a second Seller Lead for the same Contact.</div>
<?php endif; ?>

<?php if (strlen($draft['phone']) !== 10): ?>
<div class="card"><p>Open this page from the Slack button, or from a lead's page: it needs the seller's phone.</p></div>
<?php return; endif; ?>

<?php if (!$choices): ?>
<div class="flash err">No traced lead in Leads has <?= e(phone_fmt($draft['phone'])) ?>. The seller can still be created, but without property data.</div>
<?php elseif (count($choices) > 1): ?>
<form method="get" action="/podio/new" class="filters card">
  <input type="hidden" name="phone" value="<?= e($draft['phone']) ?>">
  <label style="flex:1">This owner has <?= count($choices) ?> properties in Leads. Which one did they reply about?
    <select name="pid" onchange="this.form.submit()"><?php foreach ($choices as $c): ?>
      <option value="<?= e($c['parcel_id']) ?>" <?= $c['parcel_id'] === $draft['parcel_id'] ? 'selected' : '' ?>><?= e(\Leads\Exports::fullAddress($c)) ?> · score <?= (int)$c['score'] ?></option>
    <?php endforeach; ?></select></label>
</form>
<?php endif; ?>

<form method="post" action="/podio/create" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Creating in Podio…'">
  <?= csrf_field() ?>
  <input type="hidden" name="phone" value="<?= e($draft['phone']) ?>">
  <input type="hidden" name="parcel_id" value="<?= e($draft['parcel_id']) ?>">
  <div class="grid2">
    <div class="card">
      <h2 style="margin-top:0">Seller <span class="mute small"><?= e(phone_fmt($draft['phone'])) ?> (mobile)</span>
        <?php if ($draft['parcel_id'] !== ''): ?><a class="small" href="/lead?id=<?= e(urlencode($draft['parcel_id'])) ?>" target="_blank">lead in Leads</a><?php endif; ?></h2>
      <?php foreach (\Leads\PodioSeller::FORM as $k => $label): if (isset($long[$k])) continue; ?>
      <label style="display:block;margin-bottom:8px"><span class="small mute"><?= e($label) ?></span>
        <input type="text" name="<?= e($k) ?>" value="<?= e($draft[$k] ?? '') ?>" style="width:100%"<?= $k === 'name' ? ' required' : '' ?>></label>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <?php foreach ($long as $k => $rows): ?>
      <label style="display:block;margin-bottom:8px"><span class="small mute"><?= e(\Leads\PodioSeller::FORM[$k]) ?></span>
        <textarea name="<?= e($k) ?>" rows="<?= $rows ?>" style="width:100%"><?= e($draft[$k] ?? '') ?></textarea></label>
      <?php endforeach; ?>
      <?php if ($messages): ?>
      <h3>SMS conversation</h3>
      <table class="timeline small">
        <?php foreach ($messages as $m): ?>
        <tr><td class="nowrap mute"><?= e(date('M j g:i a', (int)strtotime($m['created_at'] . ' UTC'))) ?></td>
            <td><b><?= ($m['direction'] ?? '') === 'inbound' ? 'Seller' : 'Us' ?>:</b> <?= e($m['body'] ?? '') ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <p>Creates the Contact (or links the existing one with this phone) and the Seller Lead: Lead Manager <?= e(\Leads\Env::get('PODIO_LEAD_MANAGER', 'Tom ACQ')) ?>,
    source <?= e(\Leads\Env::get('PODIO_LEAD_SOURCE', '4Return - County Leads APP')) ?>, status <?= e(\Leads\Env::get('PODIO_LEAD_STATUS', '#New Untouched#')) ?>. The comment is posted under your name.</p>
  <button<?= $configured ? '' : ' disabled' ?>>Create in Podio</button>
</form>
