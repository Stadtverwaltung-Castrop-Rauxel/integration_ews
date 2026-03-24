<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @author Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\EWS\Service;

use Psr\Log\LoggerInterface;

class HarmonizationThreadService
{

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ConfigurationService
     */
    private $ConfigurationService;

    /**
     * Name of harmonization thread script.
     */
    private const THREAD_SCRIPT = 'HarmonizationThread.php';

    /**
     * @var int|null Maximum pid
     */
    static ?int $pidMax = null;

    public function __construct(LoggerInterface $logger, ConfigurationService $ConfigurationService)
    {
        $this->logger = $logger;
        $this->ConfigurationService = $ConfigurationService;
    }

    /**
     * Launch new harmonization thread
     *
     * @param string $uid nextcloud user id
     *
     * @return int thread id on success | 0 on failure
     * @since Release 1.0.0
     *
     */
    public function launch(string $uid): int
    {
        // construct command
        $tid = $this->launchPhp($uid);

        if ($tid === 0) {
            $tid = $this->launchShell($uid);
        }

        return $tid;
    }

    /**
     * Launch a background process using proc_open().
     *
     * Passes the command as an array to proc_open() which bypasses the shell
     * entirely by delegating process creation to the OS via execve(). Safe to
     * call from FPM as no fork occurs in PHP-space and no FPM worker state
     * is inherited by the child process.
     *
     * Requires PHP 7.4+ for array command support in proc_open().
     *
     * @param string $uid nextcloud user id
     *
     * @return int PID of the spawned process | 0 on failure / unavailable
     * @since 1.0.38
     *
     */
    private function launchPhp(string $uid): int
    {
        if (!function_exists('proc_open')) {
            return 0;
        }

        $script = dirname(__DIR__) . '/Tasks/' . self::THREAD_SCRIPT;
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        // Passing an array bypasses the shell entirely (PHP 7.4+).
        $process = proc_open(
            [PHP_BINARY, '--define', 'apc.enable_cli=1', $script, '-u' . $uid],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            return 0;
        }

        $status = proc_get_status($process);

        // Detach: closing the handle here does NOT terminate the child because
        // all its streams are bound to /dev/null, not to this process.
        proc_close($process);

        $pid = $status['pid'] ?? 0;

        return is_int($pid) && $pid > 0 ? $pid : 0;
    }

    /**
     * Launch a background process using shell_exec (fallback).
     *
     * @param string $uid nextcloud user id
     *
     * @return int PID of the spawned process | 0 on failure
     * @since 1.0.38
     *
     */
    private function launchShell(string $uid): int
    {
        $command = escapeshellarg(PHP_BINARY) . ' --define apc.enable_cli=1 ' .
            escapeshellarg(dirname(__DIR__) . '/Tasks/' . self::THREAD_SCRIPT) . ' ' .
            '-u' . escapeshellarg($uid) .
            ' > /dev/null 2>&1 & echo $!;';

        $rs = shell_exec($command);
        $tid = trim((string)$rs);

        return is_numeric($tid) ? intval($tid) : 0;
    }

    /**
     * Terminate harmonization thread
     *
     * If tid is supplied terminates a harmonization thread with specific id.
     * If tid is missing and user id is supplied terminates all harmonization threads for specific user.
     * If tid is missing and user id is '*' terminates all harmonization threads for all users.
     *
     * @param string $uid nextcloud user id
     * @param int $tid thread id (optional)
     *
     * @return int quantity of threads terminated
     * @since Release 1.0.0
     *
     */
    public function terminate(string $uid, int $tid = 0): int
    {
        $tc = [];
        // evaluate if thread id exists
        if ($tid > 0) {
            $tc[] = (object)['TID' => $tid];
        } // evaluate if user id is present
        elseif (!empty($uid)) {
            // evaluate if user id is wildcard
            if ($uid === '*') {
                // retrieve list of threads
                $tc = $this->list();
            } else {
                // retrieve list of threads
                $tc = $this->list($uid);
            }
        }
        // terminate thread(s)
        foreach ($tc as $entry) {
            $rs = $this->kill($entry->TID);
        }
        // return quantity of threads terminated
        return count($tc);
    }

    protected function kill(int $process_id = 0): int
    {
        if ($process_id <= 0 || $process_id > $this->getPidMax()) {
            return -1; // invalid PID
        }

        if (function_exists('posix_kill')) {
            // SIGTERM (15) is always safe to use as a literal when
            // the POSIX extension may not define the constant.
            $signal = defined('SIGTERM') ? SIGTERM : 15;
            return posix_kill($process_id, $signal) ? 0 : 1;
        }

        if (function_exists('proc_open') && version_compare(PHP_VERSION, '7.4.0', '>=')) {
            $proc = proc_open(
                ['kill', (string)$process_id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if ($proc === false) {
                return -2;
            }
            return proc_close($proc);
        }

        if (function_exists('exec')) {
            exec('kill ' . (int)$process_id, $output, $returnCode);
            return $returnCode;
        }

        $this->logger->error('Failed to execute command "kill ' . $process_id . '" for tid ' . $process_id);

        return -1;
    }

    /**
     * List harmonization thread(s)
     *
     * Returns harmonization threads for all users if uid is null,
     * or only for a specific user if uid is supplied.
     *
     * @param string|null $uid nextcloud user id (optional)
     *
     * @return array array of thread objects with TID and UID properties
     * @since Release 1.0.0
     *
     */
    public function list(string $uid = null): array
    {
        $result = $this->listPhp($uid);

        if (empty($result)) {
            $result = $this->listShell($uid);
        }

        return $result;
    }

    /**
     * List running harmonization threads by reading the /proc filesystem.
     *
     * Each numeric directory in /proc corresponds to a running PID.
     * /proc/<pid>/cmdline contains the command line with arguments separated
     * by null bytes. Only available on Linux.
     *
     * @param string|null $uid nextcloud user id (optional filter)
     *
     * @return array array of thread objects with TID and UID properties | empty array if unavailable
     * @since 1.0.38
     *
     */
    private function listPhp(?string $uid = null): array
    {
        $procDir = '/proc';

        if (!is_dir($procDir)) {
            return [];
        }

        $pidMax = $this->getPidMax();
        $results = [];

        try {
            $dir = new \DirectoryIterator($procDir);
        } catch (\Exception $e) {
            return [];
        }

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDir() || $fileinfo->isDot()) {
                continue;
            }

            $pidStr = $fileinfo->getFilename();

            if (!ctype_digit($pidStr)) {
                continue; // Not a process ID folder
            }

            $pid = (int)$pidStr;

            if ($pid > $pidMax) {
                continue;
            }

            $cmdlineFile = "$procDir/$pidStr/cmdline";

            if (!is_readable($cmdlineFile)) {
                continue;
            }

            $cmdline = file_get_contents($cmdlineFile);

            if ($cmdline === false || !str_contains($cmdline, self::THREAD_SCRIPT)) {
                continue;
            }

            // Arguments in cmdline are separated by null bytes
            $args = explode("\0", $cmdline);
            $ncUser = $this->extractUser($args);

            if ($ncUser === null) {
                continue;
            }

            if ($uid !== null && $ncUser !== $uid) {
                continue;
            }

            $results[] = (object)['TID' => $pid, 'UID' => $ncUser];
        }

        return $results;
    }

    /**
     * List running harmonization threads using shell_exec ps (fallback).
     *
     * @param string|null $uid nextcloud user id (optional filter)
     *
     * @return array array of thread objects with TID and UID properties | empty array on failure
     * @since 1.0.38
     *
     */
    private function listShell(?string $uid = null): array
    {
        $pidMax = $this->getPidMax();
        $pattern = '/(?<ThreadUser>[a-zA-Z0-9\-_+]+)\s+(?<ThreadId>\d{1,' . $pidMax . '}).*' . preg_quote(self::THREAD_SCRIPT) . '\s+-u(?<NcUser>[a-zA-Z0-9\-_+@.]+)[\s|$]+/iu';
        $rs = shell_exec('ps -aux');

        if (empty($rs)) {
            return [];
        }

        preg_match_all($pattern, $rs, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return [];
        }

        if ($uid !== null) {
            $matches = array_filter($matches, fn($e) => $e['NcUser'] === $uid);
        }

        return array_values(
            array_map(
                fn($e) => (object)['TID' => (int)$e['ThreadId'], 'UID' => $e['NcUser']],
                $matches
            )
        );
    }

    /**
     * Extracts a flag's value from a process argument list.
     *
     * Handles both joined (-ualice) and separated (-u alice) forms.
     *
     * @param array $args Argument list, e.g. from explode("\0", $cmdline)
     * @param string $flag The flag name to search for, without the leading dash (e.g. 'u')
     *
     * @return ?string      The flag value, or null if not found
     * @since 1.0.38
     *
     */
    private function extractArg(array $args, string $flag): ?string
    {
        foreach ($args as $i => $arg) {
            if ($arg === '-' . $flag && isset($args[$i + 1])) {
                return $args[$i + 1];
            }
            if (str_starts_with($arg, '-' . $flag) && strlen($arg) > strlen($flag) + 1) {
                return substr($arg, strlen($flag) + 1);
            }
        }
        return null;
    }

    /**
     * Extracts a user argument from a process argument list.
     *
     * Handles both joined (-ualice) and separated (-u alice) forms.
     * The argument flag is hardcoded as "-u".
     *
     * @param array $args Argument list, e.g. from explode("\0", $cmdline)
     *
     * @return ?string      The user value, or null if not found
     * @since 1.0.38
     *
     */
    private function extractUser(array $args): ?string
    {
        return $this->extractArg($args, 'u');
    }

    /**
     * Gets harmonization thread id
     *
     * @param string $uid nextcloud user id
     *
     * @return int thread id if exists | 0 if does not exist
     *
     * @since 1.0.0 Release
     *
     */
    public function getId(string $uid): int
    {
        // retrieve thread id
        return $this->ConfigurationService->getHarmonizationThreadId($uid);
    }

    /**
     * Sets harmonization thread id
     *
     * @param string $uid nextcloud user id
     * @param int $tid thread id
     *
     * @return void
     * @since Release 1.0.0
     *
     */
    public function setId(string $uid, int $tid): void
    {

        // update harmonization thread id
        $this->ConfigurationService->setHarmonizationThreadId($uid, $tid);

    }

    /**
     * Gets harmonization thread heart beat
     *
     * @param string $uid nextcloud user id
     *
     * @return int|null thread heart beat time stamp if exists | null if does not exist
     * @since Release 1.0.0
     *
     */
    public function getHeartBeat(string $uid): ?int
    {

        // retrieve thread heart beat
        $thb = $this->ConfigurationService->getHarmonizationThreadHeartBeat($uid);
        // return thread heart beat
        if (is_numeric($thb)) {
            return (int)$thb;
        } else {
            return null;
        }

    }

    /**
     * Sets harmonization thread heart beat
     *
     * @param string $uid nextcloud user id
     * @param int $thb thread heart beat time stamp
     *
     * @return void
     * @since Release 1.0.0
     *
     */
    public function setHeartBeat(string $uid, int $thb): void
    {

        // update harmonization thread id
        $this->ConfigurationService->setHarmonizationThreadHeartBeat($uid, $thb);

    }

    /**
     * evaluate if harmonization thread active/live/running
     *
     * @param string $uid nextcloud user id
     *
     * @return bool true - if active thread found | false - if no active thread found
     * @since Release 1.0.0
     *
     */
    public function isActive(string $uid, int $tid): bool
    {
        if ($tid <= 0 || $tid > $this->getPidMax()) {
            return false;
        }

        // Attempt 1: Linux /proc filesystem (Fastest)
        $filename = "/proc/$tid/cmdline";
        if (is_readable($filename)) {
            $cmdline = @file_get_contents($filename);
            if (!empty($cmdline) && str_contains($cmdline, self::THREAD_SCRIPT)) {
                $args = explode("\0", $cmdline);
                return $this->extractUser($args) === $uid;
            }
        }

        // Attempt 2: POSIX signal checking (Zero signal checks process existence)
        if (function_exists('posix_kill')) {
            // Signal 0 will not terminate the process, but checks if it's reachable
            return @posix_kill($tid, 0);
        }

        // Attempt 3: shell fallback using ps (Reliability)
        if (function_exists('shell_exec')) {
            // Search specifically for the PID and the script name
            $escapedScript = escapeshellarg(self::THREAD_SCRIPT);
            $command = "ps -p " . (int)$tid . " -o args= | grep $escapedScript";
            $output = shell_exec($command);

            if (!empty($output) && str_contains($output, "-u$uid")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the maximum PID value from the kernel.
     *
     * Reads /proc/sys/kernel/pid_max when available, falling back to the
     * Linux default of 32768 if the file cannot be read. The result is
     * cached in a static variable to avoid repeated filesystem reads within
     * the same request lifecycle.
     *
     * @return int maximum PID value
     * @since 1.0.38
     *
     */
    private function getPidMax(): int
    {
        if (self::$pidMax === null) {
            $path = '/proc/sys/kernel/pid_max';
            $value = is_readable($path) ? file_get_contents($path) : false;
            self::$pidMax = ($value !== false && is_numeric(trim($value)))
                ? (int)trim($value)
                : 32768;
        }

        return self::$pidMax;
    }

}
