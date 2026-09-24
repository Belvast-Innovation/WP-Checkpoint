<?php
/**
 * PHPStan rule: the plugin never deserializes.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports any call to unserialize(), maybe_unserialize() or
 * session_decode() in the analysed code (the plugin: src/ and the entry
 * files), a method named unserialize() (ArrayObject, SplObjectStorage and
 * every Serializable class deserialize there), and any of the names as a
 * string (a callback, with or without a leading backslash). A name built up
 * at run time is out of its reach: the rule guards against a mistake, not a
 * disguise. Not even with allowed_classes => false: the replace engine reads
 * serialized data with its own byte-level reader (Replace\Serialized), which
 * instantiates nothing and keeps every byte it does not change. Tests may
 * call unserialize() to cross-check the reader's output (tests/unit/Replace);
 * they are outside the analysed paths.
 *
 * @implements Rule<Node>
 */
final class NoUnserializeRule implements Rule {

	/**
	 * Forbidden functions.
	 */
	const FORBIDDEN = array( 'unserialize', 'maybe_unserialize', 'session_decode' );

	public function getNodeType(): string {
		return Node::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode( Node $node, Scope $scope ): array {
		if ( $node instanceof FuncCall && $node->name instanceof Name && in_array( strtolower( $node->name->toString() ), self::FORBIDDEN, true ) ) {
			return array( self::error( sprintf( '%s(): the plugin never deserializes; read serialized data with Replace\Serialized.', $node->name->toString() ) ) );
		}
		if ( ( $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall ) && $node->name instanceof Node\Identifier && 'unserialize' === strtolower( $node->name->toString() ) ) {
			return array( self::error( '->unserialize() (ArrayObject, SplObjectStorage, Serializable): the plugin never deserializes.' ) );
		}
		if ( $node instanceof String_ && in_array( strtolower( ltrim( $node->value, '\\' ) ), self::FORBIDDEN, true ) ) {
			return array( self::error( sprintf( '\'%s\' as a name: the plugin never deserializes, not even through a callback.', $node->value ) ) );
		}
		return array();
	}

	private static function error( string $message ): IdentifierRuleError {
		return RuleErrorBuilder::message( $message )->identifier( 'wpcheckpoint.noUnserialize' )->build();
	}
}
