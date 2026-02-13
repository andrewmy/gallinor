<?php

declare(strict_types=1);

namespace App\Tests\Unit\Images\Domain;

use App\Images\Domain\HeicCodec;
use App\Tests\Shared\StubPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function chmod;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_decode;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class HeicCodecTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/gallinor-heic-codec-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->tmpDir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                unlink($this->tmpDir . '/' . $entry);
            }
        }

        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_decode_uses_heif_dec_with_output_flag(): void
    {
        $heifEncPath = $this->createFakeHeifEnc();
        $argvLogPath = $this->tmpDir . '/heif-dec-argv.json';
        $heifDecPath = $this->createFakeHeifDec($argvLogPath);

        $platform = new StubPlatform();
        $platform->setTool('heif-enc', $heifEncPath);
        $platform->setTool('heif-dec', $heifDecPath);

        $codec = new HeicCodec($platform);

        $targetPath = $this->tmpDir . '/candidate.png';
        $codec->decodeToPng($this->tmpDir . '/source.heic', $targetPath);

        $argv = self::readLoggedArgv($argvLogPath);

        self::assertSame($heifDecPath, $argv[0]);
        self::assertContains('-o', $argv);
        self::assertContains($targetPath, $argv);
        self::assertContains($this->tmpDir . '/source.heic', $argv);
        self::assertTrue(file_exists($targetPath));
    }

    public function test_constructor_throws_when_heif_dec_is_missing(): void
    {
        $heifEncPath = $this->createFakeHeifEnc();

        $platform = new StubPlatform();
        $platform->setTool('heif-enc', $heifEncPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool not found: heif-dec');

        new HeicCodec($platform);
    }

    private function createFakeHeifEnc(): string
    {
        $path = $this->tmpDir . '/fake-heif-enc.php';
        file_put_contents(
            $path,
            <<<'PHP'
#!/usr/bin/env php
<?php
exit(0);
PHP,
        );
        chmod($path, 0o755);

        return $path;
    }

    private function createFakeHeifDec(string $argvLogPath): string
    {
        $path = $this->tmpDir . '/fake-heif-dec.php';
        file_put_contents(
            $path,
            sprintf(
                <<<'PHP'
#!/usr/bin/env php
<?php
$logPath = %s;
file_put_contents($logPath, json_encode($argv));
$targetIndex = array_search('-o', $argv, true);
if (! is_int($targetIndex) || ! isset($argv[$targetIndex + 1])) {
    fwrite(STDERR, "missing -o output\n");
    exit(1);
}
$target = $argv[$targetIndex + 1];
file_put_contents($target, "ok");
PHP,
                var_export($argvLogPath, true),
            ),
        );
        chmod($path, 0o755);

        return $path;
    }

    /** @return list<string> */
    private static function readLoggedArgv(string $argvLogPath): array
    {
        $decoded = json_decode((string) file_get_contents($argvLogPath), true);

        self::assertTrue(is_array($decoded));
        self::assertNotSame([], $decoded);
        self::assertSame(count($decoded), count(array_filter($decoded, static fn (mixed $value): bool => is_string($value))));

        /** @var list<string> $decodedStrings */
        $decodedStrings = $decoded;

        return $decodedStrings;
    }
}
