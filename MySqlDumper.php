<?php

namespace ArturDoruch\MySqlDumper;

/**
 * Dumps and restores MySQL database.
 *
 * @author Artur Doruch <arturdoruch@interia.pl>
 */
class MySqlDumper
{
    /**
     * Database access data as string
     *
     * @var string
     */
    private $dbAccess;

    /**
     * Database access data
     *
     * @var array
     */
    private $dbConfig;

    /**
     * @var string
     */
    private $mysqlDir;

    /**
     * @var string
     */
    private $bzip2Dir;

    /**
     * @var MySqlBackupManager
     */
    private $manager;

    /**
     * @param string $host           The database host.
     * @param string $name           The database name.
     * @param string $user           The database user name.
     * @param string $pass           The database password.
     * @param string $backupDir      Path to the backup directory.
     * @param bool|string $bzip2Path If you want to compress dumped sql file with bzip2 compressor pass:
     *                               - on windows the path to bzip compressor directory,
     *                               - on linux and other OS true value.
     */
    public function __construct($host, $name, $user, $pass, $backupDir, $bzip2Path = false)
    {
        $this->manager = new MySqlBackupManager($backupDir);
        $this->dbConfig = [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];

        $this->setMySqlData();
        $this->setBzip2Dir($bzip2Path);
    }

    /**
     * @return MySqlBackupManager
     */
    public function getBackupManager()
    {
        return $this->manager;
    }

    /**
     * Dumps MySQL database into file.
     *
     * @param bool $optimizeTable         If true optimizes all database tables before dump.
     * @param callable $filenameFormatter Formats the backup filename. The callback receives two arguments
     *                                    in following order: $host - database host name, $name - database name.
     *                                    The callback must return filename without extension.
     *
     * @return string Filename to the dumped database sql file.
     * @throws \RuntimeException
     */
    public function dump($optimizeTable = true, callable $filenameFormatter = null)
    {
        if ($optimizeTable) {
            $this->optimizeTable();
        }

        $filename = $this->prepareFilename($filenameFormatter);
        $command = $this->prepareMySqlCommand('mysqldump');

        if ($this->bzip2Dir !== null) {
            $filename .= '.bz2';
            $command .= ' | ' . $this->bzip2Dir . 'bzip2';
        }

        $command .= ' > ' . $this->manager->getFilePath($filename);

        $this->runProcess($command);

        return $filename;
    }

    /**
     * Restores (imports) MySQL database from the backup file.
     *
     * @param string $filename The MySQL backup filename.
     *
     * @throws \RuntimeException
     */
    public function restore($filename)
    {
        if (!$filename) {
            throw new \InvalidArgumentException('Missing $filename argument.');
        }

        if (!file_exists($path = $this->manager->getFilePath($filename))) {
            throw new \RuntimeException(sprintf('The backup file "%s" does not exist.', $filename));
        }

        if (preg_match('/\.bz2$/i', $filename)) {
            if (PHP_OS == 'WINNT' && $this->bzip2Dir === null) {
                throw new \RuntimeException('Import compressed sql file failure. The path to bzip2 compressor is not set.');
            }

            $command = $this->bzip2Dir . 'bunzip2 < ' . $path . ' | ' . $this->prepareMySqlCommand('mysql');
        } else {
            $command = $this->prepareMySqlCommand('mysql') . ' < ' . $path;
        }

        $this->runProcess($command);
    }

    /**
     * Optimizes all tables in database.
     */
    private function optimizeTable()
    {
        $command = $this->prepareMySqlCommand('mysqlcheck --optimize');
        $this->runProcess($command);
    }

    /**
     * @param string $command
     *
     * @throws \RuntimeException
     */
    private function runProcess($command)
    {
        $process = proc_open($command, [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w']
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch a new process.');
        }

        $error = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode > 0) {
            throw new \RuntimeException(sprintf(
                'Process "%s" failure with code "%s". Error: "%s"',
                $command, $exitCode, mb_convert_encoding($error, 'UTF-8', 'UTF-8')
            ));
        }
    }

    /**
     * @param callable $filenameFormatter
     *
     * @return string The backup filename
     */
    private function prepareFilename(callable $filenameFormatter = null)
    {
        if ($filenameFormatter) {
            $filename = (string) $filenameFormatter($this->dbConfig['host'], $this->dbConfig['name']);
        } else {
            $filename = $this->dbConfig['host'] . '-' . $this->dbConfig['name'] . '-' . date('Ymd_His', time());
        }

        return $filename . '.sql';
    }

    /**
     * @param string $program The CLI MySQL program name.
     *
     * @return string
     */
    private function prepareMySqlCommand($program)
    {
        return $this->mysqlDir . $program . $this->dbAccess;
    }


    private function setMySqlData()
    {
        if (isset($_SERVER['MYSQL_HOME'])) {
            $this->mysqlDir = $_SERVER['MYSQL_HOME'] . '\\';
        }

        $this->dbAccess = sprintf(
            ' --user=%s --password=%s --host=%s %s',
            $this->dbConfig['user'],
            $this->dbConfig['pass'],
            $this->dbConfig['host'],
            $this->dbConfig['name']
        );
    }

    /**
     * Sets bzip2 compressor path
     *
     * @param bool|string $path
     */
    private function setBzip2Dir($path)
    {
        if (!$path) {
            return;
        }
        
        if (PHP_OS == 'WINNT') {
            $this->bzip2Dir = str_replace('/', '\\', rtrim($path, '\/') . '/');
            $bzip2 = $this->bzip2Dir . 'bzip2.exe';
            
            if (!is_executable($bzip2)) {
                throw new \RuntimeException(sprintf(
                    'The %s is not executable. Set proper path to bzip2 compressor directory or false.', $bzip2
                ));
            }
        } elseif ($path === true) {
            $this->bzip2Dir = '';
        }
    }
}
