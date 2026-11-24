<h1>Leads <span class="mute small"><?= number_format($total) ?> match</span></h1>
<div class="card">
  <form class="filters" method="get" action="/leads">
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
    <span class="mute small">Up to 250 per click. Already-traced parcels (last 90 days) are skipped and cost nothing.</span>
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
          <?php if ($r['is_absentee']): ?><span class="chip">Absentee</span><?php endif; ?></td>
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
