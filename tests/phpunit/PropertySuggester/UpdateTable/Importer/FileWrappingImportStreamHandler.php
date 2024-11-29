<?php

declare( strict_types = 1 );

namespace PropertySuggester\Tests\PropertySuggester\UpdateTable\Importer;

class FileWrappingImportStreamHandler {

	/** @var resource|null Must exist on stream wrapper class */
	public $context;
	/** @var resource|null The opened file */
	protected $filehandle;

	// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function stream_open( $path, $mode, $options, &$opened_path ): bool {
		$filepath = substr( $path, strlen( "testimport://" ) );
		$this->filehandle = fopen( $filepath, $mode, false );
		return true;
	}

	public function stream_read( $bytes ) {
		return fread( $this->filehandle, $bytes );
	}

	public function stream_write( $data ): int {
		return 0;
	}

	public function stream_seek( $offset, $whence ): bool {
		return false;
	}

	public function stream_eof(): bool {
		return feof( $this->filehandle );
	}

	public function stream_tell() {
		return ftell( $this->filehandle );
	}

	public function stream_close(): bool {
		return fclose( $this->filehandle );
	}

	// phpcs:enable

}
