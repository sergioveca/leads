<#
  Leads - one-time Plesk setup for leads.4-return.com
  Run on your Windows PC (Plesk API on port 8443 is reachable from there):

    powershell -ExecutionPolicy Bypass -File .\plesk-setup.ps1

  What it does (via the Plesk REST API):
    1. Server inventory (Plesk/OS version, PHP versions, DB servers, extensions)
    2. Creates subdomain leads.4-return.com (document root .../public)
    3. Sets the newest PHP 8.x handler
    4. Requests a Let's Encrypt certificate
    5. Creates MySQL database + user (random password saved ONLY on your PC)
    6. Connects the GitHub repo via the Plesk Git extension and prints its deploy key
  It is safe to re-run: existing items are detected and skipped.
  Your Plesk password is used only in this window and never printed or saved.
#>

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Panel      = 'https://az1-wss4.my-hosting-panel.com:8443'
$Domain     = '4-return.com'
$SubName    = 'leads'
$Fqdn       = "$SubName.$Domain"
$DocRoot    = "$Fqdn/public"
$DeployPath = "$Fqdn"
$DbName     = 'leads'
$DbUser     = 'leads_app'
$RepoUrl    = 'git@github.com:sergioveca/leads.git'
$RepoName   = 'leads'

$summary = [ordered]@{}
function Note($k, $v) { $script:summary[$k] = $v; Write-Host ("  {0}: {1}" -f $k, $v) }
function Step($t) { Write-Host ""; Write-Host "== $t" -ForegroundColor Cyan }

$cred = Get-Credential -Message "Plesk login for $Panel"
$pair = "{0}:{1}" -f $cred.UserName, $cred.GetNetworkCredential().Password
$auth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pair))
$H = @{ Authorization = $auth; Accept = 'application/json' }
$LeEmail = Read-Host "Email for the Let's Encrypt certificate (expiry notices)"

function Api($Method, $Path, $Body = $null) {
  $p = @{ Method = $Method; Uri = "$Panel/api/v2$Path"; Headers = $H; ContentType = 'application/json' }
  if ($Body -ne $null) { $p.Body = ($Body | ConvertTo-Json -Depth 8) }
  try { return Invoke-RestMethod @p }
  catch {
    $msg = $_.ErrorDetails.Message; if (-not $msg) { $msg = $_.Exception.Message }
    throw "API $Method $Path failed: $msg"
  }
}
function Cli($Cmd, [string[]]$Params) {
  $r = Api 'POST' "/cli/$Cmd/call" @{ params = $Params }
  return $r
}
function Try-Step($name, [scriptblock]$block) {
  try { & $block } catch { Note "$name ERROR" ($_.Exception.Message -replace '\s+', ' ') }
}

# ---------------------------------------------------------------- 1. inventory
Step "1. Server inventory"
try { $srv = Api 'GET' '/server' }
catch {
  Write-Host $_.Exception.Message -ForegroundColor Red
  Write-Host "Could not log in to the Plesk REST API. Check the user/password. The REST API needs the Plesk ADMIN (or reseller) account." -ForegroundColor Yellow
  exit 1
}
Note 'plesk_hostname' $srv.hostname
Note 'plesk_version' "$($srv.panel_version) / $($srv.platform)"
Try-Step 'cli_commands' { $c = Api 'GET' '/cli/commands'; Note 'cli_commands_count' (@($c).Count) }
Try-Step 'php_handlers' {
  $out = (Cli 'php_handler' @('--list')).stdout
  $script:phpLines = $out -split "`n" | Where-Object { $_ -match 'fpm|fastcgi' }
  Note 'php_handlers' (($phpLines | ForEach-Object { ($_ -split '\s+')[0] }) -join ', ')
}
Try-Step 'db_servers' { $script:dbs = Api 'GET' '/dbservers'; Note 'db_servers' (($dbs | ForEach-Object { "$($_.id):$($_.type)@$($_.host):$($_.port)" }) -join ', ') }
Try-Step 'extensions' {
  $out = (Cli 'extension' @('--list')).stdout
  Note 'extensions' (($out -split "`n" | Where-Object { $_ -match 'git|letsencrypt|composer|node|scheduler|ssh|php-composer|laravel' } | ForEach-Object { $_.Trim() }) -join ' | ')
}

# ---------------------------------------------------------------- 2. subdomain
Step "2. Subdomain $Fqdn"
$parent = Api 'GET' "/domains?name=$Domain"
if (-not $parent) { throw "Domain $Domain not found in this Plesk." }
$parent = @($parent)[0]
Note 'parent_domain_id' $parent.id
$existing = $null
try { $existing = @(Api 'GET' "/domains?name=$Fqdn")[0] } catch { $existing = $null }
if ($existing -and $existing.id) {
  Note 'subdomain' "already exists (id $($existing.id))"
} else {
  Try-Step 'subdomain_create' {
    $r = Cli 'subdomain' @('--create', $SubName, '-domain', $Domain, '-www-root', $DocRoot, '-ssl', 'true')
    Note 'subdomain' ("created; code {0} {1}" -f $r.code, ($r.stderr -replace '\s+', ' '))
  }
}
Try-Step 'docroot' { $r = Cli 'subdomain' @('--update', $SubName, '-domain', $Domain, '-www-root', $DocRoot); Note 'docroot' "$DocRoot (code $($r.code))" }

# ---------------------------------------------------------------- 3. PHP
Step "3. PHP"
Try-Step 'php_set' {
  $ids = $phpLines | ForEach-Object { ($_ -split '\s+')[0] } | Where-Object { $_ -match '^plesk-php8(\d)-fpm$' }
  if (-not $ids) { $ids = $phpLines | ForEach-Object { ($_ -split '\s+')[0] } | Where-Object { $_ -match 'php8' } }
  $best = $ids | Sort-Object { [int](($_ -replace '\D', '')) } | Select-Object -Last 1
  if (-not $best) { throw 'No PHP 8.x handler found' }
  $r = Cli 'site' @('--update', $Fqdn, '-php', 'true', '-php_handler_id', $best)
  Note 'php_handler' "$best (code $($r.code) $($r.stderr -replace '\s+',' '))"
}

# ---------------------------------------------------------------- 4. SSL
Step "4. Let's Encrypt SSL"
Try-Step 'dns' { $ip = (Resolve-DnsName $Fqdn -Type A -ErrorAction Stop | Where-Object { $_.IPAddress } | Select-Object -First 1).IPAddress; Note 'dns_A' $ip }
Try-Step 'ssl' {
  $r = Cli 'extension' @('--exec', 'letsencrypt', 'cli.php', '-d', $Fqdn, '-m', $LeEmail)
  Note 'ssl' ("code {0} {1} {2}" -f $r.code, ($r.stdout -replace '\s+', ' '), ($r.stderr -replace '\s+', ' '))
}

# ---------------------------------------------------------------- 5. database
Step "5. Database"
$dbPassFile = Join-Path ([Environment]::GetFolderPath('Desktop')) 'leads-db-credentials.txt'
Try-Step 'database' {
  $sub = @(Api 'GET' "/domains?name=$Fqdn")[0]
  $owner = if ($sub -and $sub.id) { $sub.id } else { $parent.id }
  $server = @($dbs | Where-Object { $_.type -eq 'mysql' })[0]
  if (-not $server) { throw 'No MySQL/MariaDB server available' }
  $all = @(Api 'GET' "/databases?domain=$Domain") + @(Api 'GET' "/databases?domain=$Fqdn")
  $db = $all | Where-Object { $_.name -eq $DbName } | Select-Object -First 1
  if (-not $db) {
    $db = Api 'POST' '/databases' @{ name = $DbName; type = 'mysql'; parent_domain = @{ id = $owner }; server_id = $server.id }
    Note 'database' "created $DbName (id $($db.id)) on $($server.host):$($server.port)"
  } else { Note 'database' "already exists (id $($db.id))" }
  $users = @(Api 'GET' "/dbusers?dbId=$($db.id)")
  if ($users | Where-Object { $_.login -eq $DbUser }) {
    Note 'db_user' "$DbUser already exists (password unchanged; see $dbPassFile if created earlier)"
  } else {
    $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'
    $pw = -join (1..24 | ForEach-Object { $chars | Get-Random })
    Api 'POST' '/dbusers' @{ login = $DbUser; password = $pw; database = @{ id = $db.id } } | Out-Null
    @"
Leads database credentials (KEEP PRIVATE - do not paste into chat)
DB_HOST=$($server.host)
DB_PORT=$($server.port)
DB_DATABASE=$DbName
DB_USERNAME=$DbUser
DB_PASSWORD=$pw
"@ | Set-Content -Path $dbPassFile -Encoding UTF8
    Note 'db_user' "created $DbUser; credentials saved to $dbPassFile"
  }
  Note 'db_host_port' "$($server.host):$($server.port)"
}

# ---------------------------------------------------------------- 6. Git
Step "6. Git repository"
Try-Step 'git_create' {
  $r = Cli 'extension' @('--exec', 'git', 'cli.php', '--create', '-domain', $Fqdn, '-name', $RepoName, '-remote-url', $RepoUrl, '-deployment-path', $DeployPath, '-deployment-mode', 'manual')
  Note 'git_create' ("code {0} {1} {2}" -f $r.code, ($r.stdout -replace '\s+', ' '), ($r.stderr -replace '\s+', ' '))
}
Try-Step 'git_key' {
  $r = Cli 'extension' @('--exec', 'git', 'cli.php', '--get-public-key', '-domain', $Fqdn, '-name', $RepoName)
  $key = ($r.stdout -split "`n" | Where-Object { $_ -match '^ssh-' } | Select-Object -First 1)
  if (-not $key) { $key = "code $($r.code) $($r.stdout -replace '\s+',' ') $($r.stderr -replace '\s+',' ')" }
  Note 'git_deploy_public_key' $key.Trim()
}
Try-Step 'scheduled_tasks' { $r = Cli 'cron' @('--help'); Note 'cron_cli' "available (code $($r.code))" }
Try-Step 'ssh_access' { $r = Cli 'subscription' @('--info', $Domain); Note 'shell' (($r.stdout -split "`n" | Where-Object { $_ -match 'shell|SSH' } | ForEach-Object { $_.Trim() }) -join '; ') }

# ---------------------------------------------------------------- summary
Step "SUMMARY - copy everything between the lines and paste it to Claude"
$text = "-----BEGIN LEADS SETUP SUMMARY-----`n"
foreach ($k in $summary.Keys) { $text += "{0}: {1}`n" -f $k, $summary[$k] }
$text += "-----END LEADS SETUP SUMMARY-----"
Write-Host $text
try { Set-Clipboard -Value $text; Write-Host "(Summary copied to clipboard. It contains no passwords.)" -ForegroundColor Green } catch {}
$cred = $null; $pair = $null; $auth = $null; $H = $null
