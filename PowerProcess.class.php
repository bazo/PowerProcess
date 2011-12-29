<?php 

/**
 * @package PowerProcess
 * 
 * PowerProcess is an abstraction class for PHP's posix and pcntl extensions
 * It enables easy process forking or threading to allow use of parallel 
 * processes for completing complicated tasks that would otherwise be 
 * inefficient for normal serial and procedural processing
 * 
 * @author Don Bauer <lordgnu@me.com>
 * @license MIT License
 * 
 * Copyright (c) 2011 Don Bauer <lordgnu@me.com>
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE 
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(ticks = 1);

class PowerProcess {
	/**
	 * Current PowerProcess version
	 * @var string
	 */
	public static $version = '2.0';
	
	/**
	 * Data store for data that is to be passed to the child process which is 
	 * to be spawned
	 * 
	 * @var mixed
	 */
	public $threadData;
	
	/**
	 * Boolean variable which determines whether or not to shutdown the control 
	 * process (parent)
	 * @var boolean
	 */
	public $complete;
	
	/**
	 * Callback array for setting callback functions based on signals that can
	 * be sent to the parent process
	 * @var array
	 */
	private $callbacks;
	
	/**
	 * The name of the current thread.  Used by WhoAmI()
	 * @var string
	 */
	private $currentThread;
	
	/** 
	 * Whether to log internal debug message
	 * @var boolean
	 */
	private $debugLogging;
	
	/**
	 * The maximum number of concurrent threads that can be running at any given
	 * time.  This setting has an impact on performance for PowerProcess so play
	 * with it on the system you are on to determine a good value.
	 * 10 is a good place to start
	 * @var integer
	 */
	private $maxThreads;
	
	/**
	 * Array which stores the thread data for the control process (parent) to
	 * manage running child threads
	 * @var array
	 */
	private $myThreads;
	
	/**
	 * Session ID of parent session when process is daemonized
	 * @var integer
	 */
	private $parentSID;
	
	/** 
	 * The pid of the parent process - Used after a process is forked to
	 * determine whether the new thread is to run the thread code
	 * @var integer
	 */
	private $parentPID;
	
	/**
	 * Sleep timer in micro seconds for the parent process to sleep between
	 * status checks using Tick()
	 * @var integer
	 */
	private $tickCount = 100;
	
	/**
	 * The maximum number of seconds a thread will be allowed to run.
	 * Set to 0 to disable a time limit (use with caution)
	 * @var integer
	 */
	private $threadTimeLimit;
	
	/**
	 * Location to log information messages to.  Can be a file or 
	 * php://stdout, php://stderr.
	 * Set to false to disable
	 * 
	 * @var mixed
	 */
	private $logTo;
	
	/**
	 * When logging is enabled, this points to the socket in which to write log
	 * messages.
	 * @var resource
	 */
	private $logSocket;
	
	/**
	 * Signals to install for SignalDispatcher.  
	 * You can use any signal constant PNCTL supports
	 * http://us3.php.net/manual/en/pcntl.constants.php
	 * @var array
	 */
	private $signalArray = array(
		SIGUSR1,	// User-Defined 1
		SIGUSR2		// User-Defined 2
	);
	
	/**
	 * PowerProcess constructor.  Returns an instanced PowerProcess object or dies on failure
	 * @param integer	$maxThreads			Max number of concurrent threads to allow at any given time
	 * @param integer	$threadTimeLimit	Maximum number of seconds a thread is allowed to live
	 * @param boolean	$daemon 			Whether to start as a deamon or just a normal script
	 * @param string	$logTo 				What stream to log output to
	 * @param boolean	$debugLogging		Whether to enable debug logging
	 * @return object	Instanced PowerProcess object
	 */
	public function PowerProcess($maxThreads = 10, $threadTimeLimit = 300, $daemon = false, $logTo = false, $debugLogging = false) {
		if (function_exists('pcntl_fork') && function_exists('posix_getpid')) {
			// Set the current thread name
			$this->currentThread = 'CONTROL';
			
			// Set the max threads setting
			$this->SetMaxThreads($maxThreads);
			
			// Set the thread time limit setting
			$this->SetThreadTimeLimit($threadTimeLimit);
			
			// Init the logger
			$this->InitializeLogger($logTo, $debugLogging);
			
			if ($daemon) {
				// Attempt to daemonize
				if (!$this->Daemonize()) {
					die("Could not daemonize");
				} else {
					$this->Log("Daemonized successfully",true);
				}
			} else {
				// Register control process PID
				$this->parentPID = $this->GetPID();
				$this->parentSID = false;
				$this->Log("Parent PID detected as {$this->parentPID}",true);
			}
			
			// The the complete flag to false
			$this->complete = false;
			
			$this->InstallSignalHandler();
			
			// Init the Thread Queue
			$this->myThreads = array();
			
			// Log completion of startup
			$this->Log("Startup process complete",true);
		} else {
			die("PowerProcess requires both the POSIX and PCNTL extensions to operate.\n");
		}
	}
	
	/**
	 * Frees up memory
	 */
	public function __destruct() {
		unset($this->callbacks);
		unset($this->myThreads);
		
		$this->RemoveLogger();
	}
	
	/**
	 * Executes specified program in the current process space
	 * @param string $process	Path to the binary process to execute
	 * @param array $args		Array of argument strings to pass to the program
	 */
	public function Exec($process, $args = null) {
		if ($args == null) {
			pcntl_exec($process);
		} else {
			pcntl_exec($process, $args);
		}
	}
	
	/**
	 * Returns the PID of the current process
	 * @return integer
	 */
	public function GetPID() {
		return posix_getpid();
	}
	
	/**
	 * Returns the PID of the process that spawned this one
	 * @return integer
	 */
	public function GetControlPID() {
		return posix_getppid();
	}
	
	/**
	 * Get the status of a running thread by name or PID
	 * @param string|integer $name The name or PID of the process for which you want status information
	 * @return array|boolean
	 */
	public function GetThreadStatus($name = false) {
		if ($name === false) return false;
		if (isset($this->myThreads[$name])) {
			return $this->myThreads[$name];
		} else {
			return false;
		}
	}
	
	/**
	 * Determine whether the control process is daemonized
	 * @return boolean
	 */
	public function IsDaemon() {
		return $this->parentSID !== false;
	}
	
	/**
	 * Log a message
	 * @param string $msg The message to log
	 * @param boolean $internal Whether this is an internal debug logging message
	 */
	public function Log($msg, $internal = false) {
		if ($this->logSocket !== false) {
			if (!$internal || $this->debugLogging) {
				fwrite($this->logSocket, sprintf("[%-12s] %s\n", $this->WhoAmI(), $msg));
			}
		}
	}
	
	/**
	 * Restarts the control process
	 */
	public function Restart() {
		// Build Path of Script
		if (isset($_SERVER['_'])) {
			$cmd = $_SERVER['_'];
			$this->Log("Attempting to restart using {$cmd}",true);
		} else {
			$this->Log("Can not restart - Shutting down", true);
			return $this->Shutdown();
		}
		
		// Wait for threads to complete
		while ($this->ThreadCount()) {
			$this->CheckThreads();
			$this->Tick();
		}
		
		// Remove the first arg if this is a stand-alone
		if ($cmd == $_SERVER['argv'][0]) unset($_SERVER['argv'][0]);
		
		// Remove blocked signal
		pcntl_sigprocmask(SIG_UNBLOCK, array(SIGHUP));
		
		// Execute Restart
		$this->Exec($cmd, $_SERVER['argv']);
		$this->Shutdown(true);
	}
	
	/**
	 * Registers a callback function for the signal dispatcher or for special signals used by PowerProcess
	 * Special signals are:
	 *   - 'shutdown' : Triggered on completion of the Shutdown() method
	 *   - 'threadotl' : Triggered on killing a thread due to exceeding time limit
	 * @param int|string $signal The signal to register a callback for
	 * @param callback $callback The callback function
	 */
	public function RegisterCallback($signal, $callback = false) {
		if ($callback !== false) $this->callbacks[$signal] = $callback;
		
		// Register with PCNTL
		if (is_int($signal)) {
			$this->Log("Registering signal {$signal} with dispatcher",true);
			pcntl_signal($signal, array($this, 'SignalDispatch'));
		}
	}
	
	/**
	 * Determines whether we should be running the control code or the thread code
	 * @return boolean
	 */
	public function RunControlCode() {
		$this->Tick();
		if (!$this->complete) {
			return $this->ControlCheck();
		} else {
			$this->SignalDispatch('shutdown');
			return false;
		}
	}
	
	/**
	 * Determines whether we should be running the child code
	 * @return boolean
	 */
	public function RunThreadCode() {
		return !$this->ControlCheck();
	}
	
	/**
	 * Set the max number of threads that can be running concurrently
	 * @param integer $maxThreads The max number of threads to run concurrently
	 */
	public function SetMaxThreads($maxThreads = 10) {
		$this->maxThreads = $maxThreads;
	}
	
	/**
	 * Set the max number of seconds a thread can run before being terminated
	 * @param integer $threadTimeLimit The max number of seconds a thread can run
	 */
	public function SetThreadTimeLimit($threadTimeLimit = 300) {
		$this->threadTimeLimit = $threadTimeLimit;
	}
	
	/**
	 * Initiates the shutdown procedure for PowerProcess
	 * @param boolean $exit When set to true, Shutdown causes the script to exit
	 */
	public function Shutdown($exit = false) {
		$this->Log("Initiating shutdown",true);
		while ($this->ThreadCount()) {
			$this->CheckThreads();
			$this->Tick();
		}
		$this->complete = true;
		if ($exit) exit;
	}
	
	/**
	 * Determines if a new process can be spawned
	 * @return boolean
	 */
	public function SpawnReady() {
		$this->Tick();
		return ($this->ThreadCount() < $this->maxThreads);
	}
	
	/**
	 * Spawn a new thread
	 * @param $name The name of the thread to be spawned
	 * @return boolean
	 */
	public function SpawnThread($name = false) {
		// Check to make sure we can spawn another thread
		if (!$this->SpawnReady()) {
			$this->Log("The maximum number of threads are already running",true);
			$this->Tick();
			return false;
		}
		
		if ($name !== false) {
			// Check to make sure there is not already a named thread with this name
			if ($this->GetThreadStatus($name) !== false) {
				$this->Log("There is already a thread named '{$name}' running",true);
				$this->Tick();
				return false;
			}
		}
		
		$pid = pcntl_fork();
		
		if ($pid) {
			// We are the control thread so log the child in a queue
			$index = ($name === false) ? $pid : $name;
			$name = ($name === false) ? "THREAD:{$pid}" : $name;
			$this->myThreads[$index] = array(
				'pid'	=>	$pid,
				'time'	=>	time(),
				'name'	=>	$name
			);
			$this->Log("Spawned thread: {$name}",true);
			$this->Tick();
			return true;
		} else {
			// We are the child thread so change the current thread var
			$this->currentThread = ($name === false) ? "THREAD:".$this->GetPID() : $name;
			return true;
		}
	}
	
	/**
	 * Get the count of running threads
	 * @return integer
	 */
	public function ThreadCount() {
		return count($this->myThreads);
	}
	
	/**
	 * Process signals to be dispatched and sleep for a number of microseconds
	 */
	public function Tick() {
		// Dispatch Pending Signals
		pcntl_signal_dispatch();
		
		// Check Running Threads
		$this->CheckThreads();
		
		// Tick
		usleep($this->tickCount);
	}
	
	/**
	 * Get the name of the current thread
	 * @return string The name of the current thread
	 */
	public function WhoAmI() {
		return $this->currentThread;
	}
	
	// All Private Functions Below Here
	/**
	 * Checks all running threads to make sure they are still running and their time limit 
	 * has not been exceeded
	 */
	private function CheckThreads() {
		foreach ($this->myThreads as $i => $thread) {
			// Check to make sure the process is still running
			if ($this->PIDDead($thread['pid']) != 0) {
				// Thread is Dead
				unset($this->myThreads[$i]);
			} elseif ($this->threadTimeLimit > 0) {
				if (time() - $thread['time'] > $this->threadTimeLimit) {
					$this->KillThread($thread['pid']);
					$this->Log("Thread {$thread['name']} has exceeded the thread time limit",true);
					$this->SignalDispatch('threadotl');
					unset($this->myThreads[$i]);
				}
			}
		}
	}
	
	/**
	 * Check if the current process is the control process
	 * @return boolean
	 */
	private function ControlCheck() {
		return $this->parentPID == $this->GetPID();
	}
	
	/**
	 * Attempts to daemonize the current process
	 * @return integer
	 */
	private function Daemonize() {
		$this->Log("Attempting to Daemonize",true);
		
		// First need to fork
		$pid = pcntl_fork();
		
		if ($pid < 0) exit; // Error
		if ($pid) exit;		// Parent
		
		$this->parentSID = posix_setsid();
		
		// Need to reset the parent PID
		$this->parentPID = $this->GetPID();
		$this->Log("Parent PID {$this->parentPID}",true);
		$this->Log("Parent SID {$this->parentSID}",true);
		
		return ($this->parentSID > 0);
	}
	
	/**
	 * Initialize the logging stream if enabled
	 * @param string|boolean $logTo The path or stream to log to or false to disable
	 */
	private function InitializeLogger($logTo, $debugLogging) {
		if ($logTo !== false) {
			$this->logSocket = @fopen($logTo, 'w');
			$this->debugLogging = $debugLogging;
		} else {
			$this->logSocket = false;
			$this->debugLogging = false;
		}
	}
	
	/**
	 * Installs the default signal handlers
	 */
	private function InstallSignalHandler() {
		// Register the callback for thread completion
		$this->RegisterCallback(SIGCHLD, array($this,'CheckThreads'));
		$this->Log("SIGCHLD callback registered",true);
		
		// Register the callback for restart requests
		$this->RegisterCallback(SIGHUP, array($this, 'Restart'));
		$this->Log("SIGHUP callback registered",true);
		
		// Register the callback for shutdown requests
		$this->RegisterCallback(SIGTERM, array($this, 'Shutdown'));
		$this->Log("SIGTERM callback registered",true);
		
		// Install the signal handler
		foreach ($this->signalArray as $signal) $this->RegisterCallback($signal);
		$this->Log("Signal Dispatcher installed",true);
	}
	
	/**
	 * Kill a thread by PID
	 * @param integer $pid The PID of the thread to kill
	 */
	private function KillThread($pid = 0) {
		if ($pid > 0) {
			posix_kill($pid, SIGTERM);
		}
	}
	
	/**
	 * Determine whether a child pid has exited
	 * Returns the PID of child which exited or 0
	 * @param integer $pid The PID to check
	 * @return integer
	 */
	private function PIDDead($pid = 0) {
		if ($pid > 0) {
			return pcntl_waitpid($pid, $status, WUNTRACED OR WNOHANG);
		} else {
			return 0;
		}
	}
	
	/**
	 * Closes the logging stream
	 */
	private function RemoveLogger() {
		if ($this->logSocket) {
			@fclose($this->logSocket);
		}
	}
	
	/**
	 * Handles dispatching of signals to user-defined callbacks
	 * @param integer|string $signal
	 */
	private function SignalDispatch($signal) {
		// Log Dispatch
		$this->Log("Dispatching signal: {$signal}",true);
		
		// Clear the block
		if (is_int($signal)) pcntl_sigprocmask(SIG_UNBLOCK,array($signal));
		
		// Check the callback array for this signal number
		if (isset($this->callbacks[$signal])) {
			// Execute the callback
			call_user_func($this->callbacks[$signal]);
		} else {
			// No callback registered
			$this->Log("There is no callback registered for signal {$signal}",true);
		}
		
		// Handle SIGTERM
		if ($signal == 15) {
			exit(0);
		}
	}
	
}
?>