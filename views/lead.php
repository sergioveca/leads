<?php $back = '/lead?id=' . urlencode($p['parcel_id']); ?>
<p><a href="/leads">← Leads</a></p>
<h1><span class="score <?= score_class((int)$p['score']) ?>"><?= (int)$p['score'] ?></span> <?= e($p['address'] ?: $p['parcel_id']) ?> <span class="mute small"><?= e($p['municipality']) ?> <?= e($p['zip'] ?? '') ?></span></h1>
<?php if ((int)$p['is_excluded'] === 1): ?><div class="flash err">Excluded from leads: <?= e($p['exclude_reason']) ?></div><?php endif; ?>

<div class="actions" style="margin-bottom:14px">
  <form method="post" action="/trace"><?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= e($p['parcel_id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <?php if ($p['traced_at']): ?><input type="hidden" name="force" value="1"><button class="secondary">Re-trace</button><?php else: ?><button>Skip trace now</button><?php endif; ?></form>
  <?php if ($p['dm_property_id']): ?>
  <form method="post" action="/push"><?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= e($p['parcel_id']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="secondary"><?= $p['pushed_to_dm_at'] ? 'Sent to DealMachine ✓ (send again)' : 'Send to DealMachine' ?></button></form>
  <?php endif; ?>
  <span class="mute small"><?= $p['traced_at'] ? 'Traced ' . e(when($p['traced_at'])) : 'Not traced yet' ?></span>
</div>

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
