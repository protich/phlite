<?php

namespace Phlite;

use Phlite\Logging\Handlers\FileHandler;
use Phlite\Logging\Handlers\StreamHandler;
use Phlite\Logging\Formatter;
use Phlite\Logging\Logger;
use Phlite\Logging\Manager;
use Phlite\Logging\RootLogger;

/**
 * Do basic configuration for the logging system.
 *
 * This function does nothing if the root logger already has handlers
 * configured. It is a convenience method intended for use by simple scripts
 * to do one-shot configuration of the logging package.
 *
 * The default behaviour is to create a StreamHandler which writes to
 * sys.stderr, set a formatter using the BASIC_FORMAT format string, and
 * add the handler to the root logger.
 *
 * A number of optional keyword arguments may be specified, which can alter
 * the default behaviour.
 *
 * filename  Specifies that a FileHandler be created, using the specified
 *           filename, rather than a StreamHandler.
 * filemode  Specifies the mode to open the file, if filename is specified
 *           (if filemode is unspecified, it defaults to 'a').
 * format    Use the specified format string for the handler.
 * datefmt   Use the specified date/time format.
 * level     Set the root logger level to the specified level.
 * stream    Use the specified stream to initialize the StreamHandler. Note
 *           that this argument is incompatible with 'filename' - if both
 *           are present, 'stream' is ignored.
 *
 * Note that you could specify a resource created using fopen(filename,
 * mode) rather than passing the filename and mode in. However, it should be
 * remembered that StreamHandler does not close its stream (since it may be
 * using php://stdout or php://stderr), whereas FileHandler closes its
 * stream when the handler is closed.
 */
function basicConfig($config) {
    if (count(Logging::$root->handlers) == 0) {
        $filename = @$config['filename'];
        if ($filename) {
            $mode = @$config['filemode'] ?: 'a';
            $hdlr = new FileHandler($filename, $mode);
        }
        else {
            $stream = @$config['stream'];
            $hdlr = new StreamHandler($stream);
        }
        $fs = @$config['format'] ?: Logging::$BASIC_FORMAT;
        $dfs = @$config['datefmt'] ?: null;
        $fmt = new Formatter($fs, $dfs);
        $hdlr->setFormatter($fmt);
        Logging::$root->addHandler($hdlr);
        $level = @$config['level'];
        if (isset($level)) {
            Logging::$root->setLevel($level);
        }
    }
}

function getLogger($name=null) {
    if ($name) {
        return Logger::$manager->getLogger($name);
    }
    else {
        return Logging::$root;
    }
}

function critical($msg, $context=array()) {
    if (count(Logging::$root->handlers) == 0) {
        namespace\basicConfig();
    }
    Logging::$root->critical($msg, $context);
}

class Logging {
    static $root;
    static $BASIC_FORMAT = '{levelname}:{name}:{message}';
}

Logging::$root = new RootLogger(Logger::WARNING);
Logger::$root = Logging::$root;
Logger::$manager = new Manager(Logger::$root);
Manager::$loggerClass = __NAMESPACE__.'\Logging\Logger';
