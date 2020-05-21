<?php declare( strict_types=1 );

namespace MediaWikiPhanUtils;

use ast\Node;
use Phan\AST\ContextNode;
use Phan\BlockAnalysisVisitor;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\FQSEN\FullyQualifiedFunctionLikeName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\ClosureType;

/**
 * @property-read Context $context
 * @property-read \Phan\CodeBase $code_base
 */
trait Utils {

	/** @var null|string|bool|resource filehandle to output debug messages */
	private $debugOutput;

	/**
	 * Analyze a function. This is very similar to Analyzable::analyze, but avoids several checks
	 * used by phan for performance. Plugins that need an unconditional analysis should use this
	 * method.
	 * @todo This is a bit hacky.
	 * @see \Phan\Analysis\Analyzable::analyze()
	 *
	 * @param FunctionInterface $func
	 * @param int $maxDepth
	 */
	protected function analyzeFunc( FunctionInterface $func, int $maxDepth ) : void {
		static $depth = 0;
		$node = $func->getNode();
		if ( !$node ) {
			return;
		}
		// @todo Tune the max depth. Raw benchmarking shows very little difference between e.g.
		// 5 and 10. However, while with higher values we can detect more issues and avoid more
		// false positives, it becomes harder to tell where an issue is coming from.
		// Thus, this value should be increased only when we'll have better error reporting.
		if ( $depth > $maxDepth ) {
			$this->log( __METHOD__, 'WARNING: aborting analysis earlier due to max depth' );
			return;
		}
		if ( $node->kind === \ast\AST_CLOSURE && isset( $node->children['uses'] ) ) {
			return;
		}
		$depth++;

		// Like Analyzable::analyze, clone the context to avoid overriding anything
		$context = clone $func->getContext();
		// @phan-suppress-next-line PhanUndeclaredMethod All implementations have it
		if ( $func->getRecursionDepth() !== 0 ) {
			// Add the arguments types to the internal scope of the function, see
			// https://github.com/phan/phan/issues/3848
			foreach ( $func->getParameterList() as $parameter ) {
				$context->addScopeVariable( $parameter->cloneAsNonVariadic() );
			}
		}
		try {
			( new BlockAnalysisVisitor( $this->code_base, $context ) )(
				$node
			);
		} finally {
			$depth--;
		}
	}

	/**
	 * Quick wrapper to get the ContextNode for a node
	 *
	 * @param Node $node
	 * @return ContextNode
	 */
	protected function getCtxN( Node $node ) : ContextNode {
		return new ContextNode(
			$this->code_base,
			$this->context,
			$node
		);
	}

	/**
	 * Get the current filename and line.
	 *
	 * @param Context|null $context Override the context to make debug info for
	 * @return string path/to/file +linenumber
	 */
	protected function dbgInfo( Context $context = null ) : string {
		$ctx = $context ?: $this->context;
		// Using a + instead of : so that I can just copy and paste
		// into a vim command line.
		return ' ' . $ctx->getFile() . ' +' . $ctx->getLineNumberStart();
	}

	/**
	 * Output a debug message to stdout.
	 *
	 * @param string $channel
	 * @param string $msg debug message
	 * @param string|null $caller
	 */
	protected function log( string $channel, string $msg, string $caller = null ) : void {
		$caller = $caller ?? debug_backtrace()[1]['function'];
		if ( $this->debugOutput === null ) {
			$errorOutput = getenv( "PHAN_DEBUG" );
			if ( $errorOutput && $errorOutput !== '-' ) {
				$this->debugOutput = fopen( $errorOutput, "wb" );
			} elseif ( $errorOutput === '-' ) {
				$this->debugOutput = '-';
			} else {
				$this->debugOutput = false;
			}
		}
		$line = "$channel - $caller \33[1m" . $this->dbgInfo() . " \33[0m$msg\n";
		if ( $this->debugOutput && $this->debugOutput !== '-' ) {
			fwrite(
				$this->debugOutput,
				$line
			);
		} elseif ( $this->debugOutput === '-' ) {
			echo $line;
		}
	}

	/**
	 * Given an AST node that's a callable, try and determine what it is
	 *
	 * This is intended for functions that register callbacks. It will
	 * only really work for callbacks that are basically literals.
	 *
	 * @note $node may not be the current node in $this->context.
	 *
	 * @param Node|string $node The thingy from AST expected to be a Callable
	 * @return FullyQualifiedMethodName|FullyQualifiedFunctionName|null The corresponding FQSEN
	 */
	protected function getFQSENFromCallable( $node ) {
		$callback = null;
		if ( is_string( $node ) ) {
			// Easy case, 'Foo::Bar'
			if ( strpos( $node, '::' ) === false ) {
				$callback = FullyQualifiedFunctionName::fromFullyQualifiedString(
					$node
				);
			} else {
				$callback = FullyQualifiedMethodName::fromFullyQualifiedString(
					$node
				);
			}
		} elseif ( $node instanceof Node && $node->kind === \ast\AST_CLOSURE ) {
			$method = (
			new ContextNode(
				$this->code_base,
				$this->context->withLineNumberStart(
					$node->lineno ?? 0
				),
				$node
			)
			)->getClosure();
			$callback = $method->getFQSEN();
		} elseif (
			$node instanceof Node
			&& $node->kind === \ast\AST_VAR
			&& is_string( $node->children['name'] )
		) {
			$cnode = $this->getCtxN( $node );
			$var = $cnode->getVariable();
			$types = $var->getUnionType()->getTypeSet();
			foreach ( $types as $type ) {
				if (
					( $type instanceof CallableType || $type instanceof ClosureType ) &&
					$type->asFQSEN() instanceof FullyQualifiedFunctionLikeName
				) {
					// @todo FIXME This doesn't work if the closure
					// is defined in a different function scope
					// then the one we are currently in. Perhaps
					// we could look up the closure in
					// $this->code_base to figure out what func
					// its defined on via its parent scope. Or
					// something.
					$callback = $type->asFQSEN();
					break;
				}
			}
		} elseif ( $node instanceof Node && $node->kind === \ast\AST_ARRAY ) {
			if ( count( $node->children ) !== 2 ) {
				return null;
			}
			if (
				$node->children[0]->children['key'] !== null ||
				$node->children[1]->children['key'] !== null ||
				!is_string( $node->children[1]->children['value'] )
			) {
				return null;
			}
			$methodName = $node->children[1]->children['value'];
			$classNode = $node->children[0]->children['value'];
			if ( is_string( $node->children[0]->children['value'] ) ) {
				$className = $classNode;
			} elseif ( $classNode instanceof Node ) {
				switch ( $classNode->kind ) {
					case \ast\AST_MAGIC_CONST:
						// Mostly a special case for MediaWiki
						// CoreParserFunctions.php
						if (
							( $classNode->flags & \ast\flags\MAGIC_CLASS ) !== 0
							&& $this->context->isInClassScope()
						) {
							$className = (string)$this->context->getClassFQSEN();
						} else {
							return null;
						}
						break;
					case \ast\AST_CLASS_NAME:
						if (
							$classNode->children['class']->kind === \ast\AST_NAME &&
							is_string( $classNode->children['class']->children['name'] )
						) {
							$className = $classNode->children['class']->children['name'];
						} else {
							return null;
						}
						break;
					case \ast\AST_CLASS_CONST:
						return null;
					case \ast\AST_VAR:
					case \ast\AST_PROP:
						$var = $classNode->kind === \ast\AST_VAR
							? $this->getCtxN( $classNode )->getVariable()
							: $this->getCtxN( $classNode )->getProperty( false );
						$type = $var->getUnionType();
						if ( $type->typeCount() !== 1 || $type->isScalar() ) {
							return null;
						}
						$cl = $type->asClassList(
							$this->code_base,
							$this->context
						);
						$clazz = false;
						foreach ( $cl as $item ) {
							$clazz = $item;
							break;
						}
						if ( !$clazz ) {
							return null;
						}
						$className = (string)$clazz->getFQSEN();
						break;
					default:
						return null;
				}

			} else {
				return null;
			}
			// Note, not from in context, since this goes to call_user_func.
			$callback = FullyQualifiedMethodName::fromFullyQualifiedString(
				$className . '::' . $methodName
			);
		} else {
			return null;
		}

		if (
			( $callback instanceof FullyQualifiedMethodName &&
				$this->code_base->hasMethodWithFQSEN( $callback ) )
			|| ( $callback instanceof FullyQualifiedFunctionName &&
				$this->code_base->hasFunctionWithFQSEN( $callback ) )
		) {
			return $callback;
		} else {
			// @todo Should almost emit an issue for this
			$this->log( __METHOD__, "Missing Callable $callback" );
			return null;
		}
	}

}
