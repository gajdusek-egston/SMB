<?php
/**
 * Copyright (c) 2014 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Licensed under the MIT license:
 * http://opensource.org/licenses/MIT
 */

namespace Icewind\SMB;

use Icewind\SMB\Exception\AccessDeniedException;
use Icewind\SMB\Exception\AlreadyExistsException;
use Icewind\SMB\Exception\ConnectionException;
use Icewind\SMB\Exception\Exception;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\NotEmptyException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\Streams\CallbackWrapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Share implements IShare {
	/**
	 * @var Server $server
	 */
	private $server;

	/**
	 * @var string $name
	 */
	private $name;

	/**
	 * @var Connection $connection
	 */
	public $connection;

	/**
	 * @var \Icewind\SMB\Parser
	 */
	protected $parser;

	/**
	 * @var LoggerInterface $logger
	 */
	protected $logger;

	private $serverTimezone;

	/**
	 * @param Server $server
	 * @param string $name
	 * @param LoggerInterface $logger
	 */
	public function __construct($server, $name, LoggerInterface $logger = null) {
		$this->logger = $logger ? $logger : new NullLogger;
		$this->server = $server;
		$this->name = $name;
		$this->parser = new Parser($this->server->getTimeZone());
	}

	/**
	 * @throws \Icewind\SMB\Exception\ConnectionException
	 * @throws \Icewind\SMB\Exception\AuthenticationException
	 * @throws \Icewind\SMB\Exception\InvalidHostException
	 */
	protected function connect() {
		if ($this->connection and $this->connection->isValid()) {
			return;
		}
		$command = sprintf('%s --authentication-file=/proc/self/fd/3 //%s/%s',
			Server::CLIENT,
			$this->server->getHost(),
			$this->name
		);
		$this->connection = new Connection($command, array(), $this->logger);
		$this->connection->writeAuthentication($this->server->getUser(), $this->server->getPassword());
		if (!$this->connection->isValid()) {
			throw new ConnectionException();
		}
	}

	/**
	 * Get the name of the share
	 *
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	protected function simpleCommand($command, $path) {
		$path = $this->escapePath($path);
		$cmd = $command . ' ' . $path;
		$output = $this->execute($cmd);
		return $this->parseOutput($output, $path);
	}

	/**
	 * List the content of a remote folder
	 *
	 * @param $path
	 * @return \Icewind\SMB\IFileInfo[]
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function dir($path) {
		$escapedPath = $this->escapePath($path);
		$output = $this->execute('cd ' . $escapedPath);
		//check output for errors
		$this->parseOutput($output, $path);
		$output = $this->execute('dir');
		$this->execute('cd /');

		return $this->parser->parseDir($output, $path);
	}

	/**
	 * @param string $path
	 * @return \Icewind\SMB\IFileInfo[]
	 */
	public function stat($path) {
		$escapedPath = $this->escapePath($path);
		$output = $this->execute('allinfo ' . $escapedPath);
		if (count($output) < 3) {
			$this->parseOutput($output, $path);
		}
		$stat = $this->parser->parseStat($output);
		return new FileInfo($path, basename($path), $stat['size'], $stat['mtime'], $stat['mode']);
	}

	/**
	 * Create a folder on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\AlreadyExistsException
	 */
	public function mkdir($path) {
		return $this->simpleCommand('mkdir', $path);
	}

	/**
	 * Remove a folder on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function rmdir($path) {
		return $this->simpleCommand('rmdir', $path);
	}

	/**
	 * Delete a file on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function del($path) {
		//del return a file not found error when trying to delete a folder
		//we catch it so we can check if $path doesn't exist or is of invalid type
		try {
			return $this->simpleCommand('del', $path);
		} catch (NotFoundException $e) {
			//no need to do anything with the result, we just check if this throws the not found error
			try {
				$this->simpleCommand('ls', $path);
			} catch (NotFoundException $e2) {
				throw $e;
			} catch (\Exception $e2) {
				throw new InvalidTypeException($path);
			}
			throw $e;
		}
	}

	/**
	 * Rename a remote file
	 *
	 * @param string $from
	 * @param string $to
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\AlreadyExistsException
	 */
	public function rename($from, $to) {
		$path1 = $this->escapePath($from);
		$path2 = $this->escapePath($to);
		$cmd = 'rename ' . $path1 . ' ' . $path2;
		$output = $this->execute($cmd);
		return $this->parseOutput($output, $to);
	}

	/**
	 * Upload a local file
	 *
	 * @param string $source local file
	 * @param string $target remove file
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function put($source, $target) {
		$path1 = $this->escapeLocalPath($source); //first path is local, needs different escaping
		$path2 = $this->escapePath($target);
		$output = $this->execute('put ' . $path1 . ' ' . $path2);
		return $this->parseOutput($output, $target);
	}

	/**
	 * Download a remote file
	 *
	 * @param string $source remove file
	 * @param string $target local file
	 * @return bool
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function get($source, $target) {
		$path1 = $this->escapePath($source);
		$path2 = $this->escapeLocalPath($target); //second path is local, needs different escaping
		$output = $this->execute('get ' . $path1 . ' ' . $path2);
		return $this->parseOutput($output, $source);
	}

	/**
	 * Open a readable stream to a remote file
	 *
	 * @param string $source
	 * @return resource a read only stream with the contents of the remote file
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function read($source) {
		$source = $this->escapePath($source);
		// close the single quote, open a double quote where we put the single quote...
		$source = str_replace('\'', '\'"\'"\'', $source);
		// since returned stream is closed by the caller we need to create a new instance
		// since we can't re-use the same file descriptor over multiple calls
		$command = sprintf('%s --authentication-file=/proc/self/fd/3 //%s/%s -c \'get %s /proc/self/fd/5\'',
			Server::CLIENT,
			$this->server->getHost(),
			$this->name,
			$source
		);
		$connection = new Connection($command, array(), $this->logger);
		$connection->writeAuthentication($this->server->getUser(), $this->server->getPassword());
		$fh = $connection->getFileOutputStream();
		stream_context_set_option($fh, 'file', 'connection', $connection);
		return $fh;
	}

	/**
	 * Open a writable stream to a remote file
	 *
	 * @param string $target
	 * @return resource a write only stream to upload a remote file
	 *
	 * @throws \Icewind\SMB\Exception\NotFoundException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 */
	public function write($target) {
		$target = $this->escapePath($target);
		// close the single quote, open a double quote where we put the single quote...
		$target = str_replace('\'', '\'"\'"\'', $target);
		// since returned stream is closed by the caller we need to create a new instance
		// since we can't re-use the same file descriptor over multiple calls
		$command = sprintf('%s --authentication-file=/proc/self/fd/3 //%s/%s -c \'put /proc/self/fd/4 %s\'',
			Server::CLIENT,
			$this->server->getHost(),
			$this->name,
			$target
		);
		$connection = new RawConnection($command, array(), $this->logger);
		$connection->writeAuthentication($this->server->getUser(), $this->server->getPassword());
		$fh = $connection->getFileInputStream();

		// use a close callback to ensure the upload is finished before continuing
		// this also serves as a way to keep the connection in scope
		return CallbackWrapper::wrap($fh, null, null, function () use ($connection) {
			$connection->close(false); // dont terminate, give the upload some time
		});
	}

	/**
	 * @param string $path
	 * @param int $mode a combination of FileInfo::MODE_READONLY, FileInfo::MODE_ARCHIVE, FileInfo::MODE_SYSTEM and FileInfo::MODE_HIDDEN, FileInfo::NORMAL
	 * @return mixed
	 */
	public function setMode($path, $mode) {
		$modeString = '';
		$modeMap = array(
			FileInfo::MODE_READONLY => 'r',
			FileInfo::MODE_HIDDEN => 'h',
			FileInfo::MODE_ARCHIVE => 'a',
			FileInfo::MODE_SYSTEM => 's'
		);
		foreach ($modeMap as $modeByte => $string) {
			if ($mode & $modeByte) {
				$modeString .= $string;
			}
		}
		$path = $this->escapePath($path);

		// first reset the mode to normal
		$cmd = 'setmode ' . $path . ' -rsha';
		$output = $this->execute($cmd);
		$this->parseOutput($output, $path);

		// then set the modes we want
		$cmd = 'setmode ' . $path . ' ' . $modeString;
		$output = $this->execute($cmd);
		return $this->parseOutput($output, $path);
	}

	/**
	 * @param string $command
	 * @return array
	 */
	protected function execute($command) {
		$this->connect();
		$this->connection->write($command . PHP_EOL);
		$this->connection->readUntilPrompt(); // first line is prompt
		$output = $this->connection->readUntilPrompt(); // until next prompt
		return $output;
	}

	/**
	 * check output for errors
	 *
	 * @param string[] $lines
	 * @param string $path
	 *
	 * @throws NotFoundException
	 * @throws \Icewind\SMB\Exception\AlreadyExistsException
	 * @throws \Icewind\SMB\Exception\AccessDeniedException
	 * @throws \Icewind\SMB\Exception\NotEmptyException
	 * @throws \Icewind\SMB\Exception\InvalidTypeException
	 * @throws \Icewind\SMB\Exception\Exception
	 * @return bool
	 */
	protected function parseOutput($lines, $path = '') {
		$this->parser->checkForError($lines, $path);
	}

	/**
	 * @param string $string
	 * @return string
	 */
	protected function escape($string) {
		return escapeshellarg($string);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	protected function escapePath($path) {
		$path = str_replace('/', '\\', $path);
		$path = str_replace('"', '^"', $path);
		return '"' . $path . '"';
	}

	/**
	 * @param string $path
	 * @return string
	 */
	protected function escapeLocalPath($path) {
		$path = str_replace('"', '\"', $path);
		return '"' . $path . '"';
	}

	public function __destruct() {
		unset($this->connection);
	}
}
