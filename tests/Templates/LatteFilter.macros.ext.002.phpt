<?php

/**
 * Test: Nette\Templates\LatteFilter and macros test.
 *
 * @author     David Grudl
 * @package    Nette\Templates
 * @subpackage UnitTests
 * @keepTrailingSpaces
 */

use Nette\Templates\Template,
	Nette\Templates\LatteFilter;



require __DIR__ . '/../initialize.php';

require __DIR__ . '/Template.inc';



// temporary directory
define('TEMP_DIR', __DIR__ . '/tmp');
TestHelpers::purge(TEMP_DIR);
Template::setCacheStorage(new MockCacheStorage(TEMP_DIR));



$template = new Template;
$template->setFile(__DIR__ . '/templates/latte.inheritance.child2.phtml');
$template->registerFilter(new LatteFilter);

$template->people = array('John', 'Mary', 'Paul');

Assert::match(file_get_contents(__DIR__ . '/LatteFilter.macros.ext.002.expect'), (string) $template);
