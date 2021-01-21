<?php
namespace PsalmWordPress\Tests;

use Psalm;
use Psalm\Config;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\Tests\Internal\Provider;

class BaseTest extends Psalm\Tests\TestCase {
	protected static $static_file_provider = null;
	protected static $static_project_analyzer = null;
	public static function setUpBeforeClass() : void {
		parent::setUpBeforeClass();
		static::$static_file_provider = new \Psalm\Tests\Internal\Provider\FakeFileProvider();

        $config = new TestConfig();
		$config->addPluginClass( 'PsalmWordPress\\Plugin' );

        $providers = new Providers(
            static::$static_file_provider,
            new Provider\FakeParserCacheProvider()
        );

        static::$static_project_analyzer = new ProjectAnalyzer(
            $config,
            $providers
		);

		$config->initializePlugins( static::$static_project_analyzer );

		$config->visitStubFiles( static::$static_project_analyzer->getCodebase() );
	}
	public function setUp() : void {
		$this->project_analyzer = static::$static_project_analyzer;
		$this->file_provider = static::$static_file_provider;
	}

	public function tearDown() : void {

	}

	/**
	 * @return Config
	 */
	protected function makeConfig() : Config {
		return $config;
	}

	/**
	 * @param  string         $file_path
	 * @param  \Psalm\Context $context
	 *
	 * @return void
	 */
	public function analyzeFile($file_path, \Psalm\Context $context, bool $track_unused_suppressions = true)
	{
		$codebase = $this->project_analyzer->getCodebase();
		$codebase->addFilesToAnalyze([$file_path => $file_path]);

		$codebase->scanFiles();
		$this->project_analyzer->trackUnusedSuppressions();

		$file_analyzer = new FileAnalyzer(
			$this->project_analyzer,
			$file_path,
			$codebase->config->shortenFileName($file_path)
		);
		$file_analyzer->analyze($context);
	}
}

class TestConfig extends Psalm\Tests\TestConfig {
	protected function getContents() : string {
		return '<?xml version="1.0"?>
			<projectFiles>
				<directory name="./" />
			</projectFiles>';
	}
}
