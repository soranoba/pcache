<?php
/**
 * @copyright 2016 Hinagiku Soranoba All Rights Reserved.
 */

namespace pcache\Middleware;

use pcache\Middleware;
use pcache\TTL;

/**
 * Class SharedMemory
 *
 * @see \pcache\Cache::instance()
 *
 * options:
 *     OPEN_KEY (requires) integer (1 <= $openKey <= 65535) : shmop's key
 *     SIZE_KEY (optional) integer (0 < $sizeKey) : shared memory size at first time.
 *     AUTO_DELETE (optional) bool (default: false) : If it is true, auto delete on destructor.
 */
class SharedMemory implements Middleware
{
    const OPEN_KEY = "key";
    const SIZE_KEY = "size";
    const AUTO_DELETE = "auto_delete";

    const VALUE = "v";
    const EXPIRES = "e";
    const DEFAULT_SIZE = 10 * 1024; // 1kB

    /**
     * @var int $shmid
     * @var int $size Shared memory size
     * @var int $openKey shmop's key (see: shmop_open)
     * @var bool $isAutoDelete
     */
    private $shmid = 0;
    private $size = 0;
    private $openKey;
    private $isAutoDelete = false;

    public function __construct(array $options)
    {
        $this->size = @$options[self::SIZE_KEY] ?: self::DEFAULT_SIZE;
        $this->openKey = $options[self::OPEN_KEY];
        $this->isAutoDelete = boolval(@$options[self::AUTO_DELETE]);
        $this->open();
    }

    public function __destruct()
    {
        if ($this->shmid) {
            if ($this->isAutoDelete) {
                shmop_delete($this->shmid);
            }
            shmop_close($this->shmid);
        }
    }

    //==========================================================================================================//
    // Middleware Interface Functions
    //==========================================================================================================//

    public static function validate($options)
    {
        if (!isset($options[self::OPEN_KEY])) {
            throw new \RuntimeException(__METHOD__ . " MUST need the option of " . self::OPEN_KEY);
        }
        $openKey = $options[self::OPEN_KEY];
        if (!(is_int($openKey) && $openKey >= 1 && $openKey <= 65535)) {
            throw new \RuntimeException(__METHOD__ . " " . self::OPEN_KEY . " type MUST be range of 1 to 65535");
        }
        if (isset($options[self::SIZE_KEY]) && !is_int($options[self::SIZE_KEY])) {
            throw new \RuntimeException(__METHOD__ . " " . self::SIZE_KEY . " type MUST be integer");
        }
        if (isset($options[self::AUTO_DELETE]) && !is_bool($options[self::AUTO_DELETE])) {
            throw new \RuntimeException(__METHOD__ . " " . self::AUTO_DELETE . " type MUST be boolean");
        }
    }

    public static function instanceName($options)
    {
        return strval(@$options[self::OPEN_KEY]);
    }

    public function deleteAll()
    {
        if ($this->shmid) {
            shmop_delete($this->shmid);
            shmop_close($this->shmid);
            $this->shmid = 0;
        }
    }

    public function get($key)
    {
        $this->exists();
        $obj = $this->read();
        if (!isset($obj[$key][self::VALUE])) {
            return false;
        }
        if (isset($obj[$key][self::EXPIRES]) && intval($obj[$key][self::EXPIRES]) < time()) {
            unset($obj[$key]);
            $this->write($obj);
            return false;
        }
        return $obj[$key][self::VALUE];
    }

    public function set($key, $value, $ttl = TTL::INFINITY)
    {
        $this->exists();
        $obj = $this->read();
        $obj = $this->updateObject($obj, $key, $value, $ttl);
        $this->write($obj);
    }

    public function delete($key)
    {
        $this->exists();
        $obj = $this->read();
        unset($obj[$key]);
        $this->write($obj);
    }

    public function ttl($key, $ttl = TTL::INFINITY)
    {
        $this->exists();
        $obj = $this->read();
        if (!isset($obj[$key])) {
            return;
        }
        $obj = $this->updateObject($obj, $key, $obj[$key][self::VALUE], $ttl);
        $this->write($obj);
    }

    public function getAll()
    {
        $this->exists();
        $obj = $this->read();
        $ret = $writeObj = array();
        foreach ($obj as $key => $value) {
            if (isset($obj[$key][self::EXPIRES]) && intval($obj[$key][self::EXPIRES]) < time()) {
                continue;
            }
            $ret[$key] = $value[self::VALUE];
            $writeObj[$key] = $value;
        }
        $this->write($writeObj);
        return $ret;
    }

    //==========================================================================================================//
    // Private Functions
    //==========================================================================================================//

    /**
     * update (or insert) key-value with ttl in $object.
     *
     * @param array $obj
     * @param string $key
     * @param string $value
     * @param integer $ttl
     * @return array
     */
    private function updateObject(array $obj, $key, $value, $ttl)
    {
        if ($ttl == TTL::INFINITY) {
            $obj[$key] = [self::VALUE => $value];
        } elseif ($ttl == TTL::NO_CHANGE) {
            $obj[$key][self::VALUE] = $value;
        } elseif (isset($obj[$key][self::EXPIRES]) && $obj[$key][self::EXPIRES] < time()) {
            unset($obj[$key]);
        } elseif ($ttl > 0) {
            $obj[$key] = [self::VALUE => $value, self::EXPIRES => time() + $ttl];
        }
        return $obj;
    }

    /**
     * If shared memory isn't opened, open method is called. Otherwise, NOP.
     */
    private function exists()
    {
        if (!$this->shmid) {
            $this->open();
        }
    }

    /**
     * open the Shared memory.
     */
    private function open()
    {
        $size = $this->size;
        $this->shmid = @shmop_open($this->openKey, "c", 0644, $size);
        if (!$this->shmid) {
            if (!($this->shmid = @shmop_open($this->openKey, "w", 0644, 0))) {
                new \RuntimeException(
                    "It MAY have reached the upper limit of the shared memory size (allocation size: {$size})"
                );
            } else {
                $this->size = shmop_size($this->shmid);
            }
        }
    }

    /**
     * Read the shared memory.
     * @return array
     */
    private function read()
    {
        $readObj = shmop_read($this->shmid, 0, $this->size);
        $index = strpos($readObj, "\0");
        return $index ? unserialize(substr($readObj, 0, $index)) : array();
    }

    /**
     * Write the shared memory.
     *
     * @param array $obj
     */
    private function write(array $obj)
    {
        $serializedObj = serialize($obj);
        if (($len = strlen($serializedObj)) > $this->size) {
            do {
                $this->size *= 2;
            } while ($len > $this->size);
            $this->deleteAll();
            $this->open();
        }
        shmop_write($this->shmid, $serializedObj, 0);
    }
}