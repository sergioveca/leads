<div class="login">
  <h1><span class="logo" style="background:#0f5c4c;color:#fff;border-radius:6px;padding:2px 6px;font-size:14px">4R</span> Leads</h1>
  <p class="mute">Motivated-seller leads from Wayne County public records.</p>
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">
    <button type="submit">Sign in</button>
  </form>
</div>
