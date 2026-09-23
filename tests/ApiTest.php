<?php

declare(strict_types=1);

namespace DbAdmin\Tests;

use DbAdmin\Api;
use DbAdmin\Connection;
use DbAdmin\Session;
use DbAdmin\UserError;

/**
 * What the API lets a session do, before any of its work begins.
 */
final class ApiTest extends TestCase
{
    public function testReadOnlySessionsCannotChangeAnything(): void
    {
        $this->client('CREATE TABLE t (id INT PRIMARY KEY)');
        $target = $this->target();
        $dir = self::tempDir();
        $config = $this->config(['session' => ['save_path' => $dir, 'secure' => false]]);
        $session = new Session($config);

        $session->signIn(['user' => $target['user'], 'password' => $target['password'], 'database' => null, 'label' => 'x', 'readonly' => true]);
        $csrf = $session->csrf();
        session_write_close();

        $api = new Api($config, $session, new Connection($config));
        $query = ['db' => $target['database'], 'table' => 't'];

        foreach ([['insert', ['values' => ['id' => '1']]], ['update', ['key' => ['id' => '1'], 'values' => ['id' => '2']]], ['delete', ['keys' => [['id' => '1']]]], ['table', ['tables' => ['t'], 'operation' => 'drop']], ['schema', ['change' => ['operation' => 'drop-column', 'table' => 't', 'name' => 'id'], 'preview' => true]], ['drop-object', ['type' => 'view', 'name' => 'v']]] as [$action, $body]) {
            $error = self::assertThrows(UserError::class, fn () => $api->handle('POST', $action, $query, $body, $csrf), 'read-only');
            self::assertSame(403, $error->status);
        }

        self::assertSame(['t'], array_column($api->handle('GET', 'tables', $query, []), 'name'));
        session_write_close();
    }
}
