<#
  Leads - one-time Plesk setup for leads.4-return.com  (customer-account version, XML API)
  Run on your Windows PC:

    powershell -ExecutionPolicy Bypass -File "$HOME\Downloads\plesk-setup.ps1"

  Uses the Plesk XML API (works with customer logins) to:
    1. Read your hosting plan for 4-return.com (PHP, permissions, SSH, cron, limits)
    2. Create subdomain leads.4-return.com (document root .../public)
    3. Create MySQL database "leads" + user "leads_app" (random password saved ONLY on your PC Desktop)
  SSL (Let's Encrypt) and Git are two clicks in Plesk afterwards - the summary tells you exactly where.
  Safe to re-run. Your Plesk password is used only in this window and never printed or saved.
#>

$ErrorActionPreference = 'Stop'
Write-Host "plesk-setup.ps1  version 6 (XML API, customer login)" -ForegroundColor Green
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Panel    = 'https://az1-wss4.my-hosting-panel.com:8443'
$Domain   = '4-return.com'
$SubName  = 'leads'
$Fqdn     = "$SubName.$Domain"
$DocRoot  = "/$Fqdn/public"
$DbName   = 'leads'
$DbUser   = 'leads_app'
$PinThumb = 'E2ACD216BFC30EDAD48D1AD851272E91A04DCACC'   # *.whgi.net (Sectigo), shown and approved in the previous run

$summary = [ordered]@{}
function Note($k, $v) { $script:summary[$k] = $v; Write-Host ("  {0}: {1}" -f $k, $v) }
function Step($t) { Write-Host ""; Write-Host "== $t" -ForegroundColor Cyan }
function Esc($s) { [Security.SecurityElement]::Escape([string]$s) }

# --- trust only the panel's known certificate (name mismatch: panel serves *.whgi.net)
if (-not ('LeadsCertPin' -as [type])) {
  Add-Type -TypeDefinition @"
using System.Net;
using System.Net.Security;
using System.Security.Cryptography.X509Certificates;
public static class LeadsCertPin {
  public static string Thumb;
  public static bool Check(object s, X509Certificate c, X509Chain ch, SslPolicyErrors e) {
    if (e == SslPolicyErrors.None) return true;
    return c != null && new X509Certificate2(c).Thumbprint == Thumb;
  }
  public static void Enable(string thumb) {
    Thumb = thumb;
    ServicePointManager.ServerCertificateValidationCallback = new RemoteCertificateValidationCallback(Check);
  }
}
"@
}
[LeadsCertPin]::Enable($PinThumb)

Write-Host "Plesk login for $Panel" -ForegroundColor Cyan
$PleskUser = Read-Host "Plesk username"
$sec = Read-Host "Plesk password (hidden)" -AsSecureString
$PleskPass = (New-Object System.Net.NetworkCredential('', $sec)).Password
$sec = $null

function Xml([string]$Inner) {
  $body = "<?xml version=`"1.0`" encoding=`"UTF-8`"?><packet>$Inner</packet>"
  $h = @{ HTTP_AUTH_LOGIN = $PleskUser; HTTP_AUTH_PASSWD = $PleskPass; HTTP_PRETTY_PRINT = 'TRUE' }
  try {
    $r = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$Panel/enterprise/control/agent.php" -Headers $h -ContentType 'text/xml; charset=UTF-8' -Body ([Text.Encoding]::UTF8.GetBytes($body))
  } catch {
    $m = $_.ErrorDetails.Message; if (-not $m) { $m = $_.Exception.Message }
    throw "XML API HTTP error: $m"
  }
  $x = [xml]$r.Content
  $sys = $x.SelectSingleNode('//system')
  if ($sys) { throw ("XML API error {0}: {1}" -f $sys.errcode, $sys.errtext) }
  return $x
}
function Check-Result($node, $what) {
  if (-not $node) { throw "$what : empty response" }
  if ($node.status -ne 'ok') { throw ("{0} failed: [{1}] {2}" -f $what, $node.errcode, $node.errtext) }
}
function Try-Step($name, [scriptblock]$block) {
  try { & $block } catch { Note "$name ERROR" ($_.Exception.Message -replace '\s+', ' ') }
}

# ------------------------------------------------------------- 1. hosting inventory
Step "1. Hosting inventory for $Domain"
$x = $null
try {
  $x = Xml ("<webspace><get><filter><name>{0}</name></filter><dataset><gen_info/><hosting/><limits/><permissions/></dataset></get></webspace>" -f (Esc $Domain))
} catch {
  Write-Host $_.Exception.Message -ForegroundColor Red
  Write-Host "Login to the Plesk XML API failed. Send the red line above to Claude." -ForegroundColor Yellow
  exit 1
}
$ws = $x.SelectSingleNode('//webspace/get/result')
$wsId = $null
if ($ws -and $ws.status -eq 'ok') {
  $wsId = $ws.id
  Note 'webspace_id' $wsId
  Note 'webspace_ip' ($ws.data.gen_info.dns_ip_address -join ', ')
  $props = @{}
  foreach ($p in $ws.SelectNodes('.//hosting//property')) { $props[$p.name] = $p.value }
  foreach ($k in 'php','php_handler_id','php_handler_type','php_version','shell','www_root','ssl','fastcgi') { if ($props.ContainsKey($k)) { Note "hosting.$k" $props[$k] } }
  $perm = @()
  foreach ($p in $ws.SelectNodes('.//permissions/permission')) { if ($p.name -match 'cron|shell|ssh|subdom|database|git|php|ssl|site') { $perm += "$($p.name)=$($p.value)" } }
  Note 'permissions' ($perm -join ', ')
  $lim = @()
  foreach ($p in $ws.SelectNodes('.//limits/limit')) { if ($p.name -match 'subdom|db|disk|max_site|mssql') { $lim += "$($p.name)=$($p.value)" } }
  Note 'limits' ($lim -join ', ')
} else {
  Note 'webspace' ("lookup failed: [{0}] {1}" -f $ws.errcode, $ws.errtext)
}

Try-Step 'php_versions' {
  $px = Xml "<php-handler><get><filter/></get></php-handler>"
  $ids = @(); foreach ($h in $px.SelectNodes('//php-handler/get/result')) { if ($h.status -eq 'ok' -and $h.enabled -ne 'false') { $ids += $h.id } }
  Note 'php_handlers_available' ($ids -join ', ')
  $script:phpIds = $ids
}

# ------------------------------------------------------------- 2. subdomain
Step "2. Subdomain $Fqdn"
Try-Step 'subdomain' {
  $sx = Xml ("<subdomain><get><filter><parent-name>{0}</parent-name></filter></get></subdomain>" -f (Esc $Domain))
  $found = $null
  foreach ($r in $sx.SelectNodes('//subdomain/get/result')) { if ($r.status -eq 'ok' -and $r.data.name -eq $SubName) { $found = $r } }
  if ($found) {
    Note 'subdomain' "already exists (id $($found.id))"
  } else {
    $ax = Xml ("<subdomain><add><parent>{0}</parent><name>{1}</name><property><name>www_root</name><value>{2}</value></property><property><name>ssl</name><value>true</value></property></add></subdomain>" -f (Esc $Domain), (Esc $SubName), (Esc $DocRoot))
    $res = $ax.SelectSingleNode('//subdomain/add/result'); Check-Result $res 'subdomain add'
    Note 'subdomain' "created (id $($res.id)), document root $DocRoot"
  }
}

# ------------------------------------------------------------- 3. PHP 8.x on the subdomain
Step "3. PHP"
Try-Step 'php' {
  $best = $phpIds | Where-Object { $_ -match 'php8\d*' -and $_ -match 'fpm' } | Sort-Object { [int](($_ -replace '\D', '')) } | Select-Object -Last 1
  if (-not $best) { $best = $phpIds | Where-Object { $_ -match 'php8' } | Sort-Object { [int](($_ -replace '\D', '')) } | Select-Object -Last 1 }
  if (-not $best) { throw 'no PHP 8.x handler listed for this account (set it in Plesk: leads.4-return.com > PHP)' }
  $sx = Xml ("<site><set><filter><name>{0}</name></filter><values><hosting><vrt_hst><property><name>php</name><value>true</value></property><property><name>php_handler_id</name><value>{1}</value></property></vrt_hst></hosting></values></set></site>" -f (Esc $Fqdn), (Esc $best))
  $res = $sx.SelectSingleNode('//site/set/result'); Check-Result $res 'set PHP'
  Note 'php_handler' $best
}

# ------------------------------------------------------------- 4. database
Step "4. Database"
$dbPassFile = Join-Path ([Environment]::GetFolderPath('Desktop')) 'leads-db-credentials.txt'
Try-Step 'database' {
  if (-not $wsId) { throw 'webspace id unknown' }
  $dx = Xml ("<database><get-db><filter><webspace-id>{0}</webspace-id></filter></get-db></database>" -f $wsId)
  $db = $null
  foreach ($r in $dx.SelectNodes('//database/get-db/result')) { if ($r.status -eq 'ok' -and $r.name -eq $DbName) { $db = $r } }
  if (-not $db) {
    $ax = Xml ("<database><add-db><webspace-id>{0}</webspace-id><name>{1}</name><type>mysql</type></add-db></database>" -f $wsId, (Esc $DbName))
    $db = $ax.SelectSingleNode('//database/add-db/result'); Check-Result $db 'add database'
    Note 'database' "created '$DbName' (id $($db.id))"
  } else {
    Note 'database' "already exists '$($db.name)' (id $($db.id))"
  }
  $dbId = $db.id
  $ux = Xml ("<database><get-db-users><filter><db-id>{0}</db-id></filter></get-db-users></database>" -f $dbId)
  $exists = $false
  foreach ($r in $ux.SelectNodes('//database/get-db-users/result')) { if ($r.login -eq $DbUser) { $exists = $true } }
  if ($exists) {
    Note 'db_user' "$DbUser already exists (password unchanged; see $dbPassFile)"
  } else {
    $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'
    $pw = -join (1..24 | ForEach-Object { $chars | Get-Random })
    $ax = Xml ("<database><add-db-user><db-id>{0}</db-id><login>{1}</login><password>{2}</password></add-db-user></database>" -f $dbId, (Esc $DbUser), (Esc $pw))
    $res = $ax.SelectSingleNode('//database/add-db-user/result'); Check-Result $res 'add database user'
    # database server host/port
    $dbHost = 'localhost'; $dbPort = '3306'
    try {
      $gx = Xml ("<database><get-db><filter><id>{0}</id></filter></get-db></database>" -f $dbId)
      $r = $gx.SelectSingleNode('//database/get-db/result')
      if ($r -and $r.'db-server-id') {
        $sv = Xml ("<db_server><get><filter><id>{0}</id></filter></get></db_server>" -f $r.'db-server-id')
        $s = $sv.SelectSingleNode('//db_server/get/result')
        if ($s -and $s.status -eq 'ok') { $dbHost = $s.host; $dbPort = $s.port }
      }
    } catch {}
    @"
Leads database credentials (KEEP PRIVATE - do not paste into chat)
DB_HOST=$dbHost
DB_PORT=$dbPort
DB_DATABASE=$DbName
DB_USERNAME=$DbUser
DB_PASSWORD=$pw
"@ | Set-Content -Path $dbPassFile -Encoding UTF8
    Note 'db_user' "created $DbUser; credentials saved to $dbPassFile"
    Note 'db_host_port' "${dbHost}:${dbPort}"
  }
  # Plesk may prefix names on shared hosting: report the real names
  $fx = Xml ("<database><get-db><filter><id>{0}</id></filter></get-db></database>" -f $dbId)
  $fr = $fx.SelectSingleNode('//database/get-db/result')
  if ($fr) { Note 'database_real_name' $fr.name }
}

# ------------------------------------------------------------- summary
Step "SUMMARY - send this to Claude (no passwords inside)"
$text = "-----BEGIN LEADS SETUP SUMMARY-----`n"
foreach ($k in $summary.Keys) { $text += "{0}: {1}`n" -f $k, $summary[$k] }
$text += "-----END LEADS SETUP SUMMARY-----"
Write-Host $text
try { Set-Clipboard -Value $text; Write-Host "(Summary copied to clipboard.)" -ForegroundColor Green } catch {}
$PleskPass = $null
