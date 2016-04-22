#!/usr/bin/env php
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

$startTime = microtime(true);

$verbose = false;
$updateFile = '-';
for( $i=1; $i<count($argv); ++$i ) {
	if( $argv[$i] == '-i' ) {
		$updateFile = $argv[++$i];
	} else if( $argv[$i] == '-v' ) {
		$verbose = true;
	} else if( $argv[$i] == '-?' or $argv[$i] == '-h' or $argv[$i] == '--help' ) {
		fwrite(STDOUT, "Usage: {$argv[0]}: [-i <file>]\nPipe in a list of git object hashes.\n");
		exit(0);
	} else {
		fwrite(STDERR, "{$argv[0]}: Error: Unrecognized argument: '{$argv[$i]}'\n");
		exit(1);
	}
}

$cachedIdMap = array(); // map of x-git-object:... => urn:sha1:...

if( $updateFile ) {
	if( file_exists($updateFile) ) {
		$cacheStream = @fopen( $updateFile, 'rb' );
		if( $cacheStream === false ) {
			$err = error_get_last();
			fwrite(STDERR, "Failed to open $updateFile for reading: ".$err['message']."\n");
			exit(1);
		}
		while( ($line = fgets($cacheStream)) !== false ) {
			$line = trim($line);
			if( $line === '' or $line[0] === '#' ) continue;
			$urns = explode("\t", $line);
			$cachedIdMap[$urns[0]] = $urns[1]; // Assuming x-git-object:... urn:sha1:...
		}
		fclose($cacheStream);
	}
	// Now let's append!
	$outstream = @fopen($updateFile, 'ab');
	if( $outstream === false ) {
		$err = error_get_last();
		fwrite(STDERR, "Failed to open '$updateFile' for appending: ".$err['message']."\n");
		exit(1);
	}
} else {
	$outstream = STDOUT;
}

if( $verbose ) fwrite(STDERR, count($cachedIdMap)." entries cached\n");

$calcCount = 0;
$outputCount = 0;
$nonBlobCount = 0;
while( ($blobId = fgets(STDIN)) !== false ) {
	$blobId = trim($blobId);
	$gitObjectUrn = "x-git-object:{$blobId}";
	if( isset($cachedIdMap[$gitObjectUrn]) ) {
		continue;
	} else if( ($type = sys("git cat-file -t $blobId")) === 'blob' ) {
		$sha1sumOutput = sys("git cat-file blob $blobId | sha1sum");
		if( preg_match('/([0-9a-f]{40})/',$sha1sumOutput,$bif) ) {
			$sha1Hex = $bif[1];
			$sha1Base32 = TOGoS_Base32::encode(hex2bin($sha1Hex));
			$urn = "urn:sha1:{$sha1Base32}";
		} else {
			fwrite(STDERR, "Bad sha1sum output: {$sha1sumOutput}\n");
			continue;
		}
	} else {
		++$nonBlobCount;
		continue;
	}
	
	fwrite($outstream, "{$gitObjectUrn}\t{$urn}\n");
	if( ++$outputCount % 64 == 0 ) fflush($outstream); // Try to flush between lines
}

if( $verbose ) {
	$elapsedTime = microtime(true) - $startTime;
	fwrite(
		STDERR,
		$outputCount." new mappings written\n".
		$nonBlobCount." non-blobs looked at\n".
		$elapsedTime." seconds elapsed\n"
	);
}

if( $updateFile ) fclose($outstream);
