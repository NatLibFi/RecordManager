<?php
/**
 * Fork-based worker pool manager
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2017-2021.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Utils;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
} else {
    declare(ticks = 10);
}

/**
 * Worker Pool Manager
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class WorkerPoolManager
{
    use ParentProcessCheckTrait;

    /**
     * Request queue
     *
     * @var array
     */
    protected $requestQueue = [];

    /**
     * Results
     *
     * @var array
     */
    protected $results = [];

    /**
     * Worker pools
     *
     * @var array
     */
    protected $workerPools = [];

    /**
     * Worker pool run methods
     *
     * @var array
     */
    protected $workerPoolRunMethods = [];

    /**
     * Maximum request queue length
     *
     * @var array
     */
    protected $maxPendingRequests = 8;

    /**
     * External process exit codes
     *
     * Since we install the SIG_CHLD handler, we might get results from processes
     * not managed by us. Store these exit codes so that they can be retrieved if
     * necessary.
     *
     * @var array
     */
    protected $externalProcessExitCodes = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        if (function_exists('pcntl_signal')) {
            if (false === pcntl_signal(SIGCHLD, [$this, 'signalHandler'])) {
                throw new \Exception('Could not set SIGCHLD handler');
            }
        }
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->destroyWorkerPools();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGCHLD, SIG_DFL);
        }
    }

    /**
     * Destroy worker pools
     *
     * @return void
     */
    public function destroyWorkerPools()
    {
        // Destroy any worker pools
        if (!empty($this->workerPools)) {
            foreach ($this->workerPools as $workers) {
                foreach ($workers as $worker) {
                    socket_close($worker['socket']);
                    posix_kill($worker['pid'], SIGHUP);
                }
            }
        }
        $this->workerPools = [];

        // Remove any pending requests
        $this->requestQueue = [];
    }

    /**
     * Create worker pool
     *
     * @param string   $poolId     Worker pool id
     * @param int      $processes  Number of worker processes
     * @param int      $maxQueue   Maximum length of request queue
     * @param callable $runMethod  Worker execution method
     * @param callable $initMethod Worker initialization method
     *
     * @return void
     */
    public function createWorkerPool(
        $poolId,
        $processes,
        $maxQueue,
        callable $runMethod,
        callable $initMethod = null
    ) {
        if (isset($this->workerPoolRunMethods[$poolId])) {
            // Already initialized
            return;
        }
        $this->workerPoolRunMethods[$poolId] = $runMethod;
        if (0 === $processes) {
            return;
        }
        if (!function_exists('pcntl_fork')) {
            throw new \Exception(
                'pcntl_fork not available, cannot create worker pool'
            );
        }
        $this->maxPendingRequests = $maxQueue;
        $this->requestQueue[$poolId] = [];
        for ($i = 0; $i < $processes; $i++) {
            $socketPair = [];
            $domain = strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX;
            if (socket_create_pair($domain, SOCK_STREAM, 0, $socketPair) === false) {
                throw new \Exception(
                    'Could not create socket pair: '
                    . socket_strerror(socket_last_error())
                );
            }
            [$childSocket, $parentSocket] = $socketPair;
            unset($socketPair);

            $parentPid = getmypid();
            $childPid = pcntl_fork();
            if ($childPid == -1) {
                throw new \Exception('Could not fork worker');
            }
            if ($childPid > 0) {
                socket_close($childSocket);
                $this->workerPools[$poolId][] = [
                    'pid' => $childPid,
                    'socket' => $parentSocket,
                    'active' => false
                ];
            } else {
                if (is_callable('cli_set_process_title')) {
                    // This doesn't work with macOS, so suppress warnings.
                    @cli_set_process_title(
                        "RecordManager $poolId worker for $parentPid"
                    );
                }
                try {
                    socket_close($parentSocket);
                    if (null !== $initMethod) {
                        call_user_func($initMethod);
                    }
                    while ($request = $this->readSocket($childSocket, true, true)) {
                        $result = call_user_func_array($runMethod, $request);
                        $this->writeSocket($childSocket, ['r' => $result], true);
                    }
                } catch (\Exception $e) {
                    echo 'Fatal: Worker ' . getmypid()
                        . " exception in pool $poolId: " . $e->getMessage()
                        . "\nStack trace: " . $e->getTraceAsString();
                    $this->writeSocket(
                        $childSocket,
                        [
                            'exception' => $e->getMessage() . "\nStack trace: "
                            . $e->getTraceAsString()
                        ],
                        true
                    );
                    socket_close($childSocket);
                    exit(255);
                }
            }
        }
    }

    /**
     * Check if a worker pool exists
     *
     * @param string $poolId Pool id
     *
     * @return bool
     */
    public function hasWorkerPool($poolId)
    {
        return !empty($this->workerPools[$poolId]);
    }

    /**
     * Add a request to the queue
     *
     * @param string $poolId Pool id
     *
     * @return void
     */
    public function addRequest($poolId/*, ... */)
    {
        $args = func_get_args();
        array_shift($args);
        if (empty($this->workerPools[$poolId])) {
            if (!isset($this->workerPoolRunMethods[$poolId])) {
                throw new \Exception("addRequest: Invalid worker pool $poolId");
            }
            // Synchronous operation
            $this->results[$poolId][] = call_user_func_array(
                $this->workerPoolRunMethods[$poolId],
                $args
            );
        } else {
            // Wait until the request queue is short enough
            while (count($this->requestQueue[$poolId]) >= $this->maxPendingRequests
            ) {
                $this->handleRequests($poolId);
                usleep(100);
            }
            $this->requestQueue[$poolId][] = $args;
            $this->handleRequests($poolId);
        }
    }

    /**
     * Start handling as many requests as possible
     *
     * @param string $poolId Pool id
     *
     * @return void
     */
    public function handleRequests($poolId)
    {
        if (empty($this->workerPools[$poolId])) {
            return;
        }
        while ($this->requestQueue[$poolId]) {
            $queueItem = array_shift($this->requestQueue[$poolId]);
            $handled = false;
            foreach ($this->workerPools[$poolId] as &$worker) {
                if (!$worker['active']) {
                    $worker['active'] = true;
                    $this->writeSocket($worker['socket'], $queueItem);
                    $handled = true;
                    break;
                }
            }
            if (!$handled) {
                array_unshift($this->requestQueue[$poolId], $queueItem);
                break;
            }
        }
        $this->checkForResults($poolId);
        $this->checkForStoppedWorkers();
    }

    /**
     * Check if there are pending requests
     *
     * @param string $poolId Pool id
     *
     * @return bool
     */
    public function requestsPending($poolId)
    {
        $this->handleRequests($poolId);
        $this->checkForStoppedWorkers();
        return !empty($this->requestQueue[$poolId])
            || $this->requestsActive($poolId);
    }

    /**
     * Check if there are active requests
     *
     * @param string $poolId Pool id
     *
     * @return bool
     */
    public function requestsActive($poolId)
    {
        $this->handleRequests($poolId);
        if (empty($this->workerPools[$poolId])) {
            return false;
        }
        foreach ($this->workerPools[$poolId] as $worker) {
            if ($worker['active']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wait until there are no pending or active requests in the pool
     *
     * @param string $poolId Pool id
     *
     * @return void
     */
    public function waitUntilDone($poolId)
    {
        while ($this->requestsPending($poolId)) {
            usleep(1000);
        }
    }

    /**
     * Check for results from workers
     *
     * @param string $poolId Pool id
     *
     * @return bool
     */
    public function checkForResults($poolId)
    {
        if (!empty($this->workerPools[$poolId])) {
            foreach ($this->workerPools[$poolId] as &$worker) {
                if ($worker['active']) {
                    $result = $this->readSocket($worker['socket']);
                    if (null !== $result) {
                        if (!empty($result['exception'])) {
                            throw new \Exception($result['exception']);
                        }
                        $this->results[$poolId][] = $result['r'];
                        $worker['active'] = false;
                    }
                }
            }
        }
        $this->checkForStoppedWorkers();
        return !empty($this->results[$poolId]);
    }

    /**
     * Get next result
     *
     * @param string $poolId Pool id
     *
     * @return mixed
     */
    public function getResult($poolId)
    {
        if (empty($this->results[$poolId])) {
            return null;
        }
        return array_shift($this->results[$poolId]);
    }

    /**
     * Read from a socket
     *
     * @param resource $socket      Socket
     * @param bool     $block       Whether to block waiting for data
     * @param bool     $checkParent Whether to chek that the parent process is alive
     *
     * @return mixed
     */
    public function readSocket($socket, $block = false, $checkParent = false)
    {
        $msgLen = '';
        $received = 0;
        $interrupted = 0;
        do {
            if ($checkParent) {
                $this->checkParentIsAlive();
            }
            $read = [$socket];
            $write = [];
            $except = [];
            $res = socket_select($read, $write, $except, $block ? 5000 : 0);
            if (false === $res) {
                $error = socket_last_error();
                if (SOCKET_EINTR === $error || (++$interrupted < 10)) {
                    // Retry after an interrupted system call
                    continue;
                }
                throw new \Exception(
                    'socket_select failed: ' . socket_strerror($error)
                );
            }
            if (0 === $res) {
                if (!$block) {
                    return null;
                }
                usleep(10);
                continue;
            }
            $interrupted = 0;

            $buffer = '';
            $result = socket_recv(
                $socket,
                $buffer,
                8 - strlen($msgLen),
                MSG_WAITALL
            );
            if (false === $result) {
                throw new \Exception(
                    'socket_recv failed: ' . socket_strerror(socket_last_error())
                );
            }
            $msgLen .= $buffer;
            $received += $result;
        } while ($received < 8);

        $messageLength = hexdec($msgLen);
        $message = '';
        $received = 0;
        while ($received < $messageLength) {
            if ($checkParent) {
                $this->checkParentIsAlive();
            }
            $buffer = '';
            $result = socket_recv(
                $socket,
                $buffer,
                $messageLength - $received,
                MSG_WAITALL
            );
            if (false === $result) {
                throw new \Exception(
                    'socket_read failed: ' . socket_strerror(socket_last_error())
                );
            }
            $message .= $buffer;
            $received += $result;
        }

        $result = unserialize($message);
        if (false === $result) {
            throw new \Exception(
                getmypid() . " could not unserialize msg from socket $socket"
            );
        }
        return $result;
    }

    /**
     * Write to a socket
     *
     * @param resource $socket      Socket
     * @param mixed    $data        Serializable data
     * @param bool     $checkParent Whether to check that parent process is alive
     *
     * @return bool
     */
    public function writeSocket($socket, $data, $checkParent = false)
    {
        $message = serialize($data);
        $length = strlen($message);

        // Prefix serialized data with the length so that the other end knows how
        // much to read
        $message = str_pad(dechex($length), 8, '0', STR_PAD_LEFT) . $message;

        $msgLen = strlen($message);
        $written = 0;
        $startTime = microtime(true);
        while (true) {
            if ($checkParent) {
                $this->checkParentIsAlive();
            }
            $read = [];
            $write = [$socket];
            $except = [];
            $res = socket_select($read, $write, $except, null);
            if (false === $res) {
                throw new \Exception(
                    'socket_select failed: ' . socket_strerror(socket_last_error())
                );
            }
            if (microtime(true) - $startTime > 60) {
                throw new \Exception(
                    'writeSocket timed out after 60 seconds'
                );
            }
            if (0 === $res) {
                usleep(100);
                continue;
            }
            $written = socket_write($socket, $message, $msgLen);
            if (false === $written) {
                throw new \Exception(
                    'Socket write failed: '
                    . socket_strerror(socket_last_error($socket))
                );
            }
            if ($written >= $msgLen) {
                break;
            }
            $message = substr($message, $written);
            $msgLen -= $written;
        }
        return true;
    }

    /**
     * Get exit code of any external child process
     *
     * @param int $pid Process ID
     *
     * @return mixed Exit code or null if not available
     */
    public function getExternalProcessExitCode($pid)
    {
        return $this->externalProcessExitCodes[$pid] ?? null;
    }

    /**
     * Signal handler
     *
     * @param int $signo Signal number
     *
     * @return void
     */
    public function signalHandler($signo)
    {
        if (SIGCHLD == $signo) {
            $this->reapChildren();
        }
    }

    /**
     * Child process reaper
     *
     * @return void
     */
    protected function reapChildren()
    {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);

        while ($pid > 0) {
            $exitCode = pcntl_wexitstatus($status);
            $found = false;
            foreach ($this->workerPools as &$workers) {
                foreach ($workers as &$worker) {
                    if ($pid === $worker['pid']) {
                        $worker['exitCode'] = $exitCode;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $this->externalProcessExitCodes[$pid] = $exitCode;
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
    }

    /**
     * Check for any failed workers
     *
     * @return void
     * @throws \Exception
     */
    protected function checkForStoppedWorkers()
    {
        if (empty($this->workerPools)) {
            return;
        }
        pcntl_signal_dispatch();
        foreach ($this->workerPools as $workers) {
            foreach ($workers as $worker) {
                if (isset($worker['exitCode'])) {
                    throw new \Exception(
                        "Worker {$worker['pid']} has stopped prematurely with exit"
                        . " code {$worker['exitCode']}"
                    );
                }
            }
        }
    }
}
