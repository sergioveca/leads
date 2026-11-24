<?php
declare(strict_types=1);

use Leads\Auth;
use Leads\Db;
use Leads\Env;
use Leads\Jobs;
use Leads\LeadQuery;
use Leads\SkipTrace;
use Leads\View;

// PHP built-in dev server: let it serve real files (css, images) directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    return false;
}

$root = dirname(__DIR__);
require $root . '/src/helpers.php';

// Not configured yet: show a friendly setup page instead of a DB error.
if (!is_file($root . '/.env')) {
    define('LEADS_ROOT', $root);
    http_response_code(503);
    require $root . '/views/setup.php';
    exit;
}

require $root . '/bootstrap.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------- machine endpoints (token)
if ($path === '/health') {
    $token = (string)Env::get('HEALTH_TOKEN', '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json');
    $jobs = Db::all('SELECT job, status, last_started_at, last_success_at, rows_last_run, message, cursor_value FROM job_state ORDER BY job');
    echo json_encode([
        'app' => 'leads', 'version' => LEADS_VERSION, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'extensions' => array_values(array_intersect(['pdo_mysql', 'curl', 'sodium', 'mbstring', 'openssl', 'json'], get_loaded_extensions())),
        'limits' => ['memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
        'db' => Db::val('SELECT VERSION()'),
        'migrations' => array_column(Db::all('SELECT name FROM migrations ORDER BY name'), 'name'),
        'counts' => [
            'properties' => (int)Db::val('SELECT COUNT(*) FROM properties'),
            'leads_50' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE is_excluded = 0 AND score >= 50'),
            'synced' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE parcel_synced_at IS NOT NULL'),
            'traced' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE dm_matched = 1'),
            'phones' => (int)Db::val('SELECT COUNT(*) FROM phones'),
            'users' => (int)Db::val('SELECT COUNT(*) FROM users'),
            'credits_month' => SkipTrace::creditsUsedThisMonth(),
        ],
        'jobs' => $jobs,
        'storage_writable' => is_writable($root . '/storage'),
        'dealmachine_key' => Env::get('DEALMACHINE_API_KEY', '') !== '',
        'time' => date('c'),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($path === '/cron') {
    $token = (string)Env::get('CRON_TOKEN', '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain');
    ignore_user_abort(true);
    $budget = max(20, min(50, (int)ini_get('max_execution_time') - 10 ?: 50));
    foreach ((new Jobs($budget))->run() as $line) {
        echo $line, "\n";
    }
    exit;
}

// ---------------------------------------------------------------- web app
Auth::start();

if ($path === '/login') {
    if ($method === 'POST') {
        Auth::checkCsrf();
        $err = Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($err === null) {
            View::redirect('/');
        }
        View::render('login', ['error' => $err, 'email' => $_POST['email'] ?? ''], 'Sign in');
        exit;
    }
    View::render('login', ['error' => null, 'email' => ''], 'Sign in');
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    Auth::checkCsrf();
    Auth::logout();
    View::redirect('/login');
}

$user = Auth::require();
$clientId = $user['client_id'] ? (int)$user['client_id'] : null;
$isAdmin = $user['role'] === 'admin';

if ($method === 'POST') {
    Auth::checkCsrf();
}

switch (true) {
    case $path === '/':
        $stats = [
            'leads' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE is_excluded = 0 AND score >= 50'),
            'hot' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE is_excluded = 0 AND score >= 70'),
            'tracked' => (int)Db::val('SELECT COUNT(*) FROM properties'),
            'traced' => (int)Db::val('SELECT COUNT(*) FROM properties WHERE dm_matched = 1'),
            'callable' => (int)Db::val('SELECT COUNT(DISTINCT c.parcel_id) FROM contacts c JOIN phones ph ON ph.contact_id = c.id LEFT JOIN opt_outs o ON o.value = ph.number WHERE COALESCE(ph.is_dnc,0) = 0 AND o.id IS NULL'),
            'tax' => (int)Db::val("SELECT COUNT(DISTINCT parcel_id) FROM signals WHERE type = 'tax_forfeiture' AND active = 1"),
            'blight' => (int)Db::val("SELECT COUNT(DISTINCT parcel_id) FROM signals WHERE type = 'blight' AND active = 1"),
            'credits' => SkipTrace::creditsUsedThisMonth(),
            'cap' => SkipTrace::monthlyCap(null),
        ];
        $jobs = Db::all('SELECT * FROM job_state ORDER BY job');
        $top = Db::all('SELECT parcel_id, address, municipality, owner1, score, score_reasons, dm_matched FROM properties WHERE is_excluded = 0 ORDER BY score DESC LIMIT 10');
        $runs = Db::all('SELECT r.*, c.name AS client FROM skiptrace_runs r LEFT JOIN clients c ON c.id = r.client_id ORDER BY r.id DESC LIMIT 8');
        View::render('dashboard', compact('stats', 'jobs', 'top', 'runs') + ['newList' => Db::setting('treasurer_latest_published')], 'Dashboard');
        break;

    case $path === '/leads':
        $f = LeadQuery::filters($_GET);
        [$where, $params] = LeadQuery::where($f);
        $total = (int)Db::val("SELECT COUNT(*) FROM properties p WHERE $where", $params);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 50;
        $rows = Db::all(
            "SELECT p.*, (SELECT GROUP_CONCAT(DISTINCT s.type) FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1) AS sig_types,
                    (SELECT COUNT(*) FROM contacts c JOIN phones ph ON ph.contact_id = c.id WHERE c.parcel_id = p.parcel_id AND COALESCE(ph.is_dnc,0) = 0) AS ok_phones
             FROM properties p WHERE $where ORDER BY " . LeadQuery::order($f['sort']) . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params
        );
        $munis = array_column(Db::all('SELECT municipality, COUNT(*) n FROM properties WHERE is_excluded = 0 GROUP BY municipality ORDER BY n DESC LIMIT 40'), 'municipality');
        View::render('leads', compact('f', 'rows', 'total', 'page', 'per', 'munis'), 'Leads');
        break;

    case $path === '/lead':
        $id = (string)($_GET['id'] ?? '');
        $p = Db::one('SELECT * FROM properties WHERE parcel_id = ?', [$id]);
        if (!$p) {
            http_response_code(404);
            View::render('message', ['message' => 'Lead not found.'], 'Not found');
            break;
        }
        $signals = Db::all('SELECT * FROM signals WHERE parcel_id = ? ORDER BY active DESC, type', [$id]);
        $contacts = Db::all('SELECT * FROM contacts WHERE parcel_id = ? ORDER BY contact_type = \'owner\' DESC, id', [$id]);
        foreach ($contacts as &$c) {
            $c['phones'] = Db::all('SELECT ph.*, (o.id IS NOT NULL) AS opted_out FROM phones ph LEFT JOIN opt_outs o ON o.value = ph.number WHERE ph.contact_id = ? ORDER BY (ph.phone_type = \'wireless\') DESC, ph.confidence DESC', [$c['id']]);
            $c['emails'] = Db::all('SELECT em.*, (o.id IS NOT NULL) AS opted_out FROM emails em LEFT JOIN opt_outs o ON o.value = em.address WHERE em.contact_id = ? ORDER BY em.confidence DESC', [$c['id']]);
        }
        unset($c);
        Auth::audit('view_lead', $id);
        View::render('lead', compact('p', 'signals', 'contacts'), $p['address'] ?: $id);
        break;

    case $path === '/trace' && $method === 'POST':
        $ids = array_slice(array_map('strval', (array)($_POST['ids'] ?? [])), 0, 250);
        try {
            $r = SkipTrace::trace($ids, $clientId, (int)$user['id'], 'manual', !empty($_POST['force']));
            Auth::audit('skiptrace', $r['message'] . ' :: ' . implode(',', array_slice($ids, 0, 20)));
            View::flash('Skip trace: ' . $r['message']);
        } catch (\Throwable $e) {
            View::flash('Skip trace failed: ' . $e->getMessage(), 'err');
        }
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/push' && $method === 'POST':
        $ids = array_slice(array_map('strval', (array)($_POST['ids'] ?? [])), 0, 1000);
        try {
            $msg = SkipTrace::pushToDealMachine($ids, $clientId);
            Auth::audit('push_dealmachine', $msg);
            View::flash($msg);
        } catch (\Throwable $e) {
            View::flash('DealMachine push failed: ' . $e->getMessage(), 'err');
        }
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/optout' && $method === 'POST':
        $val = strtolower(trim((string)($_POST['value'] ?? '')));
        $kind = str_contains($val, '@') ? 'email' : 'phone';
        if ($kind === 'phone') {
            $val = substr(preg_replace('/\D/', '', $val) ?? '', -10);
        }
        if ($val !== '') {
            Db::run('INSERT IGNORE INTO opt_outs (value, kind, note, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$val, $kind, mb_substr((string)($_POST['note'] ?? ''), 0, 255), $user['id']]);
            Auth::audit('opt_out', "$kind $val");
            View::flash('Marked do-not-contact for everyone: ' . ($kind === 'phone' ? phone_fmt($val) : $val));
        }
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/export.csv':
        $f = LeadQuery::filters($_GET);
        [$where, $params] = LeadQuery::where($f);
        $rows = Db::all("SELECT p.* FROM properties p WHERE $where ORDER BY " . LeadQuery::order($f['sort']) . ' LIMIT 5000', $params);
        Auth::audit('export_csv', count($rows) . ' rows ' . json_encode($f));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['parcel_id', 'score', 'address', 'city', 'zip', 'owner', 'owner_mailing_address', 'owner_mailing_city', 'owner_mailing_state', 'owner_mailing_zip',
            'absentee', 'est_value', 'equity_pct', 'loan_balance', 'signals', 'score_reasons', 'contact_1', 'phone_1', 'phone_1_type', 'phone_2', 'phone_2_type', 'phone_3', 'phone_3_type', 'email_1', 'email_2'], ',', '"', '\\');
        foreach ($rows as $p) {
            $sig = Db::all('SELECT type, source_ref, item_count, amount FROM signals WHERE parcel_id = ? AND active = 1', [$p['parcel_id']]);
            $sigTxt = implode(' | ', array_map(static fn($s) => $s['type'] === 'blight' ? "blight {$s['item_count']} tickets \${$s['amount']}" : "tax forfeiture {$s['source_ref']}", $sig));
            $contact = Db::one("SELECT * FROM contacts WHERE parcel_id = ? ORDER BY contact_type = 'owner' DESC, id LIMIT 1", [$p['parcel_id']]);
            $phones = $emails = [];
            if ($contact) {
                $phones = Db::all('SELECT ph.number, ph.phone_type FROM phones ph LEFT JOIN opt_outs o ON o.value = ph.number WHERE ph.contact_id = ? AND COALESCE(ph.is_dnc,0) = 0 AND o.id IS NULL ORDER BY (ph.phone_type = \'wireless\') DESC, ph.confidence DESC LIMIT 3', [$contact['id']]);
                $emails = Db::all('SELECT em.address FROM emails em LEFT JOIN opt_outs o ON o.value = em.address WHERE em.contact_id = ? AND o.id IS NULL ORDER BY em.confidence DESC LIMIT 2', [$contact['id']]);
            }
            $row = [$p['parcel_id'], $p['score'], $p['address'], $p['municipality'], $p['zip'], $p['owner1'], $p['mail_address'], $p['mail_city'], $p['mail_state'], $p['mail_zip'],
                ($p['is_absentee'] || $p['dm_owner_occupied'] === 0) ? 'yes' : 'no', $p['dm_estimated_value'], $p['dm_equity_pct'], $p['dm_loan_balance'], $sigTxt, $p['score_reasons'],
                $contact['full_name'] ?? ''];
            for ($i = 0; $i < 3; $i++) {
                $row[] = isset($phones[$i]) ? phone_fmt($phones[$i]['number']) : '';
                $row[] = $phones[$i]['phone_type'] ?? '';
            }
            $row[] = $emails[0]['address'] ?? '';
            $row[] = $emails[1]['address'] ?? '';
            fputcsv($out, $row, ',', '"', '\\');
        }
        fclose($out);
        exit;

    case $path === '/admin':
        Auth::require(true);
        $users = Db::all('SELECT u.*, c.name AS client_name FROM users u LEFT JOIN clients c ON c.id = u.client_id ORDER BY u.id');
        $clients = Db::all('SELECT c.*, (c.dm_api_key_enc IS NOT NULL) AS has_key FROM clients c ORDER BY c.id');
        $settings = ['trace_min_score' => Db::setting('trace_min_score', '50'), 'monthly_credit_cap' => Db::setting('monthly_credit_cap', '15000'), 'auto_trace' => Db::setting('auto_trace', '1')];
        $jobs = Db::all('SELECT * FROM job_state ORDER BY job');
        $audit = Db::all('SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 30');
        $optouts = Db::all('SELECT * FROM opt_outs ORDER BY id DESC LIMIT 50');
        View::render('admin', compact('users', 'clients', 'settings', 'jobs', 'audit', 'optouts'), 'Admin');
        break;

    case $path === '/admin/user' && $method === 'POST':
        Auth::require(true);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
            View::flash('Enter a valid email and a password.', 'err');
            View::redirect('/admin');
        }
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'client';
        $cid = (int)($_POST['client_id'] ?? 0) ?: null;
        Db::run(
            'INSERT INTO users (email, name, password_hash, role, client_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = VALUES(role), client_id = VALUES(client_id), failed_logins = 0, locked_until = NULL',
            [$email, trim((string)($_POST['name'] ?? '')) ?: $email, password_hash($pass, PASSWORD_DEFAULT), $role, $cid]
        );
        Auth::audit('user_saved', "$email ($role)");
        View::flash("User saved: $email");
        View::redirect('/admin');

    case $path === '/admin/client' && $method === 'POST':
        Auth::require(true);
        $cid = (int)($_POST['client_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($cid === 0 && $name !== '') {
            Db::run('INSERT IGNORE INTO clients (name, created_at) VALUES (?, UTC_TIMESTAMP())', [$name]);
            $cid = (int)Db::val('SELECT id FROM clients WHERE name = ?', [$name]);
        }
        if ($cid) {
            $key = trim((string)($_POST['dm_key'] ?? ''));
            if ($key !== '') {
                Db::run('UPDATE clients SET dm_api_key_enc = ? WHERE id = ?', [\Leads\Crypto::encrypt($key), $cid]);
            }
            if (!empty($_POST['clear_key'])) {
                Db::run('UPDATE clients SET dm_api_key_enc = NULL WHERE id = ?', [$cid]);
            }
            $cap = trim((string)($_POST['cap'] ?? ''));
            Db::run('UPDATE clients SET monthly_credit_cap = ? WHERE id = ?', [$cap === '' ? null : max(0, (int)$cap), $cid]);
            Auth::audit('client_saved', "client $cid");
            View::flash('Client saved.');
        }
        View::redirect('/admin');

    case $path === '/admin/settings' && $method === 'POST':
        Auth::require(true);
        Db::setSetting('trace_min_score', (string)max(0, min(100, (int)($_POST['trace_min_score'] ?? 50))));
        Db::setSetting('monthly_credit_cap', (string)max(0, (int)($_POST['monthly_credit_cap'] ?? 15000)));
        Db::setSetting('auto_trace', !empty($_POST['auto_trace']) ? '1' : '0');
        Auth::audit('settings_saved', json_encode($_POST['trace_min_score'] ?? null));
        View::flash('Settings saved.');
        View::redirect('/admin');

    case $path === '/admin/run' && $method === 'POST':
        Auth::require(true);
        $budget = max(20, min(50, ((int)ini_get('max_execution_time') ?: 60) - 10));
        $log = (new Jobs($budget))->run();
        View::flash('Ran jobs: ' . implode(' · ', $log) ?: 'nothing to do');
        View::redirect('/admin');

    case $path === '/admin/optout-remove' && $method === 'POST':
        Auth::require(true);
        Db::run('DELETE FROM opt_outs WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        View::redirect('/admin');

    default:
        http_response_code(404);
        View::render('message', ['message' => 'Page not found.'], 'Not found');
}
