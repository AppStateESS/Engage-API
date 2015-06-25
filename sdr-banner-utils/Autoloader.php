<?php

namespace ess;
use \spl_autoload_register;
use \spl_autoload_unregister;

/**
 * Description
 * @author Jeff Tickle <jtickle at tux dot appstate dot edu>
 */

class Autoloader
{
    const STD_CONFIG = '/etc/ess/soap.ini';
    public function __construct()
    {
        $this->register();
    }

    public function register()
    {
        spl_autoload_register(__NAMESPACE__ . '\Autoloader::autoload');
    }

    public function unregister()
    {
        spl_autoload_unregister(__NAMESPACE__ . '\Autoloader::autoload');
    }

    public function getStandardConfig()
    {
        return parse_ini_file(self::STD_CONFIG, true);
    }

    static public function autoload($class)
    {
        if(substr($class, 0, 4) != 'ess\\') return;

        $class = substr($class, 4, strlen($class));

        require_once(dirname(__FILE__) . '/' . $class . '.php');
    }
}

?>
