<h1>Leads <span class="mute small"><?= number_format($total) ?> match</span></h1>
<div class="tabs">
  <a href="/leads" class="<?= $f['view'] === 'pool' ? 'on' : '' ?>">Working pool</a>
  <a href="/leads?view=delivered&amp;status=any&amp;sort=delivered" class="<?= $f['view'] === 'delivered' ? 'on' : '' ?>">Delivered to clients</a>
  <a href="/leads?view=all&amp;status=any" class="<?= $f['view'] === 'all' ? 'on' : '' ?>">All</a>
</div>
<div class="card">
  <form class="filters" method="get" action="/leads">
    <input type="hidden" name="view" value="<?= e($f['view']) ?>">
    <label>Status<select name="status">
      <option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open (not dead/closed)</option>
      <option value="any" <?= $f['status'] === 'any' ? 'selected' : '' ?>>Any</option>
      <?php foreach (\Leads\Pipeline::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <?php if ($f['view'] !== 'pool' && $clients): ?>
    <label>Client<select name="client"><option value="">All</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['client'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label>Search<input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Address, owner or parcel"></label>
    <label>Min score<input type="number" name="min" value="<?= e($f['min']) ?>" min="0" max="100" style="width:80px"></label>
    <label>Signal<select name="signal">
      <option value="">Any</option>
      <option value="tax_forfeiture" <?= $f['signal'] === 'tax_forfeiture' ? 'selected' : '' ?>>Tax foreclosure list</option>
      <option value="blight" <?= $f['signal'] === 'blight' ? 'selected' : '' ?>>Blight tickets</option>
      <option value="both" <?= $f['signal'] === 'both' ? 'selected' : '' ?>>Both (stacked)</option>
    </select></label>
    <label>City<select name="muni"><option value="">All</option>
      <?php foreach ($munis as $m): ?><option <?= $f['muni'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
    </select></label>
    <label>Owner<select name="owner">
      <option value="">Any</option>
      <option value="person" <?= $f['owner'] === 'person' ? 'selected' : '' ?>>Individuals only</option>
      <option value="entity" <?= $f['owner'] === 'entity' ? 'selected' : '' ?>>Companies / trusts</option>
    </select></label>
    <label>ZIP<input type="text" name="zip" value="<?= e($f['zip']) ?>" style="width:80px"></label>
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
    <label style="flex-direction:row;align-items:center;gap:6px"><input type="checkbox" name="absentee" value="1" <?= $f['absentee'] ? 'checked' : '' ?>> Absentee only</label>
    <button type="submit">Filter</button>
    <a class="btn secondary" href="/export.csv<?= e(qs(['page' => null])) ?>" title="Up to 5,000 rows, highest score first; DNC and opted-out numbers removed">Export CSV</a>
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
    <tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)"></th><th>Score</th><th>Property</th><th class="hide-sm">Owner</th><th>Signals</th><th class="hide-sm right">Equity</th><th>Contact</th></tr>
    <?php foreach ($rows as $r): $types = array_filter(explode(',', (string)$r['sig_types'])); ?>
    <tr>
      <td><input class="pick" type="checkbox" name="ids[]" value="<?= e($r['parcel_id']) ?>"></td>
      <td><span class="score <?= score_class((int)$r['score']) ?>"><?= (int)$r['score'] ?></span></td>
      <td><a href="/lead?id=<?= urlencode($r['parcel_id']) ?>"><?= e($r['address'] ?: $r['parcel_id']) ?></a><div class="mute small"><?= e($r['municipality']) ?> <?= e($r['zip'] ?? '') ?> · <?= e($r['parcel_id']) ?></div></td>
      <td class="hide-sm"><?= e($r['owner1'] ?? '—') ?><?php if ($r['is_absentee']): ?><div class="mute small">Mail: <?= e(trim(($r['mail_city'] ?? '') . ', ' . ($r['mail_state'] ?? ''), ', ')) ?></div><?php endif; ?></td>
      <td><?php foreach ($types as $t): ?><span class="chip <?= $t === 'tax_forfeiture' ? 'tax' : 'blight' ?>"><?= $t === 'tax_forfeiture' ? 'Tax' : 'Blight' ?></span><?php endforeach; ?>
          <?php if ($r['is_absentee']): ?><span class="chip">Absentee</span><?php endif; ?>
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
