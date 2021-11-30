<?php

declare(strict_types=1);

use Codeception\Module\FTP;
use Codeception\PHPUnit\TestCase;
use Codeception\Util\Stub;

final class SFTPTest extends TestCase
{
    protected array $config = [
        'host' => '127.0.0.1',
        'port' => 22,
        'tmp' => 'temp',
        'user' => 'user',
        'password' => 'password',
        'type' => 'sftp'
    ];

    protected ?FTP $module = null;

    public function _setUp()
    {
        $container = Stub::make('Codeception\Lib\ModuleContainer');
        $this->module = new FTP($container);
        $this->module->_setConfig($this->config);
    }

    public function testFlow()
    {
        $this->markTestSkipped('Requires SFTP server');

        $this->module->_before(Stub::makeEmpty('\Codeception\Test\Test'));

        self::assertEquals('/', $this->module->grabDirectory());

        $this->module->makeDir('TESTING');
        $this->module->amInPath('TESTING');
        self::assertEquals('/TESTING', $this->module->grabDirectory());

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

        $this->module->amInPath('/');

        self::assertEquals('/', $this->module->grabDirectory());

        $this->module->renameDir('TESTING', 'TESTING_NEW');

        $this->module->deleteDir('TESTING_NEW');

        // Test Clearing the Directory
        $this->module->makeDir('TESTING');
        $this->module->amInPath('TESTING');
        $this->module->writeToFile('test_ftp_123.txt', 'some data added here');
        $this->module->amInPath('/');
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
