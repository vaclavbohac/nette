<?php

/**
 * Test: Nette\Config\Adapters\NeonAdapter errors.
 *
 * @author     David Grudl
 * @package    Nette\Config
 * @subpackage UnitTests
 */

use Nette\Config\Config;



require __DIR__ . '/../bootstrap.php';



Assert::throws(function() {
	$config = new Config;
	$config->load('files/config.scalar1.neon');
}, 'Nette\InvalidStateException', "Duplicated key 'scalar'.");
