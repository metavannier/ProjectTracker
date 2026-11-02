<?php
declare(strict_types=1);

/*
 * API tests, including the multi-user scenarios that lost data with the JSON
 * version. Runs INSIDE the test web container (see tests/run-tests.sh) against
 * the test database prepared by tests/migration-test.php.
 *
 *   php api-test.php <base-url>
 *   php api-test.php <base-url> --save-session <file>     (log in, store the cookie)
 *   php api-test.php <base-url> --check-session <token>   (cookie still valid?)
 */

require '/var/www/projecttracker/src/bootstrap.php';

use ProjectTracker\Config;
use ProjectTracker\Database;

$base = rtrim($argv[1] ?? 'http://127.0.0.1', '/');
const PASSWORD = 'test-password';

final class Client
{
    public ?string $token = null;

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status: int, body: mixed, headers: array<string, string>} */
    public function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $ch = $this->handle($method, $path, $body, $headers);
        $response = curl_exec($ch);
        return $this->parse($ch, (string) $response);
    }

    public function handle(string $method, string $path, mixed $body = null, array $headers = []): CurlHandle
    {
        $ch = curl_init($this->base . $path);
        $h = $headers;
        if ($body !== null && !preg_grep('/^Content-Type:/i', $h)) {
            $h[] = 'Content-Type: application/json';
        }
        if ($this->token !== null) {
            $h[] = 'Cookie: pt_session=' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        return $ch;
    }

    public function parse(CurlHandle $ch, string $response): array
    {
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($response, 0, $headerSize);
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            if (preg_match('/^Set-Cookie: pt_session=([^;]*)/i', $line, $m)) {
                $this->token = $m[1] === '' ? null : $m[1];
            }
        }
        $text = substr($response, $headerSize);
        $json = json_decode($text, true);
        return ['status' => $status, 'body' => $json ?? $text, 'headers' => $headers];
    }

    public function login(): void
    {
        $r = $this->request('POST', '/api/auth', ['password' => PASSWORD]);
        if ($r['status'] !== 200) {
            throw new RuntimeException('login failed');
        }
    }

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        return $this->request('GET', '/api/projects')['body'];
    }

    /** @return array<string, mixed>|null */
    public function project(int $id): ?array
    {
        foreach ($this->projects() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }
}

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function seconds(string $hms): int
{
    [$h, $m, $s] = array_map('intval', explode(':', $hms));
    return $h * 3600 + $m * 60 + $s;
}

function key16(): string
{
    return bin2hex(random_bytes(16));
}

// ---- Helper modes used by run-tests.sh -----------------------------------------
if (($argv[2] ?? '') === '--save-session') {
    $c = new Client($base);
    $c->login();
    file_put_contents($argv[3], $c->token);
    exit(0);
}
if (($argv[2] ?? '') === '--check-session') {
    $c = new Client($base);
    $c->token = $argv[3];
    $r = $c->request('GET', '/api/session');
    check($r['status'] === 200, 'session is still valid after restarting the web container');
    exit($failures > 0 ? 1 : 0);
}

$pdo = (new Database(Config::fromEnvironment(web: false)))->pdo();

echo "Security\n";
$anon = new Client($base);
check($anon->request('GET', '/api/projects')['status'] === 401, 'API refuses requests without login');
$r = $anon->request('GET', '/index.html');
check($r['status'] === 302 && $r['headers']['location'] === '/', 'application page redirects to the login page');
foreach (['/password.env', '/database.env', '/projects.json', '/server.js', '/src/App.php', '/views/index.html',
          '/database/schema.sql', '/bin/migrate-json.php', '/.env', '/../src/Config.php'] as $path) {
    $r = $anon->request('GET', $path);
    check(in_array($r['status'], [302, 400, 404], true) && !str_contains((string) json_encode($r['body']), 'PASSWORD'), "$path is not exposed (HTTP {$r['status']})");
}
check($anon->request('GET', '/logo.png')['status'] === 200, 'logo is public');
$r = $anon->request('POST', '/api/auth', ['password' => 'wrong']);
check($r['status'] === 401 && $r['body']['success'] === false, 'wrong password refused');
check($anon->token === null, 'no session cookie after a wrong password');

$a = new Client($base);   // engineer A
$b = new Client($base);   // engineer B, on another computer
$a->login();
$b->login();
check($a->token !== null && $a->token !== $b->token, 'login creates a session');
check($a->request('GET', '/api/session')['status'] === 200, 'session check');
$r = $a->request('GET', '/index.html');
check($r['status'] === 200 && str_contains((string) $r['body'], 'Project Time Tracker'), 'application page served after login');
check(str_contains($r['headers']['cache-control'] ?? '', 'no-store'), 'pages are not cached');
check(str_contains($a->request('GET', '/api/projects')['headers']['cache-control'] ?? '', 'no-store'), 'data is not cached');
$r = $a->request('POST', '/api/projects', 'title=x', ['Content-Type: application/x-www-form-urlencoded']);
check($r['status'] === 415 || $r['status'] === 400, 'form posts (cross-site requests) are refused');

echo "Reading\n";
$list = $a->projects();
check(count($list) === 5 && $list[0]['id'] === 2, 'projects listed in display order');
check(array_keys($list[0]) === ['id', 'title', 'researchers', 'engineers', 'institute', 'submissionDate', 'startDate', 'endDate', 'totalHours', 'lastUpdate', 'category', 'status', 'order'], 'same JSON fields as before');
check($list[1]['lastUpdate'] === "08/10/2026\r\n17:10:14", 'last update keeps date + time format');

echo "Creating projects (ids assigned by the database)\n";
$new = ['title' => 'New project', 'researchers' => ['R1'], 'engineers' => ['E1'], 'institute' => 'IBDM', 'category' => 'Bioinformatics',
        'submissionDate' => '02/11/2026', 'startDate' => '02/11/2026', 'lastUpdate' => "02/11/2026\r\n10:00:00"];
$ra = $a->handle('POST', '/api/projects', ['title' => 'Created by A'] + $new, ['Idempotency-Key: ' . key16()]);
$rb = $b->handle('POST', '/api/projects', ['title' => 'Created by B'] + $new, ['Idempotency-Key: ' . key16()]);
$multi = curl_multi_init();
curl_multi_add_handle($multi, $ra);
curl_multi_add_handle($multi, $rb);
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);
$list = $a->projects();
$byTitle = array_column($list, null, 'title');
check(isset($byTitle['Created by A'], $byTitle['Created by B']) && $byTitle['Created by A']['id'] !== $byTitle['Created by B']['id'], 'two projects created at the same moment get different ids and both exist');
$pa = $byTitle['Created by A'];
check($pa['totalHours'] === '0:00:00' && $pa['status'] === 'active' && $pa['endDate'] === '' && $pa['lastUpdate'] === "02/11/2026\r\n10:00:00", 'new project values');
$r = $a->request('POST', '/api/projects', ['title' => '', 'researchers' => [], 'institute' => '', 'category' => 'X']);
check($r['status'] === 400, 'required fields are validated on the server');
$key = key16();
$a->request('POST', '/api/projects', ['title' => 'Sent twice'] + $new, ["Idempotency-Key: $key"]);
$r = $a->request('POST', '/api/projects', ['title' => 'Sent twice'] + $new, ["Idempotency-Key: $key"]);
check($r['status'] === 200 && count(array_filter($r['body']['projects'], fn ($p) => $p['title'] === 'Sent twice')) === 1, 'a change sent twice (same id) is applied once');

echo "Recording time: concurrent timers never overwrite each other\n";
$id = 28;
$before = seconds($a->project($id)['totalHours']);
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 30; $i++) {
    $client = $i % 2 ? $a : $b;
    $h = $client->handle('POST', "/api/projects/$id/time", ['seconds' => 60, 'lastUpdate' => "02/11/2026\r\n11:00:00"], ['Idempotency-Key: ' . key16()]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);
$codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
$after = seconds($a->project($id)['totalHours']);
check(count(array_filter($codes, fn ($c) => $c === 200)) === 30, '30 simultaneous time records accepted');
check($after - $before === 1800, 'all 30 minutes recorded (expected +1800 s, got +' . ($after - $before) . ' s)');
$logged = (int) $pdo->query("SELECT SUM(seconds_delta) FROM activity_log WHERE action = 'add_time' AND project_id = $id")->fetchColumn();
check($logged === 1800, 'every time record is in the history');

// Retry of the same timer stop (network retry / restore after expired session)
$key = key16();
$a->request('POST', "/api/projects/$id/time", ['seconds' => 3600], ["Idempotency-Key: $key"]);
$a->request('POST', "/api/projects/$id/time", ['seconds' => 3600], ["Idempotency-Key: $key"]);
check(seconds($a->project($id)['totalHours']) === $after + 3600, 'a re-sent timer stop is counted once');
check($a->request('POST', "/api/projects/$id/time", ['seconds' => -5])['status'] === 400, 'negative duration refused');
check($a->request('POST', '/api/projects/999999/time', ['seconds' => 5])['status'] === 404, 'time on a missing project: 404');

// Last update never goes backwards (old change sent late)
$lastUpdate = $a->project($id)['lastUpdate'];
$a->request('POST', "/api/projects/$id/time", ['seconds' => 1, 'lastUpdate' => "01/01/2026\r\n08:00:00"]);
check($a->project($id)['lastUpdate'] === $lastUpdate, '"Last update" never moves backwards');

echo "Manual time: no silent overwrite\n";
$shownToA = $a->project($id)['totalHours'];
$b->request('POST', "/api/projects/$id/time", ['seconds' => 7200]);          // B records 2 h meanwhile
$r = $a->request('PUT', "/api/projects/$id/time", ['totalHours' => '100:00:00', 'expected' => $shownToA]);
check($r['status'] === 409 && isset($r['body']['projects']), 'manual correction based on an outdated value is refused (409) with fresh data');
check(seconds($a->project($id)['totalHours']) === seconds($shownToA) + 7200, "B's 2 hours are kept");
$current = $a->project($id)['totalHours'];
$r = $a->request('PUT', "/api/projects/$id/time", ['totalHours' => '100:00:00', 'expected' => $current, 'lastUpdate' => "02/11/2026\r\n12:00:00"]);
check($r['status'] === 200 && $a->project($id)['totalHours'] === '100:00:00', 'manual correction on the current value succeeds');
check($a->request('PUT', "/api/projects/$id/time", ['totalHours' => '1:75:00'])['status'] === 400, 'invalid manual time refused');

echo "Editing details: field-level merge\n";
$p = $a->project(30);
$original = array_intersect_key($p, array_flip(['title', 'researchers', 'engineers', 'institute', 'category']));
$r = $a->request('PATCH', '/api/projects/30', ['title' => 'Renamed by A'] + $original + ['original' => $original]);
$r = $b->request('PATCH', '/api/projects/30', ['category' => 'Training'] + $original + ['original' => $original]);
$p = $a->project(30);
check($r['status'] === 200 && $p['title'] === 'Renamed by A' && $p['category'] === 'Training', 'A renames and B changes the category at the same time: both kept');
check($p['totalHours'] === '350:00:00', 'editing never changes the recorded time');
$r = $b->request('PATCH', '/api/projects/30', ['title' => 'Renamed by B'] + $original + ['original' => $original]);
check($r['status'] === 409 && $a->project(30)['title'] === 'Renamed by A', 'both renaming the same project: second one refused (409), nothing overwritten');
check($a->request('PATCH', '/api/projects/30', ['title' => 'x', 'researchers' => [], 'institute' => 'x', 'category' => 'x'])['status'] === 400, 'edit validation');

echo "Finish / reopen / delete\n";
$r = $a->request('POST', '/api/projects/7/finish', ['endDate' => '02/11/2026']);
$p = $a->project(7);
check($r['status'] === 200 && $p['status'] === 'finished' && $p['endDate'] === '02/11/2026', 'finish');
$b->request('POST', '/api/projects/7/finish', ['endDate' => '03/11/2026']);
check($a->project(7)['endDate'] === '02/11/2026', 'finishing again (outdated screen) keeps the original end date');
$a->request('POST', '/api/projects/7/reopen');
$p = $a->project(7);
check($p['status'] === 'active' && $p['endDate'] === '', 'reopen');
$ids = [$byTitle['Created by A']['id'], $byTitle['Created by B']['id']];
$a->request('POST', '/api/projects/bulk', ['action' => 'finish', 'ids' => $ids, 'endDate' => '02/11/2026']);
check($a->project($ids[0])['status'] === 'finished' && $a->project($ids[1])['status'] === 'finished', 'bulk finish');
$a->request('POST', '/api/projects/bulk', ['action' => 'reopen', 'ids' => $ids]);
check($a->project($ids[0])['status'] === 'active', 'bulk reopen');
$r = $a->request('DELETE', '/api/projects/' . $ids[0]);
check($r['status'] === 200 && $a->project($ids[0]) === null, 'delete');
$deletedAt = $pdo->query('SELECT deleted_at FROM projects WHERE id = ' . $ids[0])->fetchColumn();
check($deletedAt !== null && $deletedAt !== false, 'deleted project is kept in the database (can be restored)');
check($b->request('DELETE', '/api/projects/' . $ids[0])['status'] === 200, 'deleting an already deleted project is not an error');
$r = $b->request('POST', '/api/projects/' . $ids[0] . '/time', ['seconds' => 60]);
check($r['status'] === 404 && str_contains($r['body']['error'], 'no longer exists'), 'recording time on a project deleted by someone else: clear 404 message');
$a->request('POST', '/api/projects/bulk', ['action' => 'delete', 'ids' => [$ids[1]]]);
check($a->project($ids[1]) === null, 'bulk delete');

echo "Drag & drop reorder\n";
$order = array_column($a->projects(), 'id');
$a->request('POST', "/api/projects/{$order[0]}/move", ['targetId' => $order[2]]);
$expected = $order;
array_splice($expected, 0, 1);
array_splice($expected, 2, 0, [$order[0]]);
check(array_column($a->projects(), 'id') === $expected, 'moving down places the project after the target (same rule as before)');
$list = $a->projects();
check(array_column($list, 'order') === range(0, count($list) - 1), 'order field renumbered');
$hoursBefore = array_column($list, 'totalHours', 'id');
$b->request('POST', "/api/projects/$id/time", ['seconds' => 600]);   // B records time...
$a->request('POST', "/api/projects/{$expected[3]}/move", ['targetId' => $expected[0]]);   // ...A reorders
$hoursAfter = array_column($a->projects(), 'totalHours', 'id');
check(seconds($hoursAfter[$id]) === seconds($hoursBefore[$id]) + 600, "a reorder never undoes another engineer's time (the old version lost it)");

echo "Import\n";
$r = $a->request('POST', '/api/projects/import', ['projects' => [
    ['id' => 2, 'title' => 'Imported 1', 'researchers' => ['X'], 'engineers' => [], 'institute' => 'LCB', 'submissionDate' => '01/01/2026',
     'startDate' => '01/01/2026', 'endDate' => '', 'totalHours' => '5:00:00', 'lastUpdate' => '01/01/2026', 'category' => 'Training', 'status' => 'active'],
    ['title' => 'Imported 2', 'researchers' => ['Y'], 'totalHours' => '1:30:00', 'status' => 'finished', 'endDate' => '02/01/2026'],
]]);
$titles = array_column($r['body']['projects'] ?? [], null, 'title');
check($r['status'] === 200 && $r['body']['imported'] === 2, 'import adds the projects');
check(isset($titles['Imported 1']) && $titles['Imported 1']['id'] !== 2 && $titles['Imported 1']['totalHours'] === '5:00:00', 'imported projects get new ids, values kept');
$countBefore = count($a->projects());
$r = $a->request('POST', '/api/projects/import', ['projects' => [['title' => 'fine'], ['title' => 'bad', 'totalHours' => 'abc']]]);
check($r['status'] === 400 && count($a->projects()) === $countBefore, 'invalid import: nothing imported');

echo "Previous version's endpoints (old page still open in a tab)\n";
$r = $a->request('POST', '/api/projects', $a->projects());
check($r['status'] === 410, 'whole-list save from an old page is refused (410)');
check($a->request('POST', '/api/project/2', ['id' => 2, 'title' => 'x'])['status'] === 410, 'old per-project save refused (410)');
check($a->request('DELETE', '/api/project/2')['status'] === 410, 'old delete refused (410)');
check($a->project(2)['title'] === 'Imaging pipeline', 'data unchanged by the old page');

echo "Sessions\n";
$hash = hash_hmac('sha256', $a->token, getenv('SESSION_SECRET'));
$expires = static fn () => $pdo->query("SELECT expires_at FROM sessions WHERE token_hash = '$hash'")->fetchColumn();
$pdo->exec("UPDATE sessions SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE token_hash = '$hash'");
$e1 = $expires();
$a->request('GET', '/api/projects', null, ['X-Background-Refresh: 1']);
check($expires() === $e1, 'automatic background refresh does not keep the session alive');
$a->request('GET', '/api/projects');
check($expires() > $e1, 'user activity extends the session');
$pdo->exec("UPDATE sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token_hash = '$hash'");
check($a->request('GET', '/api/projects')['status'] === 401, 'expired session refused');
$b->request('GET', '/logout');
check($b->request('GET', '/api/session')['status'] === 401, 'logout ends the session');

if ($failures > 0) {
    echo "$failures API test(s) FAILED" . PHP_EOL;
    exit(1);
}
echo 'API tests passed.' . PHP_EOL;
