<?php

class Zip {

    protected $file = '';
    protected $fh;
    protected $memory = '';
    protected $closed = true;
    protected $writeaccess = false;
    protected $ctrl_dir;

    /**
     * Open an existing ZIP file for reading
     *
     * @param string $file
     * @throws ZipIOException
     */
    public function open($file) {
        $this->file = $file;
        $this->fh   = @fopen($this->file, 'rb');
        if(!$this->fh) throw new ZipIOException('Could not open file for reading: '.$this->file);
        $this->closed = false;
    }

    /**
     * Read the contents of a ZIP archive
     *
     * This function lists the files stored in the archive, and returns an indexed array of associative
     * arrays containing for each file the following information:
     *
     * checksum    Tar Checksum of the file
     * filename    The full name of the stored file (up to 100 c.)
     * mode        UNIX permissions in DECIMAL, not octal
     * uid         The Owner ID
     * gid         The Group ID
     * size        Uncompressed filesize
     * mtime       Timestamp of last modification
     * typeflag    Empty for files, set for folders
     * link        Is it a symlink?
     * uname       Owner name
     * gname       Group name
     *
     * The archive is closed afer reading the contents, because rewinding is not possible in bzip2 streams.
     * Reopen the file with open() again if you want to do additional operations
     */
    public function contents() {
        if($this->closed || !$this->file) throw new ZipIOException('Can not read from a closed archive');

        $result = array();

        $centd = $this->readCentralDir();

        @rewind($this->fh);
        @fseek($this->fh, $centd['offset']);

        for($i = 0; $i < $centd['entries']; $i++) {
            $header = $this->readCentralFileHeader();

            $info                    = array();
            $info['filename']        = $header['filename'];
            $info['stored_filename'] = $header['stored_filename'];
            $info['size']            = $header['size'];
            $info['compressed_size'] = $header['compressed_size'];
            $info['crc']             = strtoupper(dechex($header['crc']));
            $info['mtime']           = $header['mtime'];
            $info['comment']         = $header['comment'];
            $info['folder']          = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;
            $info['index']           = $i;
            $info['status']          = $header['status'];
            $result[]                = $info;
        }

        $this->close();
        return $result;

    }

    /**
     * Extract an existing ZIP archive
     *
     * The $strip parameter allows you to strip a certain number of path components from the filenames
     * found in the tar file, similar to the --strip-components feature of GNU tar. This is triggered when
     * an integer is passed as $strip.
     * Alternatively a fixed string prefix may be passed in $strip. If the filename matches this prefix,
     * the prefix will be stripped. It is recommended to give prefixes with a trailing slash.
     *
     * By default this will extract all files found in the archive. You can restrict the output using the $include
     * and $exclude parameter. Both expect a full regular expression (including delimiters and modifiers). If
     * $include is set only files that match this expression will be extracted. Files that match the $exclude
     * expression will never be extracted. Both parameters can be used in combination. Expressions are matched against
     * stripped filenames as described above.
     *
     * The archive is closed afer reading the contents, because rewinding is not possible in bzip2 streams.
     * Reopen the file with open() again if you want to do additional operations
     *
     * @param string     $outdir  the target directory for extracting
     * @param int|string $strip   either the number of path components or a fixed prefix to strip
     * @param string     $exclude a regular expression of files to exclude
     * @param string     $include a regular expression of files to include
     * @throws ZipIOException
     * @return array
     */
    function extract($outdir, $strip = '', $exclude = '', $include = '') {
        if($this->closed || !$this->file) throw new ZipIOException('Can not read from a closed archive');

        $outdir = rtrim($outdir, '/');
        io_mkdir_p($outdir);
        $striplen = strlen($strip);

        $extracted = array();

        $cdir      = $this->readCentralDir();
        $pos_entry = $cdir['offset']; // begin of the central file directory

        for($i = 0; $i < $cdir['entries']; $i++) {
            @fseek($this->fh, $pos_entry);
            $header          = $this->readCentralFileHeader();
            $header['index'] = $i;
            $pos_entry       = ftell($this->fh); // position of the next file in central file directory

            //FIXME should be unneeded: @rewind($zip);
            fseek($this->fh, $header['offset']); // seek to beginning of file header
            $header = $this->readFileHeader($header);

            // strip prefix
            $filename = $this->cleanPath($header['filename']);
            if(is_int($strip)) {
                // if $strip is an integer we strip this many path components
                $parts = explode('/', $filename);
                if(!$header['typeflag']) {
                    $base = array_pop($parts); // keep filename itself
                } else {
                    $base = '';
                }
                $filename = join('/', array_slice($parts, $strip));
                if($base) $filename .= "/$base";
            } else {
                // if strip is a string, we strip a prefix here
                if(substr($filename, 0, $striplen) == $strip) $filename = substr($filename, $striplen);
            }

            // check if this should be extracted
            $extract = true;
            if(!$filename) {
                $extract = false;
            } else {
                if($include) {
                    if(preg_match($include, $filename)) {
                        $extract = true;
                    } else {
                        $extract = false;
                    }
                }
                if($exclude && preg_match($exclude, $filename)) {
                    $extract = false;
                }
            }

            // Now do the extraction (or not)
            if($extract) {
                $extracted[] = $header;

                $output    = "$outdir/$filename";
                $directory = ($header['folder']) ? $output : dirname($output);
                io_mkdir_p($directory);

                // nothing more to do for directories
                if($header['folder']) continue;

                // compressed files are written to temporary .gz file first
                if($header['compression'] == 0) {
                    $extractto = $output;
                } else {
                    $extractto = $output.'.gz';
                }

                // open file for writing
                $fp = fopen($extractto, "wb");
                if(!$fp) throw new ZipIOException('Could not open file for writing: '.$extractto);

                // prepend compression header
                if($header['compression'] != 0) {
                    $binary_data = pack(
                        'va1a1Va1a1', 0x8b1f, chr($header['compression']),
                        chr(0x00), time(), chr(0x00), chr(3)
                    );
                    fwrite($fp, $binary_data, 10);
                }

                // read the file and store it on disk
                $size = $header['compressed_size'];
                while($size != 0) {
                    $read_size   = ($size < 2048 ? $size : 2048);
                    $buffer      = fread($this->fh, $read_size);
                    $binary_data = pack('a'.$read_size, $buffer);
                    fwrite($fp, $binary_data, $read_size);
                    $size -= $read_size;
                }

                // finalize compressed file
                if($header['compression'] != 0) {
                    $binary_data = pack('VV', $header['crc'], $header['size']);
                    fwrite($fp, $binary_data, 8);
                }

                // close file
                fclose($fp);

                // unpack compressed file
                if($header['compression'] != 0) {
                    $gzp = @gzopen($extractto, 'rb');
                    if(!$gzp) {
                        @unlink($extractto);
                        throw new ZipIOException('Failed file extracting. gzip support missing?');
                    }
                    $fp = @fopen($output, 'wb');
                    if(!$fp) throw new ZipIOException('Could not open file for writing: '.$extractto);

                    $size = $header['size'];
                    while($size != 0) {
                        $read_size   = ($size < 2048 ? $size : 2048);
                        $buffer      = gzread($gzp, $read_size);
                        $binary_data = pack('a'.$read_size, $buffer);
                        @fwrite($fp, $binary_data, $read_size);
                        $size -= $read_size;
                    }
                    fclose($fp);
                    gzclose($gzp);
                }

                touch($output, $header['mtime']);
                //FIXME what about permissions?
            }
        }

        $this->close();
        return $extracted;
    }

    /**
     * Create a new ZIP file
     *
     * If $file is empty, the zip file will be created in memory
     *
     * @param string $file
     * @throws ZipIOException
     */
    public function create($file = '') {
        $this->file   = $file;
        $this->memory = '';
        $this->fh     = 0;

        if($this->file) {
            $this->fh = @fopen($this->file, 'wb');

            if(!$this->fh) throw new ZipIOException('Could not open file for writing: '.$this->file);
        }
        $this->writeaccess = true;
        $this->closed      = false;
        $this->ctrl_dir    = array();
    }

    /**
     * Add a file to the current ZIP archive using an existing file in the filesystem
     *
     * @todo handle directory adding
     * @param string $file     the original file
     * @param string $name     the name to use for the file in the archive
     * @param int    $compress Compression level, 0 for no compression
     * @throws ZipIOException
     */
    public function addFile($file, $name = '', $compress = 9) {
        if($this->closed) throw new ZipIOException('Archive has been closed, files can no longer be added');

        if(!$name) $name = $file;

        $data = @file_get_contents($file);
        if($data === false) throw new ZipIOException('Could not open file for reading: '.$file);

        $mtime = filemtime($file);

        // FIXME could we stream writing compressed data? gzwrite on a fopen handle?
        $this->addData($name, $data, $mtime, $compress);
    }

    /**
     * Add a file to the current TAR archive using the given $data as content
     *
     * @param string $name
     * @param string $data
     * @param int    $mtime
     * @param int    $compress Compression level, 0 for no compression
     * @throws ZipIOException
     */
    public function addData($name, $data, $mtime = 0, $compress = 9) {
        if($this->closed) throw new ZipIOException('Archive has been closed, files can no longer be added');

        // prepare the various header infos
        $name = $this->cleanPath($name);
        if(!$mtime) $mtime = time();
        $dtime    = dechex($this->makeDosTime($mtime));
        $hexdtime = pack(
            'H*', $dtime[6].$dtime[7].
                $dtime[4].$dtime[5].
                $dtime[2].$dtime[3].
                $dtime[0].$dtime[1]
        );
        $size     = strlen($data);
        $crc      = crc32($data);
        if($compress) {
            $fmagic = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00";
            $cmagic = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00";
            $data   = gzcompress($data, $compress);
            $data   = substr($data, 2, -4); // strip compression headers
        } else {
            $fmagic = "\x50\x4b\x03\x04\x0a\x00\x00\x00\x00\x00";
            $cmagic = "\x50\x4b\x01\x02\x14\x00\x0a\x00\x00\x00\x00\x00";
        }
        $csize  = strlen($data);
        $offset = $this->dataOffset();

        // write data
        $this->writebytes($fmagic);
        $this->writebytes($hexdtime);
        $this->writebytes(pack('V', $crc).pack('V', $csize).pack('V', $size)); //pre header
        $this->writebytes(pack('v', strlen($name)).pack('v', 0).$name.$data); //file data
        $this->writebytes(pack('V', $crc).pack('V', $csize).pack('V', $size)); //post header

        // add info to central file directory
        $cdrec = $cmagic;
        $cdrec .= $hexdtime.pack('V', $crc).pack('V', $csize).pack('V', $size);
        $cdrec .= pack('v', strlen($name)).pack('v', 0).pack('v', 0);
        $cdrec .= pack('v', 0).pack('v', 0).pack('V', 32);
        $cdrec .= pack('V', $offset);
        $cdrec .= $name;
        $this->ctrl_dir[] = $cdrec;
    }

    /**
     * Add the closing footer to the archive if in write mode, close all file handles
     *
     * After a call to this function no more data can be added to the archive, for
     * read access no reading is allowed anymore
     */
    public function close() {
        if($this->closed) return; // we did this already

        // write footer
        if($this->writeaccess) {
            $offset  = $this->dataOffset();
            $ctrldir = join('', $this->ctrl_dir);
            $this->writebytes($ctrldir);
            $this->writebytes("\x50\x4b\x05\x06\x00\x00\x00\x00"); // EOF CTRL DIR
            $this->writebytes(pack('v', count($this->ctrl_dir)).pack('v', count($this->ctrl_dir)));
            $this->writebytes(pack('V', strlen($ctrldir)).pack('V', strlen($offset))."\x00\x00");
            $this->ctrl_dir = array();
        }

        // close file handles
        if($this->file) {
            fclose($this->fh);
            $this->file = '';
            $this->fh   = 0;
        }

        $this->writeaccess = false;
        $this->closed      = true;
    }

    /**
     * Returns the created in-memory archive data
     *
     * This implicitly calls close() on the Archive
     */
    public function getArchive() {
        $this->close();

        return $this->memory;
    }

    /**
     * Save the created in-memory archive data
     *
     * Note: It's more memory effective to specify the filename in the create() function and
     * let the library work on the new file directly.
     *
     * @param     $file
     * @throws ZipIOException
     */
    public function save($file) {
        if(!file_put_contents($file, $this->getArchive())) {
            throw new ZipIOException('Could not write to file: '.$file);
        }
    }

    /**
     * Cleans up a path and removes relative parts, also strips leading slashes
     *
     * @param string $path
     * @return string
     */
    public function cleanPath($path) {
        $path    = str_replace('\\', '/', $path);
        $path    = explode('/', $path);
        $newpath = array();
        foreach($path as $p) {
            if($p === '' || $p === '.') continue;
            if($p === '..') {
                array_pop($newpath);
                continue;
            }
            array_push($newpath, $p);
        }
        return trim(implode('/', $newpath), '/');
    }

    /**
     * Read the central directory
     *
     * This key-value list contains general information about the ZIP file
     *
     * @return array
     */
    protected function readCentralDir() {
        $size = filesize($this->file);
        if($size < 277) {
            $maximum_size = $size;
        } else {
            $maximum_size = 277;
        }

        @fseek($this->fh, $size - $maximum_size);
        $pos   = ftell($this->fh);
        $bytes = 0x00000000;

        while($pos < $size) {
            $byte  = @fread($this->fh, 1);
            $bytes = (($bytes << 8) & 0xFFFFFFFF) | ord($byte);
            if($bytes == 0x504b0506) {
                break;
            }
            $pos++;
        }

        $data = unpack(
            'vdisk/vdisk_start/vdisk_entries/ventries/Vsize/Voffset/vcomment_size',
            fread($this->fh, 18)
        );

        if($data['comment_size'] != 0) {
            $centd['comment'] = fread($this->fh, $data['comment_size']);
        } else {
            $centd['comment'] = '';
        }
        $centd['entries']      = $data['entries'];
        $centd['disk_entries'] = $data['disk_entries'];
        $centd['offset']       = $data['offset'];
        $centd['disk_start']   = $data['disk_start'];
        $centd['size']         = $data['size'];
        $centd['disk']         = $data['disk'];
        return $centd;
    }

    /**
     * Read the next central file header
     *
     * Assumes the current file pointer is pointing at the right position
     *
     * @return array
     */
    protected function readCentralFileHeader() {
        $binary_data = fread($this->fh, 46);
        $header      = unpack(
            'vchkid/vid/vversion/vversion_extracted/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len/vcomment_len/vdisk/vinternal/Vexternal/Voffset',
            $binary_data
        );

        if($header['filename_len'] != 0) {
            $header['filename'] = fread($this->fh, $header['filename_len']);
        } else {
            $header['filename'] = '';
        }

        if($header['extra_len'] != 0) {
            $header['extra'] = fread($this->fh, $header['extra_len']);
        } else {
            $header['extra'] = '';
        }

        if($header['comment_len'] != 0) {
            $header['comment'] = fread($this->fh, $header['comment_len']);
        } else {
            $header['comment'] = '';
        }

        if($header['mdate'] && $header['mtime']) {
            $hour            = ($header['mtime'] & 0xF800) >> 11;
            $minute          = ($header['mtime'] & 0x07E0) >> 5;
            $seconde         = ($header['mtime'] & 0x001F) * 2;
            $year            = (($header['mdate'] & 0xFE00) >> 9) + 1980;
            $month           = ($header['mdate'] & 0x01E0) >> 5;
            $day             = $header['mdate'] & 0x001F;
            $header['mtime'] = mktime($hour, $minute, $seconde, $month, $day, $year);
        } else {
            $header['mtime'] = time();
        }

        $header['stored_filename'] = $header['filename'];
        $header['status']          = 'ok';
        if(substr($header['filename'], -1) == '/') $header['external'] = 0x41FF0010;
        $header['folder'] = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;

        return $header;
    }

    /**
     * Reads the local file header
     *
     * This header precedes each individual file inside the zip file. Assumes the current file pointer is pointing at
     * the right position already. Enhances this given central header with the data found at the local header.
     *
     * @param array $header the central file header read previously (see above)
     * @return array
     */
    function readFileHeader($header) {
        $binary_data = fread($this->fh, 30);
        $data        = unpack('vchk/vid/vversion/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len', $binary_data);

        $header['filename'] = fread($this->fh, $data['filename_len']);
        if($data['extra_len'] != 0) {
            $header['extra'] = fread($this->fh, $data['extra_len']);
        } else {
            $header['extra'] = '';
        }

        $header['compression'] = $data['compression'];
        foreach(array('size', 'compressed_size', 'crc') as $hd) { // On ODT files, these headers are 0. Keep the previous value.
            if($data[$hd] != 0) $header[$hd] = $data[$hd];
        }
        $header['flag']  = $data['flag'];
        $header['mdate'] = $data['mdate'];
        $header['mtime'] = $data['mtime'];

        if($header['mdate'] && $header['mtime']) {
            $hour            = ($header['mtime'] & 0xF800) >> 11;
            $minute          = ($header['mtime'] & 0x07E0) >> 5;
            $seconde         = ($header['mtime'] & 0x001F) * 2;
            $year            = (($header['mdate'] & 0xFE00) >> 9) + 1980;
            $month           = ($header['mdate'] & 0x01E0) >> 5;
            $day             = $header['mdate'] & 0x001F;
            $header['mtime'] = mktime($hour, $minute, $seconde, $month, $day, $year);
        } else {
            $header['mtime'] = time();
        }

        $header['stored_filename'] = $header['filename'];
        $header['status']          = "ok";
        $header['folder']          = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;
        return $header;
    }

    /**
     * Write to the open filepointer or memory
     *
     * @param string $data
     * @throws ZipIOException
     * @return int number of bytes written
     */
    protected function writebytes($data) {
        if(!$this->file) {
            $this->memory .= $data;
            $written = strlen($data);
        } else {
            $written = @fwrite($this->fh, $data);
        }
        if($written === false) throw new ZipIOException('Failed to write to archive stream');
        return $written;
    }

    /**
     * Current data pointer position
     *
     * @fixme might need a -1
     * @return int
     */
    protected function dataOffset() {
        if($this->file) {
            return ftell($this->fh);
        } else {
            return strlen($this->memory);
        }
    }

    /**
     * Create a DOS timestamp from a UNIX timestamp
     *
     * DOS timestamps start at 1980-01-01, earlier UNIX stamps will be set to this date
     *
     * @param $time
     * @return int
     */
    protected function makeDosTime($time) {
        $timearray = getdate($time);
        if($timearray['year'] < 1980) {
            $timearray['year']    = 1980;
            $timearray['mon']     = 1;
            $timearray['mday']    = 1;
            $timearray['hours']   = 0;
            $timearray['minutes'] = 0;
            $timearray['seconds'] = 0;
        }
        return (($timearray['year'] - 1980) << 25) |
        ($timearray['mon'] << 21) |
        ($timearray['mday'] << 16) |
        ($timearray['hours'] << 11) |
        ($timearray['minutes'] << 5) |
        ($timearray['seconds'] >> 1);
    }

}

class ZipIOException extends Exception {
}
