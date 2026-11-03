<?php

declare(strict_types=1);

use Codeception\Module\FTP;
use Codeception\PHPUnit\TestCase;
use Codeception\Stub;

final class SFTPTest extends TestCase
{
    protected ?FTP $module = null;

    /**
     * Writable directory on the SFTP server the test is run in
     */
    protected string $root = '/';

    public function _setUp()
    {
        $this->root = getenv('SFTP_ROOT') ?: '/';

        $container = Stub::make('Codeception\Lib\ModuleContainer');
        $this->module = new FTP($container);
        $this->module->_setConfig([
            'host' => getenv('SFTP_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('SFTP_PORT') ?: 22),
            'tmp' => sys_get_temp_dir(),
            'user' => getenv('SFTP_USER') ?: 'user',
            'password' => getenv('SFTP_PASSWORD') ?: 'password',
            'type' => 'sftp'
        ]);
    }

    public function testFlow()
    {
        if (!getenv('SFTP_HOST')) {
            $this->markTestSkipped('Requires SFTP server, set SFTP_HOST to run this test');
        }

        $this->module->_before(Stub::makeEmpty('\Codeception\Test\Test'));

        $this->module->amInPath($this->root);
        self::assertEquals($this->root, $this->module->grabDirectory());

        $this->module->makeDir('TESTING');
        $this->module->amInPath('TESTING');
        self::assertEquals(rtrim($this->root, '/') . '/TESTING', $this->module->grabDirectory());

        $files = $this->module->grabFileList();
        $this->module->writeToFile('test_ftp_123.txt', 'some data added here');
        $this->module->writeToFile('test_ftp_567.txt', 'some data added here');
        $this->module->writeToFile('test_ftp_678.txt', 'some data added here');

        $files = $this->module->grabFileList();
        self::assertContains('test_ftp_123.txt', $files);
        self::assertContains('test_ftp_567.txt', $files);
        self::assertContains('test_ftp_678.txt', $files);

        $this->module->seeFileFound('test_ftp_123.txt');
        $this->module->dontSeeFileFound('test_ftp_321.txt');
        $this->module->seeFileFoundMatches('/^test_ftp_([0-9]{3}).txt$/');
        $this->module->dontSeeFileFoundMatches('/^test_([0-9]{3})_ftp.txt$/');

        self::assertGreaterThan(0, $this->module->grabFileCount());
        self::assertGreaterThan(0, $this->module->grabFileSize('test_ftp_678.txt'));
        self::assertGreaterThan(0, $this->module->grabFileModified('test_ftp_678.txt'));

        $this->module->openFile('test_ftp_567.txt');
        $this->module->deleteThisFile();
        $this->module->dontSeeFileFound('test_ftp_567.txt');

        $this->module->openFile('test_ftp_123.txt');
        $this->module->seeInThisFile('data');

        $this->module->dontSeeInThisFile('banana');
        $this->module->seeFileContentsEqual('some data added here');

        $this->module->renameFile('test_ftp_678.txt', 'test_ftp_987.txt');

        $files = $this->module->grabFileList();
        self::assertNotContains('test_ftp_678.txt', $files);
        self::assertContains('test_ftp_987.txt', $files);

        $this->module->deleteFile('test_ftp_123.txt');

        $files = $this->module->grabFileList();
        self::assertNotContains('test_ftp_123.txt', $files);

        $this->module->amInPath($this->root);

        self::assertEquals($this->root, $this->module->grabDirectory());

        $this->module->renameDir('TESTING', 'TESTING_NEW');

        $this->module->deleteDir('TESTING_NEW');

        // Test Clearing the Directory
        $this->module->makeDir('TESTING');
        $this->module->amInPath('TESTING');
        $this->module->writeToFile('test_ftp_123.txt', 'some data added here');
        $this->module->amInPath($this->root);
        self::assertGreaterThan(0, $this->module->grabFileCount('TESTING'));
        $this->module->cleanDir('TESTING');
        self::assertEquals(0, $this->module->grabFileCount('TESTING'));
        $this->module->deleteDir('TESTING');
    }

    public function _tearDown()
    {
        $this->module->_after(Stub::makeEmpty('\Codeception\Test\Test'));
    }
}
