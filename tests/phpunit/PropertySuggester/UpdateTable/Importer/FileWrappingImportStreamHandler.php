<?php

declare( strict_types = 1 );

namespace PropertySuggester\Tests\PropertySuggester\UpdateTable\Importer;

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

/**
 * The class is registered via stream_wrapper_register
 */
class FileWrappingImportStreamHandler {

	/** @var resource|null Must exist on stream wrapper class */
	public $context;
	/** @var resource|null The opened file */
	protected $filehandle;

	/**
	 * @param string $path
	 * @param string $mode
	 * @param int $options
	 * @param ?string &$opened_path
	 * @return bool
	 */
	public function stream_open( $path, $mode, $options, &$opened_path ): bool {
		$filepath = substr( $path, strlen( "testimport://" ) );
		$this->filehandle = fopen( $filepath, $mode, false );
		return true;
	}

	/**
	 * @param int $bytes
	 * @return string|false
	 */
	public function stream_read( $bytes ) {
		return fread( $this->filehandle, $bytes );
	}

	/**
	 * @param string $data
	 * @return int
	 */
	public function stream_write( $data ): int {
		return 0;
	}

	/**
	 * @param int $offset
	 * @param int $whence
	 * @return bool
	 */
	public function stream_seek( $offset, $whence ): bool {
		return false;
	}

	public function stream_eof(): bool {
		return feof( $this->filehandle );
	}

	/**
	 * @return int
	 */
	public function stream_tell() {
		return ftell( $this->filehandle );
	}

	public function stream_close(): bool {
		return fclose( $this->filehandle );
	}

}
