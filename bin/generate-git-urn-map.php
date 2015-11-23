#!/usr/bin/php
<?php

class TOGoS_Base32 {
	protected static $base32Chars =
		"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

	/**
	 * Encodes byte array to Base32 String.
	 *
	 * Unlike the encoding described in RFC 3548, this does not
	 * pad encoded data with '=' characters.
	 */
	public static function encode( $bytes ) {
		$i = 0; $index = 0; $digit = 0;
		$base32 = "";

		while( $i < strlen($bytes) ) {
			$currByte = ord($bytes{$i});
			/* Is the current digit going to span a byte boundary? */
			if( $index > 3 ) {
				if( ($i + 1) < strlen($bytes) ) {
					$nextByte = ord($bytes{$i+1});
				} else {
					$nextByte = 0;
				}

				$digit = $currByte & (0xFF >> $index);
				$index = ($index + 5) % 8;
				$digit <<= $index;
				$digit |= $nextByte >> (8 - $index);
				$i++;
			} else {
				$digit = ($currByte >> (8 - ($index + 5))) & 0x1F;
				$index = ($index + 5) % 8;
				if( $index == 0 ) $i++;
			}
			$base32 .= self::$base32Chars{$digit};
		}

		return $base32;
	}
}

function sys($cmd) {
	//fwrite(STDERR, "$ $cmd\n");
	$rez = trim(`$cmd`);
	$lines = explode("\n", $rez);
	foreach( $lines as $l ) {
		//fwrite(STDERR, "  -> $l\n");
	}
	return $rez;
}

while( ($blobId = fgets(STDIN)) !== false ) {
	$blobId = trim($blobId);
	$type = sys("git cat-file -t $blobId");
	if( $type === 'blob' ) {
		$sha1sumOutput = sys("git cat-file blob $blobId | sha1sum");
		if( preg_match('/([0-9a-f]{40})/',$sha1sumOutput,$bif) ) {
			$sha1Hex = $bif[1];
			$sha1Base32 = TOGoS_Base32::encode(hex2bin($sha1Hex));
			echo "x-git-object:{$blobId}\turn:sha1:{$sha1Base32}\n";
		} else {
			fwrite(STDERR, "Bad sha1sum output: {$sha1sumOutput}\n");
		}
	}
}
