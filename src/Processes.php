<?php

declare(strict_types=1);

namespace DbAdmin;

/**
 * The signed-in user's own connections to the server, and stopping them.
 *
 * Without the PROCESS privilege a user sees only its own threads anyway; the
 * list is filtered to the user all the same, so a session never sees or
 * stops another account's queries even where the privilege is broader. The
 * connection doing the listing is left out.
 */
final class Processes
{
    public function __construct(private readonly Client $client, private readonly string $user) {}

    /**
     * @return list<array{id: int, database: ?string, command: string, seconds: int, state: ?string, query: ?string}>
     */
    public function all(): array
    {
        $own = (int) $this->client->value('SELECT CONNECTION_ID()');
        $processes = [];

        foreach ($this->client->select('SHOW FULL PROCESSLIST') as $row) {
            if ((string) $row['User'] !== $this->user || (int) $row['Id'] === $own) {
                continue;
            }

            $processes[] = [
                'id' => (int) $row['Id'],
                'database' => $row['db'],
                'command' => (string) $row['Command'],
                'seconds' => (int) $row['Time'],
                'state' => $row['State'] === '' ? null : $row['State'],
                'query' => $row['Info'] === null ? null : mb_strcut((string) $row['Info'], 0, 2000, 'UTF-8'),
            ];
        }

        return $processes;
    }

    /**
     * Stop a process's running query, or the whole connection.
     */
    public function kill(mixed $id, bool $connection): void
    {
        foreach ($this->all() as $process) {
            if ($process['id'] === (is_numeric($id) ? (int) $id : -1)) {
                $this->client->query(sprintf('KILL %s %d', $connection ? 'CONNECTION' : 'QUERY', $process['id']));

                return;
            }
        }

        throw new UserError('That process has already finished.', 404);
    }
}
