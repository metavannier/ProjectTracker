<?php
declare(strict_types=1);

namespace ProjectTracker;

use JsonException;
use Throwable;

/**
 * HTTP front controller: routing, authentication, JSON responses and error
 * handling. Every response that contains data is sent with
 * "Cache-Control: no-store", so browsers and proxies never show stale data.
 *
 * Pages
 *   GET  /                      login page (or redirect to /index.html when logged in)
 *   GET  /index.html            the application (login required)
 *   GET  /logout                end the session
 *
 * API (JSON, login required except /api/auth and /api/health)
 *   POST   /api/auth                    {password}
 *   GET    /api/session
 *   GET    /api/health                  container health check
 *   GET    /api/projects                list of projects (same format as before)
 *   POST   /api/projects                create a project
 *   PATCH  /api/projects/{id}           edit title/researchers/engineers/institute/category
 *   DELETE /api/projects/{id}           delete a project
 *   POST   /api/projects/{id}/time      add timer time            {seconds, lastUpdate}
 *   PUT    /api/projects/{id}/time      set total time manually   {totalHours, expected, lastUpdate}
 *   POST   /api/projects/{id}/finish    {endDate}
 *   POST   /api/projects/{id}/reopen
 *   POST   /api/projects/{id}/move      drag & drop               {targetId}
 *   POST   /api/projects/bulk           {action: finish|reopen|delete, ids, endDate}
 *   POST   /api/projects/import         {projects: [...]}
 *
 * Every change returns {"status": "ok", "projects": [...]} with the complete,
 * up-to-date list, and accepts an "Idempotency-Key" header.
 */
final class App
{
    private Config $config;
    private Database $db;
    private bool $authenticated = false;

    public function run(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
        $isApi = str_starts_with($path, '/api/');

        try {
            $this->config = Config::fromEnvironment(web: true);
            date_default_timezone_set($this->config->timezone);
            $this->db = new Database($this->config);
            $this->dispatch($method, $path, $isApi);
        } catch (ApiException $e) {
            $this->sendError($e->status, $e->getMessage(), $e->extra, $isApi);
        } catch (Throwable $e) {
            error_log(sprintf('[ProjectTracker] %s %s: %s in %s:%d', $method, $path, $e->getMessage(), $e->getFile(), $e->getLine()));
            if (Database::isConnectionError($e)) {
                $this->sendError(503, 'The database is not reachable at the moment. Please try again in a few seconds.', [], $isApi);
            } else {
                $this->sendError(500, 'Internal server error. Your change was not saved; please try again.', [], $isApi);
            }
        }
    }

    private function dispatch(string $method, string $path, bool $isApi): void
    {
        // ---- Public routes -------------------------------------------------
        if ($path === '/api/health' && $method === 'GET') {
            $this->db->pdo()->query('SELECT 1 FROM projects LIMIT 1');
            $this->json(200, ['status' => 'ok']);
            return;
        }
        if ($path === '/' && $method === 'GET') {
            if ($this->auth()->check(extend: true)) {
                $this->redirect('/index.html');
            } else {
                $this->page('login.html');
            }
            return;
        }
        if ($path === '/login.html' && $method === 'GET') {
            $this->page('login.html');
            return;
        }
        if ($path === '/logout' && $method === 'GET') {
            $this->auth()->logout();
            $this->redirect('/');
            return;
        }
        if ($path === '/api/auth' && $method === 'POST') {
            $this->login();
            return;
        }

        // ---- Everything else requires a valid session ------------------------
        $background = ($_SERVER['HTTP_X_BACKGROUND_REFRESH'] ?? '') === '1';
        $this->authenticated = $this->auth()->check(extend: !$background);
        if (!$this->authenticated) {
            if ($isApi) {
                throw new ApiException(401, 'Unauthorized');
            }
            $this->redirect('/');
            return;
        }

        if ($path === '/index.html' && $method === 'GET') {
            $this->page('index.html');
            return;
        }
        if (!$isApi) {
            throw new ApiException(404, 'Not found');
        }
        $this->api($method, $path);
    }

    private function api(string $method, string $path): void
    {
        $service = new ProjectService($this->db, $this->config, $_SERVER['REMOTE_ADDR'] ?? null);

        if ($path === '/api/session' && $method === 'GET') {
            $this->json(200, ['authenticated' => true]);
            return;
        }

        if ($path === '/api/projects') {
            if ($method === 'GET') {
                $this->json(200, $service->list());
                return;
            }
            if ($method === 'POST') {
                $body = $this->body();
                if (array_is_list($body) && $body !== []) {
                    // The previous version saved the whole list here: refuse it, so that a
                    // browser tab still running the old page can never overwrite the data.
                    throw $this->outdatedPage();
                }
                $service->create($body, $this->opId());
                $this->ok($service);
                return;
            }
        }

        if ($path === '/api/projects/import' && $method === 'POST') {
            $body = $this->body();
            $count = $service->import($body['projects'] ?? null, $this->opId());
            $this->ok($service, ['imported' => $count]);
            return;
        }

        if ($path === '/api/projects/bulk' && $method === 'POST') {
            $body = $this->body();
            $ids = $this->ids($body['ids'] ?? null);
            match ($body['action'] ?? null) {
                'finish' => $service->setStatus($ids, 'finished', $body['endDate'] ?? null, $this->opId()),
                'reopen' => $service->setStatus($ids, 'active', null, $this->opId()),
                'delete' => $service->delete($ids, $this->opId()),
                default => throw new ApiException(400, 'Unknown action'),
            };
            $this->ok($service);
            return;
        }

        if (preg_match('#^/api/projects/(\d{1,10})(/[a-z]+)?$#', $path, $m)) {
            $id = (int) $m[1];
            $action = $m[2] ?? '';
            switch ("$method $action") {
                case 'PATCH ':
                    $service->updateDetails($id, $this->body(), $this->opId());
                    break;
                case 'DELETE ':
                    $service->delete([$id], $this->opId());
                    break;
                case 'POST /time':
                    $body = $this->body();
                    $service->addTime($id, $body['seconds'] ?? null, $body['lastUpdate'] ?? null, $this->opId());
                    break;
                case 'PUT /time':
                    $body = $this->body();
                    $service->setTime($id, $body['totalHours'] ?? null, $body['expected'] ?? null, $body['lastUpdate'] ?? null, $this->opId());
                    break;
                case 'POST /finish':
                    $body = $this->body(optional: true);
                    $service->setStatus([$id], 'finished', $body['endDate'] ?? null, $this->opId(), single: true);
                    break;
                case 'POST /reopen':
                    $this->body(optional: true);
                    $service->setStatus([$id], 'active', null, $this->opId(), single: true);
                    break;
                case 'POST /move':
                    $body = $this->body();
                    $service->move($id, $body['targetId'] ?? null, $this->opId());
                    break;
                default:
                    throw new ApiException(405, 'Method not allowed');
            }
            $this->ok($service);
            return;
        }

        // Endpoints of the previous (JSON file) version
        if (preg_match('#^/api/project/\d+$#', $path) || $path === '/api/projects/cleanup') {
            throw $this->outdatedPage();
        }

        throw new ApiException(404, 'Not found');
    }

    // ------------------------------------------------------------------ login

    private function login(): void
    {
        $body = $this->body();
        $password = $body['password'] ?? null;
        if (!is_string($password) || $password === '') {
            $this->json(400, ['success' => false, 'error' => 'No password provided']);
            return;
        }
        if ($this->auth()->login($password)) {
            $this->json(200, ['success' => true]);
            return;
        }
        // Random delay to slow down brute-force attacks
        usleep(random_int(100_000, 300_000));
        $this->json(401, ['success' => false, 'error' => 'Invalid password']);
    }

    // ---------------------------------------------------------------- helpers

    private function auth(): Auth
    {
        return new Auth($this->db, $this->config);
    }

    /** Sends the up-to-date project list after a successful change. */
    private function ok(ProjectService $service, array $extra = []): void
    {
        $this->json(200, ['status' => 'ok'] + $extra + ['projects' => $service->list()]);
    }

    /** @return array<mixed> */
    private function body(bool $optional = false): array
    {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            if ($optional) {
                return [];
            }
            throw new ApiException(400, 'Empty request');
        }
        // A JSON content type cannot be sent by a cross-site HTML form (CSRF protection)
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($type, 'application/json')) {
            throw new ApiException(415, 'Expected a JSON request');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'Invalid JSON');
        }
        if (!is_array($data)) {
            throw new ApiException(400, 'Invalid request');
        }
        return $data;
    }

    private function opId(): ?string
    {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($key === null || $key === '') {
            return null;
        }
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new ApiException(400, 'Invalid Idempotency-Key');
        }
        return $key;
    }

    /** @return list<int> */
    private function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 10000) {
            throw new ApiException(400, 'No project selected');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new ApiException(400, 'Invalid project id');
            }
        }
        return $ids;
    }

    private function outdatedPage(): ApiException
    {
        return new ApiException(410, 'The application has been updated. Please reload the page (F5) and repeat your last change.');
    }

    private function page(string $file): void
    {
        $this->noCache();
        header('Content-Type: text/html; charset=utf-8');
        readfile(PROJECT_TRACKER_ROOT . '/views/' . $file);
    }

    private function redirect(string $location): void
    {
        $this->noCache();
        header('Location: ' . $location, true, 302);
    }

    private function noCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /** @param array<mixed> $data */
    private function json(int $status, array $data): void
    {
        http_response_code($status);
        $this->noCache();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $extra */
    private function sendError(int $status, string $message, array $extra, bool $isApi): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('Location');
        if (!$isApi) {
            http_response_code($status);
            $this->noCache();
            header('Content-Type: text/plain; charset=utf-8');
            echo $message;
            return;
        }
        // After a refused change, also send the current data so the page can refresh itself
        if ($this->authenticated && in_array($status, [404, 409], true)) {
            try {
                $extra['projects'] = (new ProjectService($this->db, $this->config))->list();
            } catch (Throwable) {
                // the error message is more important than the refresh
            }
        }
        $this->json($status, ['error' => $message] + $extra);
    }
}
