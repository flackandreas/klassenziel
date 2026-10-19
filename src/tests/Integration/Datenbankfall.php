<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Integration;

use Klassenziel\Konten\Konto;
use Klassenziel\Sicherheit\Schluessel;
use Klassenziel\Support\Datenbank;
use Klassenziel\Support\Kern;
use Klassenziel\Support\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Grundlage der Tests gegen eine echte MariaDB.
 *
 * Nur mit TEST_DB_NAME, und nie gegen die Entwicklungsdatenbank: jeder Test
 * beginnt mit einem leeren Schema.
 */
abstract class Datenbankfall extends TestCase
{
    protected static ?PDO $db = null;

    protected function setUp(): void
    {
        $name = (string) (getenv('TEST_DB_NAME') ?: '');
        if ($name === '') {
            self::markTestSkipped('Keine Testdatenbank (TEST_DB_NAME).');
        }

        if (!str_ends_with($name, '_test')) {
            self::fail('TEST_DB_NAME muss auf _test enden - die Tests leeren die Datenbank.');
        }

        self::$db ??= Datenbank::oeffne(
            (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            (string) (getenv('TEST_DB_PORT') ?: '3306'),
            $name,
            (string) (getenv('TEST_DB_USER') ?: 'klassenziel'),
            (string) (getenv('TEST_DB_PASS') ?: ''),
            5,
        );

        self::leeren(self::$db);

        $vermerk = sys_get_temp_dir() . '/klassenziel-test-migration-' . getmypid();
        @unlink($vermerk);
        $ergebnis = (new Migrator(null, $vermerk))->einspielen(self::$db);
        self::assertSame([], $ergebnis['fehler']);

        Datenbank::setzeVerbindung(self::$db);
        Kern::zuruecksetzen();
        self::leereTestablage();
    }

    protected function tearDown(): void
    {
        Datenbank::setzeVerbindung(null);
        Kern::zuruecksetzen();
    }

    protected function db(): PDO
    {
        self::assertNotNull(self::$db);

        return self::$db;
    }

    protected function schluessel(): Schluessel
    {
        return Schluessel::ausWert(TEST_SCHLUESSEL);
    }

    protected function konto(string $name = 'Frau Beispiel', bool $verwaltung = false): Konto
    {
        $this->db()->prepare('INSERT INTO konten (sso_sub, name, ist_verwaltung) VALUES (?, ?, ?)')
            ->execute([bin2hex(random_bytes(8)), $name, $verwaltung ? 1 : 0]);

        $zeile = $this->db()->query('SELECT * FROM konten WHERE id = ' . (int) $this->db()->lastInsertId())->fetch();
        self::assertIsArray($zeile);

        return Konto::ausZeile($zeile);
    }

    /**
     * Die Umgebung fuer einen Kindprozess, der dieselbe Testdatenbank benutzt.
     *
     * @return array<string,string>
     */
    protected static function testumgebung(): array
    {
        return [
            'DB_HOST' => (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '3306'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USER' => (string) (getenv('TEST_DB_USER') ?: 'klassenziel'),
            'DB_PASS' => (string) (getenv('TEST_DB_PASS') ?: ''),
            'KLASSENZIEL_SCHLUESSEL' => TEST_SCHLUESSEL,
            'TZ' => 'Europe/Berlin',
            'APP_URL' => 'http://127.0.0.1',
            'AUTO_MIGRATE' => '0',
            'TRUSTED_PROXIES' => '',
            'KLASSENZIEL_ABLAGE' => self::testablage(),
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /** Ablage des Testservers - getrennt von der der Entwicklungsinstanz. */
    protected static function testablage(): string
    {
        return sys_get_temp_dir() . '/klassenziel-testserver-ablage';
    }

    /**
     * Leert den Zwischenspeicher des Testservers. Jeder Test beginnt mit
     * einer leeren Datenbank; Klasse 1 eines Tests ist nicht Klasse 1 des
     * naechsten.
     */
    protected static function leereTestablage(): void
    {
        foreach (glob(self::testablage() . '/cache/stand/*') ?: [] as $datei) {
            @unlink($datei);
        }
    }

    private static function leeren(PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tabelle) {
            $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $tabelle) . '`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
