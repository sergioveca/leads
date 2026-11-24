<?php use Leads\Lists; ?>
<h1>Saved lists</h1>
<p class="mute" style="margin-top:-8px">Each list is a saved combination of motivators and filters. Open one to see the leads, change the filters, or export it for mail, calls or a client. <b>New</b> = leads that appeared since the list was last exported.</p>
<div class="card">
  <table>
    <tr><th>List</th><th class="right">Leads</th><th class="right">New</th><th class="hide-sm">Last export</th><th></th></tr>
    <?php foreach ($lists as $l): $f = Lists::filters($l); $q = Lists::query($f) . '&list=' . (int)$l['id']; ?>
    <tr>
      <td><a href="/leads?<?= e($q) ?>"><b><?= e($l['name']) ?></b></a>
        <?php if ($l['description'] && $l['description'] !== Lists::describe($f)): ?><div class="mute small"><?= e($l['description']) ?></div><?php endif; ?>
        <div class="small"><?= e(Lists::describe($f)) ?></div></td>
      <td class="right"><?= $l['last_count'] === null ? '<span class="mute">…</span>' : number_format((int)$l['last_count']) ?></td>
      <td class="right"><?= (int)$l['last_new'] > 0 ? '<span class="chip okc">+' . number_format((int)$l['last_new']) . '</span>' : '<span class="mute">0</span>' ?></td>
      <td class="small nowrap hide-sm"><?= e(when($l['last_exported_at'], 'M j, Y')) ?></td>
      <td class="nowrap">
        <a class="btn secondary" href="/leads?<?= e($q) ?>">Open</a>
        <a class="btn secondary" href="/export.csv?<?= e($q) ?>">Export CSV</a>
        <?php if ($isAdmin): ?><form method="post" action="/lists/delete" style="display:inline" onsubmit="return confirm('Delete this list? The leads are not affected.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="link small">Delete</button></form><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$lists): ?><tr><td colspan="5" class="mute">No saved lists yet. Build one on the Leads page and click "Save list".</td></tr><?php endif; ?>
  </table>
  <form method="post" action="/lists/recount" style="margin-top:10px"><?= csrf_field() ?><button class="secondary">Refresh counts</button> <span class="mute small">Counts refresh automatically every hour<?= $lists && $lists[0]['last_counted_at'] ? '; last ' . e(when($lists[0]['last_counted_at'])) : '' ?>.</span></form>
</div>
