<?php
/**
 * The moderation switches: three layers on/off, and built-in terms removable.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/moderation-check.php --path=/var/www/html
 *
 * **No network and no money.** Layer 2 runs against a stub TextProvider, so
 * the override behaviour is asserted without a Gemini call — which also means
 * the awkward cases (a block with no translation, a transport failure) can be
 * produced on demand rather than waited for.
 *
 * Two things here are worth knowing before trusting a green run:
 *
 *   - The blocklist half writes the real `aicake_blocklist` option, because
 *     that is what `Blocklist` reads. It snapshots and restores it, so a run
 *     leaves the shop's list exactly as it found it — including a shop that
 *     has never configured one (the option must go back to *absent*, not to
 *     an empty array, or `terms()` takes a different branch afterwards).
 *   - The layer half never touches the database. It subclasses `Settings` and
 *     answers from an array, so an interrupted run cannot leave moderation
 *     switched off on a live shop.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.NamingConventions.ValidVariableName

use AiCake\Domain\PromptAnalysis;
use AiCake\Moderation\Blocklist;
use AiCake\Moderation\Moderator;
use AiCake\Moderation\Sanitiser;
use AiCake\Providers\ProviderRegistry;
use AiCake\Providers\TextProvider;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

/*
 * Explicitly in $GLOBALS: `wp eval-file` runs this inside a function, so a
 * plain assignment is a local that `global` in the helper can never see.
 */
$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * @param string $label  What is being asserted.
 * @param mixed  $expect Expected value.
 * @param mixed  $actual Actual value.
 */
function aicake_check( string $label, $expect, $actual ): void {
	global $aicake_pass, $aicake_fail;

	if ( $expect === $actual ) {
		printf( "  ok    %-58s %s\n", $label, is_scalar( $actual ) ? (string) $actual : gettype( $actual ) );
		++$aicake_pass;

		return;
	}

	printf( "  FAIL  %-58s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
	++$aicake_fail;
}

/* ------------------------------------------------------------------- stubs */

/**
 * Settings that answer from an array, so the layer switches can be flipped
 * without writing to a live shop's options.
 */
class AiCake_Check_Settings extends Settings {

	/**
	 * @var array<string, mixed>
	 */
	private array $overrides;

	/**
	 * @param array<string, mixed> $overrides Key => value.
	 */
	public function __construct( array $overrides ) {
		$this->overrides = $overrides;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return array_key_exists( $key, $this->overrides )
			? $this->overrides[ $key ]
			: parent::get( $key, $default );
	}
}

/**
 * A classifier that returns whatever the scenario needs.
 */
class AiCake_Check_TextProvider implements TextProvider {

	private PromptAnalysis $answer;

	/**
	 * @param PromptAnalysis $answer What analyse() returns.
	 */
	public function __construct( PromptAnalysis $answer ) {
		$this->answer = $answer;
	}

	/**
	 * @param string $prompt_lt Ignored — the answer is fixed per scenario.
	 */
	public function analyse( string $prompt_lt ): PromptAnalysis {
		return $this->answer;
	}

	/**
	 * Published price.
	 */
	public function estimate_cost(): float {
		return 0.0;
	}

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return 'aicake-check-stub';
	}

	/**
	 * Model name.
	 */
	public function model(): string {
		return 'stub';
	}

	/**
	 * Always callable.
	 */
	public function is_configured(): bool {
		return true;
	}
}

/**
 * A Moderator wired to fixed settings and a fixed classifier answer.
 *
 * @param array<string, mixed> $overrides Setting overrides.
 * @param PromptAnalysis|null  $answer    What layer 2 returns, if it is called.
 */
function aicake_moderator( array $overrides, ?PromptAnalysis $answer = null ): Moderator {
	$settings = new AiCake_Check_Settings( array_merge( array( 'text_provider' => 'aicake-check-stub' ), $overrides ) );
	$logger   = new Logger( $settings );
	$registry = new ProviderRegistry( $settings, $logger );

	if ( null !== $answer ) {
		$registry->add_text_provider( new AiCake_Check_TextProvider( $answer ) );
	}

	return new Moderator( new Sanitiser(), new Blocklist(), $registry, $logger, $settings );
}

/* --------------------------------------------------- 1. built-in term edits */

echo "\n1. Removing a built-in term\n";

$snapshot = get_option( Blocklist::OPTION, null );
$had_none = null === $snapshot;

delete_option( Blocklist::OPTION );

$starter = Blocklist::starter_terms();
$list    = new Blocklist();

aicake_check( 'baseline: Elsos suknelė is blocked', 'block', $list->check( 'Elsos suknelė' )->verdict );
aicake_check( 'baseline: every shipped term is active', count( $starter ), count( $list->terms() ) );

$list->set_removed_terms( array( 'Elsa' ) );
$list = new Blocklist();

aicake_check( 'removed: Elsos suknelė passes', 'allow', $list->check( 'Elsos suknelė' )->verdict );
aicake_check( 'removed: one fewer active term', count( $starter ) - 1, count( $list->terms() ) );
aicake_check( 'removed: stored as an exclusion', array( 'Elsa' ), $list->removed_terms() );

/*
 * The assertion that earns its place. Everything above also passes if
 * set_removed_terms() emptied the whole list, which is the mistake worth
 * catching: a shop that switched off one term would silently stop screening
 * anything at all.
 */
aicake_check( 'removed: a neighbouring term still blocks', 'block', $list->check( 'Olafas ant torto' )->verdict );
aicake_check( 'removed: an unrelated term still blocks', 'block', $list->check( 'noriu Pikaču' )->verdict );

$list->set_removed_terms( array( 'Elsa', 'Ne toks terminas' ) );
$list = new Blocklist();

aicake_check( 'junk removals are discarded', array( 'Elsa' ), $list->removed_terms() );

$list->set_custom_terms( array( 'Testinis Personažas' ) );
$list = new Blocklist();

aicake_check( 'a custom term coexists with a removal', 'block', $list->check( 'testinio personažo tortas' )->verdict );
aicake_check( 'and the removal still holds', 'allow', $list->check( 'Elsos suknelė' )->verdict );
aicake_check( 'active total is starter - 1 + 1', count( $starter ), count( $list->terms() ) );

$list->set_removed_terms( array() );
$list = new Blocklist();

aicake_check( 'un-removing restores the term', 'block', $list->check( 'Elsos suknelė' )->verdict );

/* --------------------------------------------------------- 2. layer toggles */

echo "\n2. Switching layers off\n";

delete_option( Blocklist::OPTION );

$all_on = aicake_moderator( array() );

aicake_check( 'all on: a franchise name is blocked', 'blocklist', $all_on->pre_check( 'Elsos suknelė' )->layer );
aicake_check( 'all on: gibberish is blocked', 'sanity', $all_on->pre_check( 'aaaaaaaaaa' )->layer );
aicake_check( 'enabled() reads the setting', true, $all_on->enabled( 'ai' ) );
aicake_check( 'an unknown layer is not enabled', false, $all_on->enabled( 'nonsense' ) );

$no_list = aicake_moderator( array( 'moderation_blocklist' => false ) );

aicake_check( 'blocklist off: the franchise name passes', true, $no_list->pre_check( 'Elsos suknelė' )->allowed_through() );
aicake_check( 'blocklist off: gibberish is still blocked', 'sanity', $no_list->pre_check( 'aaaaaaaaaa' )->layer );

$no_sanity = aicake_moderator( array( 'moderation_sanity' => false ) );

aicake_check( 'sanity off: gibberish passes', true, $no_sanity->pre_check( 'aaaaaaaaaa' )->allowed_through() );
aicake_check( 'sanity off: the franchise name is still blocked', 'blocklist', $no_sanity->pre_check( 'Elsos suknelė' )->layer );

$nothing = aicake_moderator(
	array(
		'moderation_sanity'    => false,
		'moderation_blocklist' => false,
	)
);

aicake_check( 'both off: gibberish passes', true, $nothing->pre_check( 'aaaaaaaaaa' )->allowed_through() );
aicake_check( 'both off: the franchise name passes', true, $nothing->pre_check( 'Elsos suknelė' )->allowed_through() );

/*
 * Not a formality. The endpoints refuse '' themselves precisely so that
 * switching layer 0 off cannot send an empty prompt to a provider that
 * charges for it — but clean() is what produces that '', and it must keep
 * running when the layer is off.
 */
aicake_check( 'sanity off: clean() still strips and caps', 'a b', $nothing->clean( "a\n\n  b\t" ) );

/* ----------------------------------------------------- 3. the layer 2 switch */

echo "\n3. The AI classifier switch\n";

/**
 * @param string $prompt_en Translation.
 * @param string $verdict   allow | review | block.
 */
function aicake_answer( string $prompt_en, string $verdict ): PromptAnalysis {
	return new PromptAnalysis( $prompt_en, $verdict, array( 'franchise:frozen' ), array(), 1.0, 'stub', 'stub' );
}

// Distinct prompts throughout: analyse() caches by prompt hash, and a shared
// prompt would have scenario two reading scenario one's verdict.
$on = aicake_moderator( array(), aicake_answer( 'a dress', PromptAnalysis::BLOCK ) );

aicake_check( 'ai on: a block is a block', true, $on->analyse( 'Elsos suknelė A' )->blocked() );

$off = aicake_moderator( array( 'moderation_ai' => false ), aicake_answer( 'a dress', PromptAnalysis::BLOCK ) );
$out = $off->analyse( 'Elsos suknelė B' );

aicake_check( 'ai off: the block is overridden', true, $out->allowed() );
aicake_check( 'ai off: the translation survives', 'a dress', $out->prompt_en );
aicake_check( 'ai off: the reason says why', true, in_array( 'ai_layer_disabled', $out->reasons, true ) );
aicake_check( 'ai off: the original reason is kept', true, in_array( 'franchise:frozen', $out->reasons, true ) );

// The classifier is told it may return an empty prompt_en when it blocks, and
// Google's safety filter returns nothing at all. Overriding without a
// translation would post an empty prompt to a provider that bills for it.
$blind = aicake_moderator( array( 'moderation_ai' => false ), aicake_answer( '', PromptAnalysis::BLOCK ) );
$out   = $blind->analyse( 'Elsos suknelė C' );

aicake_check( 'ai off, no translation: still allowed', true, $out->allowed() );
aicake_check( 'ai off, no translation: falls back to Lithuanian', 'Elsos suknelė C', $out->prompt_en );

// A transport failure is not a moderation opinion, so the switch must not
// convert one into an allow — that would generate from an empty prompt.
$broken = aicake_moderator( array( 'moderation_ai' => false ), PromptAnalysis::failed( 'stub outage' ) );
$out    = $broken->analyse( 'Elsos suknelė D' );

aicake_check( 'ai off: a failed call still fails', false, $out->ok() );
aicake_check( 'ai off: and is not turned into an allow', true, $out->blocked() );

$clean = aicake_moderator( array( 'moderation_ai' => false ), aicake_answer( 'a dinosaur', PromptAnalysis::ALLOW ) );
$out   = $clean->analyse( 'Linksmas dinozauras E' );

aicake_check( 'ai off: an allow is untouched', false, in_array( 'ai_layer_disabled', $out->reasons, true ) );

foreach ( array( 'A', 'B', 'C', 'D' ) as $suffix ) {
	delete_transient( 'aicake_mod_' . md5( \AiCake\Moderation\LtNormaliser::fold( 'Elsos suknelė ' . $suffix ) ) );
}

delete_transient( 'aicake_mod_' . md5( \AiCake\Moderation\LtNormaliser::fold( 'Linksmas dinozauras E' ) ) );

/* ----------------------------------------------------------------- restore */

if ( $had_none ) {
	delete_option( Blocklist::OPTION );
} else {
	update_option( Blocklist::OPTION, $snapshot );
}

$restored = get_option( Blocklist::OPTION, null );

aicake_check( 'the shop\'s own list is put back', $snapshot, $restored );

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

if ( $GLOBALS['aicake_fail'] > 0 ) {
	exit( 1 );
}
