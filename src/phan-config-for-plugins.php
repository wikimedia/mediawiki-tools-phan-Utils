<?php

use Phan\Config;

/**
 * This configuration will be read and overlayed on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */
return [
	'directory_list' => [
		Config::projectPath( 'src' ),
		Config::projectPath( 'vendor' )
	],
	"exclude_analysis_directory_list" => [
		Config::projectPath( 'vendor' )
	],

	'quick_mode' => false,

	'analyze_signature_compatibility' => true,

	"minimum_severity" => 0,

	'allow_missing_properties' => false,

	'null_casts_as_any_type' => false,

	'scalar_implicit_cast' => false,

	'dead_code_detection' => true,

	'dead_code_detection_prefer_false_negative' => true,

	'processes' => 1,

	'suppress_issue_types' => [
		// As noted in phan's own cfg file: "The types of ast\Node->children are all possibly unset"
		'PhanTypePossiblyInvalidDimOffset',
	],

	'generic_types_enabled' => true,

	'plugins' => [
		'UnusedSuppressionPlugin',
		'DuplicateExpressionPlugin'
	],

	'redundant_condition_detection' => true
];
