<?php

/**
 * Test: Nette\Debug::consoleDump() in non-HTML mode.
 *
 * @author     David Grudl
 * @package    Nette
 * @subpackage UnitTests
 */

use Nette\Debug;



require __DIR__ . '/../initialize.php';



Debug::$consoleMode = FALSE;
Debug::$productionMode = FALSE;
header('Content-Type: text/plain');

Debug::enable();

function shutdown() {
	Assert::same('', ob_get_clean());
}
Assert::handler('shutdown');
