<?php

declare(strict_types=1);

namespace Hfiuc\Http;

use Hfiuc\Analytics\AnalyticsService;
use Hfiuc\Announcement\AnnouncementService;
use Hfiuc\Auth\AuthService;
use Hfiuc\Catalog\CatalogService;
use Hfiuc\Config;
use Hfiuc\Database;
use Hfiuc\Http\Input;
use Hfiuc\Log\Logger;
use Hfiuc\Reservation\ReservationService;
use Hfiuc\Worker\CloudflareQueue;
use Hfiuc\Worker\Outbox;
use Hfiuc\Worker\OutboxWorker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory as SlimFactory;
use Slim\Psr7\Response;

final class Application
{
    public static function create(Config $config, Database $db, Logger $logger): App
    {
        $auth = new AuthService($db, $config, $logger);
        $catalog = new CatalogService($db, $auth, $logger);
        $queue = new CloudflareQueue($config, $logger);
        $outbox = new Outbox($db, $queue, $logger);
        $reservations = new ReservationService($db, $auth, $config, $logger, $outbox);
        $jobs = new OutboxWorker($db, $config, $logger, $outbox);
        $announcements = new AnnouncementService($db, $auth, $logger);
        $analytics = new AnalyticsService($db, $auth);
        $app = SlimFactory::create();

        $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            return Responder::data($response, [
                'status' => 'ok',
                'service' => 'hfiuc-php',
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        });
        $app->get('/debug', function (ServerRequestInterface $request, ResponseInterface $response) use ($config, $auth, $logger): ResponseInterface {
            if (!$config->debug) {
                throw new HttpException(404, 'Not found.');
            }
            $admin = $auth->requireAdmin($request);
            if ($admin['role'] !== 'global') {
                throw new HttpException(403, 'Global administrator required.');
            }
            $logger->audit('debug.secrets.read', 'configuration');

            return Responder::data($response, [
                'QUEUE_PROCESS_SECRET' => $config->queueProcessSecret,
                'TASK_PULL_SECRET' => $config->taskPullSecret,
                'TASK_EXECUTE_SECRET' => $config->taskExecuteSecret,
            ])
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('Pragma', 'no-cache');
        });
        $app->get('/_csrf', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth): ResponseInterface {
            $token = $auth->issueCsrf();

            return Responder::data($response, $token)->withHeader('x-csrf-token', $token);
        });
        $app->get('/announcement/current', function (ServerRequestInterface $request, ResponseInterface $response) use ($announcements): ResponseInterface {
            return Responder::data($response, $announcements->current());
        });
        $app->get('/announcement/admin', function (ServerRequestInterface $request, ResponseInterface $response) use ($announcements): ResponseInterface {
            return Responder::data($response, $announcements->admin($request));
        });
        $app->post('/announcement/update', function (ServerRequestInterface $request, ResponseInterface $response) use ($announcements): ResponseInterface {
            $announcements->update($request);

            return Responder::message($response, 'Announcement updated successfully.');
        });
        $app->get('/campus/list', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $catalog->campuses($request)));
        $app->get('/class/list', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $catalog->classes($request)));
        $app->get('/room/list', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $catalog->rooms($request)));
        $app->post('/campus/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->createCampus($request);

            return Responder::message($response, 'Campus created successfully.');
        });
        $app->post('/campus/edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editCampus($request);

            return Responder::message($response, 'Campus edited successfully.');
        });
        $app->post('/campus/delete', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->deleteCampus($request);

            return Responder::message($response, 'Campus deleted successfully.');
        });
        $app->post('/campus/restore', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->restoreCampus($request);

            return Responder::message($response, 'Campus restored successfully.');
        });
        $app->post('/class/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->createClass($request);

            return Responder::message($response, 'Class created successfully.');
        });
        $app->post('/class/edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editClass($request);

            return Responder::message($response, 'Class edited successfully.');
        });
        $app->post('/class/delete', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->deleteClass($request);

            return Responder::message($response, 'Class deleted successfully.');
        });
        $app->post('/class/restore', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->restoreClass($request);

            return Responder::message($response, 'Class restored successfully.');
        });
        $app->post('/room/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->createRoom($request);

            return Responder::message($response, 'Room created successfully.');
        });
        $app->post('/room/edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editRoom($request);

            return Responder::message($response, 'Room edited successfully.');
        });
        $app->post('/room/delete', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->deleteRoom($request);

            return Responder::message($response, 'Room deleted successfully.');
        });
        $app->post('/room/restore', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->restoreRoom($request);

            return Responder::message($response, 'Room restored successfully.');
        });
        $app->post('/policy/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->createPolicy($request);

            return Responder::message($response, 'Policy created successfully.');
        });
        $app->post('/policy/edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editPolicy($request);

            return Responder::message($response, 'Policy edited successfully.');
        });
        $app->post('/policy/toggle', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->togglePolicy($request);

            return Responder::message($response, 'Policy toggled successfully.');
        });
        $app->post('/policy/delete', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->deletePolicy($request);

            return Responder::message($response, 'Policy deleted successfully.');
        });
        $app->get('/reservation/availability', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->availability($request)));
        $app->post('/reservation/create', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->create($request)));
        $app->get('/reservation/get', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->list($request)));
        $app->get('/reservation/future', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->future($request)));
        $app->get('/reservation/export', function (ServerRequestInterface $request, ResponseInterface $response) use ($reservations): ResponseInterface {
            $export = $reservations->export($request);
            $response->getBody()->write($export['bytes']);
            $response->getBody()->rewind();

            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="reservations-' . $export['mode'] . '.xlsx"');
        });
        $app->get('/reservation/cancel/preview', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->cancelPreview($request)));
        $app->post('/reservation/cancel', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::message($response, $reservations->cancel($request)));
        $app->post('/reservation/modify', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $reservations->modify($request)));
        $app->post('/reservation/admin-edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($reservations): ResponseInterface {
            $reservations->adminEdit($request);

            return Responder::message($response, 'Reservation updated successfully.');
        });
        $app->post('/reservation/approval', function (ServerRequestInterface $request, ResponseInterface $response) use ($reservations): ResponseInterface {
            $reservations->approve($request);

            return Responder::message($response, 'Reservation updated successfully.');
        });
        $app->post('/reservation/ai-unlock', function (ServerRequestInterface $request, ResponseInterface $response) use ($reservations): ResponseInterface {
            $reservations->unlockAiReview($request);

            return Responder::message($response, 'AI review unlocked.');
        });
        $app->post('/admin/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth): ResponseInterface {
            $auth->login($request);

            return self::cookies(Responder::message($response, 'Login successful.'), $auth);
        });
        $app->get('/admin/logout', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth): ResponseInterface {
            $auth->logout($request);

            return self::cookies(Responder::message($response, 'Logout successful.'), $auth);
        });
        $app->get('/admin/check-login', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $auth->check($request)));
        $app->get('/admin/list', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $catalog->admins($request)));
        $app->get('/admin/permissions', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $catalog->permissions($request)));
        $app->post('/admin/permissions/update', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->updatePermissions($request);

            return Responder::message($response, 'Administrator permissions updated.');
        });
        $app->post('/admin/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->createAdmin($request);

            return Responder::message($response, 'Admin created successfully.');
        });
        $app->post('/admin/edit', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editAdmin($request);

            return Responder::message($response, 'Admin edited successfully.');
        });
        $app->post('/admin/edit-password', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->editPassword($request);

            return Responder::message($response, 'Password changed successfully.');
        });
        $app->post('/admin/notification-settings', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->notificationSettings($request);

            return Responder::message($response, 'Reservation notification settings updated successfully.');
        });
        $app->post('/admin/delete', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->deleteAdmin($request);

            return Responder::message($response, 'Admin deleted successfully.');
        });
        $app->get('/analytics/overview', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $analytics->overview()));
        $app->get('/analytics/weekly', fn (ServerRequestInterface $request, ResponseInterface $response) => Responder::data($response, $analytics->weekly()));
        $csv = function (ServerRequestInterface $request, ResponseInterface $response) use ($analytics): ResponseInterface {
            $response->getBody()->write($analytics->export($request));
            $response->getBody()->rewind();

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="analytics.csv"');
        };
        $app->get('/analytics/overview/export', $csv);
        $app->get('/analytics/weekly/export', $csv);
        $app->post('/internal/outbox/process', function (ServerRequestInterface $request, ResponseInterface $response) use ($config, $jobs): ResponseInterface {
            $provided = $request->getHeaderLine('Authorization');
            $expected = 'Bearer ' . $config->queueProcessSecret;
            if ($config->queueProcessSecret === '' || !hash_equals($expected, $provided)) {
                throw new HttpException(401, 'Unauthorized.');
            }
            $body = $request->getAttribute('json');
            $jobId = (new Input(is_array($body) ? $body : []))->int('jobId');
            $jobs->processQueuedJob($jobId);

            return Responder::message($response, 'Job processed.');
        });
        $app->get('/tasks', function (ServerRequestInterface $request, ResponseInterface $response) use ($config, $outbox): ResponseInterface {
            $provided = $request->getHeaderLine('Authorization');
            $expected = 'Bearer ' . $config->taskPullSecret;
            if ($config->taskPullSecret === '' || !hash_equals($expected, $provided)) {
                throw new HttpException(401, 'Unauthorized.');
            }
            $limit = (new QueryInput($request->getQueryParams()))->optionalInt('limit') ?? 50;

            return Responder::data($response, ['tasks' => $outbox->claimDue($limit)]);
        });
        $app->post('/tasks/{taskId}/execute', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($config, $jobs): ResponseInterface {
            $provided = $request->getHeaderLine('Authorization');
            $expected = 'Bearer ' . $config->taskExecuteSecret;
            if ($config->taskExecuteSecret === '' || !hash_equals($expected, $provided)) {
                throw new HttpException(401, 'Unauthorized.');
            }
            $taskId = filter_var($args['taskId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($taskId === false) {
                throw new HttpException(400, 'Invalid task ID.');
            }
            $body = $request->getAttribute('json');
            $dispatchToken = (new Input(is_array($body) ? $body : []))->string('dispatchToken');
            $jobs->processQueuedJob((int) $taskId, $dispatchToken);

            return Responder::message($response, 'Task processed.');
        });
        $app->post('/catalog/invalidate', function (ServerRequestInterface $request, ResponseInterface $response) use ($catalog): ResponseInterface {
            $catalog->invalidateAndAudit();

            return Responder::message($response, 'Catalog cache invalidated.');
        });

        $cors = new CorsMiddleware($config);
        $app->add(new JsonMiddleware());
        $app->addRoutingMiddleware();
        $app->add($cors);
        $app->add(new RequestIdMiddleware($logger));
        $errors = $app->addErrorMiddleware(false, true, true);
        $errors->setDefaultErrorHandler(function (
            ServerRequestInterface $request,
            \Throwable $exception,
            bool $displayErrorDetails,
            bool $logErrors,
            bool $logErrorDetails,
        ) use ($config, $logger, $cors): ResponseInterface {
            $response = new Response();
            $requestId = $request->getAttribute('requestId');
            if (is_string($requestId) && $requestId !== '') {
                $response = $response->withHeader('x-request-id', $requestId);
            }
            $detail = $config->debug ? DebugError::payload($exception) : [];
            if ($exception instanceof HttpException) {
                if ($exception->status >= 500) {
                    $logger->error($exception->getMessage(), ['status' => $exception->status]);
                }
                $validation = $exception->status < 500
                    ? array_intersect_key($exception->detail, array_flip(['field', 'code']))
                    : [];

                return $cors->decorate($request, Responder::error($response, $exception->status, $exception->getMessage(), $detail, $validation));
            }
            if ($exception instanceof HttpNotFoundException) {
                return $cors->decorate($request, Responder::error($response, 404, 'Not found.', $detail));
            }
            if ($exception instanceof HttpMethodNotAllowedException) {
                return $cors->decorate($request, Responder::error($response, 405, 'Method not allowed.', $detail));
            }
            $logger->error($exception->getMessage(), [
                'type' => $exception::class,
                'trace' => substr($exception->getTraceAsString(), 0, 4000),
            ]);

            return $cors->decorate($request, Responder::error($response, 500, 'Internal server error.', $detail));
        });

        return $app;
    }

    private static function cookies(ResponseInterface $response, AuthService $auth): ResponseInterface
    {
        foreach ($auth->setCookies as $cookie) {
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }
        $auth->setCookies = [];

        return $response;
    }
}
