<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

/**
 * Der eingebaute Server von PHP mit dem Front Controller und der
 * Testdatenbank - fuer alles, was sich nur ueber echtes HTTP pruefen laesst
 * (Kopfzeilen, Cookies, das ausgelieferte HTML).
 */
trait Testserver
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    private static function starteServer(): void
    {
        if (is_resource(self::$server)) {
            return;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($name, strrpos($name, ':') + 1);

        $wurzel = dirname(__DIR__, 2);
        $prozess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $wurzel . '/public', $wurzel . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir() . '/klassenziel-testserver.log', 'w']],
            $pipes,
            $wurzel,
            self::testumgebung()
        );
        self::assertIsResource($prozess);
        self::$server = $prozess;

        for ($i = 0; $i < 50; $i++) {
            $verbindung = @fsockopen('127.0.0.1', self::$port, $nr, $text, 0.1);
            if ($verbindung !== false) {
                fclose($verbindung);

                return;
            }
            usleep(100_000);
        }

        self::fail('Der Testserver startet nicht.');
    }

    /**
     * @return array{status: int, koepfe: array<string,string>, inhalt: string}
     */
    private static function hole(string $pfad): array
    {
        $kontext = stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);

        $inhalt = @file_get_contents('http://127.0.0.1:' . self::$port . $pfad, false, $kontext);
        /** @var list<string> $roh */
        $roh = $http_response_header ?? [];

        $status = 0;
        $koepfe = [];
        foreach ($roh as $zeile) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $zeile, $treffer) === 1) {
                $status = (int) $treffer[1];
                continue;
            }
            [$name, $wert] = array_pad(explode(':', $zeile, 2), 2, '');
            $koepfe[strtolower(trim($name))] = trim($wert);
        }

        return ['status' => $status, 'koepfe' => $koepfe, 'inhalt' => (string) $inhalt];
    }
}
