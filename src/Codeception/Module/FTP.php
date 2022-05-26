<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\TestInterface;
use Codeception\Exception\ModuleException;

/**
 *
 * Works with SFTP/FTP servers.
 *
 * In order to test the contents of a specific file stored on any remote FTP/SFTP system
 * this module downloads a temporary file to the local system. The temporary directory is
 * defined by default as ```tests/_data``` to specify a different directory set the tmp config
 * option to your chosen path.
 *
 * Don't forget to create the folder and ensure its writable.
 *
 * Supported and tested FTP types are:
 *
 * * FTP
 * * SFTP
 *
 * Connection uses php build in FTP client for FTP,
 * connection to SFTP uses [phpseclib](http://phpseclib.sourceforge.net/) pulled in using composer.
 *
 * For SFTP, add [phpseclib](http://phpseclib.sourceforge.net/) to require list.
 * ```
 * "require": {
 *  "phpseclib/phpseclib": "^2.0.14"
 * }
 * ```
 *
 * ## Status
 *
 * * Stability:
 *     - FTP: **stable**
 *     - SFTP: **stable**
 *
 * ## Config
 *
 * * type: ftp - type of connection ftp/sftp (defaults to ftp).
 * * host *required* - hostname/ip address of the ftp server.
 * * port: 21 - port number for the ftp server
 * * timeout: 90 - timeout settings for connecting the ftp server.
 * * user: anonymous - user to access ftp server, defaults to anonymous authentication.
 * * password - password, defaults to empty for anonymous.
 * * key - path to RSA key for sftp.
 * * tmp - path to local directory for storing tmp files.
 * * passive: true - Turns on or off passive mode (FTP only)
 * * cleanup: true - remove tmp files from local directory on completion.
 *
 * ### Example
 * #### Example (FTP)
 *
 *     modules:
 *        enabled: [FTP]
 *        config:
 *           FTP:
 *              type: ftp
 *              host: '127.0.0.1'
 *              port: 21
 *              timeout: 120
 *              user: 'root'
 *              password: 'root'
 *              key: ~/.ssh/id_rsa
 *              tmp: 'tests/_data/ftp'
 *              passive: true
 *              cleanup: false
 *
 * #### Example (SFTP)
 *
 *     modules:
 *        enabled: [FTP]
 *        config:
 *           FTP:
 *              type: sftp
 *              host: '127.0.0.1'
 *              port: 22
 *              timeout: 120
 *              user: 'root'
 *              password: 'root'
 *              key: ''
 *              tmp: 'tests/_data/ftp'
 *              cleanup: false
 *
 *
 * This module extends the Filesystem module, file contents methods are inherited from this module.
 */
class FTP extends Filesystem
{
    /**
     * FTP/SFTP connection handler
     * @var null|bool|\Net_SFTP|\phpseclib\Net\SFTP|resource
     */
    protected $ftp = null;

    /**
     * Configuration options and default settings
     */
    protected array $config = [
        'type'     => 'ftp',
        'port'     => 21,
        'timeout'  => 90,
        'user'     => 'anonymous',
        'password' => '',
        'key'      => '',
        'tmp'      => 'tests/_data',
        'passive'  => false,
        'cleanup'  => true
    ];

    /**
     * Required configuration fields
     *
     * @var string[]
     */
    protected array $requiredFields = ['host'];

    // ----------- SETUP METHODS BELOW HERE -------------------------//

    /**
     * Setup connection and login with config settings
     */
    public function _before(TestInterface $test): void
    {
        // Login using config settings
        $this->loginAs($this->config['user'], $this->config['password']);
    }

    /**
     * Close the FTP connection & Clear up
     */
    public function _after(TestInterface $test): void
    {
        $this->_closeConnection();

        // Clean up temp files
        if ($this->config['cleanup'] && file_exists($this->config['tmp'] . '/ftp_data_file.tmp')) {
            unlink($this->config['tmp'] . '/ftp_data_file.tmp');
        }
    }

    /**
     * Change the logged in user mid-way through your test, this closes the
     * current connection to the server and initialises and new connection.
     *
     * On initiation of this modules you are automatically logged into
     * the server using the specified config options or defaulted
     * to anonymous user if not provided.
     *
     * ``` php
     * <?php
     * $I->loginAs('user','password');
     * ```
     */
    public function loginAs(string $user = 'anonymous', string $password = ''): void
    {
        $this->_openConnection($user, $password); // Create new connection and login.
    }

    /**
     * Enters a directory on the ftp system - FTP root directory is used by default
     */
    public function amInPath(string $path): void
    {
        $this->_changeDirectory($this->path = $this->absolutizePath($path) . ($path == '/' ? '' : DIRECTORY_SEPARATOR));
        $this->debug('Moved to ' . $this->path);
    }

    /**
     * Resolve path
     */
    protected function absolutizePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->path . $path;
    }

    // ----------- SEARCH METHODS BELOW HERE ------------------------//

    /**
     * Checks if file exists in path on the remote FTP/SFTP system.
     * DOES NOT OPEN the file when it's exists
     *
     * ``` php
     * <?php
     * $I->seeFileFound('UserModel.php','app/models');
     * ```
     */
    public function seeFileFound(string $filename, string $path = ''): void
    {
        $files = $this->grabFileList($path);
        $this->debug("see file: {$filename}");
        $this->assertContains($filename, $files, "file {$filename} not found in {$path}");
    }

    /**
     * Checks if file exists in path on the remote FTP/SFTP system, using regular expression as filename.
     * DOES NOT OPEN the file when it's exists
     *
     *  ``` php
     * <?php
     * $I->seeFileFoundMatches('/^UserModel_([0-9]{6}).php$/','app/models');
     * ```
     */
    public function seeFileFoundMatches(string $regex, string $path = ''): void
    {
        foreach ($this->grabFileList($path) as $filename) {
            preg_match($regex, $filename, $matches);
            if (!empty($matches)) {
                $this->debug("file '{$filename}' matches '{$regex}'");
                return;
            }
        }

        $this->fail("no file matches found for '{$regex}'");
    }

    /**
     * Checks if file does not exist in path on the remote FTP/SFTP system
     */
    public function dontSeeFileFound(string $filename, string $path = ''): void
    {
        $files = $this->grabFileList($path);
        $this->debug("don't see file: {$filename}");
        $this->assertNotContains($filename, $files);
    }

    /**
     * Checks if file does not exist in path on the remote FTP/SFTP system, using regular expression as filename.
     * DOES NOT OPEN the file when it's exists
     */
    public function dontSeeFileFoundMatches(string $regex, string $path = ''): void
    {
        foreach ($this->grabFileList($path) as $filename) {
            preg_match($regex, $filename, $matches);
            if (!empty($matches)) {
                $this->fail("file matches found for {$regex}");
            }
        }

        $this->assertTrue(true);
        $this->debug("no files match '{$regex}'");
    }

    // ----------- UTILITY METHODS BELOW HERE -------------------------//

    /**
     * Opens a file (downloads from the remote FTP/SFTP system to a tmp directory for processing)
     * and stores it's content.
     *
     * Usage:
     *
     * ``` php
     * <?php
     * $I->openFile('composer.json');
     * $I->seeInThisFile('codeception/codeception');
     * ```
     */
    public function openFile(string $filename): void
    {
        $this->_openFile($this->absolutizePath($filename));
    }

    /**
     * Saves contents to tmp file and uploads the FTP/SFTP system.
     * Overwrites current file on server if exists.
     *
     * ``` php
     * <?php
     * $I->writeToFile('composer.json', 'some data here');
     * ```
     */
    public function writeToFile(string $filename, string $contents): void
    {
        $this->_writeToFile($this->absolutizePath($filename), $contents);
    }

    /**
     * Create a directory on the server
     *
     * ``` php
     * <?php
     * $I->makeDir('vendor');
     * ```
     */
    public function makeDir(string $dirname): void
    {
        $this->makeDirectory($this->absolutizePath($dirname));
    }

    /**
     * Currently not supported in this module, overwrite inherited method
     */
    public function copyDir(string $src, string $dst): void
    {
        $this->fail('copyDir() currently unsupported by FTP module');
    }

    /**
     * Rename/Move file on the FTP/SFTP server
     *
     * ``` php
     * <?php
     * $I->renameFile('composer.lock', 'composer_old.lock');
     * ```
     */
    public function renameFile(string $filename, string $rename): void
    {
        $this->renameDirectory($this->absolutizePath($filename), $this->absolutizePath($rename));
    }

    /**
     * Rename/Move directory on the FTP/SFTP server
     *
     * ``` php
     * <?php
     * $I->renameDir('vendor', 'vendor_old');
     * ```
     */
    public function renameDir(string $dirname, string $rename): void
    {
        $this->renameDirectory($this->absolutizePath($dirname), $this->absolutizePath($rename));
    }

    /**
     * Deletes a file on the remote FTP/SFTP system
     *
     * ``` php
     * <?php
     * $I->deleteFile('composer.lock');
     * ```
     */
    public function deleteFile(string $filename): void
    {
        $this->delete($this->absolutizePath($filename));
    }

    /**
     * Deletes directory with all subdirectories on the remote FTP/SFTP server
     *
     * ``` php
     * <?php
     * $I->deleteDir('vendor');
     * ```
     */
    public function deleteDir(string $dirname): void
    {
        $this->delete($this->absolutizePath($dirname));
    }

    /**
     * Erases directory contents on the FTP/SFTP server
     *
     * ``` php
     * <?php
     * $I->cleanDir('logs');
     * ```
     */
    public function cleanDir(string $dirname): void
    {
        $this->clearDirectory($this->absolutizePath($dirname));
    }

    // ----------- GRABBER METHODS BELOW HERE -----------------------//

    /**
     * Grabber method for returning file/folders listing in an array
     *
     * ```php
     * <?php
     * $files = $I->grabFileList();
     * $count = $I->grabFileList('TEST', false); // Include . .. .thumbs.db
     * ```
     *
     * @param bool $ignore - suppress '.', '..' and '.thumbs.db'
     */
    public function grabFileList(string $path = '', bool $ignore = true): array
    {
        $absolutizePath = $this->absolutizePath($path)
            . ($path != '' && !str_ends_with($path, '/') ? DIRECTORY_SEPARATOR : '');
        $files = $this->_listFiles($absolutizePath);

        $display_files = [];
        if (is_array($files) && !empty($files)) {
            $this->debug('File List:');
            foreach ($files as &$file) {
                if (strtolower($file) != '.' &&
                    strtolower($file) != '..' &&
                    strtolower($file) != 'thumbs.db'
                ) { // Ignore '.', '..' and 'thumbs.db'
                    // Replace full path from file listings if returned in listing
                    $file = str_replace(
                        $absolutizePath,
                        '',
                        $file
                    );
                    $display_files[] = $file;
                    $this->debug('    - ' . $file);
                }
            }

            return $ignore ? $display_files : $files;
        }

        $this->debug("File List: <empty>");
        return [];
    }

    /**
     * Grabber method for returning file/folders count in directory
     *
     * ```php
     * <?php
     * $count = $I->grabFileCount();
     * $count = $I->grabFileCount('TEST', false); // Include . .. .thumbs.db
     * ```
     *
     * @param bool $ignore - suppress '.', '..' and '.thumbs.db'
     */
    public function grabFileCount(string $path = '', bool $ignore = true): int
    {
        $count = count($this->grabFileList($path, $ignore));
        $this->debug("File Count: {$count}");
        return $count;
    }

    /**
     * Grabber method to return file size
     *
     * ```php
     * <?php
     * $size = $I->grabFileSize('test.txt');
     * ```
     */
    public function grabFileSize(string $filename): int
    {
        $fileSize = $this->size($filename);
        $this->debug(sprintf('%s has a file size of %s', $filename, $fileSize));
        return $fileSize;
    }

    /**
     * Grabber method to return last modified timestamp
     *
     * ```php
     * <?php
     * $time = $I->grabFileModified('test.txt');
     * ```
     */
    public function grabFileModified(string $filename): int
    {
        $time = $this->modified($filename);
        $this->debug("{$filename} was last modified at {$time}");
        return $time;
    }

    /**
     * Grabber method to return current working directory
     *
     * ```php
     * <?php
     * $pwd = $I->grabDirectory();
     * ```
     */
    public function grabDirectory(): string
    {
        $pwd = $this->_directory();
        $this->debug("PWD: {$pwd}");
        return $pwd;
    }

    // ----------- SERVER CONNECTION METHODS BELOW HERE -------------//

    /**
     * Open a new FTP/SFTP connection and authenticate user.
     */
    private function _openConnection(string $user = 'anonymous', string $password = ''): void
    {
        $this->_closeConnection();   // Close connection if already open
        if ($this->isSFTP()) {
            $this->sftpConnect($user, $password);
        } else {
            $this->ftpConnect($user, $password);
        }

        $pwd = $this->grabDirectory();
        $this->path = $pwd . ($pwd == '/' ? '' : DIRECTORY_SEPARATOR);
    }

    /**
     * Close open FTP/SFTP connection
     */
    private function _closeConnection(): void
    {
        if (!$this->ftp) {
            return;
        }

        if (!$this->isSFTP()) {
            ftp_close($this->ftp);
            $this->ftp = null;
        }
    }

    /**
     * Get the file listing for FTP/SFTP connection
     *
     * @return string[]
     */
    private function _listFiles(string $path): array
    {
        $files = $this->isSFTP() ? @$this->ftp->nlist($path) : @ftp_nlist($this->ftp, $path);

        if ($files === false) {
            $this->fail("couldn't list files");
        }

        return $files;
    }

    /**
     * Get the current directory for the FTP/SFTP connection
     */
    private function _directory(): string
    {
        $pwd = $this->isSFTP() ? @$this->ftp->pwd() : @ftp_pwd($this->ftp);

        if (!$pwd) {
            $this->fail("couldn't get current directory");
        }

        return $pwd;
    }

    /**
     * Change the working directory on the FTP/SFTP server
     */
    private function _changeDirectory(string $path): void
    {
        $changed = $this->isSFTP() ? @$this->ftp->chdir($path) : @ftp_chdir($this->ftp, $path);

        if (!$changed) {
            $this->fail("couldn't change directory {$path}");
        }
    }

    /**
     * Download remote file to local tmp directory and open contents.
     */
    private function _openFile(string $filename): void
    {
        // Check local tmp directory
        if (!is_dir($this->config['tmp']) || !is_writable($this->config['tmp'])) {
            $this->fail('tmp directory not found or is not writable');
        }

        // Download file to local tmp directory
        $tmp_file = $this->config['tmp'] . "/ftp_data_file.tmp";

        if ($this->isSFTP()) {
            $downloaded = @$this->ftp->get($filename, $tmp_file);
        } else {
            $downloaded = @ftp_get($this->ftp, $tmp_file, $filename, FTP_BINARY);
        }

        if (!$downloaded) {
            $this->fail('failed to download file to tmp directory');
        }

        // Open file content to variable
        if ($this->file = file_get_contents($tmp_file)) {
            $this->filePath = $filename;
        } else {
            $this->fail('failed to open tmp file');
        }
    }

    /**
     * Write data to local tmp file and upload to server
     */
    private function _writeToFile(string $filename, string $contents): void
    {
        // Check local tmp directory
        if (!is_dir($this->config['tmp']) || !is_writable($this->config['tmp'])) {
            $this->fail('tmp directory not found or is not writable');
        }

        // Build temp file
        $tmp_file = $this->config['tmp'] . "/ftp_data_file.tmp";
        file_put_contents($tmp_file, $contents);

        // Update variables
        $this->filePath = $filename;
        $this->file = $contents;

        // Upload the file to server
        if ($this->isSFTP()) {
            $flag = defined('NET_SFTP_LOCAL_FILE') ? NET_SFTP_LOCAL_FILE : \phpseclib\Net\SFTP::SOURCE_LOCAL_FILE;

            $uploaded = @$this->ftp->put($filename, $tmp_file, $flag);
        } else {
            $uploaded = ftp_put($this->ftp, $filename, $tmp_file, FTP_BINARY);
        }

        if (!$uploaded) {
            $this->fail('failed to upload file to server');
        }
    }

    /**
     * Make new directory on server
     */
    private function makeDirectory(string $path): void
    {
        $created = $this->isSFTP() ? @$this->ftp->mkdir($path, true) : @ftp_mkdir($this->ftp, $path);

        if (!$created) {
            $this->fail("couldn't make directory {$path}");
        }

        $this->debug("Make directory: {$path}");
    }

    /**
     * Rename/Move directory/file on server
     */
    private function renameDirectory(string $path, string $rename): void
    {
        $renamed = $this->isSFTP() ? @$this->ftp->rename($path, $rename) : @ftp_rename($this->ftp, $path, $rename);

        if (!$renamed) {
            $this->fail("couldn't rename directory {$path} to {$rename}");
        }

        $this->debug(sprintf('Renamed directory: %s to %s', $path, $rename));
    }

    /**
     * Delete file on server
     */
    private function delete(string $filename, bool $isDir = false): void
    {
        $deleted = $this->isSFTP() ? @$this->ftp->delete($filename, $isDir) : @$this->ftpDelete($filename);

        if (!$deleted) {
            $this->fail("couldn't delete {$filename}");
        }

        $this->debug("Deleted: {$filename}");
    }


    /**
     * Function to recursively delete folder, used for PHP FTP build in client.
     */
    private function ftpDelete(string $directory): bool
    {
        // here we attempt to delete the file/directory
        if (!@ftp_rmdir($this->ftp, $directory) && !@ftp_delete($this->ftp, $directory)) {
            // if the attempt to delete fails, get the file listing
            $fileList = @ftp_nlist($this->ftp, $directory);

            // loop through the file list and recursively delete the FILE in the list
            foreach ($fileList as $file) {
                $this->ftpDelete($file);
            }

            // if the file list is empty, delete the DIRECTORY we passed
            $this->ftpDelete($directory);
        }

        return true;
    }

    /**
     * Clear directory on server of all content
     */
    private function clearDirectory(string $path): void
    {
        $this->debug("Clear directory: {$path}");
        $this->delete($path);
        $this->makeDirectory($path);
    }

    /**
     * Return the size of a given file
     */
    private function size(string $filename): int
    {
        $size = $this->isSFTP() ? (int)@$this->ftp->size($filename) : @ftp_size($this->ftp, $filename);

        if ($size < 0) {
            $this->fail("couldn't get the file size for {$filename}");
        }

        return $size;
    }

    /**
     * Return the last modified time of a given file
     */
    private function modified(string $filename): int
    {
        if ($this->isSFTP()) {
            $info = @$this->ftp->lstat($filename);
            if ($info) {
                return $info['mtime'];
            }
        } elseif (($time = @ftp_mdtm($this->ftp, $filename)) !== 0) {
            return $time;
        }

        $this->fail("couldn't get the file size for {$filename}");
    }

    protected function sftpConnect(string $user, string $password): void
    {
        if (class_exists('Net_SFTP')) {
            $this->ftp = new \Net_SFTP($this->config['host'], $this->config['port'], $this->config['timeout']);
        } elseif (class_exists(\phpseclib\Net\SFTP::class)) {
            $this->ftp = new \phpseclib\Net\SFTP($this->config['host'], $this->config['port'], $this->config['timeout']);
        } else {
            throw new ModuleException('FTP', 'phpseclib/phpseclib library is not installed');
        }

        if (!empty($this->config['key'])) {
            $keyFile = file_get_contents($this->config['key']);

            if (class_exists('Crypt_RSA')) {
                $password = new \Crypt_RSA();
            } elseif (class_exists(\phpseclib\Crypt\RSA::class)) {
                $password = new \phpseclib\Crypt\RSA();
            } else {
                throw new ModuleException('FTP', 'phpseclib/phpseclib library is not installed');
            }

            $password->loadKey($keyFile);
        }

        if (!$this->ftp->login($user, $password)) {
            $this->fail('failed to authenticate user');
        }
    }

    protected function ftpConnect(string $user, string $password): void
    {
        $this->ftp = ftp_connect($this->config['host'], $this->config['port'], $this->config['timeout']);
        if ($this->ftp === false) {
            $this->ftp = null;
            $this->fail('failed to connect to ftp server');
        }

        // Login using given access details
        if (!@ftp_login($this->ftp, $user, $password)) {
            $this->fail('failed to authenticate user');
        }

        // Set passive mode option (ftp only option)
        if (isset($this->config['passive'])) {
            ftp_pasv($this->ftp, $this->config['passive']);
        }
    }

    protected function isSFTP(): bool
    {
        return strtolower($this->config['type']) === 'sftp';
    }
}
