<?php
/**
 * Copyright (c) 2014 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Licensed under the MIT license:
 * http://opensource.org/licenses/MIT
 */

namespace Icewind\SMB;

use Icewind\Streams\File;

class NativeStream implements File {
	/**
	 * @var resource
	 */
	public $context;

	/**
	 * @var \Icewind\SMB\NativeState
	 */
	private $state;

	/**
	 * @var resource
	 */
	private $handle;

	/**
	 * @var bool
	 */
	private $eof = false;

	/**
	 * Wrap a stream from libsmbclient-php into a regular php stream
	 *
	 * @param \Icewind\SMB\NativeState $state
	 * @param resource $smbStream
	 * @param string $mode
	 * @return resource
	 */
	public static function wrap($state, $smbStream, $mode) {
		stream_wrapper_register('nativesmb', '\Icewind\SMB\NativeStream');
		$context = stream_context_create(array(
			'nativesmb' => array(
				'state' => $state,
				'handle' => $smbStream
			)
		));
		$fh = fopen('nativesmb://', $mode, false, $context);
		stream_wrapper_unregister('nativesmb');
		return $fh;
	}

	public function stream_close() {
		return $this->state->close($this->handle);
	}

	public function stream_eof() {
		return $this->eof;
	}

	public function stream_flush() {
	}


	public function stream_open($path, $mode, $options, &$opened_path) {
		$context = stream_context_get_options($this->context);
		$this->state = $context['nativesmb']['state'];
		$this->handle = $context['nativesmb']['handle'];
		return true;
	}

	public function stream_read($count) {
		$result = $this->state->read($this->handle, $count);
		if (strlen($result) < $count) {
			$this->eof = true;
		}
		return $result;
	}

	public function stream_seek($offset, $whence = SEEK_SET) {
		$this->eof = false;
		return $this->state->lseek($this->handle, $offset, $whence) !== false;
	}

	public function stream_stat() {
		return $this->state->fstat($this->handle);
	}

	public function stream_tell() {
		return $this->state->lseek($this->handle, 0, SEEK_CUR);
	}

	public function stream_write($data) {
		return $this->state->write($this->handle, $data);
	}

	public function stream_truncate($size) {
		return $this->state->ftruncate($this->handle, $size);
	}

	public function stream_set_option($option, $arg1, $arg2) {
		return false;
	}

	public function stream_lock($operation) {
		return false;
	}
}
