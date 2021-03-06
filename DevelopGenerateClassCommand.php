<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class DevelopGenerateClassCommand extends ContainerAwareCommand
{
	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
		$this
			->setName('develop:generate:dto')
			->setDescription('Hello PhpStorm');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$bundleNames = array_keys($this->getContainer()->get('kernel')->getBundles());
		$console = new QuestionHelper($input);

		/** @var BundleInterface $bundle */
		$bundle = $console->ask($input, $output, (new Question('Bundle name: '))->setAutocompleterValues($bundleNames)->setValidator(function ($value) use ($bundleNames)
		{
			return $this->getContainer()->get('kernel')->getBundle($value);
		}));
		$directory = $console->ask($input, $output, new Question('Directory (Model): ', 'Model'));

		$className = $console->ask($input, $output, (new Question('ClassName (ModelDto): ', 'ModelDto'))->setValidator(function ($value) use ($bundle, $directory, $console, $input, $output)
		{
			$value = ltrim($value, '.php');
			if (file_exists("{$bundle->getPath()}/{$directory}/$value.php"))
			{
				if ($console->ask($input, $output, new Question("File {$bundle->getPath()}/{$directory}/$value.php already exist. Override? (y): ", 'y')) !== 'y')
				{
					throw new \Exception("File {$bundle->getPath()}/{$directory}/$value.php already exist");
				}
			}
			return $value;
		}));

		$method = $console->ask($input, $output, (new Question('How you going to create class? 1-xml, 2-json,3-manual (3): ', '3'))->setValidator(function ($value)
		{
			if (!in_array($value, [1, 2, 3]))
			{
				throw new \Exception("Wrong method type");
			}
			return $value;
		}));

		switch ($method)
		{
			case 1:
				$this->createClassFromXml($bundle, $directory, $className, $input, $output);
				break;
			case 2:
				break;
			case 3:
				$this->createManual($bundle, $directory, $className, $input, $output);
				break;
		}

	}


	protected function createManual(BundleInterface $bundle, string $directory, $className, InputInterface $input, OutputInterface $output)
	{
		$console = new QuestionHelper($input);

		$fields = [];
		$files = [];
		foreach (Finder::create()->in(dirname(dirname(__DIR__)))->files()->name("!Dto\.php$!") as $file)
		{
			/** @var \SplFileInfo $file */
			$files[] = str_replace(".php", "", $file->getFilename());
		}

		while (true)
		{
			$fieldName = $console->ask($input, $output, (new Question("New field name (press <return> to stop adding fields): ", null))->setValidator(function ($value) use ($fields)
			{
				if (in_array($value, $fields))
				{
					throw new \Exception("Field {$value} already exist");
				}
				return $value;
			}));
			if (!$fieldName)
			{
				break;
			}
			$types = array_merge(['int', 'float', 'string', 'bool', 'object', 'array'], $files);

			$fieldDataType = $console->ask($input, $output, (new Question('Enter filed type (string): ', 'string'))->setAutocompleterValues($types)->setValidator(function ($value) use ($types)
			{
				if (!in_array($value, $types))
				{
					throw new \Exception('Field type must be one of basic types or *Dto. Try use string…');
				}
				return $value;
			}));

			if ($fieldName && $fieldDataType)
			{
				$fields[$fieldName] = $fieldDataType;
			}
			else
			{
				break;
			}
		}
		$this->createClass($bundle, $directory, $className, $fields);
	}

	protected function createClassFromXml(BundleInterface $bundle, string $directory, string $className, InputInterface $input, OutputInterface $output)
	{
		$console = new QuestionHelper($input);

		$fields = $console->ask($input, $output, (new Question('Paste xml there: '))->setValidator(function ($value)
		{
			return array_keys(json_decode(json_encode(simplexml_load_string($value)), true));
		}));
		if ($console->ask($input, $output, (new Question("Fields to create: " . implode(',', $fields) . " with type string\nContinue? (y): ", 'y'))) === 'y')
		{
			$fields = array_fill_keys($fields, 'string');
			$this->createClass($bundle, $directory, $className, $fields);
		}
		else
		{
			$output->writeln('<bg=red>Creation aborted. Reason : user not accept fields</>.');
		}


	}

	protected function createClass(BundleInterface $bundle, string $directory, string $className, array $fields)
	{
		$c = "<?php
		
namespace {$bundle->getNamespace()}\\$directory;

class {$className} implements \JsonSerializable
{\n";
		foreach ($fields as $field => $type)
		{
			$c .= "\t/** @var {$type} */\n\tprotected \${$field};\n";
		}
		$c .= "
	/**
	 * {$className} constructor.
	 * @param array \$raw 
	 */
	public function __construct(\$raw = [])
	{\n";
		foreach ($fields as $field => $type)
		{
			$c .= "\t\t\$this->{$field} = \$raw['{$field}'] ?? null;\n";
		}
		$c .= "
	}
	
	public function jsonSerialize()
	{
		return [\n";
		foreach ($fields as $field => $type)
		{
			$c .= "\t\t\t'{$field}' => \$this->get" . ucfirst($field) . "(),\n";
		}
		$c .= "
		];
		
	}
	public function toXml()
	{
		\$root = new \\SimpleXMLElement('<" . str_replace("Dto", "", lcfirst($className)) . "></" . str_replace("Dto", "", lcfirst($className)) . ">');\n";
		foreach ($fields as $field => $type)
		{
			$c .= "\t\t\$root->addChild('{$field}', \$this->get" . ucfirst($field) . "());\n";
		}
		$c .= "
		return \$root->asXML();
	}
	";
		foreach ($fields as $field => $type)
		{
			$c .= "
	/**
	* @return {$type}
	*/	
	public function get" . ucfirst($field) . "()
	{
		return \$this->{$field};
	}
	
	public function set" . ucfirst($field) . "($type \$value)
	{
		\$this->{$field} = \$value;
		return \$this;
	}
";

		}
		$c .= "}";
		if (is_dir("{$bundle->getPath()}/{$directory}") || mkdir("{$bundle->getPath()}/{$directory}", 0755, true))
		{
			file_put_contents("{$bundle->getPath()}/{$directory}/{$className}.php", $c);
		}

		$c = "<?php
namespace {$bundle->getNamespace()}\\$directory;

class {$className}Test extends \PHPUnit_Framework_TestCase
{
	/** @var {$className} */
	protected \$instance = null;
	
	protected function setUp()
	{
		\$this->instance = new {$className}([]);
	
	}	
";
		foreach ($fields as $field => $type)
		{
			$c .= "
		
	public function testGet" . ucfirst($field) . "()
	{
		\$this->assertEquals(null, \$this->instance->get" . ucfirst($field) . "());
	}
	
	public function testSet" . ucfirst($field) . "()
	{
		\$this->assertEquals(\$this->instance, \$this->instance->set" . ucfirst($field) . "(($type)111));
		\$this->assertEquals(($type)111, \$this->instance->get" . ucfirst($field) . "());
	}
	
";

		}
		$c .= "
	public function testJsonSerialize()
	{
		\$this->assertEquals(json_decode(json_encode(\$this->instance), true), \$this->instance->jsonSerialize());
	}
}";
		$path = str_replace("src", 'tests', $bundle->getPath());

		if (is_dir("{$path}/{$directory}") || mkdir("{$path}/{$directory}", 0755, true))
		{
			file_put_contents("{$path}/{$directory}/{$className}.php", $c);
		}
	}
}
