<h1>Dashboard</h1>
<div class="kpis">
  <div class="kpi"><b><?= number_format($stats['leads']) ?></b><span>Leads scoring 50+</span></div>
  <div class="kpi"><b><?= number_format($stats['hot']) ?></b><span>Hot leads (70+)</span></div>
  <div class="kpi"><b><?= number_format($stats['traced']) ?></b><span>Skip traced (matched)</span></div>
  <div class="kpi"><b><?= number_format($stats['callable']) ?></b><span>With a callable phone (not DNC)</span></div>
  <div class="kpi"><b><?= number_format($stats['credits']) ?> / <?= number_format($stats['cap']) ?></b><span>DealMachine credits this month</span></div>
</div>
<div class="kpis">
  <div class="kpi"><b><?= number_format($stats['tracked']) ?></b><span>Parcels with distress signals</span></div>
  <div class="kpi"><b><?= number_format($stats['tax']) ?></b><span>On Treasurer foreclosure lists</span></div>
  <div class="kpi"><b><?= number_format($stats['blight']) ?></b><span>With open blight tickets</span></div>
  <div class="kpi"><b><?= e($newList ?: '—') ?></b><span>Latest Treasurer list published</span></div>
</div>

<div class="grid2">
  <div class="card">
    <h2 style="margin-top:0">Top leads</h2>
    <table>
      <tr><th>Score</th><th>Property</th><th class="hide-sm">Why</th></tr>
      <?php foreach ($top as $t): ?>
      <tr>
        <td><span class="score <?= score_class((int)$t['score']) ?>"><?= (int)$t['score'] ?></span></td>
        <td><a href="/lead?id=<?= urlencode($t['parcel_id']) ?>"><?= e($t['address'] ?: $t['parcel_id']) ?></a><div class="mute small"><?= e($t['municipality']) ?> · <?= e($t['owner1'] ?? '') ?></div></td>
        <td class="hide-sm small mute"><?= e(mb_strimwidth((string)$t['score_reasons'], 0, 120, '…')) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$top): ?><tr><td colspan="3" class="mute">No leads yet: the first data import is running (Scheduled Tasks).</td></tr><?php endif; ?>
    </table>
    <p><a class="btn" href="/leads">Open all leads</a></p>
  </div>
  <div>
    <div class="card">
      <h2 style="margin-top:0">Data sources</h2>
      <table>
        <tr><th>Job</th><th>Status</th><th>Last success</th></tr>
        <?php foreach ($jobs as $j): ?>
        <tr><td><?= e($j['job']) ?><div class="mute small"><?= e(mb_strimwidth((string)$j['message'], 0, 90, '…')) ?></div></td>
            <td class="status-<?= e($j['status']) ?>"><?= e($j['status']) ?></td><td class="nowrap small"><?= e(when($j['last_success_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?><tr><td colspan="3" class="mute">Waiting for the first scheduled run.</td></tr><?php endif; ?>
      </table>
    </div>
    <div class="card">
      <h2 style="margin-top:0">Pipeline</h2>
      <?php $byStatus = array_column($pipeline, 'n', 'status'); ?>
      <div class="actions"><?php foreach (\Leads\Pipeline::STATUSES as $k => $label): if ($k === 'new' || empty($byStatus[$k])) continue; ?>
        <a class="chip st-<?= e($k) ?>" href="/leads?view=all&amp;status=<?= e($k) ?>&amp;min=0&amp;sort=status"><?= e($label) ?>: <?= number_format((int)$byStatus[$k]) ?></a>
      <?php endforeach; ?><?php if (count(array_diff_key($byStatus, ['new' => 1])) === 0): ?><span class="mute small">No leads worked yet. Set a status or add a note on a lead.</span><?php endif; ?></div>
      <table style="margin-top:10px">
        <tr><th>Delivered to</th><th class="right">Total</th><th class="right">Last 30 days</th><th class="right">Closed</th><th class="right">Dead</th></tr>
        <?php foreach ($deliveries as $d): ?>
        <tr><td><a href="/leads?view=delivered&amp;client=<?= (int)$d['id'] ?>&amp;status=any&amp;sort=delivered"><?= e($d['name']) ?></a></td><td class="right"><?= number_format((int)$d['n']) ?></td><td class="right"><?= number_format((int)$d['n30']) ?></td><td class="right"><?= number_format((int)$d['closed']) ?></td><td class="right"><?= number_format((int)$d['dead']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$deliveries): ?><tr><td colspan="5" class="mute">No leads delivered yet.</td></tr><?php endif; ?>
      </table>
    </div>
    <div class="card">
      <h2 style="margin-top:0">Recent skip traces</h2>
      <table>
        <tr><th>When</th><th>Account</th><th>Result</th></tr>
        <?php foreach ($runs as $r): ?>
        <tr><td class="nowrap small"><?= e(when($r['created_at'])) ?></td><td><?= e($r['client'] ?? '—') ?> <span class="mute small"><?= e($r['trigger_type']) ?></span></td><td class="small"><?= e($r['message']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$runs): ?><tr><td colspan="3" class="mute">None yet.</td></tr><?php endif; ?>
      </table>
    </div>
  </div>
</div>
