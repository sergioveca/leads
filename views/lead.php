<?php $back = '/lead?id=' . urlencode($p['parcel_id']); ?>
<p><a href="/leads">← Leads</a></p>
<h1><span class="score <?= score_class((int)$p['score']) ?>"><?= (int)$p['score'] ?></span> <?= e($p['address'] ?: $p['parcel_id']) ?> <span class="mute small"><?= e($p['municipality']) ?> <?= e($p['zip'] ?? '') ?></span></h1>
<?php if ((int)$p['is_excluded'] === 1): ?><div class="flash err">Excluded from leads: <?= e($p['exclude_reason']) ?></div><?php endif; ?>
<?php if ($p['exclusive_client_id'] !== null): ?>
<div class="flash excl">Exclusive to <b><?= e($p['exclusive_client'] ?? 'a client') ?></b> · delivered <?= e(when($p['delivered_at'])) ?> via <?= e(\Leads\Pipeline::CHANNELS[$p['delivery_channel']] ?? $p['delivery_channel'] ?? '—') ?>.
  <?= $canWork ? '' : 'Read-only for you: no skip trace or DealMachine push. Record the client\'s outcome below.' ?></div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Pipeline <span class="chip st-<?= e($p['status']) ?>"><?= e(\Leads\Pipeline::label($p['status'])) ?></span><?php if ($p['status_at']): ?> <span class="mute small">since <?= e(when($p['status_at'])) ?></span><?php endif; ?></h2>
  <?php if ($canEdit): ?>
  <form method="post" action="/lead/update" class="filters">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['parcel_id']) ?>">
    <label>Status<select name="status"><?php foreach (\Leads\Pipeline::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label style="flex:1;min-width:240px">Note<textarea name="note" rows="2" placeholder="Call notes, motivation, asking price, what the client reported…"></textarea></label>
    <button>Save</button>
  </form>
  <?php endif; ?>
  <?php if ($isAdmin && $p['exclusive_client_id'] === null && $clients): ?>
  <form method="post" action="/deliver" class="filters" style="margin-top:10px" onsubmit="return confirm('Deliver this lead? It becomes exclusive to the client and leaves the 4Return pool.')">
    <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= e($p['parcel_id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <label>Deliver to<select name="client_id"><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Via<select name="channel"><?php foreach (\Leads\Pipeline::CHANNELS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label style="flex:1;min-width:200px">Delivery note<input type="text" name="note" placeholder="Optional, e.g. Podio item link"></label>
    <button class="secondary">Deliver (exclusive)</button>
  </form>
  <?php elseif ($isAdmin && $p['exclusive_client_id'] !== null): ?>
  <form method="post" action="/lead/release" class="filters" style="margin-top:10px" onsubmit="return confirm('Release exclusivity? The lead returns to the 4Return pool.')">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['parcel_id']) ?>">
    <label style="flex:1;min-width:200px">Reason<input type="text" name="note" placeholder="e.g. client passed on it"></label>
    <button class="link small">Release exclusivity</button>
  </form>
  <?php endif; ?>
  <?php if ($events): ?>
  <table class="timeline" style="margin-top:12px">
    <?php foreach ($events as $ev): ?>
    <tr>
      <td class="nowrap small mute"><?= e(when($ev['created_at'])) ?><div><?= e($ev['email'] ?? 'system') ?></div></td>
      <td class="small"><?php
        if ($ev['kind'] === 'status') { echo 'Status <b>' . e(\Leads\Pipeline::label($ev['old_status'])) . '</b> → <b>' . e(\Leads\Pipeline::label($ev['new_status'])) . '</b>'; }
        elseif ($ev['kind'] === 'delivered') { echo '<b>Delivered to ' . e($ev['client_name'] ?? '?') . '</b> (exclusive)'; }
        elseif ($ev['kind'] === 'released') { echo '<b>Exclusivity released</b> (was ' . e($ev['client_name'] ?? '?') . ')'; }
        else { echo '<b>Note</b>'; } ?>
        <?php if ($ev['body'] !== null && $ev['body'] !== ''): ?><div class="note"><?= nl2br(e($ev['body'])) ?></div><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><p class="mute small" style="margin-bottom:0">No notes yet.</p><?php endif; ?>
</div>

<?php if ($canWork): ?>
<div class="actions" style="margin-bottom:14px">
  <form method="post" action="/trace"><?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= e($p['parcel_id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?php if ($p['traced_at']): ?><input type="hidden" name="force" value="1"><button class="secondary">Re-trace</button><?php else: ?><button>Skip trace now</button><?php endif; ?></form>
  <?php if ($p['dm_property_id']): ?>
  <form method="post" action="/push"><?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= e($p['parcel_id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="secondary"><?= $p['pushed_to_dm_at'] ? 'Sent to DealMachine ✓ (send again)' : 'Send to DealMachine' ?></button></form>
  <?php endif; ?>
  <span class="mute small"><?= $p['traced_at'] ? 'Traced ' . e(when($p['traced_at'])) : 'Not traced yet' ?></span>
</div>
<?php endif; ?>

<div class="grid2">
  <div>
    <div class="card">
      <h2 style="margin-top:0">Why this lead</h2>
      <ul><?php foreach (array_filter(explode('; ', (string)$p['score_reasons'])) as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul>
    </div>
    <div class="card">
      <h2 style="margin-top:0">Public record signals</h2>
      <table>
        <tr><th>Signal</th><th>Details</th><th>Date</th></tr>
        <?php foreach ($signals as $s): ?>
        <tr class="<?= $s['active'] ? '' : 'mute' ?>">
          <td><span class="chip <?= $s['type'] === 'tax_forfeiture' ? 'tax' : 'blight' ?>"><?= $s['type'] === 'tax_forfeiture' ? 'Tax foreclosure list ' . e($s['source_ref']) : 'Blight tickets' ?></span><?= $s['active'] ? '' : ' (closed)' ?><div class="mute small"><?= e($s['source']) ?></div></td>
          <td class="small"><?php if ($s['type'] === 'blight'): ?><?= (int)$s['item_count'] ?> open ticket(s), <?= money($s['amount']) ?> unpaid<?php else: ?>Interested parties: <?= e(mb_strimwidth((string)$s['details'], 0, 300, '…')) ?><?php endif; ?></td>
          <td class="nowrap small"><?= e($s['event_date'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="card">
      <h2 style="margin-top:0">Property &amp; owner (public record)</h2>
      <dl class="kv">
        <dt>Parcel</dt><dd><?= e($p['parcel_id']) ?></dd>
        <dt>Owner</dt><dd><?= e($p['owner1'] ?? '—') ?><?= $p['owner2'] ? '<br>' . e($p['owner2']) : '' ?><?= $p['is_entity'] ? ' <span class="chip">Entity</span>' : '' ?></dd>
        <dt>Owner mailing</dt><dd><?= e(trim(($p['mail_address'] ?? '') . ', ' . ($p['mail_city'] ?? '') . ' ' . ($p['mail_state'] ?? '') . ' ' . ($p['mail_zip'] ?? ''), ', ') ?: '—') ?><?= $p['is_absentee'] ? ' <span class="chip">Absentee</span>' : '' ?></dd>
        <dt>Class</dt><dd><?= e($p['property_class'] ?? '—') ?></dd>
        <dt>Tax status</dt><dd><?= e($p['tax_status'] ?? '—') ?></dd>
        <dt>Assessed / taxable</dt><dd><?= money($p['assessed_value']) ?> / <?= money($p['taxable_value']) ?></dd>
        <dt>Last sale</dt><dd><?= e($p['sale_date'] ?? '—') ?> <?= $p['sale_price'] ? money($p['sale_price']) : '' ?></dd>
        <dt>Year built / sqft</dt><dd><?= e($p['year_built'] ?? $p['dm_year_built'] ?? '—') ?> / <?= e($p['building_sqft'] ?? $p['dm_sqft'] ?? '—') ?></dd>
      </dl>
    </div>
  </div>
  <div>
    <div class="card">
      <h2 style="margin-top:0">DealMachine property data</h2>
      <?php if ($p['dm_matched']): ?>
      <dl class="kv">
        <dt>Estimated value</dt><dd><?= money($p['dm_estimated_value']) ?></dd>
        <dt>Equity</dt><dd><?= money($p['dm_equity_amount']) ?> <?= $p['dm_equity_pct'] !== null ? '(' . (int)$p['dm_equity_pct'] . '%)' : '' ?></dd>
        <dt>Loan balance</dt><dd><?= money($p['dm_loan_balance']) ?></dd>
        <dt>Owner occupied</dt><dd><?= $p['dm_owner_occupied'] === null ? '—' : ((int)$p['dm_owner_occupied'] ? 'Yes' : 'No') ?></dd>
        <dt>Beds / baths</dt><dd><?= e($p['dm_beds'] ?? '—') ?> / <?= e($p['dm_baths'] ?? '—') ?></dd>
        <dt>Annual tax</dt><dd><?= money($p['dm_annual_tax']) ?></dd>
      </dl>
      <?php else: ?><p class="mute"><?= $p['traced_at'] ? 'DealMachine had no match for this parcel.' : 'Skip trace to load value, equity and contacts.' ?></p><?php endif; ?>
    </div>
    <div class="card">
      <h2 style="margin-top:0">Contacts</h2>
      <p class="mute small">Call 9 a.m.–9 p.m. Eastern only. No prerecorded/AI voice or ringless voicemail. Numbers marked DNC or opted out must not be called or texted.</p>
      <?php foreach ($contacts as $c): ?>
        <div style="border-top:1px solid var(--line);padding-top:10px;margin-top:10px">
          <b><?= e($c['full_name'] ?? 'Unknown') ?></b> <span class="chip"><?= e($c['contact_type'] ?? '') ?></span><?= $c['is_resident'] ? '<span class="chip">Lives there</span>' : '' ?>
          <div class="mute small"><?= e(trim(($c['res_address'] ?? '') . ', ' . ($c['res_city'] ?? '') . ' ' . ($c['res_state'] ?? '') . ' ' . ($c['res_zip'] ?? ''), ', ')) ?></div>
          <table style="margin-top:6px">
            <?php foreach ($c['phones'] as $ph): $blocked = $ph['is_dnc'] || $ph['opted_out']; ?>
            <tr>
              <td class="nowrap"><?= $blocked ? '<s>' . e(phone_fmt($ph['number'])) . '</s>' : '<a href="tel:+1' . e($ph['number']) . '">' . e(phone_fmt($ph['number'])) . '</a>' ?></td>
              <td><span class="chip"><?= e($ph['phone_type'] ?? '?') ?></span><?php if ($ph['opted_out']): ?><span class="chip dnc">Opted out</span><?php elseif ($ph['is_dnc']): ?><span class="chip dnc">DNC</span><?php else: ?><span class="chip okc">OK to call</span><?php endif; ?></td>
              <td class="small mute hide-sm"><?= e($ph['carrier'] ?? '') ?> · <?= $ph['confidence'] !== null ? (int)$ph['confidence'] . '% match' : '' ?> · use <?= e($ph['usage_12m'] ?? '?') ?></td>
              <td><?php if (!$ph['opted_out']): ?><form method="post" action="/optout" onsubmit="return confirm('Mark this number do-not-contact for everyone?')"><?= csrf_field() ?><input type="hidden" name="value" value="<?= e($ph['number']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="link small">Opt out</button></form><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($c['emails'] as $em): ?>
            <tr><td colspan="2"><?= $em['opted_out'] ? '<s>' . e($em['address']) . '</s>' : '<a href="mailto:' . e($em['address']) . '">' . e($em['address']) . '</a>' ?></td><td class="small mute hide-sm"><?= e($em['verification'] ?? '') ?></td>
              <td><?php if (!$em['opted_out']): ?><form method="post" action="/optout"><?= csrf_field() ?><input type="hidden" name="value" value="<?= e($em['address']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="link small">Opt out</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><p class="mute">No contacts yet.</p><?php endif; ?>
    </div>
  </div>
</div>
