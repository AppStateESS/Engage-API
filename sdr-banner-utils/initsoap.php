<?php

require_once('ess/Autoloader.php');
$loader = new \ess\Autoloader();
$cfg = $loader->getStandardConfig();
$factory = new \ess\SoapClientFactory($cfg[SOAP_INTERFACE]);
return $factory->getClient(SOAP_CLIENT);

?>
