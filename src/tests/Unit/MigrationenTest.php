<?php

declare(strict_types=1);

namespace Klassenziel\Tests\Unit;

use Klassenziel\Support\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Die Reihenfolge der Migrationen steht im Code, nicht im Verzeichnis. Wer
 * eine Datei anlegt und das Eintragen vergisst, merkt sonst nichts - im Portal
 * ist genau das beim Anlegen von 007 passiert.
 */
final class MigrationenTest extends TestCase
{
    public function testJedeDateiIstEingetragenUndUmgekehrt(): void
    {
        $vorhanden = array_map('basename', glob(dirname(__DIR__, 2) . '/migrationen/*.sql') ?: []);
        sort($vorhanden);

        $eingetragen = Migrator::dateien();
        $sortiert = $eingetragen;
        sort($sortiert);

        self::assertSame($vorhanden, $sortiert);
        self::assertSame($sortiert, $eingetragen, 'Die Dateien sind in aufsteigender Reihenfolge eingetragen.');
    }

    public function testSemikolonImKommentarZerreisstNichts(): void
    {
        $anweisungen = Migrator::anweisungen("-- Hinweis; mit Semikolon\nCREATE TABLE a (x INT);\n\n-- noch einer;\nCREATE TABLE b (y INT);\n");

        self::assertSame(['CREATE TABLE a (x INT)', 'CREATE TABLE b (y INT)'], $anweisungen);
    }

    public function testKeineAnweisungIstLeer(): void
    {
        foreach (Migrator::dateien() as $datei) {
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrationen/' . $datei);
            $anweisungen = Migrator::anweisungen($sql);

            self::assertNotEmpty($anweisungen, $datei);
            foreach ($anweisungen as $anweisung) {
                self::assertMatchesRegularExpression('/^(CREATE|INSERT|ALTER|UPDATE)\b/', $anweisung, $datei);
            }
        }
    }
}
