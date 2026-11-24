<?php
use Leads\Lists;
use Leads\Motivators;

$mode = [];
foreach (['all', 'any', 'none'] as $k) {
    foreach ($f[$k] as $key) {
        $mode[$key] = $k;
    }
}
$qsNow = Lists::query($f);
$hidden = static function (string $query): string {
    parse_str($query, $arr);
    $out = '';
    foreach ($arr as $k => $v) {
        foreach ((array)$v as $one) {
            $out .= '<input type="hidden" name="' . e(is_array($v) ? $k . '[]' : $k) . '" value="' . e($one) . '">';
        }
    }
    return $out;
};
?>
<h1><?= $list ? e($list['name']) : 'Leads' ?> <span class="mute small"><?= number_format($total) ?> match</span></h1>
<?php if ($list): ?><p class="mute" style="margin-top:-8px"><?= e(Lists::describe($f)) ?> · <a href="/lists">All lists</a></p><?php endif; ?>
<div class="tabs">
  <a href="/leads" class="<?= $f['view'] === 'pool' ? 'on' : '' ?>">Working pool</a>
  <a href="/leads?view=delivered&amp;status=any&amp;sort=delivered" class="<?= $f['view'] === 'delivered' ? 'on' : '' ?>">Delivered to clients</a>
  <a href="/leads?view=all&amp;status=any" class="<?= $f['view'] === 'all' ? 'on' : '' ?>">All</a>
  <a href="/lists">Saved lists</a>
</div>
<div class="card">
  <form method="get" action="/leads">
    <input type="hidden" name="view" value="<?= e($f['view']) ?>">
    <?php if ($list): ?><input type="hidden" name="list" value="<?= (int)$list['id'] ?>"><?php endif; ?>
    <div class="motpick">
      <?php foreach (Motivators::GROUPS as $g => $gLabel): ?>
      <fieldset><legend><?= e($gLabel) ?></legend>
        <?php foreach (Motivators::all() as $key => $m): if ($m['group'] !== $g) continue; $cur = $mode[$key] ?? ''; ?>
        <label class="mrow <?= $cur ? 'on-' . e($cur) : '' ?>" title="<?= e($m['hint']) ?>">
          <select name="m[<?= e($key) ?>]" onchange="this.parentNode.className='mrow '+(this.value?'on-'+this.value:'')">
            <option value="">—</option>
            <option value="all" <?= $cur === 'all' ? 'selected' : '' ?>>Must</option>
            <option value="any" <?= $cur === 'any' ? 'selected' : '' ?>>Any of</option>
            <option value="none" <?= $cur === 'none' ? 'selected' : '' ?>>Exclude</option>
          </select>
          <span><?= e($m['label']) ?></span>
        </label>
        <?php endforeach; ?>
      </fieldset>
      <?php endforeach; ?>
    </div>
    <p class="mute small" style="margin:4px 0 10px"><b>Must</b> = has all of these · <b>Any of</b> = has at least one · <b>Exclude</b> = has none. Hover a motivator for what it means.</p>
    <div class="filters">
      <label>Stacked<select name="stack">
        <option value="">Any</option>
        <?php foreach ([2, 3, 4] as $n): ?><option value="<?= $n ?>" <?= $f['stack'] === $n ? 'selected' : '' ?>><?= $n ?>+ distress signals</option><?php endforeach; ?>
      </select></label>
      <label>Property<select name="ptype">
        <option value="">Any</option>
        <option value="house" <?= $f['ptype'] === 'house' ? 'selected' : '' ?>>Houses (residential, built)</option>
        <option value="land" <?= $f['ptype'] === 'land' ? 'selected' : '' ?>>Vacant land</option>
      </select></label>
      <label>Min score<input type="number" name="min" value="<?= e($f['min']) ?>" min="0" max="100" style="width:80px"></label>
      <label>Status<select name="status">
        <option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open (not dead/closed)</option>
        <option value="any" <?= $f['status'] === 'any' ? 'selected' : '' ?>>Any</option>
        <?php foreach (\Leads\Pipeline::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <?php if ($f['view'] !== 'pool' && $clients): ?>
      <label>Client<select name="client"><option value="">All</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['client'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label>City<select name="muni"><option value="">All</option>
        <?php foreach ($munis as $mu): ?><option <?= $f['muni'] === $mu ? 'selected' : '' ?>><?= e($mu) ?></option><?php endforeach; ?>
      </select></label>
      <label>ZIP<input type="text" name="zip" value="<?= e($f['zip']) ?>" style="width:80px"></label>
      <label>Search<input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Address, owner or parcel"></label>
      <label>Skip trace<select name="traced">
        <option value="">Any</option>
        <option value="phone" <?= $f['traced'] === 'phone' ? 'selected' : '' ?>>Has callable phone</option>
        <option value="yes" <?= $f['traced'] === 'yes' ? 'selected' : '' ?>>Traced (matched)</option>
        <option value="no" <?= $f['traced'] === 'no' ? 'selected' : '' ?>>Not traced yet</option>
      </select></label>
      <label>Sort<select name="sort">
        <option value="score">Score</option>
        <option value="equity" <?= $f['sort'] === 'equity' ? 'selected' : '' ?>>Equity %</option>
        <option value="recent" <?= $f['sort'] === 'recent' ? 'selected' : '' ?>>Newest</option>
        <option value="address" <?= $f['sort'] === 'address' ? 'selected' : '' ?>>Address</option>
        <option value="status" <?= $f['sort'] === 'status' ? 'selected' : '' ?>>Last status change</option>
        <option value="delivered" <?= $f['sort'] === 'delivered' ? 'selected' : '' ?>>Delivered (newest)</option>
      </select></label>
      <button type="submit">Filter</button>
      <a class="btn secondary" href="/leads<?= $f['view'] !== 'pool' ? '?view=' . e($f['view']) : '' ?>">Reset</a>
      <a class="btn secondary" href="/export.csv?<?= e($qsNow . ($list ? '&list=' . (int)$list['id'] : '')) ?>" title="Up to 5,000 rows, highest score first; DNC and opted-out numbers removed">Export CSV</a>
    </div>
  </form>
  <form method="post" action="/lists/save" class="filters" style="margin-top:10px;border-top:1px solid var(--line);padding-top:10px">
    <?= csrf_field() ?><?= $hidden($qsNow) ?>
    <?php if ($list): ?>
      <input type="hidden" name="list_id" value="<?= (int)$list['id'] ?>">
      <button name="update" value="1" class="secondary">Update “<?= e($list['name']) ?>” with these filters</button>
      <span class="mute small">or save a copy:</span>
    <?php endif; ?>
    <label>Save these filters as a list<input type="text" name="name" placeholder="e.g. Eastside tired landlords" style="min-width:240px"></label>
    <button class="secondary">Save list</button>
  </form>
</div>


<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/leads') ?>">
  <div class="actions" style="margin-bottom:10px">
    <button formaction="/trace">Skip trace selected</button>
    <button formaction="/push" class="secondary">Send selected to DealMachine</button>
    <span class="mute small">Up to 250 per click. Already-traced parcels (last 90 days) and leads exclusive to a client are skipped and cost nothing.</span>
  </div>
  <div class="actions" style="margin-bottom:10px">
    <select name="bulk_status" aria-label="New status"><?php foreach (\Leads\Pipeline::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <button formaction="/leads/status" class="secondary">Set status</button>
    <?php if ($isAdmin && $clients): ?>
    <span class="sep"></span>
    <select name="client_id" aria-label="Client"><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="channel" aria-label="Delivery channel"><?php foreach (\Leads\Pipeline::CHANNELS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <button formaction="/deliver" class="secondary" onclick="return confirm('Deliver the selected leads? They become exclusive to the client and leave the 4Return pool.')">Deliver selected (exclusive)</button>
    <?php endif; ?>
  </div>
  <table>
    <tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)"></th><th>Score</th><th>Property</th><th class="hide-sm">Owner</th><th>Motivators</th><th class="hide-sm right">Equity</th><th>Contact</th></tr>
    <?php $allM = Motivators::all(); foreach ($rows as $r): ?>
    <tr>
      <td><input class="pick" type="checkbox" name="ids[]" value="<?= e($r['parcel_id']) ?>"></td>
      <td><span class="score <?= score_class((int)$r['score']) ?>"><?= (int)$r['score'] ?></span></td>
      <td><a href="/lead?id=<?= urlencode($r['parcel_id']) ?>"><?= e($r['address'] ?: $r['parcel_id']) ?></a><div class="mute small"><?= e($r['municipality']) ?> <?= e($r['zip'] ?? '') ?> · <?= e($r['parcel_id']) ?></div></td>
      <td class="hide-sm"><?= e($r['owner1'] ?? '—') ?><?php if ($r['is_absentee']): ?><div class="mute small">Mail: <?= e(trim(($r['mail_city'] ?? '') . ', ' . ($r['mail_state'] ?? ''), ', ')) ?></div><?php endif; ?></td>
      <td><?php foreach ($r['motivators'] as $k): if ($k === 'individual' || ($k === 'tax' && in_array('tax_pending', $r['motivators'], true))) continue; ?><span class="chip m-<?= e($allM[$k]['group']) ?>" title="<?= e($allM[$k]['hint']) ?>"><?= e($allM[$k]['label']) ?></span><?php endforeach; ?>
          <?php if ($r['status'] !== 'new'): ?><span class="chip st-<?= e($r['status']) ?>"><?= e(\Leads\Pipeline::label($r['status'])) ?></span><?php endif; ?>
          <?php if ($r['exclusive_client']): ?><span class="chip excl" title="Delivered <?= e(when($r['delivered_at'])) ?>">Exclusive: <?= e($r['exclusive_client']) ?></span><?php endif; ?></td>
      <td class="hide-sm right"><?= $r['dm_equity_pct'] !== null ? (int)$r['dm_equity_pct'] . '%' : '<span class="mute">—</span>' ?></td>
      <td><?php if ((int)$r['ok_phones'] > 0): ?><span class="chip okc"><?= (int)$r['ok_phones'] ?> phone<?= (int)$r['ok_phones'] > 1 ? 's' : '' ?></span>
          <?php elseif ($r['traced_at']): ?><span class="chip"><?= $r['dm_matched'] ? 'No phone' : 'No match' ?></span>
          <?php else: ?><span class="mute small">Not traced</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="mute">No leads match these filters.</td></tr><?php endif; ?>
  </table>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn secondary" href="<?= e(qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
    <span class="mute small">Page <?= $page ?> of <?= max(1, (int)ceil($total / $per)) ?></span>
    <?php if ($page * $per < $total): ?><a class="btn secondary" href="<?= e(qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </div>
</form>
