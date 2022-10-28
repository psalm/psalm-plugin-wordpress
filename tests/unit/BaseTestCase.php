<?php

namespace PsalmWordPress\Tests;

use Psalm;
use Psalm\Config;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\Tests\Internal\Provider;

abstract class BaseTestCase extends Psalm\Tests\TestCase {
	public function setUp() : void {
		parent::setUp();
		$this->project_analyzer->getConfig()->initializePlugins( $this->project_analyzer );
		$this->project_analyzer->getConfig()->visitStubFiles( $this->project_analyzer->getCodebase() );
	}

	/**
	 * @return Config
	 */
	protected function makeConfig() : Config {
		$config = new TestConfig();
		$config->addPluginClass( 'PsalmWordPress\\Plugin' );
		return $config;
	}

	public function analyzeFile(
		$file_path,
		\Psalm\Context $context,
		bool $track_unused_suppressions = true,
		bool $taint_flow_tracking = false
	) : void {
		$codebase = $this->project_analyzer->getCodebase();
		$codebase->addFilesToAnalyze( [ $file_path => $file_path ] );

		$codebase->scanFiles();
		$this->project_analyzer->trackUnusedSuppressions();

		$file_analyzer = new FileAnalyzer(
			$this->project_analyzer,
			$file_path,
			$codebase->config->shortenFileName( $file_path )
		);
		$file_analyzer->analyze( $context );
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
