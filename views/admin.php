<h1>Admin</h1>
<div class="grid2">
  <div class="card">
    <h2 style="margin-top:0">Skip trace settings</h2>
    <form method="post" action="/admin/settings">
      <?= csrf_field() ?>
      <dl class="kv">
        <dt>Auto skip trace</dt><dd><label><input type="checkbox" name="auto_trace" value="1" <?= $settings['auto_trace'] === '1' ? 'checked' : '' ?>> Trace new leads automatically</label><div class="mute small">Off = only leads you select on the Leads page are traced.</div></dd>
        <dt>Minimum score</dt><dd><input type="number" name="trace_min_score" value="<?= e($settings['trace_min_score']) ?>" min="0" max="100"></dd>
        <dt>Monthly credit cap</dt><dd><input type="number" name="monthly_credit_cap" value="<?= e($settings['monthly_credit_cap']) ?>" min="0"> <span class="mute small">4Return key; ~2 credits per matched lead</span></dd>
      </dl>
      <p><button>Save settings</button></p>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Scheduled jobs</h2>
    <table>
      <tr><th>Job</th><th>Status</th><th>Last success</th></tr>
      <?php foreach ($jobs as $j): ?>
      <tr><td><?= e($j['job']) ?><div class="mute small"><?= e($j['message']) ?></div></td><td class="status-<?= e($j['status']) ?>"><?= e($j['status']) ?></td><td class="small nowrap"><?= e(when($j['last_success_at'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="/admin/run"><?= csrf_field() ?><p><button class="secondary">Run jobs now (≈50 s)</button></p></form>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0">Users</h2>
  <table>
    <tr><th>Email</th><th>Role</th><th>Client</th><th>Last login</th></tr>
    <?php foreach ($users as $x): ?><tr><td><?= e($x['email']) ?></td><td><?= e($x['role']) ?></td><td><?= e($x['client_name'] ?? '—') ?></td><td class="small"><?= e(when($x['last_login_at'])) ?></td></tr><?php endforeach; ?>
  </table>
  <h2>Add user / reset password</h2>
  <form method="post" action="/admin/user" class="filters">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required></label>
    <label>Name<input type="text" name="name"></label>
    <label>Password<input type="password" name="password" required autocomplete="new-password"></label>
    <label>Role<select name="role"><option value="client">Client</option><option value="admin">Admin</option></select></label>
    <label>Client<select name="client_id"><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <button>Save user</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Clients &amp; DealMachine keys</h2>
  <p class="mute small">A client with its own DealMachine API key is traced on its own credits. Without one, 4Return's key (from <code>.env</code>) is used. Keys are stored encrypted and never shown again.</p>
  <table>
    <tr><th>Client</th><th>Own key</th><th>Monthly cap</th><th>Update</th></tr>
    <?php foreach ($clients as $c): ?>
    <tr><td><?= e($c['name']) ?></td><td><?= $c['has_key'] ? '<span class="chip okc">Set</span>' : '<span class="chip">Uses 4Return</span>' ?></td><td><?= $c['monthly_credit_cap'] !== null ? number_format((int)$c['monthly_credit_cap']) : 'Default' ?></td>
      <td><form method="post" action="/admin/client" class="filters"><?= csrf_field() ?><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
        <input type="password" name="dm_key" placeholder="New DealMachine key" autocomplete="off">
        <input type="number" name="cap" placeholder="Cap" value="<?= e($c['monthly_credit_cap'] ?? '') ?>" style="width:100px">
        <label style="flex-direction:row;gap:4px"><input type="checkbox" name="clear_key" value="1"> Remove key</label>
        <button class="secondary">Save</button></form></td></tr>
    <?php endforeach; ?>
  </table>
  <form method="post" action="/admin/client" class="filters" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="client_id" value="0"><label>New client name<input type="text" name="name" required></label><button class="secondary">Add client</button></form>
</div>

<div class="grid2">
  <div class="card">
    <h2 style="margin-top:0">Do-not-contact list</h2>
    <form method="post" action="/optout" class="filters"><?= csrf_field() ?><input type="hidden" name="back" value="/admin"><label>Phone or email<input type="text" name="value" required></label><label>Note<input type="text" name="note"></label><button class="secondary">Add</button></form>
    <table style="margin-top:8px"><?php foreach ($optouts as $o): ?><tr><td><?= e($o['kind'] === 'phone' ? phone_fmt($o['value']) : $o['value']) ?></td><td class="small mute"><?= e($o['note'] ?? '') ?></td><td class="small"><?= e(when($o['created_at'], 'M j, Y')) ?></td>
      <td><form method="post" action="/admin/optout-remove"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="link small">Remove</button></form></td></tr><?php endforeach; ?></table>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Activity log</h2>
    <table><?php foreach ($audit as $a): ?><tr><td class="small nowrap"><?= e(when($a['created_at'])) ?></td><td class="small"><?= e($a['email'] ?? 'system') ?></td><td class="small"><?= e($a['action']) ?> <span class="mute"><?= e(mb_strimwidth((string)$a['details'], 0, 80, '…')) ?></span></td></tr><?php endforeach; ?></table>
  </div>
</div>
