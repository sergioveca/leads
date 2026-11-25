<?php
declare(strict_types=1);

use Leads\Auth;
use Leads\Db;
use Leads\Env;
use Leads\Jobs;
use Leads\LeadQuery;
use Leads\Lists;
use Leads\Motivators;
use Leads\Pipeline;
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
        $top = Db::all('SELECT parcel_id, address, municipality, owner1, score, score_reasons, dm_matched FROM properties WHERE is_excluded = 0 AND exclusive_client_id IS NULL AND status NOT IN (\'dead\', \'closed\') ORDER BY score DESC LIMIT 10');
        $runs = Db::all('SELECT r.*, c.name AS client FROM skiptrace_runs r LEFT JOIN clients c ON c.id = r.client_id ORDER BY r.id DESC LIMIT 8');
        // pipeline: status counts for leads being worked (anything moved past "new") and deliveries per client
        $pipeline = Db::all("SELECT status, COUNT(*) n FROM properties WHERE status <> 'new' OR exclusive_client_id IS NOT NULL GROUP BY status");
        $deliveries = Db::all(
            "SELECT c.id, c.name, COUNT(*) n, SUM(p.delivered_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) n30,
                    SUM(p.status = 'closed') closed, SUM(p.status = 'dead') dead
             FROM properties p JOIN clients c ON c.id = p.exclusive_client_id GROUP BY c.id, c.name ORDER BY n DESC"
        );
        View::render('dashboard', compact('stats', 'jobs', 'top', 'runs', 'pipeline', 'deliveries') + ['newList' => Db::setting('treasurer_latest_published')], 'Dashboard');
        break;

    case $path === '/leads':
        $f = LeadQuery::filters($_GET);
        [$where, $params] = LeadQuery::where($f, $user);
        $total = (int)Db::val("SELECT COUNT(*) FROM properties p WHERE $where", $params);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = (int)($_GET['per'] ?? 50);
        $per = in_array($per, [50, 100, 250], true) ? $per : 50;
        $rows = Db::all(
            "SELECT p.*, xc.name AS exclusive_client, (SELECT GROUP_CONCAT(DISTINCT s.type) FROM signals s WHERE s.parcel_id = p.parcel_id AND s.active = 1) AS sig_types,
                    (SELECT COUNT(*) FROM contacts c JOIN phones ph ON ph.contact_id = c.id WHERE c.parcel_id = p.parcel_id AND COALESCE(ph.is_dnc,0) = 0) AS ok_phones
             FROM properties p LEFT JOIN clients xc ON xc.id = p.exclusive_client_id WHERE $where ORDER BY " . LeadQuery::order($f['sort']) . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params
        );
        // motivator chips per row (one signals query for the page)
        $sigs = [];
        if ($rows) {
            $ids = array_column($rows, 'parcel_id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all("SELECT parcel_id, type, source_ref, event_date, amount FROM signals WHERE active = 1 AND parcel_id IN ($in)", $ids) as $s) {
                $sigs[$s['parcel_id']][] = $s;
            }
        }
        foreach ($rows as &$r) {
            $r['motivators'] = Motivators::evaluate($r, $sigs[$r['parcel_id']] ?? []);
        }
        unset($r);
        $munis = array_column(Db::all('SELECT municipality, COUNT(*) n FROM properties WHERE is_excluded = 0 GROUP BY municipality ORDER BY n DESC LIMIT 80'), 'municipality');
        $clients = $isAdmin ? Db::all('SELECT id, name FROM clients WHERE id <> ? ORDER BY name', [$clientId ?? 0]) : [];
        $list = !empty($_GET['list']) ? Db::one('SELECT * FROM saved_lists WHERE id = ?', [(int)$_GET['list']]) : null;
        View::render('leads', compact('f', 'rows', 'total', 'page', 'per', 'munis', 'clients', 'isAdmin', 'list'), 'Leads');
        break;

    case $path === '/lists':
        $lists = Lists::all();
        View::render('lists', compact('lists', 'isAdmin'), 'Lists');
        break;

    case $path === '/lists/save' && $method === 'POST':
        $f = LeadQuery::filters($_POST);
        $name = trim((string)($_POST['name'] ?? ''));
        $id = (int)($_POST['list_id'] ?? 0);
        if ($id && !empty($_POST['update'])) {
            Db::run('UPDATE saved_lists SET query = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [Lists::query($f), $id]);
            Auth::audit('list_updated', "list $id: " . Lists::query($f));
        } elseif ($name !== '') {
            Db::run(
                'INSERT INTO saved_lists (name, description, query, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [mb_substr($name, 0, 120), mb_substr(Lists::describe($f), 0, 500), Lists::query($f), $user['id']]
            );
            $id = (int)Db::pdo()->lastInsertId();
            Auth::audit('list_saved', "$name: " . Lists::query($f));
        } else {
            View::flash('Give the list a name.', 'err');
            View::redirect('/leads?' . Lists::query($f));
        }
        Lists::recount($id);
        View::flash('List saved.');
        View::redirect('/leads?' . Lists::query($f) . '&list=' . $id);

    case $path === '/lists/delete' && $method === 'POST':
        Auth::require(true);
        Db::run('DELETE FROM saved_lists WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        Auth::audit('list_deleted', (string)(int)($_POST['id'] ?? 0));
        View::flash('List deleted.');
        View::redirect('/lists');

    case $path === '/lists/recount' && $method === 'POST':
        Lists::recount();
        View::flash('Counts refreshed.');
        View::redirect('/lists');

    case $path === '/lead':
        $id = (string)($_GET['id'] ?? '');
        $p = Db::one('SELECT p.*, xc.name AS exclusive_client FROM properties p LEFT JOIN clients xc ON xc.id = p.exclusive_client_id WHERE p.parcel_id = ?', [$id]);
        if (!$p || (!$isAdmin && !Pipeline::canWork($p, $user))) {
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
        $events = Pipeline::events($id);
        $motivators = Motivators::evaluate($p, array_filter($signals, static fn($s) => (int)$s['active'] === 1));
        $canWork = Pipeline::canWork($p, $user);
        $canEdit = Pipeline::canEdit($p, $user);
        $clients = $isAdmin ? Db::all('SELECT id, name FROM clients WHERE id <> ? ORDER BY name', [$clientId ?? 0]) : [];
        View::render('lead', compact('p', 'signals', 'contacts', 'events', 'canWork', 'canEdit', 'clients', 'isAdmin', 'motivators'), $p['address'] ?: $id);
        break;

    case $path === '/lead/update' && $method === 'POST':
        $id = (string)($_POST['id'] ?? '');
        $p = Db::one('SELECT parcel_id, exclusive_client_id FROM properties WHERE parcel_id = ?', [$id]);
        if (!$p || !Pipeline::canEdit($p, $user)) {
            View::flash('You cannot change this lead.', 'err');
        } else {
            try {
                View::flash(Pipeline::update($id, isset($_POST['status']) ? (string)$_POST['status'] : null, (string)($_POST['note'] ?? ''), $user));
            } catch (\InvalidArgumentException $e) {
                View::flash($e->getMessage(), 'err');
            }
        }
        View::redirect('/lead?id=' . urlencode($id));

    case $path === '/leads/status' && $method === 'POST':
        $ids = LeadQuery::bulkIds($_POST, $user, 5000);
        if (!$isAdmin) {
            $ids = Pipeline::workable($ids, $user);
        }
        $n = Pipeline::bulkStatus($ids, (string)($_POST['bulk_status'] ?? ''), $user);
        View::flash($n . ' lead(s) set to ' . Pipeline::label((string)($_POST['bulk_status'] ?? '')) . '.');
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/deliver' && $method === 'POST':
        Auth::require(true);
        $ids = LeadQuery::bulkIds($_POST, $user, 5000);
        try {
            [$ok, $skipped] = Pipeline::deliver($ids, (int)($_POST['client_id'] ?? 0), (string)($_POST['channel'] ?? ''), (string)($_POST['note'] ?? ''), $user);
            $name = (string)Db::val('SELECT name FROM clients WHERE id = ?', [(int)($_POST['client_id'] ?? 0)]);
            View::flash("$ok lead(s) delivered to $name (now exclusive)" . ($skipped ? "; $skipped skipped: already exclusive to another client" : '') . '.', $ok ? 'ok' : 'err');
        } catch (\InvalidArgumentException $e) {
            View::flash($e->getMessage(), 'err');
        }
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/lead/release' && $method === 'POST':
        Auth::require(true);
        $id = (string)($_POST['id'] ?? '');
        Pipeline::release($id, (string)($_POST['note'] ?? ''), $user);
        View::flash('Exclusivity released; the lead is back in the 4Return pool.');
        View::redirect('/lead?id=' . urlencode($id));

    case $path === '/trace' && $method === 'POST':
        $ids = LeadQuery::bulkIds($_POST, $user, 250, 'p.traced_at IS NULL OR p.traced_at < UTC_TIMESTAMP() - INTERVAL 90 DAY');
        $ids = Pipeline::workable($ids, $user); // never trace a lead delivered exclusively to another client
        if (!$ids) {
            View::flash('Nothing to trace: the selected leads were traced in the last 90 days or are exclusive to a client.', 'err');
            View::redirect((string)($_POST['back'] ?? '/leads'));
        }
        try {
            $r = SkipTrace::trace($ids, $clientId, (int)$user['id'], 'manual', !empty($_POST['force']));
            Auth::audit('skiptrace', $r['message'] . ' :: ' . implode(',', array_slice($ids, 0, 20)));
            View::flash('Skip trace: ' . $r['message']);
        } catch (\Throwable $e) {
            View::flash('Skip trace failed: ' . $e->getMessage(), 'err');
        }
        View::redirect((string)($_POST['back'] ?? '/leads'));

    case $path === '/trace.json' && $method === 'POST':
        // "Skip trace all matching": the Leads page calls this repeatedly; each call traces the next 250
        // untraced matching leads. dry=1 only returns the estimate shown in the confirmation.
        header('Content-Type: application/json');
        $untraced = 'p.traced_at IS NULL OR p.traced_at < UTC_TIMESTAMP() - INTERVAL 90 DAY';
        $scope = ['scope' => 'all', 'query' => (string)($_POST['query'] ?? '')];
        [, $creditClient] = (static function () use ($clientId) {
            try {
                return SkipTrace::keyFor($clientId);
            } catch (\Throwable) {
                return [null, null];
            }
        })();
        $left = max(0, SkipTrace::monthlyCap($creditClient) - SkipTrace::creditsUsedThisMonth($creditClient));
        $remaining = count(Pipeline::workable(LeadQuery::bulkIds($scope, $user, 20000, $untraced), $user));
        if (!empty($_POST['dry'])) {
            echo json_encode(['remaining' => $remaining, 'credits_left' => $left, 'max_credits' => $remaining * 2]);
            exit;
        }
        $ids = Pipeline::workable(LeadQuery::bulkIds($scope, $user, 250, $untraced), $user);
        try {
            $r = $ids ? SkipTrace::trace($ids, $clientId, (int)$user['id'], 'manual') : ['submitted' => 0, 'matched' => 0, 'credits' => 0, 'message' => 'Nothing left to trace'];
            Auth::audit('skiptrace', 'trace all: ' . $r['message']);
            echo json_encode($r + ['remaining' => max(0, $remaining - $r['submitted']), 'credits_left' => max(0, $left - $r['credits'])]);
        } catch (\Throwable $e) {
            http_response_code(502);
            echo json_encode(['error' => $e->getMessage(), 'remaining' => $remaining]);
        }
        exit;

    case $path === '/push' && $method === 'POST':
        $ids = Pipeline::workable(LeadQuery::bulkIds($_POST, $user, 1000, 'p.dm_property_id IS NOT NULL'), $user);
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

    case $path === '/export-sms.csv':
        $f = LeadQuery::filters($_GET);
        $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'leads.4-return.com');
        $list = !empty($_GET['list']) ? Db::one('SELECT id, name FROM saved_lists WHERE id = ?', [(int)$_GET['list']]) : null;
        $slug = $list ? preg_replace('/[^a-z0-9]+/', '-', strtolower($list['name'])) . '-' : '';
        [$rows, $leads] = \Leads\Exports::smsRows($f, $user, $base);
        if ($list) {
            Db::run('UPDATE saved_lists SET last_exported_at = UTC_TIMESTAMP(), last_new = 0 WHERE id = ?', [$list['id']]);
        }
        \Leads\Exports::logSms($rows, $list['name'] ?? (\Leads\Suggestions::all()[(string)($_GET['s'] ?? '')]['name'] ?? 'Leads search'));
        Auth::audit('export_sms', count($rows) . " numbers from $leads leads " . json_encode($f));
        \Leads\Exports::send('sms-' . $slug . date('Ymd-His') . '.csv', \Leads\Exports::SMS_COLUMNS, $rows);

    case $path === '/export.csv':
        $f = LeadQuery::filters($_GET);
        [$where, $params] = LeadQuery::where($f, $user);
        $rows = Db::all("SELECT p.*, xc.name AS exclusive_client FROM properties p LEFT JOIN clients xc ON xc.id = p.exclusive_client_id WHERE $where ORDER BY " . LeadQuery::order($f['sort']) . ' LIMIT 5000', $params);
        $list = !empty($_GET['list']) ? Db::one('SELECT id, name FROM saved_lists WHERE id = ?', [(int)$_GET['list']]) : null;
        if ($list) {
            Db::run('UPDATE saved_lists SET last_exported_at = UTC_TIMESTAMP(), last_new = 0 WHERE id = ?', [$list['id']]);
        }
        Auth::audit('export_csv', count($rows) . ' rows ' . ($list ? "list {$list['id']} {$list['name']} " : '') . json_encode($f));
        header('Content-Type: text/csv; charset=utf-8');
        $slug = $list ? preg_replace('/[^a-z0-9]+/', '-', strtolower($list['name'])) . '-' : '';
        header('Content-Disposition: attachment; filename="leads-' . $slug . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['parcel_id', 'score', 'address', 'city', 'zip', 'owner', 'owner_mailing_address', 'owner_mailing_city', 'owner_mailing_state', 'owner_mailing_zip',
            'absentee', 'est_value', 'equity_pct', 'loan_balance', 'motivators', 'score_reasons', 'contact_1', 'phone_1', 'phone_1_type', 'phone_2', 'phone_2_type', 'phone_3', 'phone_3_type', 'email_1', 'email_2',
            'status', 'exclusive_client', 'delivered_at', 'delivery_channel'], ',', '"', '\\');
        foreach ($rows as $p) {
            $sig = Db::all('SELECT type, source_ref, event_date, amount FROM signals WHERE parcel_id = ? AND active = 1', [$p['parcel_id']]);
            $sigTxt = implode(' | ', array_map([Motivators::class, 'label'], Motivators::evaluate($p, $sig)));
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
            array_push($row, Pipeline::label($p['status']), $p['exclusive_client'] ?? '', $p['delivered_at'] ? when($p['delivered_at'], 'Y-m-d H:i T') : '', $p['delivery_channel'] ?? '');
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

    case $path === '/admin/run.json' && $method === 'POST':
        // one ~50 s round of jobs; the Admin page calls this repeatedly until `pending` is empty
        Auth::require(true);
        ignore_user_abort(true);
        $budget = max(20, min(50, ((int)ini_get('max_execution_time') ?: 60) - 10));
        $log = (new Jobs($budget))->run();
        $pending = Jobs::pending();
        Auth::audit('run_jobs', implode(' · ', $log));
        header('Content-Type: application/json');
        echo json_encode(['log' => $log, 'pending' => $pending, 'done' => !$pending, 'time' => when(gmdate('Y-m-d H:i:s'), 'g:i:s a T')]);
        exit;

    case $path === '/admin/run' && $method === 'POST':
        Auth::require(true);
        $budget = max(20, min(50, ((int)ini_get('max_execution_time') ?: 60) - 10));
        $log = (new Jobs($budget))->run();
        View::flash('Ran jobs: ' . implode(' · ', $log) ?: 'nothing to do');
        View::redirect('/admin');

    // ---------------------------------------------------------------- New Podio Seller (Slack button / lead page)
    case $path === '/podio/new':
        Auth::require(true);
        $phone = \Leads\PodioSeller::phone10((string)($_GET['phone'] ?? ''));
        $messages = strlen($phone) === 10 ? \Leads\PodioSeller::messages($phone) : [];
        $r = \Leads\PodioSeller::draft($phone, isset($_GET['pid']) && $_GET['pid'] !== '' ? (string)$_GET['pid'] : null, (string)($_GET['address'] ?? ''), $messages);
        View::render('podio', $r + ['error' => null, 'done' => null, 'configured' => \Leads\Podio::configured()], 'New Podio Seller');
        break;

    case $path === '/podio/create' && $method === 'POST':
        Auth::require(true);
        $d = ['phone' => (string)($_POST['phone'] ?? ''), 'parcel_id' => (string)($_POST['parcel_id'] ?? '')];
        foreach (array_keys(\Leads\PodioSeller::FORM) as $k) {
            $d[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $phone = \Leads\PodioSeller::phone10($d['phone']);
        $done = $error = null;
        try {
            $done = \Leads\PodioSeller::create($d, $user);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            \Leads\Log::error('podio create: ' . $error);
        }
        $pushed = Db::one('SELECT * FROM podio_pushes WHERE phone = ? ORDER BY id DESC LIMIT 1', [$phone]);
        View::render('podio', ['draft' => $d, 'choices' => \Leads\PodioSeller::properties($phone), 'pushed' => $done ? null : $pushed, 'messages' => [],
            'error' => $error, 'done' => $done, 'configured' => \Leads\Podio::configured()], 'New Podio Seller');
        break;

    case $path === '/podio/check':
        Auth::require(true);
        $check = ['configured' => \Leads\Podio::configured(), 'apps' => [], 'error' => null];
        if ($check['configured']) {
            try {
                if (!empty($_GET['refresh'])) {
                    Db::run("DELETE FROM settings WHERE `key` LIKE 'podio_app_%'");
                }
                $sample = \Leads\PodioSeller::draft('0000000000');
                $check['apps'] = [
                    ['app' => \Leads\Podio::app(\Leads\PodioSeller::contactsApp()), 'want' => array_keys(\Leads\PodioSeller::contactValues($sample['draft']))],
                    ['app' => \Leads\Podio::app(\Leads\PodioSeller::sellerApp()), 'want' => array_keys(\Leads\PodioSeller::sellerValues($sample['draft'], 0))],
                ];
            } catch (\Throwable $e) {
                $check['error'] = $e->getMessage();
            }
        }
        View::render('podio_check', $check, 'Podio connection');
        break;

    case $path === '/admin/optout-remove' && $method === 'POST':
        Auth::require(true);
        Db::run('DELETE FROM opt_outs WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        View::redirect('/admin');

    default:
        http_response_code(404);
        View::render('message', ['message' => 'Page not found.'], 'Not found');
}
