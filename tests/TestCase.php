<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Tests that exercise the installer set this to false to start from an uninstalled app. */
    protected bool $installed = true;

    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/thryve-test-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
        config([
            'thryve.install_marker' => $this->tmpDir.'/installed.json',
            'thryve.env_path' => $this->tmpDir.'/.env',
        ]);
        if ($this->installed) {
            file_put_contents($this->tmpDir.'/installed.json', '{}');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }
}
