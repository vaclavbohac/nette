<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004, 2011 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Nette\DI;

use Nette,
	Nette\Utils\Arrays,
	Nette\Utils\PhpGenerator\Helpers,
	Nette\Utils\PhpGenerator\PhpLiteral;



/**
 * Basic container builder.
 *
 * @author     David Grudl
 */
class ContainerBuilder extends Nette\Object
{
	const CREATED_SERVICE = 'self',
		THIS_CONTAINER = 'container';

	/** @var array */
	public $parameters = array();

	/** @var array */
	private $definitions = array();

	/** @var array */
	private $classes;

	/** @var array */
	private $dependencies = array();



	/**
	 * Adds new service definition. Expands %param% and @service values.
	 * @param  string
	 * @return ServiceDefinition
	 */
	public function addDefinition($name)
	{
		if (isset($this->definitions[$name])) {
			throw new Nette\InvalidStateException("Service '$name' has already been added.");
		}
		return $this->definitions[$name] = new ServiceDefinition;
	}



	/**
	 * Removes the specified service definition.
	 * @return void
	 */
	public function removeDefinition($name)
	{
		unset($this->definitions[$name]);
	}



	/**
	 * Gets the service definition.
	 * @param  string
	 * @return ServiceDefinition
	 */
	public function getDefinition($name)
	{
		if (!isset($this->definitions[$name])) {
			throw new MissingServiceException("Service '$name' not found.");
		}
		return $this->definitions[$name];
	}



	/**
	 * Does the service definition exist?
	 * @param  string
	 * @return bool
	 */
	public function hasDefinition($name)
	{
		return isset($this->definitions[$name]);
	}



	/********************* class resolving ****************d*g**/



	/**
	 * Resolves service name by type.
	 * @param  string  class or interface
	 * @return string  service name or NULL
	 * @throws ServiceCreationException
	 */
	public function findByClass($class)
	{
		$classes = & $this->classes[ltrim(strtolower($class), '\\')];
		if (isset($classes[TRUE]) && count($classes[TRUE]) === 1) {
			return $classes[TRUE][0];

		} elseif (!isset($classes[TRUE]) && isset($classes[FALSE]) && count($classes[FALSE]) === 1) {
			return $classes[FALSE][0];

		} elseif (isset($classes[TRUE])) {
			throw new ServiceCreationException("Multiple preferred services of type $class found: " . implode(', ', $classes[TRUE]));

		} elseif (isset($classes[FALSE])) {
			throw new ServiceCreationException("Multiple services of type $class found: " . implode(', ', $classes[FALSE]));
		}
	}



	/**
	 * Gets the service objects of the specified tag.
	 * @param  string
	 * @return array of [service name => tag attributes]
	 */
	public function findByTag($tag)
	{
		$found = array();
		foreach ($this->definitions as $name => $definition) {
			if (isset($definition->tags[$tag])) {
				$found[$name] = $definition->tags[$tag];
			}
		}
		return $found;
	}



	/**
	 * Generates list of arguments using autowiring.
	 * @return array
	 */
	public function autowireArguments($class, $method, array $arguments)
	{
		$optCount = 0;
		$num = -1;
		$res = array();
		try {
			$rm = Nette\Reflection\Method::from($class, $method);
			if ($rm->isAbstract() || !$rm->isPublic()) {
				throw new ServiceCreationException("$rm is not callable.");
			}
			$this->dependencies[$rm->getFileName()] = TRUE;

			foreach ($rm->getParameters() as $num => $parameter) {
				if (array_key_exists($num, $arguments)) {
					$res[$num] = $arguments[$num];
					unset($arguments[$num]);
					$optCount = 0;

				} elseif (array_key_exists($parameter->getName(), $arguments)) {
					$res[$num] = $arguments[$parameter->getName()];
					unset($arguments[$parameter->getName()]);
					$optCount = 0;

				} elseif ($parameter->getClass()) {
					$service = $this->findByClass($parameter->getClass()->getName());
					if ($service === NULL) {
						if ($parameter->allowsNull()) {
							$res[$num] = NULL;
							$optCount++;
						} else {
							throw new ServiceCreationException("No service of type {$parameter->getClass()->getName()} found");
						}
					} else {
						$res[$num] = '@' . $service;
						$optCount = 0;
					}

				} elseif ($parameter->isOptional()) {
					$res[$num] = $parameter->getDefaultValue();
					$optCount++;

				} elseif ($parameter->allowsNull()) {
					$res[$num] = NULL;
					$optCount = 0;

				} else {
					throw new ServiceCreationException("$parameter is missing.");
				}
			}

		} catch (\ReflectionException $e) {
			if ($arguments && $method === '__construct') {
				throw new ServiceCreationException("Unable to pass arguments, class $class has not constructor.");
			}
		}

		// extra parameters
		while (array_key_exists(++$num, $arguments)) {
			$res[$num] = $arguments[$num];
			unset($arguments[$num]);
			$optCount = 0;
		}
		if ($arguments) {
			throw new ServiceCreationException("Unexcepted parameters: " . implode(', ', array_keys($arguments)));
		}

		return $optCount ? array_slice($res, 0, -$optCount) : $res;
	}



	public function prepareClassList()
	{
		$this->classes = $this->dependencies = array();

		$self = 'Nette\DI\Container';
		foreach (class_parents($self) + class_implements($self) + array($self) as $parent) {
			$this->classes[strtolower($parent)][TRUE][] = self::THIS_CONTAINER;
		}

		foreach ($this->definitions as $name => $definition) {
			if (!$definition->class && $definition->factory) {
				$factory = is_array($definition->factory) ? $definition->factory : explode('::', $definition->factory);
				if (self::isService($factory[0]) && isset($this->definitions[substr($factory[0], 1)]->class)) {
					$factory[0] = $this->definitions[substr($factory[0], 1)]->class;
				}
				if (self::isExpanded(implode('', $factory))) {
					$factory = callback($factory);
					if (!$factory->isCallable()) {
						throw new Nette\InvalidStateException("Factory '$factory' is not callable.");
					}
					try {
						$definition->class = $factory->toReflection()->getAnnotation('return');
					} catch (\ReflectionException $e) {
					}
				}
			}

			if ($definition->class && self::isExpanded($definition->class)) {
				if (!class_exists($definition->class) && !interface_exists($definition->class)) {
					throw new Nette\InvalidStateException("Class $definition->class" . (isset($factory) ? " returned by $factory" : '') . " has not been found.");
				}
				foreach (class_parents($definition->class) + class_implements($definition->class) + array($definition->class) as $parent) {
					$this->classes[strtolower($parent)][(bool) $definition->autowired][] = $name;
				}
			}
			$factory = NULL;
		}

		foreach ($this->classes as $class => $foo) {
			$this->dependencies[Nette\Reflection\ClassType::from($class)->getFileName()] = TRUE;
		}
	}



	/**
	 * Returns class files.
	 * @return array
	 */
	public function getDependencies()
	{
		unset($this->dependencies[FALSE]);
		return array_keys($this->dependencies);
	}



	/********************* code generator ****************d*g**/



	/**
	 * Generates PHP class.
	 * @return Nette\Utils\PhpGenerator\ClassType
	 */
	public function generateClass()
	{
		$this->prepareClassList();

		$class = new Nette\Utils\PhpGenerator\ClassType('Container');
		$class->addExtend('Nette\DI\Container');
		$class->addProperty('parameters', $this->parameters);

		$classes = $class->addProperty('classes', array());
		foreach ($this->classes as $name => $foo) {
			try {
				$classes->value[$name] = $this->findByClass($name);
			} catch (ServiceCreationException $e) {
				$classes->value[$name] = FALSE;
			}
		}

		$meta = $class->addProperty('meta', array());
		foreach ($this->definitions as $name => $def) {
			foreach ($def->tags as $tag => $value) {
				$meta->value[$name][Container::TAGS][$tag] = $value;
			}
		}

		foreach ($this->definitions as $name => $definition) {
			try {
				$type = $definition->class && self::isExpanded($definition->class) ? $definition->class : 'object';
				$class->addDocument("@property $type \$$name");
				$class->addMethod('createService' . ucfirst($name))
					->addDocument("@return $type")
					->setBody($this->generateService($name));
			} catch (\Exception $e) {
				throw new ServiceCreationException("Error creating service '$name': {$e->getMessage()}", 0, $e);
			}
		}

		return $class;
	}



	/**
	 * Generates factory method code for service.
	 * @return string
	 */
	private function generateService($name)
	{
		$definition = $this->definitions[$name];

		if ($definition->factory) {
			$code = '$service = ' . $this->formatCall($definition->factory, $definition->arguments);
			if ($definition->class) {
				$message = var_export("Unable to create service '$name', value returned by factory is not % type.", TRUE);
				if (self::isExpanded($definition->class)) {
					$code .= "if (!\$service instanceof $definition->class) {\n\t"
						. 'throw new Nette\UnexpectedValueException(' . str_replace('%', $definition->class, $message) . ");\n}\n";
				} else {
					$code .= $this->formatPhp('$class = ?;', array($definition->class))
						. 'if (!$service instanceof $class) {' . "\n\t"
						. 'throw new Nette\UnexpectedValueException(' . str_replace('%', "'.\$class.'", $message) . ");\n}\n";
				}
			}

		} elseif ($definition->class) { // class
			if (self::isExpanded($definition->class)) {
				$arguments = $this->autowireArguments($definition->class, '__construct', (array) $definition->arguments);
				$code = $this->formatPhp("\$service = new $definition->class" . ($arguments ? '(?*);' : ';'), array($arguments));
			} else {
				$code = $this->formatPhp('$class = ?; $service = new $class' . ($definition->arguments ? '(?*);' : ';'), array($definition->class, $definition->arguments));
			}

		} else {
			throw new ServiceCreationException("Class and factory method are missing.");
		}

		foreach ((array) $definition->setup as $setup) {
			list($target, $arguments) = $setup;

			if (is_string($target) && substr($target, 0, 1) !== '\\') { // auto-prepend @self
				$target = explode('::', $target);
				if (count($target) === 1) {
					array_unshift($target, '@' . self::CREATED_SERVICE);
				}
			}

			if (Arrays::isList($target) && count($target) === 2 && substr($target[1], 0, 1) === '$') { // property setter
				if (self::isService($target[0])) {
					$code .= $this->formatPhp('?->? = ?;', array($target[0], substr($target[1], 1), $arguments), $name);
				} else {
					$code .= $this->formatPhp($target[0] . '::$? = ?;', array(substr($target[1], 1), $arguments), $name);
				}
			} else {
				$code .= $this->formatCall($target, $arguments, $name);
			}
		}

		return $code .= 'return $service;';
	}



	/**
	 * Formats PHP statement.
	 * @return string
	 */
	public static function formatPhp($statement, $args, $self = NULL)
	{
		array_walk_recursive($args, function(&$val) use ($self) {
			if (!is_string($val)) {
				return;
			} elseif ($val === '@' . ContainerBuilder::THIS_CONTAINER) {
				$val = new PhpLiteral('$this');
			} elseif (ContainerBuilder::isService($val)) {
				$val = new PhpLiteral($val === "@$self" || $val === '@' . ContainerBuilder::CREATED_SERVICE ? '$service' : '$this->' . Helpers::formatMember(substr($val, 1)));
			} elseif (preg_match('#^%[\w-]+%$#', $val)) {
				$val = new PhpLiteral('$this->parameters[' . Helpers::dump(substr($val, 1, -1)) . ']');
			} elseif (!ContainerBuilder::isExpanded($val)) {
				$val = new PhpLiteral('Nette\Utils\Strings::expand(' . Helpers::dump($val) . ', $this->parameters)');
			}
		});
		return Helpers::formatArgs($statement, $args) . "\n";
	}



	/**
	 * Formats function calling in PHP.
	 * @return string
	 */
	public function formatCall($function, $arguments, $self = NULL)
	{
		if (!is_array($arguments) && $arguments !== NULL) {
			throw new Nette\InvalidStateException("Expected array of arguments for ".implode('::', (array) $function)."().");
		}
		$arguments = (array) $arguments;

		if (is_string($function)) {
			$function = explode('::', $function);
			if (count($function) === 1 && self::isExpanded($function[0])) { // globalFunc
				return $this->formatPhp("$function[0](?*);", array($arguments), $self);
			}
		}

		if (!Arrays::isList($function) || count($function) !== 2 || !self::isExpanded($function[0] . $function[1])) {
			array_unshift($arguments, $function);
			return $this->formatPhp('call_user_func(?*);', array($arguments), $self);

		} elseif (self::isService($function[0])) {
			$service = substr($function[0], 1);
			if (isset($this->definitions[$service]->class) && self::isExpanded($this->definitions[$service]->class)) {
				$arguments = $this->autowireArguments($this->definitions[$service]->class, $function[1], $arguments);
			}
			return $this->formatPhp('?->?(?*);', array($function[0], $function[1], $arguments), $self);

		} else {
			$arguments = $this->autowireArguments($function[0], $function[1], $arguments);
			return $this->formatPhp("$function[0]::$function[1](?*);", array($arguments), $self);
		}
	}



	public static function isExpanded($arg)
	{
		return strpos($arg, '%') === FALSE;
	}



	public static function isService($arg)
	{
		return (bool) preg_match('#^@\w+$#', $arg);
	}

}
